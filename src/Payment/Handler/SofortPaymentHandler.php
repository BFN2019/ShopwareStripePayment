<?php declare(strict_types=1);

namespace Stripe\ShopwarePlugin\Payment\Handler;

use Exception;
use Shopware\Core\Checkout\Cart\Exception\CustomerNotLoggedInException;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentFinalizeException;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\InvalidOrderException;
use Shopware\Core\Checkout\Payment\Exception\InvalidTransactionException;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Stripe\ShopwarePlugin\Payment\StripeApi\StripeApi;
use Stripe\ShopwarePlugin\Payment\Util\PaymentContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class SofortPaymentHandler implements AsynchronousPaymentHandlerInterface
{
    /**
     * @var StripeApi
     */
    private $stripeApi;

    /**
     * @var PaymentContext
     */
    private $paymentContext;

    /**
     * @var EntityRepositoryInterface
     */
    private $orderAddressRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $currencyRepository;

    /**
     * @var OrderTransactionStateHandler
     */
    private $orderTransactionStateHandler;

    public function __construct(
        StripeApi $stripeApi,
        PaymentContext $paymentContext,
        EntityRepositoryInterface $orderAddressRepository,
        EntityRepositoryInterface $currencyRepository,
        OrderTransactionStateHandler $orderTransactionStateHandler
    ) {
        $this->stripeApi = $stripeApi;
        $this->paymentContext = $paymentContext;
        $this->orderAddressRepository = $orderAddressRepository;
        $this->currencyRepository = $currencyRepository;
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
    }

    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): RedirectResponse {
        $order = $transaction->getOrder();
        $orderTransaction = $transaction->getOrderTransaction();
        $context = $salesChannelContext->getContext();
        $customer = $salesChannelContext->getCustomer();

        try {
            self::validateCustomer($customer);

            $source = $this->stripeApi->createSource([
                'type' => 'sofort',
                'amount' => self::getPayableAmount($orderTransaction),
                'currency' => $this->getCurrency($order, $context)->getIsoCode(),
                'owner' => [
                    'name' => self::getCustomerName($salesChannelContext->getCustomer()),
                ],
                'sofort' => [
                    'country' => $this->getBillingCountry($order, $context)->getIso(),
                    // 'statement_descriptor' => 'TODO',
                ],
                'redirect' => [
                    'return_url' => $transaction->getReturnUrl(),
                ],
                'metadata' => [
                    'shopware_order_transaction_id' => $orderTransaction->getId(),
                ],
            ]);
            if ($source->redirect->status !== 'pending') {
                throw new InvalidSourceRedirectException($source, $orderTransaction);
            }

            $this->paymentContext->saveStripeSource($orderTransaction, $context, $source);

            return new RedirectResponse($source->redirect->url);
        } catch (Exception $exception) {
            throw new AsyncPaymentProcessException($orderTransaction->getId(), $exception->getMessage());
        }
    }

    public function finalize(
        AsyncPaymentTransactionStruct $transaction,
        Request $request,
        SalesChannelContext $salesChannelContext
    ): void {
        $order = $transaction->getOrder();
        $orderTransaction = $transaction->getOrderTransaction();
        $context = $salesChannelContext->getContext();
        $customer = $salesChannelContext->getCustomer();

        try {
            self::validateCustomer($customer);

            // Validate the Stripe source
            $source = $this->paymentContext->getStripeSource($orderTransaction, $context);
            if (!$source || $source->client_secret !== $request->get('client_secret')) {
                throw new InvalidTransactionException($orderTransaction->getId());
            }
            if ($source->redirect->status === 'failed' && $source->redirect->failure_reason === 'user_abort') {
                throw new CustomerCanceledAsyncPaymentException($orderTransaction->getId(), [
                    'stripe_payment' => ['source_id' => $source->id],
                ]);
            }
            if ($source->status !== 'chargeable') {
                throw new SourceNotChargeableException($source, $orderTransaction);
            }

            $chargeParams = [
                'source' => $source->id,
                'amount' => $source->amount,
                'currency' => $source->currency,
                'description' => self::createChargeDescription($order, $customer),
            ];
            // if ($paymentMethod->includeStatmentDescriptorInCharge()) {
            //     $chargeParams['statement_descriptor'] = mb_substr('TODO', 0, 22);
            // }
            // $stripeCustomer = Util::getStripeCustomer();
            // if ($source->customer && $stripeCustomer) {
            //     $chargeParams['customer'] = $stripeCustomer->id;
            // }
            // if ($sendReceiptEmails) {
            //     $chargeParams['receipt_email'] = $customer->getEmail();
            // }

            $charge = $this->stripeApi->createCharge($chargeParams);
            $this->paymentContext->saveStripeCharge($orderTransaction, $context, $charge);
            switch ($charge->status) {
                case 'succeeded':
                    $this->orderTransactionStateHandler->pay($orderTransaction->getId(), $context);
                    break;
                case 'failed':
                    throw new ChargeFailedException($charge, $orderTransaction);
                default:
                    break;
            }
        } catch (Exception $exception) {
            throw new AsyncPaymentFinalizeException($orderTransaction->getId(), $exception->getMessage());
        }
    }

    /**
     * @throws CustomerNotLoggedInException
     */
    private static function validateCustomer(?CustomerEntity $customer)
    {
        if (!$customer) {
            throw new CustomerNotLoggedInException();
        }
    }

    private static function getPayableAmount(OrderTransactionEntity $orderTransaction): int
    {
        return intval(round(100 * $orderTransaction->getAmount()->getTotalPrice()));
    }

    private static function getCustomerName(CustomerEntity $customer): string
    {
        return trim($customer->getCompany() ?? sprintf('%s %s', $customer->getFirstName(), $customer->getLastName()));
    }

    /**
     * @throws InvalidOrderException
     */
    private function getBillingCountry(OrderEntity $order, Context $context): CountryEntity
    {
        $billingAddressId = $order->getBillingAddressId();
        $criteria = new Criteria([$billingAddressId]);
        $criteria->addAssociation('country');
        $billingAddress = $this->orderAddressRepository->search($criteria, $context)->get($billingAddressId);
        if (!$billingAddress || !$billingAddress->getCountry()) {
            throw new InvalidOrderException($order->getId());
        }

        return $billingAddress->getCountry();
    }

    /**
     * @throws InvalidOrderException
     */
    private function getCurrency(OrderEntity $order, Context $context): CurrencyEntity
    {
        if ($order->getCurrency()) {
            return $order->getCurrency();
        }

        $currencyId = $order->getCurrencyId();
        $criteria = new Criteria([$currencyId]);
        $currency = $this->currencyRepository->search($criteria, $context)->get($currencyId);
        if (!$currency) {
            throw new InvalidOrderException($order->getId());
        }

        return $currency;
    }

    private static function createChargeDescription(OrderEntity $order, CustomerEntity $customer): string
    {
        return sprintf(
            '%s / Customer %s / Order %s',
            $customer->getEmail(),
            $customer->getCustomerNumber(),
            $order->getOrderNumber()
        );
    }
}

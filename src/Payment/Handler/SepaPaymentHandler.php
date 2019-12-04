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
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Stripe\ShopwarePlugin\Payment\Services\SessionService;
use Stripe\ShopwarePlugin\Payment\Settings\SettingsService;
use Stripe\ShopwarePlugin\Payment\StripeApi\StripeApi;
use Stripe\ShopwarePlugin\Payment\StripeApi\StripeApiFactory;
use Stripe\ShopwarePlugin\Payment\Util\PaymentContext;
use Stripe\ShopwarePlugin\Payment\Util\Util;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class SepaPaymentHandler implements AsynchronousPaymentHandlerInterface
{
    private const STRIPE_REQUEST_PARAMETER_PAYMENT_INTENT_CLIENT_SECRET = 'payment_intent_client_secret';

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
    /**
     * @var SettingsService
     */
    private $settingsService;
    /**
     * @var SessionService
     */
    private $sessionService;
    private $customerRepository;
    /**
     * @var StripeApiFactory
     */
    private $stripeApiFactory;

    public function __construct(
        StripeApiFactory $stripeApiFactory,
        PaymentContext $paymentContext,
        EntityRepositoryInterface $orderAddressRepository,
        EntityRepositoryInterface $currencyRepository,
        OrderTransactionStateHandler $orderTransactionStateHandler,
        SettingsService $settingsService,
        SessionService $sessionService,
        $customerRepository
    ) {
        $this->stripeApiFactory = $stripeApiFactory;
        $this->paymentContext = $paymentContext;
        $this->orderAddressRepository = $orderAddressRepository;
        $this->currencyRepository = $currencyRepository;
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
        $this->settingsService = $settingsService;
        $this->sessionService = $sessionService;
        $this->customerRepository = $customerRepository;
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

            $stripeSession = $this->sessionService->getStripeSession();
            if (!$stripeSession->selectedSepaBankAccount || !isset($stripeSession->selectedSepaBankAccount['id'])) {
                throw new \Exception('no bank account selected');
            }

            $salesChannelId = $salesChannelContext->getSalesChannel()->getId();
            $stripeApi = $this->stripeApiFactory->getStripeApiForSalesChannel($salesChannelId);

            $stripeCustomer = Util::getOrCreateStripeCustomer(
                $this->customerRepository,
                $customer,
                $stripeApi,
                $context
            );
            $paymentIntentConfig = [
                'amount' => self::getPayableAmount($orderTransaction),
                'currency' => mb_strtolower($this->getCurrency($order, $context)->getIsoCode()),
                'payment_method' => $stripeSession->selectedSepaBankAccount['id'],
                'payment_method_types' => ['sepa_debit'],
                'confirmation_method' => 'automatic',
                'confirm' => true,
                'mandate_data' => [
                    'customer_acceptance' => [
                        'type' => 'online',
                        'online' => [
                            'ip_address' => $_SERVER['REMOTE_ADDR'],
                            'user_agent' => $_SERVER['HTTP_USER_AGENT'],
                        ],
                    ],
                ],
                'return_url' => $transaction->getReturnUrl(),
                'metadata' => [
                    'shopware_order_transaction_id' => $orderTransaction->getId(),
                ],
                'customer' => $stripeCustomer->id,
                'description' => sprintf('%s / Customer %s', $customer->getEmail(), $customer->getCustomerNumber()),
            ];

            $statementDescriptor = mb_substr(
                $this->settingsService->getConfigValue('statementDescriptorSuffix', $salesChannelId) ?: '',
                0,
                22
            );
            if ($statementDescriptor) {
                $paymentIntentConfig['statement_descriptor'] = $statementDescriptor;
            }

            if ($this->settingsService->getConfigValue('sendStripeChargeEmails', $salesChannelId)) {
                $paymentIntentConfig['receipt_email'] = $customer->getEmail();
            }

            if ($stripeSession->saveSepaBankAccountForFutureCheckouts) {
                // Add the bank account to the Stripe customer
                $paymentIntentConfig['save_payment_method'] = $stripeSession->saveSepaBankAccountForFutureCheckouts;
            }

            $paymentIntent = $stripeApi->createPaymentIntent($paymentIntentConfig);

            if ($paymentIntent->status === 'succeeded') {
                // No special flow required, save the payment intent and charge id in the order transaction
                try {
                    // Persist the payment intent id in the transaction
                    $this->paymentContext->saveStripePaymentIntent($orderTransaction, $context, $paymentIntent);
                    $this->paymentContext->saveStripeCharge($orderTransaction, $context, $paymentIntent->charges->data[0]);
                    $this->sessionService->resetStripeSession();
                } catch (Exception $e) {
                    // TODO
                    $message = $e->getMessage();

                    throw new \Exception($message);
                }

                // Redirect directly to the finalize step
                $parameters = http_build_query([
                    self::STRIPE_REQUEST_PARAMETER_PAYMENT_INTENT_CLIENT_SECRET => $paymentIntent->client_secret,
                ]);

                return new RedirectResponse(sprintf('%s&%s', $transaction->getReturnUrl(), $parameters));
            }
            if ($paymentIntent->status === 'requires_action') {
                // We need to redirect to handle the required action
                if (!$paymentIntent->next_action || $paymentIntent->next_action->type !== 'redirect_to_url') {
                    // TODO
                    $message = 'no redirect url';

                    throw new \Exception($message);
                }

                $this->paymentContext->saveStripePaymentIntent($orderTransaction, $context, $paymentIntent);

                return new RedirectResponse($paymentIntent->next_action->redirect_to_url->url);
            }
            if ($paymentIntent->status === 'processing') {
                // Redirect directly to the finalize step, we need to update the order status via a webhook

                $this->paymentContext->saveStripePaymentIntent($orderTransaction, $context, $paymentIntent);
                if (count($paymentIntent->charges->data) > 0) {
                    $this->paymentContext->saveStripeCharge($orderTransaction, $context, $paymentIntent->charges->data[0]);
                }

                $parameters = http_build_query([
                    self::STRIPE_REQUEST_PARAMETER_PAYMENT_INTENT_CLIENT_SECRET => $paymentIntent->client_secret,
                ]);

                return new RedirectResponse(sprintf('%s&%s', $transaction->getReturnUrl(), $parameters));
            }

            // Unable to process payment
            // TODO: use custom exception and correct snippet
            throw new PaymentIntentNotChargeableException($paymentIntent, $orderTransaction);
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

            // Validate the Stripe payment intent
            $paymentIntent = $this->paymentContext->getStripePaymentIntent($orderTransaction, $salesChannelContext);
            if (!$paymentIntent || $paymentIntent->client_secret !== $request->get(self::STRIPE_REQUEST_PARAMETER_PAYMENT_INTENT_CLIENT_SECRET)) {
                throw new InvalidTransactionException($orderTransaction->getId());
            }
            if ($paymentIntent->status === 'processing') {
                // Do not mark the payment as payed, but complete the checkout workflow.
                // A webhook event will update the order payment status asynchronously.
                $this->sessionService->resetStripeSession();

                return;
            }
            if ($paymentIntent->status !== 'succeeded') {
                // TODO
                throw new PaymentIntentNotChargeableException($paymentIntent, $orderTransaction);
            }

            // Attach the created charge id to the order transaction
            $this->paymentContext->saveStripeCharge($orderTransaction, $context, $paymentIntent->charges->data[0]);
            $this->sessionService->resetStripeSession();

            // Update the payment intent with the order number
            $paymentIntent->description .= ' / Order ' . $order->getOrderNumber();
            $paymentIntent->save();

            if ($orderTransaction->getStateMachineState()->getTechnicalName() === 'paid') {
                return;
            }
            // Mark the order as payed
            $this->orderTransactionStateHandler->pay($orderTransaction->getId(), $context);
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

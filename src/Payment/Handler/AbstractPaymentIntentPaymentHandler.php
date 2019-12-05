<?php declare(strict_types=1);

namespace Stripe\ShopwarePlugin\Payment\Handler;

use Shopware\Core\Checkout\Cart\Exception\CustomerNotLoggedInException;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentFinalizeException;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Checkout\Payment\Exception\InvalidOrderException;
use Shopware\Core\Checkout\Payment\Exception\InvalidTransactionException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\Language\LanguageEntity;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\Locale\LocaleEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Stripe\ShopwarePlugin\Payment\Services\SessionConfig;
use Stripe\ShopwarePlugin\Payment\Services\SessionService;
use Stripe\ShopwarePlugin\Payment\Settings\SettingsService;
use Stripe\ShopwarePlugin\Payment\StripeApi\StripeApiFactory;
use Stripe\ShopwarePlugin\Payment\Util\PaymentContext;
use Stripe\ShopwarePlugin\Payment\Util\Util;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

abstract class AbstractPaymentIntentPaymentHandler implements AsynchronousPaymentHandlerInterface
{
    /**
     * @var SettingsService
     */
    protected $settingsService;
    /**
     * @var SessionService
     */
    protected $sessionService;
    /**
     * @var StripeApiFactory
     */
    protected $stripeApiFactory;
    /**
     * @var PaymentContext
     */
    protected $paymentContext;
    /**
     * @var OrderTransactionStateHandler
     */
    protected $orderTransactionStateHandler;
    /**
     * @var EntityRepositoryInterface
     */
    protected $orderAddressRepository;
    /**
     * @var EntityRepositoryInterface
     */
    protected $currencyRepository;
    /**
     * @var EntityRepositoryInterface
     */
    protected $languageRepository;
    /**
     * @var EntityRepositoryInterface
     */
    protected $customerRepository;

    private const STRIPE_REQUEST_PARAMETER_PAYMENT_INTENT_CLIENT_SECRET = 'payment_intent_client_secret';

    abstract protected function createPaymentIntentConfig(
        AsyncPaymentTransactionStruct $transaction,
        SalesChannelContext $salesChannelContext
    ): array;

    public function __construct(
        SettingsService $settingsService,
        SessionService $sessionService,
        StripeApiFactory $stripeApiFactory,
        PaymentContext $paymentContext,
        OrderTransactionStateHandler $orderTransactionStateHandler,
        EntityRepositoryInterface $orderAddressRepository,
        EntityRepositoryInterface $currencyRepository,
        EntityRepositoryInterface $languageRepository,
        EntityRepositoryInterface $customerRepository
    ) {
        $this->stripeApiFactory = $stripeApiFactory;
        $this->paymentContext = $paymentContext;
        $this->orderAddressRepository = $orderAddressRepository;
        $this->currencyRepository = $currencyRepository;
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
        $this->settingsService = $settingsService;
        $this->sessionService = $sessionService;
        $this->languageRepository = $languageRepository;
        $this->customerRepository = $customerRepository;
    }

    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): RedirectResponse {
        $orderTransaction = $transaction->getOrderTransaction();

        try {
            $customer = $salesChannelContext->getCustomer();
            self::validateCustomer($customer);

            $stripeSessionConfig = $this->sessionService->getStripeSession();
            $this->validateSessionConfig($stripeSessionConfig);

            $paymentIntentConfig = $this->createPaymentIntentConfig($transaction, $salesChannelContext);

            $salesChannelId = $salesChannelContext->getSalesChannel()->getId();
            $stripeApi = $this->stripeApiFactory->getStripeApiForSalesChannel($salesChannelId);
            $paymentIntent = $stripeApi->createPaymentIntent($paymentIntentConfig);

            $context = $salesChannelContext->getContext();
            if ($paymentIntent->status === 'succeeded') {
                // No special flow required, save the payment intent and charge id in the order transaction
                try {
                    $this->paymentContext->saveStripePaymentIntent($orderTransaction, $context, $paymentIntent);
                    $this->paymentContext->saveStripeCharge($orderTransaction, $context, $paymentIntent->charges->data[0]);
                    $this->sessionService->resetStripeSession();
                } catch (\Exception $e) {
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
                // Redirect directly to the finalize step, we need to update the order status via a Webhook
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
        } catch (\Exception $exception) {
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
                // A Webhook event will update the order payment status asynchronously.
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

            // Mark the order as payed if it isn't yet
            if ($orderTransaction->getStateMachineState()->getTechnicalName() === 'paid') {
                return;
            }
            $this->orderTransactionStateHandler->pay($orderTransaction->getId(), $context);
        } catch (\Exception $exception) {
            throw new AsyncPaymentFinalizeException($orderTransaction->getId(), $exception->getMessage());
        }
    }

    protected function validateSessionConfig(SessionConfig $sessionConfig): void
    {
    }

    /**
     * @throws CustomerNotLoggedInException
     */
    private static function validateCustomer(?CustomerEntity $customer): void
    {
        if (!$customer) {
            throw new CustomerNotLoggedInException();
        }
    }

    protected static function getPayableAmount(OrderTransactionEntity $orderTransaction): int
    {
        return intval(round(100 * $orderTransaction->getAmount()->getTotalPrice()));
    }

    protected static function getCustomerName(CustomerEntity $customer): string
    {
        return trim($customer->getCompany() ?? sprintf('%s %s', $customer->getFirstName(), $customer->getLastName()));
    }

    /**
     * @param OrderEntity $order
     * @param Context $context
     * @return CurrencyEntity
     * @throws InvalidOrderException
     * @throws \Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException
     */
    protected function getCurrency(OrderEntity $order, Context $context): CurrencyEntity
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

    /**
     * @param OrderEntity $order
     * @param Context $context
     * @return OrderAddressEntity
     * @throws InvalidOrderException
     * @throws \Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException
     */
    protected function getBillingAddress(OrderEntity $order, Context $context): OrderAddressEntity
    {
        $billingAddressId = $order->getBillingAddressId();
        $criteria = new Criteria([$billingAddressId]);
        $criteria->addAssociation('country');
        $billingAddress = $this->orderAddressRepository->search($criteria, $context)->get($billingAddressId);
        if (!$billingAddress) {
            throw new InvalidOrderException($order->getId());
        }

        return $billingAddress;
    }

    /**
     * @param OrderEntity $order
     * @param Context $context
     * @return OrderAddressEntity
     * @throws \Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException
     */
    protected function getShippingAddress(OrderEntity $order, Context $context): OrderAddressEntity
    {
        $criteria = new Criteria();
        $criteria->addAssociations(
            [
                'country',
                'countryState',
            ]
        );
        $criteria->addFilter(
            new EqualsFilter(
                'orderId',
                $order->getId()
            )
        );
        $criteria->addFilter(
            new NotFilter(NotFilter::CONNECTION_AND, [new EqualsFilter('id', $order->getBillingAddressId())])
        );

        $result = $this->orderAddressRepository->search($criteria, $context);
        if ($result->getTotal() === 0) {
            // TODO
            throw new \Exception('no shipping address');
        }

        /** @var OrderAddressEntity $shippingAddress */
        $shippingAddress = $result->getEntities()->first();

        return $shippingAddress;
    }

    /**
     * @param CustomerEntity $customer
     * @param Context $context
     * @return LocaleEntity
     * @throws \Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException
     */
    protected function getLocale(CustomerEntity $customer, Context $context): LocaleEntity
    {
        $languageId = $customer->getLanguageId();
        $criteria = new Criteria([$languageId]);
        $criteria->addAssociation('locale');
        /** @var LanguageEntity $language */
        $language = $this->languageRepository->search($criteria, $context)->get($languageId);
        if (!$language) {
            throw new \Exception('invalid customer');
        }

        return $language->getLocale();
    }
}

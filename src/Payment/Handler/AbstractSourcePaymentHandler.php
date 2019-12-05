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
use Stripe\ShopwarePlugin\Payment\Services\SessionService;
use Stripe\ShopwarePlugin\Payment\Settings\SettingsService;
use Stripe\ShopwarePlugin\Payment\StripeApi\StripeApiFactory;
use Stripe\ShopwarePlugin\Payment\Util\PaymentContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

abstract class AbstractSourcePaymentHandler implements AsynchronousPaymentHandlerInterface
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

    abstract protected function createSourceConfig(
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
        EntityRepositoryInterface $languageRepository
    ) {
        $this->stripeApiFactory = $stripeApiFactory;
        $this->paymentContext = $paymentContext;
        $this->orderAddressRepository = $orderAddressRepository;
        $this->currencyRepository = $currencyRepository;
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
        $this->settingsService = $settingsService;
        $this->sessionService = $sessionService;
        $this->languageRepository = $languageRepository;
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

            $sourceConfig = $this->createSourceConfig($transaction, $salesChannelContext);

            $salesChannelId = $salesChannelContext->getSalesChannel()->getId();
            $stripeApi = $this->stripeApiFactory->getStripeApiForSalesChannel($salesChannelId);
            $source = $stripeApi->createSource($sourceConfig);

            if ($source->redirect->status !== 'pending') {
                throw new InvalidSourceRedirectException($source, $orderTransaction);
            }

            $context = $salesChannelContext->getContext();
            $this->paymentContext->saveStripeSource($orderTransaction, $context, $source);

            return new RedirectResponse($source->redirect->url);
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
            static::validateCustomer($customer);

            // Validate the Stripe source
            $source = $this->paymentContext->getStripeSource($orderTransaction, $salesChannelContext);
            if (!$source || $source->client_secret !== $request->get('client_secret')) {
                throw new InvalidTransactionException($orderTransaction->getId());
            }
            if (($source->redirect->status === 'failed' && $source->redirect->failure_reason === 'user_abort')
                || $request->get('redirect_status') === 'canceled'
            ) {
                throw new CustomerCanceledAsyncPaymentException($orderTransaction->getId(), [
                    'stripe_payment' => ['source_id' => $source->id],
                ]);
            }
            if ($source->status === 'pending') {
                // Do not mark the payment as payed, but complete the checkout workflow.
                // A webhook event will update the order payment status asynchronously and create the charge.
                $this->sessionService->resetStripeSession();

                return;
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

            $salesChannelId = $salesChannelContext->getSalesChannel()->getId();
            if ($this->settingsService->getConfigValue('sendStripeChargeEmails', $salesChannelId)) {
                $chargeParams['receipt_email'] = $customer->getEmail();
            }

            $salesChannelId = $salesChannelContext->getSalesChannel()->getId();
            $stripeApi = $this->stripeApiFactory->getStripeApiForSalesChannel($salesChannelId);

            $charge = $stripeApi->createCharge($chargeParams);
            $this->paymentContext->saveStripeCharge($orderTransaction, $context, $charge);
            switch ($charge->status) {
                case 'succeeded':
                    $this->sessionService->resetStripeSession();
                    if ($orderTransaction->getStateMachineState()->getTechnicalName() === 'paid') {
                        return;
                    }
                    $this->orderTransactionStateHandler->pay($orderTransaction->getId(), $context);
                    break;
                case 'failed':
                    throw new ChargeFailedException($charge, $orderTransaction);
                default:
                    break;
            }
        } catch (\Exception $exception) {
            throw new AsyncPaymentFinalizeException($orderTransaction->getId(), $exception->getMessage());
        }
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
     * @param CustomerEntity $customer
     * @return string
     */
    private static function createChargeDescription(OrderEntity $order, CustomerEntity $customer): string
    {
        return sprintf(
            '%s / Customer %s / Order %s',
            $customer->getEmail(),
            $customer->getCustomerNumber(),
            $order->getOrderNumber()
        );
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

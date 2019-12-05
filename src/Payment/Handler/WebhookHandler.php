<?php

namespace Stripe\ShopwarePlugin\Payment\Handler;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Exception\InvalidOrderException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\Currency\CurrencyEntity;
use Stripe\Charge;
use Stripe\PaymentIntent;
use Stripe\ShopwarePlugin\Payment\Settings\SettingsService;
use Stripe\ShopwarePlugin\Payment\StripeApi\StripeApiFactory;
use Stripe\ShopwarePlugin\Payment\Util\PaymentContext;
use Stripe\ShopwarePlugin\Payment\Util\Util;
use Stripe\Source;

class WebhookHandler
{
    /**
     * @var \Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface
     */
    private $orderTransactionRepo;
    /**
     * @var OrderTransactionStateHandler
     */
    private $orderTransactionStateHandler;

    /**
     * @var PaymentContext
     */
    private $paymentContext;
    /**
     * @var EntityRepositoryInterface
     */
    private $customerRepository;
    /**
     * @var StripeApiFactory
     */
    private $stripeApiFactory;
    /**
     * @var EntityRepositoryInterface
     */
    private $currencyRepository;
    /**
     * @var SettingsService
     */
    private $settingsService;

    public function __construct(
        DefinitionInstanceRegistry $definitionRegistry,
        OrderTransactionStateHandler $orderTransactionStateHandler,
        OrderTransactionDefinition $orderTransactionDefinition,
        PaymentContext $paymentContext,
        EntityRepositoryInterface $customerRepository,
        StripeApiFactory $stripeApiFactory,
        EntityRepositoryInterface $currencyRepository,
        SettingsService $settingsService
    ) {
        $this->orderTransactionRepo = $definitionRegistry->getRepository($orderTransactionDefinition->getEntityName());
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
        $this->paymentContext = $paymentContext;
        $this->customerRepository = $customerRepository;
        $this->stripeApiFactory = $stripeApiFactory;
        $this->currencyRepository = $currencyRepository;
        $this->settingsService = $settingsService;
    }

    public function handlePaymentIntentSuccessful(PaymentIntent $paymentIntent, Context $context): void
    {
        try {
            $orderTransaction = $this->getOrderTransactionForPaymentIntent($paymentIntent, $context);
        } catch (\Exception $e) {
            // Nothing to do, no order exists
            return;
        }

        // Already paid, nothing to do
        if ($orderTransaction->getStateMachineState()->getTechnicalName() === 'paid') {
            return;
        }

        // Persist charge id and append order number to the payment intent
        $this->paymentContext->saveStripeCharge($orderTransaction, $context, $paymentIntent->charges->data[0]);
        $order = $orderTransaction->getOrder();
        $paymentIntent->description .= ' / Order ' . $order->getOrderNumber();
        $paymentIntent->save();

        $this->orderTransactionStateHandler->pay($orderTransaction->getId(), $context);
    }

    public function handlePaymentIntentUnsuccessful(PaymentIntent $paymentIntent, Context $context): void
    {
        try {
            $orderTransaction = $this->getOrderTransactionForPaymentIntent($paymentIntent, $context);
        } catch (\Exception $e) {
            // Nothing to do, no order exists
            return;
        }

        // Already cancelled, nothing to do
        if ($orderTransaction->getStateMachineState()->getTechnicalName() === 'cancelled') { // TODO: correct name?
            return;
        }
        $this->orderTransactionStateHandler->cancel($orderTransaction->getId(), $context);
    }

    public function handleChargeSuccessful(Charge $charge, Context $context): void
    {
        try {
            $orderTransaction = $this->getOrderTransactionForCharge($charge, $context);
        } catch (\Exception $e) {
            // Nothing to do, no order exists
            return;
        }

        // Already paid, nothing to do
        if ($orderTransaction->getStateMachineState()->getTechnicalName() === 'paid') {
            return;
        }

        // Append the order number to the charge
        $order = $orderTransaction->getOrder();
        $charge->description .= ' / Order ' . $order->getOrderNumber();
        $charge->save();

        $this->orderTransactionStateHandler->pay($orderTransaction->getId(), $context);
    }

    public function handleChargeUnsuccessful(Charge $charge, Context $context): void
    {
        try {
            $orderTransaction = $this->getOrderTransactionForCharge($charge, $context);
        } catch (\Exception $e) {
            // Nothing to do, no order exists
            return;
        }

        // Already cancelled, nothing to do
        if ($orderTransaction->getStateMachineState()->getTechnicalName() === 'cancelled') {
            return;
        }
        $this->orderTransactionStateHandler->cancel($orderTransaction->getId(), $context);
    }

    public function handleSourceChargable(Source $source, Context $context): void
    {
        // Wait a little so that the redirect may finish creating the charge
        sleep(5);

        try {
            $orderTransaction = $this->getOrderTransactionForSource($source, $context);
        } catch (\Exception $e) {
            // Nothing to do, no order exists
            return;
        }

        $orderTransactionPaymentContext = $orderTransaction->getCustomFields()['stripe_payment_context'];
        if ($orderTransactionPaymentContext
            && $orderTransactionPaymentContext['payment']
            && $orderTransactionPaymentContext['payment']['charge_id']
        ) {
            // The charge was already created in the redirect, discard here
            return;
        }

        $salesChannelId = $context->getSource()->getSalesChannelId();
        $stripeApi = $this->stripeApiFactory->getStripeApiForSalesChannel($salesChannelId);

        // Refresh the source and verify it is still chargeable
        $source = $stripeApi->getSource($source->id);
        if ($source->status !== 'chargeable') {
            return;
        }

        $order = $orderTransaction->getOrder();
        $customer = $order->getOrderCustomer()->getCustomer();
        $stripeCustomer = Util::getOrCreateStripeCustomer(
            $this->customerRepository,
            $customer,
            $stripeApi,
            $context
        );

        $chargeConfig = [
            'source' => $source->id,
            'amount' => self::getPayableAmount($orderTransaction),
            'currency' => mb_strtolower($this->getCurrency($order, $context)->getIsoCode()),
            'metadata' => [
                'shopware_order_transaction_id' => $orderTransaction->getId(),
            ],
            'description' => sprintf(
                '%s / Customer %s / Order %s',
                $customer->getEmail(),
                $customer->getCustomerNumber(),
                $order->getOrderNumber()
            ),
            'customer' => $stripeCustomer->id,
        ];

        $statementDescriptor = mb_substr(
            $this->settingsService->getConfigValue('statementDescriptorSuffix', $salesChannelId) ?: '',
            0,
            22
        );
        if ($statementDescriptor) {
            $chargeConfig['statement_descriptor'] = $statementDescriptor;
        }
        if ($this->settingsService->getConfigValue('sendStripeChargeEmails', $salesChannelId)) {
            $chargeConfig['receipt_email'] = $customer->getEmail();
        }

        $charge = $stripeApi->createCharge($chargeConfig);

        if ($charge->status !== 'succeeded') {
            if ($orderTransaction->getStateMachineState()->getTechnicalName() === 'cancelled') {
                return;
            }
            $this->orderTransactionStateHandler->cancel($orderTransaction->getId(), $context);

            return;
        }

        $this->paymentContext->saveStripeCharge($orderTransaction, $context, $charge);

        // Already paid, nothing to do
        if ($orderTransaction->getStateMachineState()->getTechnicalName() === 'paid') {
            return;
        }
        $this->orderTransactionStateHandler->pay($orderTransaction->getId(), $context);
    }

    public function handleSourceUnsuccessful(Source $source, Context $context): void
    {
        try {
            $orderTransaction = $this->getOrderTransactionForSource($source, $context);
        } catch (\Exception $e) {
            // Nothing to do, no order exists
            return;
        }

        // Already cancelled, nothing to do
        if ($orderTransaction->getStateMachineState()->getTechnicalName() === 'cancelled') {
            return;
        }
        $this->orderTransactionStateHandler->cancel($orderTransaction->getId(), $context);
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

    private function getOrderTransactionForSource(Source $source, Context $context): OrderTransactionEntity
    {
        $criteria = new Criteria();
        $criteria->addAssociations([
            'order',
            'order.orderCustomer.customer',
        ]);
        $criteria->addFilter(
            new EqualsFilter(
                'customFields.stripe_payment_context.payment.source_id',
                $source->id
            )
        );
        $result = $this->orderTransactionRepo->search($criteria, $context);

        if ($result->getTotal() === 0) {
            throw new \Exception('No order transaction found for charge');
        }

        return $result->getEntities()->first();
    }

    private function getOrderTransactionForCharge(Charge $charge, Context $context): OrderTransactionEntity
    {
        $criteria = new Criteria();
        $criteria->addAssociation('order');
        $criteria->addFilter(
            new EqualsFilter(
                'customFields.stripe_payment_context.payment.charge_id',
                $charge->id
            )
        );
        $result = $this->orderTransactionRepo->search($criteria, $context);

        if ($result->getTotal() === 0) {
            throw new \Exception('No order transaction found for charge');
        }

        return $result->getEntities()->first();
    }

    private function getOrderTransactionForPaymentIntent(PaymentIntent $paymentIntent, Context $context): OrderTransactionEntity
    {
        $criteria = new Criteria();
        $criteria->addAssociation('order');
        $criteria->addFilter(
            new EqualsFilter(
                'customFields.stripe_payment_context.payment.payment_intent_id',
                $paymentIntent->id
            )
        );
        $result = $this->orderTransactionRepo->search($criteria, $context);

        if ($result->getTotal() === 0) {
            throw new \Exception('No order transaction found for payment intent');
        }

        return $result->getEntities()->first();
    }

    private static function getPayableAmount(OrderTransactionEntity $orderTransaction): int
    {
        return intval(round(100 * $orderTransaction->getAmount()->getTotalPrice()));
    }
}

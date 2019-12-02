<?php

namespace Stripe\ShopwarePlugin\Payment\Handler;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Exception\InvalidOrderException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\Currency\CurrencyEntity;
use Stripe\Charge;
use Stripe\PaymentIntent;
use Stripe\ShopwarePlugin\Payment\StripeApi\StripeApiFactory;
use Stripe\ShopwarePlugin\Payment\Util\PaymentContext;
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

    public function __construct(
        DefinitionInstanceRegistry $definitionRegistry,
        OrderTransactionStateHandler $orderTransactionStateHandler,
        OrderTransactionDefinition $orderTransactionDefinition,
        PaymentContext $paymentContext
    ) {
        $this->orderTransactionRepo = $definitionRegistry->getRepository($orderTransactionDefinition->getEntityName());
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
        $this->paymentContext = $paymentContext;
    }

    public function handlePaymentIntentSuccessful(PaymentIntent $paymentIntent, Context $context): void
    {
        $orderTransaction = $this->getOrderTransactionForPaymentIntent($paymentIntent, $context);

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
        $orderTransaction = $this->getOrderTransactionForPaymentIntent($paymentIntent, $context);

        // Already canceled, nothing to do
        if ($orderTransaction->getStateMachineState()->getTechnicalName() === 'canceled') { // TODO: correct name?
            return;
        }
        $this->orderTransactionStateHandler->cancel($orderTransaction->getId(), $context);
    }

    public function handleChargeSuccessful(Charge $charge, Context $context): void
    {
        $orderTransaction = $this->getOrderTransactionForCharge($charge, $context);

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
        $orderTransaction = $this->getOrderTransactionForCharge($charge, $context);

        // Already canceled, nothing to do
        if ($orderTransaction->getStateMachineState()->getTechnicalName() === 'canceled') {
            return;
        }
        $this->orderTransactionStateHandler->cancel($orderTransaction->getId(), $context);
    }

    public function handleSourceChargable(Source $source, Context $context): void
    {
        $orderTransaction = $this->getOrderTransactionForSource($source, $context);

        // TODO
        // $order = orderRepo->search();
        // $customer = $order->getOrderCustomer()->getCustomer();
        // $stripeCustomerId = 123;
        // $statementDescriptor = 123;
        // $receiptEmails;
        $chargeData = [
            'source' => $source->id,
            'amount' => self::getPayableAmount($orderTransaction),
            'currency' => mb_strtolower($this->getCurrency($order, $context)->getIsoCode()),
            'description' => sprintf('%s / Customer %s', $customer->getEmail(), $customer->getCustomerNumber()),
        ];

        // TODO: create charge
    }

    public function handleSourceUnsuccessful(Source $source, Context $context): void
    {
        $orderTransaction = $this->getOrderTransactionForSource($source, $context);

        // Already canceled, nothing to do
        if ($orderTransaction->getStateMachineState()->getTechnicalName() === 'canceled') {
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
        $criteria->addAssociation('order');
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

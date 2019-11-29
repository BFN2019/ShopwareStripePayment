<?php declare(strict_types=1);

namespace Stripe\ShopwarePlugin\Payment\Util;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Stripe\Charge;
use Stripe\PaymentIntent;
use Stripe\ShopwarePlugin\Payment\StripeApi\StripeApi;
use Stripe\ShopwarePlugin\Payment\StripeApi\StripeApiFactory;
use Stripe\Source;

class PaymentContext
{
    private const PAYMENT_CONTEXT_KEY = 'stripe_payment_context';

    /**
     * @var StripeApiFactory
     */
    private $stripeApiFactory;

    /**
     * @var EntityRepositoryInterface
     */
    private $orderTransactionRepository;

    public function __construct(StripeApiFactory $stripeApiFactory, EntityRepositoryInterface $orderTransactionRepository)
    {
        $this->stripeApiFactory = $stripeApiFactory;
        $this->orderTransactionRepository = $orderTransactionRepository;
    }

    public function getStripeSource(OrderTransactionEntity $orderTransaction, SalesChannelContext $salesChannelContext): ?Source
    {
        $paymentContext = $this->getPaymentContextFromTransaction($orderTransaction);
        $sourceId = isset($paymentContext['payment']['source_id']) ? $paymentContext['payment']['source_id'] : null;
        if (!$sourceId) {
            return null;
        }

        $salesChannelId = $salesChannelContext->getSalesChannel()->getId();
        $stripeApi = $this->stripeApiFactory->getStripeApiForSalesChannel($salesChannelId);

        return $stripeApi->getSource((string) $sourceId);
    }

    public function getStripePaymentIntent(OrderTransactionEntity $orderTransaction, SalesChannelContext $salesChannelContext): ?PaymentIntent
    {
        $paymentContext = $this->getPaymentContextFromTransaction($orderTransaction);
        $paymentIntentId = isset($paymentContext['payment']['payment_intent_id']) ? $paymentContext['payment']['payment_intent_id'] : null;
        if (!$paymentIntentId) {
            return null;
        }

        $salesChannelId = $salesChannelContext->getSalesChannel()->getId();
        $stripeApi = $this->stripeApiFactory->getStripeApiForSalesChannel($salesChannelId);

        return $stripeApi->getPaymentIntent((string) $paymentIntentId);
    }

    public function saveStripeSource(
        OrderTransactionEntity $orderTransaction,
        Context $context,
        Source $source
    ): void {
        $paymentContext = $this->getPaymentContextFromTransaction($orderTransaction);
        $paymentContext['payment'] = $paymentContext['payment'] ?? [];
        $paymentContext['payment']['source_id'] = $source->id;
        $this->savePaymentContextInTransaction($orderTransaction, $context, $paymentContext);
    }

    public function saveStripeCharge(
        OrderTransactionEntity $orderTransaction,
        Context $context,
        Charge $charge
    ): void {
        $paymentContext = $this->getPaymentContextFromTransaction($orderTransaction);
        $paymentContext['payment'] = $paymentContext['payment'] ?? [];
        $paymentContext['payment']['charge_id'] = $charge->id;
        $this->savePaymentContextInTransaction($orderTransaction, $context, $paymentContext);
    }

    public function saveStripePaymentIntent(
        OrderTransactionEntity $orderTransaction,
        Context $context,
        PaymentIntent $paymentIntent
    ): void {
        $paymentContext = $this->getPaymentContextFromTransaction($orderTransaction);
        $paymentContext['payment'] = $paymentContext['payment'] ?? [];
        $paymentContext['payment']['payment_intent_id'] = $paymentIntent->id;
        $this->savePaymentContextInTransaction($orderTransaction, $context, $paymentContext);
    }

    private function getPaymentContextFromTransaction(OrderTransactionEntity $orderTransaction): array
    {
        return $orderTransaction->getCustomFields()[self::PAYMENT_CONTEXT_KEY] ?? [];
    }

    private function savePaymentContextInTransaction(
        OrderTransactionEntity $orderTransaction,
        Context $context,
        array $stripePaymentContext
    ): void {
        $orderTransactionValues = [
            'id' => $orderTransaction->getId(),
            'customFields' => [
                self::PAYMENT_CONTEXT_KEY => $stripePaymentContext,
            ],
        ];
        $this->orderTransactionRepository->update([$orderTransactionValues], $context);
    }
}

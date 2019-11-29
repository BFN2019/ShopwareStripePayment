<?php declare(strict_types=1);

namespace Stripe\ShopwarePlugin\Payment\Handler;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Payment\Exception\PaymentProcessException;
use Stripe\PaymentIntent;
use Stripe\Source;

class PaymentIntentNotChargeableException extends PaymentProcessException
{
    public function __construct(PaymentIntent $paymentIntent, OrderTransactionEntity $orderTransaction)
    {
        parent::__construct(
            $orderTransaction->getId(),
            'The Stripe payment intent {{ paymentIntentId }} is not chargeable.',
            ['paymentIntentId' => $paymentIntent->id]
        );
    }

    public function getErrorCode(): string
    {
        return 'STRIPE_PAYMENT__PAYMENT_INTENT_NOT_CHARGEABLE';
    }
}

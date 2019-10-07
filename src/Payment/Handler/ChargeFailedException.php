<?php declare(strict_types=1);

namespace Stripe\ShopwarePlugin\Payment\Handler;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Payment\Exception\PaymentProcessException;
use Stripe\Charge;

class ChargeFailedException extends PaymentProcessException
{
    public function __construct(Charge $charge, OrderTransactionEntity $orderTransaction)
    {
        parent::__construct(
            $orderTransaction->getId(),
            'The Stripe charge {{ chargeId }} did not succeed.',
            ['chargeId' => $charge->id]
        );
    }

    public function getErrorCode(): string
    {
        return 'STRIPE_PAYMENT__CHARGE_FAILED';
    }
}

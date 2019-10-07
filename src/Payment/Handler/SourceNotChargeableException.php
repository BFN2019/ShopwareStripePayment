<?php declare(strict_types=1);

namespace Stripe\ShopwarePlugin\Payment\Handler;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Payment\Exception\PaymentProcessException;
use Stripe\Source;

class SourceNotChargeableException extends PaymentProcessException
{
    public function __construct(Source $source, OrderTransactionEntity $orderTransaction)
    {
        parent::__construct(
            $orderTransaction->getId(),
            'The Stripe source {{ sourceId }} is not chargeable.',
            ['sourceId' => $source->id]
        );
    }

    public function getErrorCode(): string
    {
        return 'STRIPE_PAYMENT__SOURCE_NOT_CHARGEABLE';
    }
}

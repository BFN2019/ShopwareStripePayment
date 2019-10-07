<?php declare(strict_types=1);

namespace Stripe\ShopwarePlugin\Payment\Handler;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Payment\Exception\PaymentProcessException;
use Stripe\Source;

class InvalidSourceRedirectException extends PaymentProcessException
{
    public function __construct(Source $source, OrderTransactionEntity $orderTransaction)
    {
        parent::__construct(
            $orderTransaction->getId(),
            'The redirect for Stripe source {{ sourceId }} is invalid (redirect status: "{{ redirectStatus }}").',
            [
                'sourceId' => $source->id,
                'redirectStatus' => $source->redirect->status,
            ]
        );
    }

    public function getErrorCode(): string
    {
        return 'STRIPE_PAYMENT__INVALID_SOURCE_REDIRECT';
    }
}

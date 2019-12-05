<?php declare(strict_types=1);

namespace Stripe\ShopwarePlugin\Payment\Handler;

use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class SofortPaymentHandler extends AbstractSourcePaymentHandler
{
    protected function createSourceConfig(
        AsyncPaymentTransactionStruct $transaction,
        SalesChannelContext $salesChannelContext
    ): array {
        $order = $transaction->getOrder();
        $orderTransaction = $transaction->getOrderTransaction();
        $context = $salesChannelContext->getContext();

        $sourceConfig = [
            'type' => 'sofort',
            'amount' => self::getPayableAmount($orderTransaction),
            'currency' => $this->getCurrency($order, $context)->getIsoCode(),
            'owner' => [
                'name' => self::getCustomerName($salesChannelContext->getCustomer()),
            ],
            'sofort' => [
                'country' => $this->getBillingAddress($order, $context)->getCountry()->getIso(),
            ],
            'redirect' => [
                'return_url' => $transaction->getReturnUrl(),
            ],
            'metadata' => [
                'shopware_order_transaction_id' => $orderTransaction->getId(),
            ],
        ];

        $statementDescriptor = mb_substr(
            $this->paymentContext->getStatementDescriptor($salesChannelContext->getSalesChannel(), $order),
            0,
            22
        );
        if ($statementDescriptor) {
            $sourceConfig['statement_descriptor'] = $statementDescriptor;
        }

        return $sourceConfig;
    }
}

<?php declare(strict_types=1);

namespace Stripe\ShopwarePlugin\Payment\Handler;

use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class BancontactPaymentHandler extends AbstractSourcePaymentHandler
{
    protected function createSourceConfig(
        AsyncPaymentTransactionStruct $transaction,
        SalesChannelContext $salesChannelContext
    ): array {
        $order = $transaction->getOrder();
        $orderTransaction = $transaction->getOrderTransaction();
        $context = $salesChannelContext->getContext();

        $sourceConfig = [
            'type' => 'bancontact',
            'amount' => self::getPayableAmount($orderTransaction),
            'currency' => $this->getCurrency($order, $context)->getIsoCode(),
            'owner' => [
                'name' => self::getCustomerName($salesChannelContext->getCustomer()),
            ],
            'redirect' => [
                'return_url' => $transaction->getReturnUrl(),
            ],
            'metadata' => [
                'shopware_order_transaction_id' => $orderTransaction->getId(),
            ],
        ];

        $salesChannelId = $salesChannelContext->getSalesChannel()->getId();
        $statementDescriptor = mb_substr(
            $this->settingsService->getConfigValue('statementDescriptorSuffix', $salesChannelId) ?: '',
            0,
            22
        );
        if ($statementDescriptor) {
            $sourceConfig['statement_descriptor'] = $statementDescriptor;
        }

        return $sourceConfig;
    }
}

<?php declare(strict_types=1);

namespace Stripe\ShopwarePlugin\Payment\Handler;

use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class KlarnaPaymentHandler extends AbstractSourcePaymentHandler
{
    protected function createSourceConfig(
        AsyncPaymentTransactionStruct $transaction,
        SalesChannelContext $salesChannelContext
    ): array {
        $order = $transaction->getOrder();
        $orderTransaction = $transaction->getOrderTransaction();
        $context = $salesChannelContext->getContext();
        $customer = $salesChannelContext->getCustomer();

        $billingAddress = $this->getBillingAddress($order, $context);
        $shippingAddress = $this->getShippingAddress($order, $context);
        $locale = $this->getLocale($customer, $context);
        $currencyIsoCode = $this->getCurrency($order, $context)->getIsoCode();

        $shipping = [
            'type' => 'shipping',
            'description' => 'Shipping',
            'currency' => $currencyIsoCode,
            'amount' => round($order->getShippingTotal() * 100),
        ];

        $items = array_map(function (OrderLineItemEntity $lineItem) use ($currencyIsoCode) {
            return [
                'type' => 'sku',
                'description' => $lineItem->getLabel(),
                'quantity' => $lineItem->getQuantity(),
                'currency' => $currencyIsoCode,
                'amount' => round($lineItem->getTotalPrice() * 100),
            ];
        }, array_values($order->getLineItems()->getElements()));

        $items[] = $shipping;

        $sourceConfig = [
            'type' => 'klarna',
            'amount' => self::getPayableAmount($orderTransaction),
            'currency' => $currencyIsoCode,
            'flow' => 'redirect',
            'owner' => [
                'name' => self::getCustomerName($salesChannelContext->getCustomer()),
                'email' => $customer->getEmail(),
                'address' => [
                    'line1' => $billingAddress->getStreet(),
                    'city' => $billingAddress->getCity(),
                    'postal_code' => $billingAddress->getZipcode(),
                    'country' => $billingAddress->getCountry()->getIso(),
                ],
            ],
            'klarna' => [
                'first_name' => $customer->getFirstName(),
                'last_name' => $customer->getLastName(),
                'product' => 'payment',
                'purchase_country' => $billingAddress->getCountry()->getIso(),
                'shipping_first_name' => $shippingAddress->getFirstName(),
                'shipping_last_name' => $shippingAddress->getLastName(),
                'locale' => $locale->getCode(),
            ],
            'source_order' => [
                'items' => array_values($items),
                'shipping' => [
                    'address' => [
                        'line1' => $shippingAddress->getStreet(),
                        'city' => $shippingAddress->getCity(),
                        'postal_code' => $shippingAddress->getZipcode(),
                        'country' => $shippingAddress->getCountry()->getIso(),
                    ],
                ],
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

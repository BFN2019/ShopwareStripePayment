<?php declare(strict_types=1);

namespace Stripe\ShopwarePlugin\Payment\Handler;

use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Stripe\ShopwarePlugin\Payment\Util\Util;

class DigitalWalletsPaymentHandler extends AbstractPaymentIntentPaymentHandler
{
    protected function createPaymentIntentConfig(
        AsyncPaymentTransactionStruct $transaction,
        SalesChannelContext $salesChannelContext,
        RequestDataBag $dataBag
    ): array {
        $order = $transaction->getOrder();
        $orderTransaction = $transaction->getOrderTransaction();
        $context = $salesChannelContext->getContext();
        $salesChannelId = $salesChannelContext->getSalesChannel()->getId();
        $stripeApi = $this->stripeApiFactory->getStripeApiForSalesChannel($salesChannelId);
        $customer = $salesChannelContext->getCustomer();

        $stripeCustomer = Util::getOrCreateStripeCustomer(
            $this->customerRepository,
            $customer,
            $stripeApi,
            $context
        );

        $paymentIntentConfig = [
            'amount' => self::getPayableAmount($orderTransaction),
            'currency' => $this->getCurrency($order, $context)->getIsoCode(),
            'payment_method' => $dataBag->get('stripeDigitalWalletPaymentMethodId'),
            'confirmation_method' => 'automatic',
            'confirm' => true,
            'return_url' => $transaction->getReturnUrl(),
            'metadata' => [
                'shopware_order_transaction_id' => $orderTransaction->getId(),
            ],
            'customer' => $stripeCustomer->id,
            'description' => sprintf('%s / Customer %s', $customer->getEmail(), $customer->getCustomerNumber()),
        ];

        $statementDescriptor = mb_substr(
            $this->paymentContext->getStatementDescriptor($salesChannelContext->getSalesChannel(), $order),
            0,
            22
        );
        if ($statementDescriptor) {
            $paymentIntentConfig['statement_descriptor'] = $statementDescriptor;
        }

        if ($this->settingsService->getConfigValue('sendStripeChargeEmails', $salesChannelId)) {
            $paymentIntentConfig['receipt_email'] = $customer->getEmail();
        }

        return $paymentIntentConfig;
    }

    protected function validateRequestDataBag(RequestDataBag $dataBag): void
    {
        if (!$dataBag->has('stripeDigitalWalletPaymentMethodId')) {
            throw new \Exception('no digital wallet payment method supplied');
        }
    }
}

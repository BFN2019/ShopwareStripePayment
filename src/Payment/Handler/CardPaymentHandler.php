<?php declare(strict_types=1);

namespace Stripe\ShopwarePlugin\Payment\Handler;

use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Stripe\ShopwarePlugin\Payment\Services\SessionConfig;
use Stripe\ShopwarePlugin\Payment\Util\Util;

class CardPaymentHandler extends AbstractPaymentIntentPaymentHandler
{
    protected function createPaymentIntentConfig(AsyncPaymentTransactionStruct $transaction, SalesChannelContext $salesChannelContext): array
    {
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

        $stripeSessionConfig = $this->sessionService->getStripeSession();
        $paymentIntentConfig = [
            'amount' => self::getPayableAmount($orderTransaction),
            'currency' => $this->getCurrency($order, $context)->getIsoCode(),
            'payment_method' => $stripeSessionConfig->selectedCard['id'],
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

        if ($stripeSessionConfig->saveCardForFutureCheckouts) {
            // Add the card to the Stripe customer
            $paymentIntentConfig['save_payment_method'] = $stripeSessionConfig->saveCardForFutureCheckouts;
        }

        return $paymentIntentConfig;
    }

    protected function validateSessionConfig(SessionConfig $sessionConfig): void
    {
        if (!$sessionConfig->selectedCard || !isset($sessionConfig->selectedCard['id'])) {
            throw new \Exception('no card selected');
        }
    }
}

<?php
// Copyright (c) Pickware GmbH. All rights reserved.
// This file is part of software that is released under a proprietary license.
// You must not copy, modify, distribute, make publicly available, or execute
// its contents or parts thereof without express permission by the copyright
// holder, unless otherwise permitted by law.

namespace Shopware\Plugins\StripePayment\Components\PaymentMethods;

use Shopware\Plugins\StripePayment\Util;
use Stripe;

class Card extends AbstractStripePaymentIntentPaymentMethod
{
    /**
     * @inheritdoc
     */
    public function createStripePaymentIntent($amountInCents, $currencyCode)
    {
        Util::initStripeAPI();
        $pluginConfig = $this->get('plugins')->get('Frontend')->get('StripePayment')->Config();
        $stripeConnectedAccountId = $pluginConfig->get('stripeConnectedAccountId');

        // Determine the card
        $stripeSession = Util::getStripeSession();
        if (!$stripeSession->selectedCard || !isset($stripeSession->selectedCard['id'])) {
            throw new \Exception($this->getSnippet('payment_error/message/no_card_selected'));
        }

        $stripeCustomer = Util::getStripeCustomer();
        if (!$stripeCustomer) {
            $stripeCustomer = Util::createStripeCustomer($stripeConnectedAccountId);
        }
        $user = Shopware()->Session()->sOrderVariables['sUserData'];
        $userEmail = $user['additional']['user']['email'];
        $customerNumber = $user['additional']['user']['customernumber'];

        // hacked in to get the application fee
        $applicationFee = $this->getApplicationFeeAmount($amountInCents);

        // Use the token to create a new Stripe card payment intent
        $returnUrl = $this->assembleShopwareUrl([
            'controller' => 'StripePaymentIntent',
            'action' => 'completeRedirectFlow',
        ]);
        $paymentIntentConfig = [
            'amount' => $amountInCents,
            'currency' => $currencyCode,
            'application_fee_amount' => $applicationFee, // hacked in application fee for Connect
            'payment_method' => $stripeSession->selectedCard['id'],
            'confirmation_method' => 'automatic',
            'confirm' => true,
            'return_url' => $returnUrl,
            'metadata' => $this->getSourceMetadata(),
            'customer' => $stripeCustomer->id,
            'description' => sprintf('%s / Customer %s', $userEmail, $customerNumber),
        ];
        if ($this->includeStatmentDescriptorInCharge()) {
            $paymentIntentConfig['statement_descriptor'] = mb_substr($this->getStatementDescriptor(), 0, 22);
        }

        // Enable MOTO transaction, if configured and order is placed by shop admin (aka user has logged in via backend)
        $isAdminRequest = isset($this->get('session')->Admin) && $this->get('session')->Admin === true;
        if ($isAdminRequest && $pluginConfig->get('allowMotoTransactions')) {
            $paymentIntentConfig['payment_method_options'] = [
                'card' => [
                    'moto' => true,
                ],
            ];
        }

        // Enable receipt emails, if configured
        if ($pluginConfig->get('sendStripeChargeEmails')) {
            $paymentIntentConfig['receipt_email'] = $userEmail;
        }

        if ($stripeSession->saveCardForFutureCheckouts) {
            // Add the card to the Stripe customer
            $paymentIntentConfig['save_payment_method'] = $stripeSession->saveCardForFutureCheckouts;
            unset($stripeSession->saveCardForFutureCheckouts);
        }

        $paymentIntent = Stripe\PaymentIntent::create($paymentIntentConfig, ['stripe_account' => $stripeConnectedAccountId]);
        if (!$paymentIntent) {
            throw new \Exception($this->getSnippet('payment_error/message/transaction_not_found'));
        }

        return $paymentIntent;
    }

    /**
     * @inheritdoc
     */
    public function includeStatmentDescriptorInCharge()
    {
        // Card payment methods can be reused several times and hence should contain a statement descriptor in charge
        return true;
    }

    /**
     * @inheritdoc
     */
    public function getSnippet($name)
    {
        return ($this->get('snippets')->getNamespace('frontend/plugins/payment/stripe_payment/card')->get($name)) ?: parent::getSnippet($name);
    }

    /**
     * @inheritdoc
     */
    public function validate($paymentData)
    {
        // Check the payment data for a selected card
        if (empty($paymentData['selectedCard'])) {
            return [
                'STRIPE_CARD_VALIDATION_FAILED'
            ];
        }

        return [];
    }

    /**
     * Function to iterate over the products in the basket and find their
     * "purchaseprice" which is the amount that should go to the consultant.
     *
     * We then subtract that from the price of the product to get the
     * "fee" that Befeni keeps.
     *
     * Note: this works for now, but does NOT take into account discounts or
     * taxes and other complicated business rules so we should revisit this
     * in future
     *
     * @param  int $chargedAmount
     * @return float
     */
    protected function getApplicationFeeAmount($chargedAmount) {

        $applicationFee = 0;

        // get shipping costs and add them to our application fee 
        $applicationFee += (Shopware()->Session()->sOrderVariables['sShippingcosts'] * 100);

        // get the `article IDs` for the products in the basket
        $basket = Shopware()->Session()->sOrderVariables['sBasket'];
        $basketProducts = [];

        if(count($basket['content'])) {
            foreach($basket['content'] as $product) {
                $basketProducts[$product['articleID']] = [
                    'articleID'   => $product['articleID'],
                    'quantity'    => (int) $product['quantity'],
                    'ordernumber' => $product['ordernumber']
                ];
            }
        }
        // get the total `take` for the consultant for each product
        $sql = 'SELECT articleID, purchaseprice FROM s_articles_details WHERE';
        $count = 1;
        $parameters = [];
        foreach($basketProducts as $product) {
            if($count === 1) {
                $sql .= ' articleID = :articleID' . $count.' AND ordernumber = :ordernumber' . $count;
            } else {
                $sql .= ' OR (articleID = :articleID' . $count.' AND ordernumber = :ordernumber' . $count . ')';
            }
            $parameters['articleID' . $count] = $product['articleID'];
            $parameters['ordernumber' . $count] = $product['ordernumber'];
            $count++;
        }

        $purchasePrices = Shopware()->Db()->fetchAll($sql, $parameters);

        if(count($purchasePrices)) {
            foreach($purchasePrices as $prices) {
                $qty = $basketProducts[$prices['articleID']]['quantity'];
                $amountCent = ($prices['purchaseprice'] * 100) * $qty;
                $applicationFee += $amountCent;
            }
        }

        // return the application fee
        if($applicationFee <= $chargedAmount) {
            return $applicationFee;
        }

        throw new \Exception('The application fee should always be <= than charged amount');
    }
}

<?php

namespace Stripe\ShopwarePlugin\Payment\Util;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Stripe\Customer;
use Stripe\ShopwarePlugin\Payment\StripeApi\StripeApi;

class Util
{
    // TODO: move somewhere appropriate
    public static function getOrCreateStripeCustomer(
        EntityRepositoryInterface $customerRepository,
        CustomerEntity $customer,
        StripeApi $stripeApi,
        Context $context
    ): Customer {
        // TODO: do we always want to attach the stripe customer?
        if (!$customer->getCustomFields()) {
            $customer->setCustomFields([]);
        }
        $stripeCustomer = null;
        if (isset($customer->getCustomFields()['stripeCustomerId'])) {
            try {
                $stripeCustomer = $stripeApi->getCustomer(
                    $customer->getCustomFields()['stripeCustomerId']
                );
                if ($stripeCustomer && isset($stripeCustomer->deleted)) {
                    throw new \Exception('Customer deleted');
                }
            } catch (\Exception $e) {
                // TODO: move removal into another service
                $customerValues = [
                    'id' => $customer->getId(),
                    'customFields' => [
                        'stripeCustomerId' => null,
                    ],
                ];
                $customerRepository->update([$customerValues], $context);
                $stripeCustomer = null;
            }
        }
        if (!$stripeCustomer) {
            $customerName = $customer->getFirstName() . ' ' . $customer->getLastName();
            $stripeCustomer = $stripeApi->createCustomer(
                [
                    'name' => $customerName,
                    'description' => $customerName,
                    'email' => $customer->getEmail(),
                ]
            );

            // Attach the stripe customer to the shopware customer
            $customerValues = [
                'id' => $customer->getId(),
                'customFields' => [
                    'stripeCustomerId' => $stripeCustomer->id,
                ],
            ];
            $customerRepository->update([$customerValues], $context);
        }

        return $stripeCustomer;
    }

    // TODO: move somewhere appropriate
    public static function getStripeCustomer(
        EntityRepositoryInterface $customerRepository,
        CustomerEntity $customer,
        StripeApi $stripeApi,
        Context $context
    ): Customer {
        if (!$customer->getCustomFields()) {
            return null;
        }
        $stripeCustomer = null;
        if (!isset($customer->getCustomFields()['stripeCustomerId'])) {
            return null;
        }
        try {
            $stripeCustomer = $stripeApi->getCustomer(
                $customer->getCustomFields()['stripeCustomerId']
            );
            if ($stripeCustomer && isset($stripeCustomer->deleted)) {
                throw new \Exception('Customer deleted');
            }
        } catch (\Exception $e) {
            // TODO: move removal into another service
            $customerValues = [
                'id' => $customer->getId(),
                'customFields' => [
                    'stripeCustomerId' => null,
                ],
            ];
            $customerRepository->update([$customerValues], $context);

            return null;
        }

        return $stripeCustomer;
    }
}

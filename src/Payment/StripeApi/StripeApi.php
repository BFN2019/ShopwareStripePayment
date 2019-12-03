<?php declare(strict_types=1);

namespace Stripe\ShopwarePlugin\Payment\StripeApi;

use Stripe\Charge;
use Stripe\Customer;
use Stripe\Event;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\Source;
use Stripe\Stripe;
use Stripe\Webhook;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class StripeApi
{
    // TODO: Update for shopware 6?
    public const STRIPE_PLATFORM_NAME = 'UMXJ4nBknsWR3LN_shopware_v50';

    /**
     * @var StripeApiConfig
     */
    private $config;

    public function __construct(StripeApiConfig $config)
    {
        $this->config = $config;
        Stripe::setApiVersion('2019-11-05');
    }

    public function getSource(string $id): ?Source
    {
        $this->initializeStripeApi();

        return Source::retrieve($id);
    }

    public function createSource(array $params): Source
    {
        $this->initializeStripeApi();

        return Source::create(self::patchMetadata($params));
    }

    public function createCharge(array $params): Charge
    {
        $this->initializeStripeApi();

        return Charge::create(self::patchMetadata($params));
    }

    public function createPaymentIntent(array $params): PaymentIntent
    {
        $this->initializeStripeApi();

        return PaymentIntent::create(self::patchMetadata($params));
    }

    public function getPaymentIntent(string $paymentIntentId): PaymentIntent
    {
        $this->initializeStripeApi();

        return PaymentIntent::retrieve($paymentIntentId);
    }

    public function getPaymentMethod(string $paymentMethodId): PaymentMethod
    {
        $this->initializeStripeApi();

        return PaymentMethod::retrieve($paymentMethodId);
    }

    public function getCustomer(string $stripeCustomerId): Customer
    {
        $this->initializeStripeApi();

        return Customer::retrieve($stripeCustomerId);
    }

    public function getSavedCardsForCustomer(string $stripeCustomerId): array
    {
        $this->initializeStripeApi();

        $cardPaymentMethods = PaymentMethod::all([
            'customer' => $stripeCustomerId,
            'type' => 'card',
        ])->data;

        $cards = array_map(
            function ($paymentMethod) {
                return [
                    'id' => $paymentMethod->id,
                    'name' => $paymentMethod->billing_details->name,
                    'brand' => $paymentMethod->card->brand,
                    'last4' => $paymentMethod->card->last4,
                    'exp_month' => $paymentMethod->card->exp_month,
                    'exp_year' => $paymentMethod->card->exp_year,
                ];
            },
            $cardPaymentMethods
        );

        // Sort the cards by id (which correspond to the date, the card was created/added)
        usort($cards, function ($cardA, $cardB) {
            return strcmp($cardA['id'], $cardB['id']);
        });

        return $cards;
    }

    public function getSavedSepaBankAccountsForCustomer(string $stripeCustomerId): array
    {
        $this->initializeStripeApi();

        $sepaPaymentMethods = PaymentMethod::all([
            'customer' => $stripeCustomerId,
            'type' => 'sepa_debit',
        ])->data;

        $sepaPaymentMethods = array_map(
            function ($paymentMethod) {
                return [
                    'id' => $paymentMethod->id,
                    'name' => $paymentMethod->billing_details->name,
                    'country' => $paymentMethod->sepa_debit->country,
                    'last4' => $paymentMethod->sepa_debit->last4,
                ];
            },
            $sepaPaymentMethods
        );

        // Sort the sepa payment methods by id (which correspond to the date, the payment method was created/added)
        usort($sepaPaymentMethods, function ($sepaPaymentMethodA, $sepaPaymentMethodB) {
            return strcmp($sepaPaymentMethodA['id'], $sepaPaymentMethodB['id']);
        });

        return $sepaPaymentMethods;
    }

    public function createCustomer(array $params): Customer
    {
        $this->initializeStripeApi();

        return Customer::create(self::patchMetadata($params));
    }

    public function getWebhookEvent(string $webhookPayload, string $webhookSignature): Event
    {
        $this->initializeStripeApi();

        return Webhook::constructEvent($webhookPayload, $webhookSignature, $this->config->webhookSecret, 99999999);
    }

    /**
     * @param Request $request
     * @return string
     */
    public static function getWebhookPayloadFromSymfonyRequest(Request $request): string
    {
        return $request->getContent();
    }

    public static function getWebhookSignatureFromSymfonyRequest(Request $request)
    {
        return $request->headers->get('stripe-signature');
    }

    private function initializeStripeApi(): void
    {
        Stripe::setApiKey($this->config->secretKey);
        Stripe::setAppInfo(
            self::STRIPE_PLATFORM_NAME,
            '1.0.0', // TODO
            'some-domain.tld' // TODO
        );
    }

    private static function patchMetadata(array $params): array
    {
        $params['metadata'] = array_replace_recursive(
            $params['metadata'] ?? [],
            ['platform_name' => self::STRIPE_PLATFORM_NAME]
        );

        return $params;
    }
}

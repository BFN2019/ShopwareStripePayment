<?php

namespace Stripe\ShopwarePlugin\Payment\StripeApi;

class StripeApiConfig
{
    public $secretKey;

    public $webhookSecret;

    private function __construct()
    {
    }

    public static function fromShopwareConfig(array $config)
    {
        $self = new self();
        $self->secretKey = $config['stripeSecretKey'];
        $self->webhookSecret = $config['stripeWebhookSecret'];

        return $self;
    }
}

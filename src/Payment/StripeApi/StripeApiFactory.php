<?php

namespace Stripe\ShopwarePlugin\Payment\StripeApi;

use Stripe\ShopwarePlugin\Payment\Settings\SettingsService;

class StripeApiFactory
{
    /**
     * @var SettingsService
     */
    private $settingsService;

    public function __construct(
        SettingsService $settingsService
    ) {
        $this->settingsService = $settingsService;
    }

    private $stripeApiPerSalesChannelId = [];

    /**
     * @param string|null $salesChannelId
     * @return StripeApi
     */
    public function getStripeApiForSalesChannel(string $salesChannelId = null): StripeApi
    {
        if (isset($this->stripeApiPerSalesChannelId[$salesChannelId])) {
            return $this->stripeApiPerSalesChannelId[$salesChannelId];
        }

        $stripeConfig = $this->settingsService->getConfig($salesChannelId);
        $stripeApiConfig = StripeApiConfig::fromShopwareConfig($stripeConfig);

        $this->stripeApiPerSalesChannelId[$salesChannelId] = new StripeApi($stripeApiConfig);

        return $this->stripeApiPerSalesChannelId[$salesChannelId];
    }
}

<?php declare(strict_types=1);

namespace Stripe\ShopwarePlugin\Payment\Settings;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class SettingsService
{
    private const CONFIG_KEY = 'StripePayment.config';

    /**
     * @var SystemConfigService
     */
    private $systemConfigService;

    public function __construct(SystemConfigService $systemConfigService)
    {
        $this->systemConfigService = $systemConfigService;
    }

    /**
     * @param string $key
     * @param string|null $salesChannelId
     * @return array|mixed|null
     */
    public function getConfigValue(string $key, string $salesChannelId = null)
    {
        return $this->systemConfigService->get(self::CONFIG_KEY . '.' . $key, $salesChannelId);
    }

    /**
     * @param string|null $salesChannelId
     * @return array
     */
    public function getConfig(string $salesChannelId = null): array
    {
        return $this->systemConfigService->get(self::CONFIG_KEY, $salesChannelId);
    }
}

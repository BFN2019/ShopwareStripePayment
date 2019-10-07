<?php declare(strict_types=1);

namespace Stripe\ShopwarePlugin\Payment\StripeApi;

use Stripe\Charge;
use Stripe\Source;
use Stripe\Stripe;

class StripeApi
{
    // TODO: Update for shopware 6?
    private const STRIPE_PLATFORM_NAME = 'UMXJ4nBknsWR3LN_shopware_v50';

    public function __construct()
    {
        // TODO: Require plugin config
        Stripe::setApiKey('');
    }

    public function getSource(string $id): ?Source
    {
        return Source::retrieve($id);
    }

    public function createSource(array $params): Source
    {
        return Source::create(self::patchMetadata($params));
    }

    public function createCharge(array $params): Charge
    {
        return Charge::create(self::patchMetadata($params));
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

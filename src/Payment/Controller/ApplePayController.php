<?php declare(strict_types=1);

namespace Stripe\ShopwarePlugin\Payment\Controller;

use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @RouteScope(scopes={"storefront"})
 */
class ApplePayController extends StorefrontController
{
    /**
     * @Route("/.well-known/apple-developer-merchantid-domain-association", name="frontend.stripePayment.applePay.domain-association", options={"seo"="false"}, methods={"GET"})
     */
    public function domainAssociationFile(): Response
    {
        return new Response(file_get_contents(__DIR__ . '/../../../assets/apple-developer-merchantid-domain-association'));
    }
}

<?php declare(strict_types=1);

namespace Stripe\ShopwarePlugin\Payment\Controller;

use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Stripe\ShopwarePlugin\Payment\Services\SessionConfig;
use Stripe\ShopwarePlugin\Payment\Services\SessionService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @RouteScope(scopes={"storefront"})
 */
class PersistPaymentMethodSettingsController extends StorefrontController
{
    /**
     * @var SessionService
     */
    private $sessionService;

    public function __construct(SessionService $sessionService)
    {
        $this->sessionService = $sessionService;
    }

    /**
     * @Route("/stripePayment/persist", name="frontend.checkout.stripePayment.persist", options={"seo"="false"}, methods={"POST"}, defaults={"XmlHttpRequest"=true})
     */
    public function persist(): JsonResponse
    {
        $stripeSession = $this->sessionService->getStripeSession();
        $request = $this->get('request_stack')->getCurrentRequest();
        if ($request->request->get('card')) {
            $stripeSession->selectedCard = $request->request->get('card');
        }
        if ($request->request->get('saveCard')) {
            $stripeSession->saveCardForFutureCheckouts = $request->request->get('saveCard');
        }
        if ($request->request->get('selectedSepaBankAccount')) {
            $stripeSession->selectedSepaBankAccount = $request->request->get('selectedSepaBankAccount');
        }
        if ($request->request->get('saveSepaBankAccount')) {
            $stripeSession->saveSepaBankAccountForFutureCheckouts = $request->request->get('saveSepaBankAccount');
        }

        return new JsonResponse([
            'success' => true,
        ]);
    }
}

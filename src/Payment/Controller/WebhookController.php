<?php declare(strict_types=1);

namespace Stripe\ShopwarePlugin\Payment\Controller;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\System\SystemConfig\SystemConfigCollection;
use Stripe\ShopwarePlugin\Payment\Handler\WebhookHandler;
use Stripe\ShopwarePlugin\Payment\Settings\SettingsService;
use Stripe\ShopwarePlugin\Payment\StripeApi\StripeApi;
use Stripe\ShopwarePlugin\Payment\StripeApi\StripeApiFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Annotation\Route;

class WebhookController extends AbstractController
{
    /**
     * @var WebhookHandler
     */
    private $webhookHandler;

    /**
     * @var StripeApiFactory
     */
    private $stripeApiFactory;

    public function __construct(
        StripeApiFactory $stripeApiFactory,
        WebhookHandler $webhookHandler
    ) {
        $this->stripeApiFactory = $stripeApiFactory;
        $this->webhookHandler = $webhookHandler;
    }

    /**
     * @RouteScope(scopes={"storefront"})
     * @Route("/stripePayment/webhook/execute", name="stripePayment.webhook.execute", methods={"POST"})
     *
     * @throws BadRequestHttpException
     */
    public function executeWebhook(Request $request, Context $context): Response
    {
        $webhookSignature = StripeApi::getWebhookSignatureFromSymfonyRequest($request);
        if (!$webhookSignature) {
            return new Response('', 400);
        }
        $webhookPayload = StripeApi::getWebhookPayloadFromSymfonyRequest($request);
        if (!$webhookPayload) {
            return new Response('', 400);
        }

        $salesChannelId = $context->getSource()->getSalesChannelId();
        $stripeApi = $this->stripeApiFactory->getStripeApiForSalesChannel($salesChannelId);

        try {
            $event = $stripeApi->getWebhookEvent($webhookPayload, $webhookSignature);
        } catch (\Exception $e) {
            // Invalid event
            return new Response('', 400);
        }

        switch ($event->type) {
            case 'payment_intent.succeeded':
                $paymentIntent = $event->data->object;
                $this->webhookHandler->handlePaymentIntentSuccessful($paymentIntent, $context);
                break;
            case 'payment_intent.canceled':
                $paymentIntent = $event->data->object;
                $this->webhookHandler->handlePaymentIntentUnsuccessful($paymentIntent, $context);
                break;
            case 'charge.succeeded':
                $charge = $event->data->object;
                $this->webhookHandler->handleChargeSuccessful($charge, $context);
                break;
            case 'charge.failed':
                $charge = $event->data->object;
                $this->webhookHandler->handleChargeUnsuccessful($charge, $context);
                break;
            case 'source.chargeable':
                $source = $event->data->object;
                $this->webhookHandler->handleSourceChargable($source, $context);
                break;
            default:
                return new Response('', 400);
        }


        return new Response();
    }
}

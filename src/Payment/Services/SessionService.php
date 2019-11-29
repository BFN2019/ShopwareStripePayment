<?php declare(strict_types=1);

namespace Stripe\ShopwarePlugin\Payment\Services;

use Symfony\Component\HttpFoundation\Session\Session;

class SessionService
{
    /**
     * @var Session
     */
    private $session;

    public function __construct(Session $session)
    {
        $this->session = $session;
    }

    public function getStripeSession(): SessionConfig
    {
        $stripeSession = $this->session->get('stripePayment');
        if (!$stripeSession) {
            $this->resetStripeSession();
            $stripeSession = $this->session->get('stripePayment');
        }

        return $stripeSession;
    }

    public function resetStripeSession(): void
    {
        $this->session->set('stripePayment', new SessionConfig());
    }
}

<?php declare(strict_types=1);

namespace Stripe\ShopwarePlugin\Payment\Services;

class SessionConfig
{
    public $selectedCard = null;

    public $saveCardForFutureCheckouts = false;

    public $saveSepaBankAccountForFutureCheckouts = false;

    public $selectedSepaBankAccount = null;
}
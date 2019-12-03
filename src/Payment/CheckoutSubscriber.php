<?php declare(strict_types=1);

namespace Stripe\ShopwarePlugin\Payment;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Stripe\ShopwarePlugin\Payment\Services\SessionService;
use Stripe\ShopwarePlugin\Payment\Settings\SettingsService;
use Stripe\ShopwarePlugin\Payment\StripeApi\StripeApi;
use Stripe\ShopwarePlugin\Payment\StripeApi\StripeApiFactory;
use Stripe\ShopwarePlugin\StripePayment;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Framework\Context;

class CheckoutSubscriber implements EventSubscriberInterface
{
    /**
     * @var SettingsService
     */
    private $settingsService;
    /**
     * @var SessionService
     */
    private $sessionService;
    /**
     * @var EntityRepositoryInterface
     */
    private $countryRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $customerRepository;
    /**
     * @var StripeApiFactory
     */
    private $stripeApiFactory;

    public function __construct(
        SettingsService $settingsService,
        StripeApiFactory $stripeApiFactory,
        SessionService $sessionService,
        EntityRepositoryInterface $countryRepository,
        EntityRepositoryInterface $customerRepository
    ) {
        $this->settingsService = $settingsService;
        $this->stripeApiFactory = $stripeApiFactory;
        $this->sessionService = $sessionService;
        $this->countryRepository = $countryRepository;
        $this->customerRepository = $customerRepository;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class => 'onCheckoutConfirmLoaded',
        ];
    }

    public function onCheckoutConfirmLoaded(CheckoutConfirmPageLoadedEvent $event): void
    {
        // TODO: move into api controller?
        $salesChannelContext = $event->getSalesChannelContext();

        $salesChannelId = $salesChannelContext->getSalesChannel()->getId();
        $stripeApi = $this->stripeApiFactory->getStripeApiForSalesChannel($salesChannelId);

        // $this->sessionService->resetStripeSession();
        $customer = $salesChannelContext->getCustomer();
        $savedCards = [];
        $savedSepaBankAccounts = [];
        if ($customer->getCustomFields() && isset($customer->getCustomFields()['stripeCustomerId'])) {
            $savedCards = $stripeApi->getSavedCardsForCustomer($customer->getCustomFields()['stripeCustomerId']);
            $savedSepaBankAccounts = $stripeApi->getSavedSepaBankAccountsForCustomer($customer->getCustomFields()['stripeCustomerId']);
        }

        $stripeSession = $this->sessionService->getStripeSession();
        if ($stripeSession->selectedCard) {
            // Make sure the selected card is part of the list of available cards
            $cardExists = false;
            foreach ($savedCards as $card) {
                if ($card['id'] === $stripeSession->selectedCard['id']) {
                    $cardExists = true;
                    break;
                }
            }
            if (!$cardExists && $stripeSession->selectedCard) {
                $savedCards[] = $stripeSession->selectedCard;
            }
        }

        if ($stripeSession->selectedSepaBankAccount) {
            // Make sure the selected sepa bank account is part of the list of available sepa bank accounts
            $sepaBankAccountExists = false;
            foreach ($savedSepaBankAccounts as $sepaBankAccount) {
                if ($sepaBankAccount['id'] === $stripeSession->selectedSepaBankAccount['id']) {
                    $sepaBankAccountExists = true;
                    break;
                }
            }
            if (!$sepaBankAccountExists && $stripeSession->selectedSepaBankAccount) {
                $savedSepaBankAccounts[] = $stripeSession->selectedSepaBankAccount;
            }
        }

        $allowSavingCreditCards = $this->settingsService->getConfigValue('allowSavingCreditCards', $salesChannelContext->getSalesChannel()->getId());
        $allowSavingSepaBankAccounts = $this->settingsService->getConfigValue('allowSavingSepaBankAccounts', $salesChannelContext->getSalesChannel()->getId());
        $stripePublicKey = $this->settingsService->getConfigValue('stripePublicKey', $salesChannelContext->getSalesChannel()->getId());

        // TODO: filter sepa countries?
        $countries = $this->countryRepository->search(new Criteria(), Context::createDefaultContext())->getElements();

        $stripeData = new StripeData();
        $stripeData->assign([
            'data' => [
                'stripePublicKey' => $stripePublicKey,
                'allowSavingCreditCards' => $allowSavingCreditCards,
                'allowSavingSepaBankAccounts' => $allowSavingSepaBankAccounts,
                'availableCards' => $savedCards,
                'selectedCard' => $stripeSession->selectedCard,
                'sepaCountryList' => $countries,
                'selectedSepaBankAccount' => $stripeSession->selectedSepaBankAccount,
                'availableSepaBankAccounts' => $savedSepaBankAccounts,
            ],
        ]);

        $event->getPage()->addExtension('stripeData', $stripeData);
    }
}

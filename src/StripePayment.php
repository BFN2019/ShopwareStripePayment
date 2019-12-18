<?php declare(strict_types=1);

namespace Stripe\ShopwarePlugin;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Stripe\ShopwarePlugin\Payment\Handler\BancontactPaymentHandler;
use Stripe\ShopwarePlugin\Payment\Handler\CardPaymentHandler;
use Stripe\ShopwarePlugin\Payment\Handler\DigitalWalletsPaymentHandler;
use Stripe\ShopwarePlugin\Payment\Handler\GiropayPaymentHandler;
use Stripe\ShopwarePlugin\Payment\Handler\IdealPaymentHandler;
use Stripe\ShopwarePlugin\Payment\Handler\KlarnaPaymentHandler;
use Stripe\ShopwarePlugin\Payment\Handler\SepaPaymentHandler;
use Stripe\ShopwarePlugin\Payment\Handler\SofortPaymentHandler;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;

require_once __DIR__ . '/../autoload-dist/autoload.php';

class StripePayment extends Plugin
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $loader = new XmlFileLoader($container, new FileLocator(__DIR__));
        $loader->load('Payment/DependencyInjection/handler.xml');
        $loader->load('Payment/DependencyInjection/settings.xml');
        $loader->load('Payment/DependencyInjection/stripe_api.xml');
        $loader->load('Payment/DependencyInjection/subscriber.xml');
        $loader->load('Payment/DependencyInjection/services.xml');
        $loader->load('Payment/DependencyInjection/util.xml');
    }

    public function getViewPaths(): array
    {
        $viewPaths = parent::getViewPaths();
        $viewPaths[] = 'Resources/views/storefront';

        return $viewPaths;
    }

    public function install(InstallContext $installContext): void
    {
        $context = $installContext->getContext();
        $pluginId = $this->container->get(PluginIdProvider::class)->getPluginIdByBaseClass(
            $this->getClassName(),
            $context
        );

        // Check for existing 'Sofort' payment method
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('handlerIdentifier', SofortPaymentHandler::class));
        $paymentMethodRepository = $this->container->get('payment_method.repository');
        $sofortPaymentMethodId = $paymentMethodRepository->searchIds($criteria, $context)->firstId();

        $sofortPaymentMethod = [
            'id' => $sofortPaymentMethodId,
            'name' => 'SOFORT (via Stripe)',
            'handlerIdentifier' => SofortPaymentHandler::class,
            'pluginId' => $pluginId,
        ];

        // Check for existing 'Card' payment method
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('handlerIdentifier', CardPaymentHandler::class));
        $cardPaymentMethodId = $paymentMethodRepository->searchIds($criteria, $context)->firstId();

        $cardPaymentMethod = [
            'id' => $cardPaymentMethodId,
            'name' => 'Kreditkarte (via Stripe)',
            'handlerIdentifier' => CardPaymentHandler::class,
            'pluginId' => $pluginId,
        ];

        // Check for existing 'Sepa' payment method
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('handlerIdentifier', SepaPaymentHandler::class));
        $sepaPaymentMethodId = $paymentMethodRepository->searchIds($criteria, $context)->firstId();

        $sepaPaymentMethod = [
            'id' => $sepaPaymentMethodId,
            'name' => 'SEPA-Lastschrift (via Stripe)',
            'handlerIdentifier' => SepaPaymentHandler::class,
            'pluginId' => $pluginId,
        ];

        // Check for existing 'Bancontact' payment method
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('handlerIdentifier', BancontactPaymentHandler::class));
        $bancontactPaymentMethodId = $paymentMethodRepository->searchIds($criteria, $context)->firstId();

        $bancontactPaymentMethod = [
            'id' => $bancontactPaymentMethodId,
            'name' => 'Bancontact (via Stripe)',
            'handlerIdentifier' => BancontactPaymentHandler::class,
            'pluginId' => $pluginId,
        ];

        // Check for existing 'Giropay' payment method
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('handlerIdentifier', GiropayPaymentHandler::class));
        $giropayPaymentMethodId = $paymentMethodRepository->searchIds($criteria, $context)->firstId();

        $giropayPaymentMethod = [
            'id' => $giropayPaymentMethodId,
            'name' => 'Giropay (via Stripe)',
            'handlerIdentifier' => GiropayPaymentHandler::class,
            'pluginId' => $pluginId,
        ];

        // Check for existing 'Ideal' payment method
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('handlerIdentifier', IdealPaymentHandler::class));
        $idealPaymentMethodId = $paymentMethodRepository->searchIds($criteria, $context)->firstId();

        $idealPaymentMethod = [
            'id' => $idealPaymentMethodId,
            'name' => 'iDEAL (via Stripe)',
            'handlerIdentifier' => IdealPaymentHandler::class,
            'pluginId' => $pluginId,
        ];

        // Check for existing 'Klarna' payment method
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('handlerIdentifier', KlarnaPaymentHandler::class));
        $klarnaPaymentMethodId = $paymentMethodRepository->searchIds($criteria, $context)->firstId();

        $klarnaPaymentMethod = [
            'id' => $klarnaPaymentMethodId,
            'name' => 'Klarna (via Stripe)',
            'handlerIdentifier' => KlarnaPaymentHandler::class,
            'pluginId' => $pluginId,
        ];

        // Check for existing 'DigitalWallets' payment method
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('handlerIdentifier', DigitalWalletsPaymentHandler::class));
        $digitalWalletsPaymentMethodId = $paymentMethodRepository->searchIds($criteria, $context)->firstId();

        $digitalWalletsPaymentMethod = [
            'id' => $digitalWalletsPaymentMethodId,
            'name' => 'Digital Wallets (via Stripe)',
            'handlerIdentifier' => DigitalWalletsPaymentHandler::class,
            'pluginId' => $pluginId,
        ];

        $paymentMethodRepository->upsert([
            $sofortPaymentMethod,
            $cardPaymentMethod,
            $sepaPaymentMethod,
            $bancontactPaymentMethod,
            $giropayPaymentMethod,
            $idealPaymentMethod,
            $klarnaPaymentMethod,
            $digitalWalletsPaymentMethod,
        ], $context);

        // TODO: Activate/deactivate payment methods upon plugin activation/deactivation and uninstall

        parent::install($installContext);
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);
    }
}

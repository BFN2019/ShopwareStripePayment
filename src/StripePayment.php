<?php declare(strict_types=1);

namespace Stripe\ShopwarePlugin;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
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
        $loader->load('Payment/DependencyInjection/stripe_api.xml');
        $loader->load('Payment/DependencyInjection/util.xml');
    }

    public function getViewPaths(): array
    {
        $viewPaths = parent::getViewPaths();
        $viewPaths[] = 'Resources/views/storefront';

        return $viewPaths;
    }

    public function getStorefrontScriptPath(): string
    {
        return 'Resources/dist/storefront/js';
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
        $this->container->get('payment_method.repository')->upsert([$sofortPaymentMethod], $context);

        // TODO: Activate/deactivate payment methods upon plugin activation/deactivation and uninstall

        parent::install($installContext);
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);
    }
}

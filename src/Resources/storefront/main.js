// Import all necessary Storefront plugins and scss files
import StripePaymentCardSelection from './card-selection/stripe-payment.card-selection';
import StripePaymentSepaSelection from './sepa-selection/stripe-payment.sepa-selection';
import StripePaymentDigitalWalletSelection from './digital-wallet-selection/stripe-payment.digital-wallet-selection';

// Register them via the existing PluginManager
const PluginManager = window.PluginManager;
PluginManager.register('StripePaymentCardSelection', StripePaymentCardSelection, '[data-stripe-payment-card-selection]');
PluginManager.register('StripePaymentSepaSelection', StripePaymentSepaSelection, '[data-stripe-payment-sepa-selection]');
PluginManager.register('StripePaymentDigitalWalletSelection', StripePaymentDigitalWalletSelection, '[data-stripe-payment-digital-wallet-selection]');


//Necessary for the webpack hot module reloading server
if (module.hot) {
    module.hot.accept();
}

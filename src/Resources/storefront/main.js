// Import all necessary Storefront plugins and scss files
import StripePaymentCardSelection from './card-selection/stripe-payment.card-selection';
import StripePaymentSepaSelection from './sepa-selection/stripe-payment.sepa-selection';

// Register them via the existing PluginManager
const PluginManager = window.PluginManager;
PluginManager.register('StripePaymentCardSelection', StripePaymentCardSelection, '[data-stripe-payment-card-selection]');
PluginManager.register('StripePaymentSepaSelection', StripePaymentSepaSelection, '[data-stripe-payment-sepa-selection]');


//Necessary for the webpack hot module reloading server
if (module.hot) {
    module.hot.accept();
}

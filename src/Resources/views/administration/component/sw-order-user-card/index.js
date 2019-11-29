import template from './sw-order-user-card.html.twig';
import './sw-order-user-card.scss';
import deDE from './de-DE.json';
import enGB from './en-GB.json';

const { Application } = Shopware;

Application.addInitializerDecorator('locale', (localeFactory) => {
    localeFactory.extend('de-DE', deDE);
    localeFactory.extend('en-GB', enGB);

    return localeFactory;
});

Shopware.Component.override('sw-order-user-card', {
    template,

    props: {
        currentOrder: {
            type: Object,
            required: true
        },
    },

    computed: {
        getStripePaymentIntentIdOrChargeId() {
            if (this.currentOrder.transactions.length === 0) {
                return null;
            }

            if (!this.currentOrder.transactions[0].customFields
                || !this.currentOrder.transactions[0].customFields.stripe_payment_context
                || !this.currentOrder.transactions[0].customFields.stripe_payment_context.payment
                || (
                    !this.currentOrder.transactions[0].customFields.stripe_payment_context.payment.charge_id
                    && !this.currentOrder.transactions[0].customFields.stripe_payment_context.payment.payment_intent_id
                )
            ) {
                return null;
            }

            return (
                this.currentOrder.transactions[0].customFields.stripe_payment_context.payment.payment_intent_id
                || this.currentOrder.transactions[0].customFields.stripe_payment_context.payment.charge_id
            );
        },

        hasStripePaymentIntentOrCharge() {
            return !!this.getStripePaymentIntentIdOrChargeId;
        }
    },
});

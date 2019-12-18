/* eslint-disable import/no-unresolved */

import HttpClient from 'src/script/service/http-client.service';
import Plugin from 'src/script/plugin-system/plugin.class';

export default class StripePaymentDigitalWalletSelection extends Plugin {
    static options = {
        /**
         * @type string
         */
        stripePublicKey: '',

        locale: 'en',

        lineItems: [],

        countryCode: 'DE',

        currencyCode: 'EUR',

        amount: 123,
    };

    paymentApiAvailable = false;

    init() {
        this._client = new HttpClient(window.accessKey, window.contextToken);

        this.options = Object.assign(StripePaymentDigitalWalletSelection.options, this.options || {});

        /* eslint-disable no-undef */
        this.stripeClient = Stripe(this.options.stripePublicKey);

        this.paymentDisplayItems = this.options.lineItems.map(function (item) {
            return {
                label: item.articlename,
                amount: Math.round(item.amountNumeric * 100),
            };
        });
        if (this.options.shippingCost) {
            this.paymentDisplayItems.push({
                label: this.snippets.shippingCost,
                amount: Math.round(this.options.shippingCost * 100),
            });
        }

        this.createPaymentRequest(
            this.options.countryCode,
            this.options.currencyCode,
            this.options.statementDescriptor || '',
            this.options.amount
        );

        this.findForm().on('submit', { scope: this }, this.onFormSubmission);
    }

    createPaymentRequest(countryCode, currencyCode, statementDescriptor, amount) {
        this.paymentRequest = this.stripeClient.paymentRequest({
            country: countryCode.toUpperCase(),
            currency: currencyCode.toLowerCase(),
            total: {
                label: statementDescriptor,
                amount: Math.round(amount * 100),
            },
            displayItems: this.paymentDisplayItems,
        });

        // Add a listener for once the payment is created. This happens once the user selects "pay" in his browser
        // specific payment popup
        this.paymentRequest.on('paymentmethod', (paymentResponse) => {
            this.paymentMethodId = paymentResponse.paymentMethod.id;

            // Complete the browser's payment flow
            paymentResponse.complete('success');

            // Add the created Stripe token to the form and submit it
            const form = this.findForm();
            $('input[name="stripeDigitalWalletPaymentMethodId"]').remove();
            $('<input type="hidden" name="stripeDigitalWalletPaymentMethodId" />')
                .val(this.paymentMethodId)
                .appendTo(form);
            form.submit();
        });

        // Add listener for cancelled payment flow
        this.paymentRequest.on('cancel', () => {
            this.paymentMethodId = null;
            this.handleStripeError('payment cancelled');
            this.resetSubmitButton();
        });

        // Check for availability of the payment api
        this.paymentRequest.canMakePayment().then((result) => {
            this.paymentApiAvailable = !!result;
            if (this.paymentApiAvailable) {
                return;
            }
            if (!this.isSecureConnection()) {
                this.handleStripeError('insecure connection');

                return;
            }
            this.handleStripeError('not available');
        });
    }

    isSecureConnection() {
        return window.location.protocol === 'https:';
    }

    /**
     * First validates the form and payment state and, if the main form can be submitted, does nothing further.
     * If however the main form cannot be submitted, because no card is selected (or no token was created), a new Stripe
     * card and token are generated using the entered card data and saved in the form, before the submission is
     * triggered again.
     *
     * @param event
     */
    onFormSubmission(event) {
        const me = event.data.scope;

        if ($('input#tos').length === 1 && !$('input#tos').is(':checked')) {
            return undefined;
        }

        // Check if a Stripe payment method was generated and hence the form can be submitted
        if (me.paymentMethodId) {
            return undefined;
        }

        // Prevent the form from being submitted until a new Stripe payment method is generated and received
        event.preventDefault();

        // We have to manually check whether this site is served via HTTPS before checking the digital wallet payments availability
        // using Stripe.js. Even though Stripe.js checks the used protocol and declines the payment if not served via
        // HTTPS, only a generic 'not available' error message is returned and the HTTPS warning is logged to the
        // console. We however want to show a specific error message that informs about the lack of security.
        if (!me.isSecureConnection()) {
            me.shouldResetSubmitButton = true;
            me.handleStripeError('insecure connection');

            return undefined;
        }

        // Check for general availability of the digital wallet payments
        if (!me.paymentApiAvailable) {
            me.shouldResetSubmitButton = true;
            me.handleStripeError('payment api available');

            return undefined;
        }

        $('#stripe-payment-checkout-error-box').hide();

        me.setSubmitButtonLoading();

        // Process the payment
        me.paymentRequest.show();
    }

    /**
     * Finds both submit buttons on the page and adds the 'disabled' attribute as well as the loading indicator to each
     * of them.
     */
    setSubmitButtonLoading() {
        $('#confirmFormSubmit button[type="submit"]').attr('disabled', 'disabled');
    }

    /**
     * Finds both submit buttons on the page and resets them by removing the 'disabled' attribute as well as the
     * loading indicator.
     */
    resetSubmitButton() {
        $('#confirmFormSubmit button[type="submit"]').removeAttr('disabled');
    }

    /**
     * Sets the given message in the general error box and scrolls the page to make it visible.
     *
     * @param String message A Stripe error message.
     */
    handleStripeError(message) {
        $('#stripe-payment-checkout-error-box').show().children('.error-content').html(message);
    }

    /**
     * @return jQuery The main payment selection form element.
     */
    findForm() {
        return $('#confirmOrderForm');
    }
}

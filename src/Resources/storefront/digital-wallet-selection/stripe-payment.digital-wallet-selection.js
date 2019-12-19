/* eslint-disable import/no-unresolved */

import HttpClient from 'src/script/service/http-client.service';
import Plugin from 'src/script/plugin-system/plugin.class';

export default class StripePaymentDigitalWalletSelection extends Plugin {
    static options = {
        /**
         * @type string
         */
        stripePublicKey: '',

        lineItems: [],

        countryCode: 'DE',

        currencyCode: 'EUR',

        snippets: {},
    };

    paymentApiAvailable = false;

    init() {
        this._client = new HttpClient(window.accessKey, window.contextToken);

        this.options = Object.assign(StripePaymentDigitalWalletSelection.options, this.options || {});

        /* eslint-disable no-undef */
        this.stripeClient = Stripe(this.options.stripePublicKey);

        this.paymentDisplayItems = this.options.lineItems.map((item) => ({
            label: `${item.quantity}x ${item.label}`,
            amount: Math.round(item.price.totalPrice * 100),
        }));
        if (this.options.shippingCost) {
            this.paymentDisplayItems.push({
                label: this.options.snippets.shipping_cost,
                amount: Math.round(this.options.shippingCost * 100),
            });
        }

        this.findForm().on('submit', this.onFormSubmission.bind(this));

        this.createPaymentRequest(
            this.options.countryCode,
            this.options.currencyCode,
            this.options.amount
        );
    }

    createPaymentRequest(countryCode, currencyCode, amount) {
        this.paymentRequest = this.stripeClient.paymentRequest({
            country: countryCode.toUpperCase(),
            currency: currencyCode.toLowerCase(),
            total: {
                label: this.options.snippets.total,
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
            this.handleStripeError(this.options.snippets.errors.payment_cancelled);
            this.resetSubmitButton();
        });

        // Check for availability of the payment api
        this.paymentRequest.canMakePayment().then((result) => {
            this.paymentApiAvailable = !!result;
            if (this.paymentApiAvailable) {
                return;
            }
            if (!this.isSecureConnection()) {
                this.handleStripeError(this.options.snippets.errors.connection_not_secure);

                return;
            }
            this.handleStripeError(this.options.snippets.errors.payment_api_unavailable);
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
        if ($('input#tos').length === 1 && !$('input#tos').is(':checked')) {
            return undefined;
        }

        // Check if a Stripe payment method was generated and hence the form can be submitted
        if (this.paymentMethodId) {
            return undefined;
        }

        // Prevent the form from being submitted until a new Stripe payment method is generated and received
        event.preventDefault();

        // We have to manually check whether this site is served via HTTPS before checking the digital wallet payments availability
        // using Stripe.js. Even though Stripe.js checks the used protocol and declines the payment if not served via
        // HTTPS, only a generic 'not available' error message is returned and the HTTPS warning is logged to the
        // console. We however want to show a specific error message that informs about the lack of security.
        if (!this.isSecureConnection()) {
            this.resetSubmitButton();
            this.handleStripeError(this.options.snippets.errors.connection_not_secure);

            return undefined;
        }

        // Check for general availability of the digital wallet payments
        if (!this.paymentApiAvailable) {
            this.resetSubmitButton();
            this.handleStripeError(this.options.snippets.errors.payment_api_unavailable);

            return undefined;
        }

        $('#stripe-payment-checkout-error-box').hide();

        this.setSubmitButtonLoading();

        // Process the payment
        this.paymentRequest.show();
    }

    /**
     * Finds the submit button on the page and adds the 'disabled' attribute as well as a loading indicator.
     */
    setSubmitButtonLoading() {
        const submitButton = $('#confirmFormSubmit');
        submitButton.html('<div class="loader" role="status" style="position: relative;top: 4px"><span class="sr-only">Loading...</span></div>' + submitButton.html()).attr('disabled', 'disabled');
    }

    /**
     * Finds the submit button on the page and resets it by removing the 'disabled' attribute as well as the
     * loading indicator.
     */
    resetSubmitButton() {
        $('#confirmFormSubmit').removeAttr('disabled').find('.loader').remove();
    }

    /**
     * Sets the given message in the general error box and scrolls the page to make it visible.
     *
     * @param {String} message
     */
    handleStripeError(message) {
        $('#stripe-payment-checkout-error-box').show().find('.alert-content').html(`${this.options.snippets.error}: ${message}`);
        window.scrollTo({
            top: 0,
            behavior: 'smooth',
        });
    }

    /**
     * @return jQuery The main payment selection form element.
     */
    findForm() {
        return $('#confirmOrderForm');
    }
}

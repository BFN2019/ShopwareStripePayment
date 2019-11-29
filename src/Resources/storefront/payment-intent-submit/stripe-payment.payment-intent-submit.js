/* eslint-disable import/no-unresolved */

import Plugin from 'src/script/plugin-system/plugin.class';

export default class StripePaymentPaymentIntentSubmit extends Plugin {
    static options = {

        handlerIdentifier: null,

        paymentIntentClientSecret: null,

        /**
         * @type string
         */
        stripePublicKey: '',

        selectedCard: null,
    };

    init() {
        this.submitForm = false;
        this.options = Object.assign(StripePaymentPaymentIntentSubmit.options, this.options || {});

        if (!this.options.paymentIntentClientSecret || !this.options.stripePublicKey) {
            // TODO: error
            return;
        }

        /* eslint-disable no-undef */
        this.stripeClient = Stripe(this.options.stripePublicKey);

        this.findForm().on('submit', { scope: this }, this.onFormSubmission);
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
        const form = $(this);

        if (this.submitForm) {
            return undefined;
        }

        // Prevent the form from being submitted until a new Stripe token is generated and received
        event.preventDefault();

        // Send the credit card information to Stripe
        me.setSubmitButtonLoading();
        switch (me.options.paymentMethodHandlerIdentifier) {
            case 'Stripe\\ShopwarePlugin\\Payment\\Handler\\CardPaymentHandler':
                // Check if a token/card was generated and hence the form can be submitted
                if (!me.options.selectedCard) {
                    //TODO: error
                    return;
                }

                me.stripeClient.confirmCardPayment(
                    me.options.paymentIntentClientSecret,
                    {
                        payment_method: me.options.selectedCard.id,
                    }
                ).then(function(result) {
                    // Handle result.error or result.paymentIntent
                    if (result.error) {
                        // Only reset the submit buttons in case of an error, because otherwise the form is submitted again
                        // right aways and hence we want the buttons to stay disabled
                        me.resetSubmitButton();

                        // Display the error
                        const message = result.error.message || 'Unknown error';
                        me.handleStripeError('Error: ' + message);
                    } else {
                        // Save the card information
                        const paymentIntentId = result.paymentIntent.id;

                        $('input[name="stripePaymentIntentId"]').remove();
                        $('<input type="hidden" name="stripePaymentIntentId" />')
                            .val(paymentIntentId)
                            .appendTo(form);

                        me.submitForm = true;
                        form.submit();
                    }
                });
                break;
            case 'Stripe\\ShopwarePlugin\\Payment\\Handler\\SepaPaymentHandler':
                // Check if a token/card was generated and hence the form can be submitted
                if (!me.options.selectedBankAccount) {
                    //TODO: error
                    return;
                }

                me.stripeClient.confirmSepaDebitPayment(
                    me.options.paymentIntentClientSecret,
                    {
                        payment_method: me.options.selectedBankAccount.id,
                    }
                ).then(function(result) {
                    // Handle result.error or result.paymentIntent
                    if (result.error) {
                        // Only reset the submit buttons in case of an error, because otherwise the form is submitted again
                        // right aways and hence we want the buttons to stay disabled
                        me.resetSubmitButton();

                        // Display the error
                        const message = result.error.message || 'Unknown error';
                        me.handleStripeError('Error: ' + message);
                    } else {
                        // Save the card information
                        const paymentIntentId = result.paymentIntent.id;

                        $('input[name="stripePaymentIntentId"]').remove();
                        $('<input type="hidden" name="stripePaymentIntentId" />')
                            .val(paymentIntentId)
                            .appendTo(form);

                        me.submitForm = true;
                        form.submit();
                    }
                });

                break;
            default:
                // TODO: error
                return;
        }
    }

    /**
     * Finds the submit button on the page and adds the 'disabled' attribute as well as the loading indicator to each
     * of them.
     */
    setSubmitButtonLoading() {
        // Reset the button first to prevent it from being added multiple loading indicators
        this.resetSubmitButton();
        $('#confirmFormSubmit button[type="submit"], button[form="confirmOrderForm"]').each(function() {
            $(this).html($(this).text() + '<div class="js--loading"></div>').attr('disabled', 'disabled');
        });
    }

    /**
     * Finds both submit buttons on the page and resets them by removing the 'disabled' attribute as well as the
     * loading indicator.
     */
    resetSubmitButton() {
        $('#confirmFormSubmit button[type="submit"], button[form="confirmOrderForm"]').each(function() {
            $(this).removeAttr('disabled').find('.js--loading').remove();
        });
    }

    /**
     * Sets the given message in the general error box and scrolls the page to make it visible.
     *
     * @param String message A Stripe error message.
     */
    handleStripeError(message) {
        // Display the error information above the credit card form and scroll to its position
        this.formEl('.stripe-payment-error-box').show().children('.error-content').html(message);
    }

    /**
     * Applies a jQuery query on the DOM tree under the active stripe card form using the given selector. This method
     * should be used when selecting any fields that are part of a Stripe card payment form. If no Stripe card form is
     * active, an empty query result is returned.
     *
     * @param String selector
     * @return jQuery
     */
    formEl(selector) {
        const form = this.findForm();
        return (form) ? form.find(selector) : $('stripe_payment_card_not_found');
    }

    /**
     * @return jQuery The main payment selection form element.
     */
    findForm() {
        return $('#confirmOrderForm');
    }
}

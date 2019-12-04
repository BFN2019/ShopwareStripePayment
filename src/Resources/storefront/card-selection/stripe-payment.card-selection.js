/* eslint-disable import/no-unresolved */

import HttpClient from 'src/script/service/http-client.service';
import Plugin from 'src/script/plugin-system/plugin.class';

export default class StripePaymentCardSelection extends Plugin {
    static options = {
        /**
         * @type string
         */
        stripePublicKey: '',

        selectedCard: null,

        availableCards: [],

        locale: 'en',
    };

    init() {
        this._client = new HttpClient(window.accessKey, window.contextToken);
        this.stripeElements = [];
        this.invalidFields = [];

        this.options = Object.assign(StripePaymentCardSelection.options, this.options || {});

        /* eslint-disable no-undef */
        this.stripeClient = Stripe(this.options.stripePublicKey);
        // Save config
        this.setSelectedCard(this.options.selectedCard);

        // Setup form on payment method changes
        const paymentMethodElements = document.querySelectorAll('input.payment-method-input');
        paymentMethodElements.forEach(element => {
            element.addEventListener('change', () => {
                this.setupForm();
            });
        });

        this.setupForm();
    }

    /**
     * Saves the given card and removes all hidden Stripe fields from the form. If the card exists, its ID as well as
     * its encoded data are added to the form as hidden fields.
     *
     * @param card A Stripe card object.
     */
    setSelectedCard(card) {
        this.selectedCard = card;
        if (this.selectedCard) {
            console.log(`set card ${JSON.stringify(card)}`);
            this.selectedCardChanged = true;
        }
    }

    /**
     * Sets up the payment form by first unounting all Stripe elements that might be already mounted to the DOM and
     * clearing all validation errors. Then, if a stripe card payment method is selected, mounts new Stripe Elements
     * fields to the form and adds some observers to other fields as well as the form.
     */
    setupForm() {
        // Reset form
        this.unmountStripeElements();
        this.invalidFields = [];
        this.updateValidationErrors();

        if (this.getActiveStripeCardForm()) {
            this.getStripeCardForm().show();
            // Mount Stripe form fields again to the now active form and add other observers
            this.mountStripeElements();
            this.observeForm(); //TODO: remove listeners as well on change

            // Make sure the card selection matches the internal state
            if (this.selectedCard) {
                this.formEl('.stripe-saved-cards').val(this.selectedCard.id);
            }
            this.formEl('.stripe-saved-cards').trigger('change');
        } else {
            this.removeFormListeners();
            this.getStripeCardForm().hide();
        }
    }

    /**
     * Creates the Stripe Elements fields for card number, expiry and CVC and mounts them to their resepctive nodes in
     * the active Stripe card payment form.
     */
    mountStripeElements() {
        // Define options to apply to all fields when creating them
        const cardHolderFieldEl = this.formEl('.stripe-card-holder');
        const defaultOptions = {
            style: {
                base: {
                    color: cardHolderFieldEl.css('color'),
                    fontFamily: cardHolderFieldEl.css('font-family'),
                    fontSize: cardHolderFieldEl.css('font-size'),
                    fontWeight: cardHolderFieldEl.css('font-weight'),
                    lineHeight: cardHolderFieldEl.css('line-height'),
                },
            },
        };

        // Define a closure to create all elements using the same 'Elements' instance
        const elements = this.stripeClient.elements({
            locale: this.options.locale,
        });
        const me = this;
        const createAndMountStripeElement = function(type, mountSelector) {
            // Create the element and add the change listener
            const element = elements.create(type, defaultOptions);
            element.on('change', function(event) {
                if (event.error && event.error.type === 'validation_error') {
                    me.markFieldInvalid(type, event.error.code, event.error.message);
                } else {
                    me.markFieldValid(type);
                }
            });

            // Mount it to the DOM
            const mountElement = me.formEl(mountSelector).get(0);
            element.mount(mountElement);

            return element;
        };

        // Create all elements
        this.stripeElements = [
            createAndMountStripeElement('cardNumber', '.stripe-element-card-number'),
            createAndMountStripeElement('cardExpiry', '.stripe-element-card-expiry'),
            createAndMountStripeElement('cardCvc', '.stripe-element-card-cvc'),
        ];
    }

    /**
     * Unmounts all existing Stripe elements from the Stripe card payment form they are currently mounted to.
     */
    unmountStripeElements() {
        this.stripeElements.forEach((element) => element.unmount());
        this.stripeElements = [];
    }

    /**
     * Checks the list of invalid fields for any entries and, if found, joins them to an error message, which is then
     * displayed in the error box. If no invalid fields are found, the error box is hidden.
     */
    updateValidationErrors() {
        const errorBox = this.formEl('.stripe-payment-validation-error-box');
        const boxContent = errorBox.find('.error-content');
        boxContent.empty();
        if (Object.keys(this.invalidFields).length > 0) {
            // Update the error box message and make it visible
            const listEl = $('<ul></ul>')
                .addClass('alert--list')
                .appendTo(boxContent);
            Object.keys(this.invalidFields).forEach((key) => {
                $('<li></li>')
                    .addClass('list--entry')
                    .text(this.invalidFields[key])
                    .appendTo(listEl);
            });
            errorBox.show();
        } else {
            errorBox.hide();
        }
    }

    /**
     * Adds change listeners to the card selection and card holder field as well as a submission listener on the main
     * payment form.
     */
    observeForm() {
        // Add listeners
        this.findForm().on('submit', { scope: this }, this.onFormSubmission);
        this.formEl('.stripe-saved-cards').on('change', { scope: this }, this.onCardSelectionChange);

        // Save the current value and add listener
        const cardHolderElem = this.formEl('.stripe-card-holder');
        cardHolderElem.data('oldVal', cardHolderElem.val());
        cardHolderElem.on('propertychange keyup input paste', { scope: this }, this.onCardHolderChange);
    }

    removeFormListeners() {
        this.findForm().off('submit', this.onFormSubmission);
        this.formEl('.stripe-saved-cards').off('change', this.onCardSelectionChange);
        this.formEl('.stripe-card-holder').off('propertychange keyup input paste', this.onCardHolderChange);
    }

    /**
     * Removes all validation errors for the field with the given 'fieldId' and triggers an update of the displayed
     * validation errors.
     *
     * @param String fieldId
     */
    markFieldValid(fieldId) {
        delete this.invalidFields[fieldId];
        this.updateValidationErrors();
    }

    /**
     * Determines the error message based on the given 'errorCode' and 'message' and triggers
     * an update of the displayed validation errors.
     *
     * @param fieldId
     * @param errorCode (optional) The code used to find a localised error message.
     * @param message (optioanl) The fallback error message used in case no 'errorCode' is provided or no respective, localised description exists.
     */
    markFieldInvalid(fieldId, errorCode, message) {
        // TODO: add localized error with snippets if avail
        this.invalidFields[fieldId] = message || 'Unknown error';
        this.updateValidationErrors();
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

        // Not the currently selected payment method
        if (!me.getActiveStripeCardForm()) {
            return undefined;
        }

        // Check if a token/card was generated and hence the form can be submitted
        if (me.selectedCard) {
            if (!me.selectedCardChanged) {
                return undefined;
            }

            me.unmountStripeElements();

            event.preventDefault();
            me._client.post(me.options.persistUrl, JSON.stringify({
                card: me.selectedCard,
            }), (res) => {
                const result = JSON.parse(res);
                if (!result.success) {
                    return;
                }
                me.selectedCardChanged = null;

                // Submit the form again to finish the payment process
                form.submit();
            });

            return;
        }

        // Prevent the form from being submitted until a new Stripe token is generated and received
        event.preventDefault();

        // Check for invalid fields
        if (Object.keys(me.invalidFields).length > 0) {
            return;
        }

        // Send the credit card information to Stripe
        me.setSubmitButtonsLoading();
        me.stripeClient.createPaymentMethod('card', me.stripeElements[0], {
            billing_details: {
                name: me.formEl('.stripe-card-holder').val(),
            },
        }).then((result) => {
            if (result.error) {
                // Only reset the submit buttons in case of an error, because otherwise the form is submitted again
                // right aways and hence we want the buttons to stay disabled
                me.resetSubmitButtons();

                // Display the error
                // TODO: add localized error with snippets if avail
                const message = result.error.message || 'Unknown error';
                me.handleStripeError('Error: ' + message);
            } else {
                // Save the card information
                const card = result.paymentMethod.card;
                card.id = result.paymentMethod.id;
                card.name = me.formEl('.stripe-card-holder').val();
                me.setSelectedCard(card);

                // Save whether to save the credit card for future checkouts
                const saveCard = me.formEl('.stripe-save-card').is(':checked');
                try {
                    me._client.post(me.options.persistUrl, JSON.stringify({
                        card,
                        saveCard,
                    }), (res) => {
                        const result = JSON.parse(res);
                        if (!result.success) {
                            return;
                        }

                        // Submit the form again to finish the payment process
                        form.submit();
                    });
                } catch (err) {
                    /* eslint-disable no-debugger */
                    debugger;
                }
            }
        });
    }

    /**
     * Adds a subscriber to the card holder form field that is fired when its value is changed to validate the
     * entered value.
     *
     * @param Object event
     */
    onCardHolderChange(event) {
        const me = event.data.scope;
        const elem = $(this);
        // Check if value has changed
        if (elem.data('oldVal') === elem.val()) {
            return;
        }
        elem.data('oldVal', elem.val());

        // Validate the field
        if (elem.val().trim().length === 0) {
            elem.addClass('instyle_error has--error');
            me.markFieldInvalid('cardHolder', 'invalid_card_holder');
        } else {
            elem.removeClass('instyle_error has--error');
            me.markFieldValid('cardHolder');
        }
    }

    /**
     * Adds a change observer to the card selection field. If an existing card is selected, all form fields are hidden
     * and the card's Stripe information is added to the form. If the 'new' option is selected, all fields made visible
     * and the Stripe card info is removed from the form.
     *
     * @param Object event
     */
    onCardSelectionChange(event) {
        const me = event.data.scope;
        const elem = $(this);

        if (elem.val() === 'new') {
            // A new, empty card was selected
            me.setSelectedCard(null);

            // Make validation errors visible
            me.updateValidationErrors();

            // Show the save check box
            me.formEl('.stripe-card-field').show();
            me.formEl('.stripe-save-card').show().prop('checked', true);

            return;
        }

        // Find the selected card
        for (let i = 0; i < me.options.availableCards.length; i++) {
            const selectedCard = me.options.availableCards[i];
            if (selectedCard.id !== elem.val()) {
                continue;
            }

            // Save the card
            me.setSelectedCard(selectedCard);

            // Hide validation errors
            me.formEl('.stripe-payment-validation-error-box').hide();

            // Hide all card fields
            me.formEl('.stripe-card-field').hide();
            me.formEl('.stripe-save-card').hide();

            break;
        }
    }

    /**
     * Finds both submit buttons on the page and adds the 'disabled' attribute as well as the loading indicator to each
     * of them.
     */
    setSubmitButtonsLoading() {
        // Reset the button first to prevent it from being added multiple loading indicators
        this.resetSubmitButtons();
        $('#confirmPaymentForm button[type="submit"], .confirm--actions button[form="confirmPaymentForm"]').each(function() {
            $(this).html($(this).text() + '<div class="js--loading"></div>').attr('disabled', 'disabled');
        });
    }

    /**
     * Finds both submit buttons on the page and resets them by removing the 'disabled' attribute as well as the
     * loading indicator.
     */
    resetSubmitButtons() {
        $('#confirmPaymentForm button[type="submit"], .confirm--actions button[form="confirmPaymentForm"]').each(function() {
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
     * Tries to find a stripe card form for the currently active payment method. That is, if a stripe card payment
     * method is selected, its form is returned, otherwise returns null.
     *
     * @return jQuery|null
     */
    getActiveStripeCardForm() {
        const form = $('input[id^="paymentMethod"]:checked').closest('.payment-method').find('.stripe-payment-card-form');

        return (form.length > 0) ? form.first() : null;
    }

    getStripeCardForm() {
        const form = $('input[id^="paymentMethod"]').closest('.payment-method').find('.stripe-payment-card-form');

        return (form.length > 0) ? form.first() : null;
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
        const form = this.getActiveStripeCardForm();
        return (form) ? form.find(selector) : $('stripe_payment_card_not_found');
    }

    /**
     * @return jQuery The main payment selection form element.
     */
    findForm() {
        return $('#confirmPaymentForm');
    }
}

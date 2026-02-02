/**
 * Checkout.com Frame Payment Handler
 */
jQuery(document).ready(function($) {
    
    console.log('Checkout.com Frame script loaded');
    
    if (typeof Frames === 'undefined' || typeof checkoutComFrame === 'undefined') {
        console.error('Checkout.com Frames or configuration not loaded');
        return;
    }

    console.log('Checkout.com Frame configuration:', checkoutComFrame);

    var payButton = $('#pay-button');  // Fixed ID
    var form = $('#payment-form');     // Fixed ID
    var errorDiv = $('#checkout-error'); // Fixed ID

    console.log('Form elements found:', {
        payButton: payButton.length,
        form: form.length,
        errorDiv: errorDiv.length
    });

    // Initialize Frames
    console.log('Initializing Checkout.com Frames');
    Frames.init({
        publicKey: checkoutComFrame.publicKey,
        style: {
            base: {
                color: '#333',
                fontSize: '16px',
                fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif'
            },
            invalid: {
                color: '#e74c3c'
            }
        }
    });

    // Handle card validation changes
    Frames.addEventHandler(Frames.Events.CARD_VALIDATION_CHANGED, function(event) {
        console.log('Card validation changed:', event);
        payButton.prop('disabled', !Frames.isCardValid());
    });

    // Handle frame validation changes
    Frames.addEventHandler(Frames.Events.FRAME_VALIDATION_CHANGED, function(event) {
        console.log('Frame validation changed:', event);
        if (!event.isValid && !event.isEmpty) {
            showError(getFieldError(event.element));
        } else {
            hideError();
        }
    });

    // Handle card tokenization
    Frames.addEventHandler(Frames.Events.CARD_TOKENIZED, function(event) {
        console.log('Card tokenized:', event.token.substring(0, 10) + '...');
        $('#checkout_payment_token').val(event.token);  // Fixed ID
        form.off('submit').submit(); // Remove handler and submit
    });

    // Handle form submission
    form.on('submit', function(event) {
        console.log('Form submission started');
        event.preventDefault();
        hideError();

        if (!Frames.isCardValid()) {
            console.log('Card validation failed');
            showError('Please enter valid card details.');
            return;
        }

        if ($('#checkout_payment_token').val()) {  // Fixed ID
            console.log('Token already exists, submitting form');
            // Token already set, submit form
            return true;
        }

        console.log('Submitting card for tokenization');
        // Disable button and submit card for tokenization
        payButton.prop('disabled', true).text('Processing...');
        Frames.submitCard();
    });

    /**
     * Show error message
     */
    function showError(message) {
        console.log('Showing error:', message);
        errorDiv.text(message).show();
    }

    /**
     * Hide error message
     */
    function hideError() {
        console.log('Hiding error');
        errorDiv.hide();
    }

    /**
     * Get field-specific error message
     */
    function getFieldError(field) {
        var errors = {
            'card-number': 'Please enter a valid card number',
            'expiry-date': 'Please enter a valid expiry date',
            'cvv': 'Please enter a valid CVV'
        };
        return errors[field] || 'Please check your card details';
    }

});

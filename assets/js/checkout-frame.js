/**
 * Checkout.com Frame Payment Handler - Simplified like working plugin
 */
jQuery(document).ready(function($) {
    
    console.log('Checkout.com Frame script loaded');
    
    if (typeof Frames === 'undefined' || typeof checkoutComFrame === 'undefined') {
        console.error('Checkout.com Frames or configuration not loaded');
        return;
    }

    var payButton = $('#pay-button');
    var form = $('#payment-form');
    var loader = $('#checkout-loader');

    console.log('Form elements found:', {
        payButton: payButton.length,
        form: form.length,
        loader: loader.length
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

    // Hide loader when Frames is ready
    Frames.addEventHandler(Frames.Events.READY, function() {
        console.log('Frames ready, hiding loader and showing card frame');
        if (loader.length) loader.hide();
        $('.card-frame').removeClass('hidden');
    });

    // Handle card validation changes
    Frames.addEventHandler(Frames.Events.CARD_VALIDATION_CHANGED, function(event) {
        console.log('Card validation changed:', event);
        payButton.prop('disabled', !Frames.isCardValid());
    });

    // Handle successful tokenization
    Frames.addEventHandler(Frames.Events.CARD_TOKENIZED, function(event) {
        console.log('SUCCESS - Card tokenized, submitting form');
        $('#checkout_payment_token').val(event.token);
        form[0].submit();
    });

    // Handle tokenization errors
    Frames.addEventHandler(Frames.Events.CARD_TOKENIZATION_FAILED, function(event) {
        console.log('FAILED - Card tokenization failed:', event);
        payButton.prop('disabled', false).text('Pay Now');
    });

    // Handle form submission
    form.on('submit', function(event) {
        console.log('Form submission started');
        
        if (!Frames.isCardValid()) {
            console.log('Card validation failed');
            event.preventDefault();
            return false;
        }
        
        if ($('#checkout_payment_token').val() == '') {
            console.log('No token, requesting tokenization');
            event.preventDefault();
            payButton.prop('disabled', true).text('Processing...');
            Frames.submitCard();
        }
    });

});

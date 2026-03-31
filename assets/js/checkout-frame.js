/**
 * Checkout.com Frame Payment Handler - Simplified like working plugin
 */
jQuery(document).ready(function ($) {



    if (typeof Frames === 'undefined' || typeof checkoutComFrame === 'undefined') {
        return;
    }

    var payButton = $('#pay-button');
    var form = $('#payment-form');
    var loader = $('#checkout-loader');

    var loader = $('#checkout-loader');


    // Initialize Frames

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
    Frames.addEventHandler(Frames.Events.READY, function () {

        if (loader.length) loader.hide();
        $('.card-frame').removeClass('hidden');
    });

    // Handle card validation changes
    Frames.addEventHandler(Frames.Events.CARD_VALIDATION_CHANGED, function (event) {

        payButton.prop('disabled', !Frames.isCardValid());
    });

    // Handle successful tokenization
    Frames.addEventHandler(Frames.Events.CARD_TOKENIZED, function (event) {

        $('#checkout_payment_token').val(event.token);
        form[0].submit();
    });

    // Handle tokenization errors
    Frames.addEventHandler(Frames.Events.CARD_TOKENIZATION_FAILED, function (event) {

        payButton.prop('disabled', false).text('Pay Now');
    });

    // Handle form submission
    form.on('submit', function (event) {


        if (!Frames.isCardValid()) {

            event.preventDefault();
            return false;
        }

        if ($('#checkout_payment_token').val() == '') {

            event.preventDefault();
            payButton.prop('disabled', true).text('Processing...');
            Frames.submitCard();
        }
    });

});

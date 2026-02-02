/**
 * Admin settings JavaScript for Checkout.com Pro
 */
jQuery(document).ready(function($) {
    
    // Function to toggle credential fields based on mode
    function toggleCredentialFields() {
        var selectedMode = $('input[name="_gform_setting_mode"]:checked').val();
        
        // Use the correct selectors for the field container divs
        var testFields = $('#gform_setting_test_public_key, #gform_setting_test_secret_key, #gform_setting_test_processing_channel_id, #gform_setting_test_webhook_secret');
        var liveFields = $('#gform_setting_live_public_key, #gform_setting_live_secret_key, #gform_setting_live_processing_channel_id, #gform_setting_live_webhook_secret');
        
        if (selectedMode === 'test') {
            testFields.show();
            liveFields.hide();
        } else if (selectedMode === 'live') {
            liveFields.show();
            testFields.hide();
        }
    }
    
    // Run on page load
    toggleCredentialFields();
    
    // Run when mode changes
    $('input[name="_gform_setting_mode"]').on('change', function() {
        toggleCredentialFields();
    });
    
});

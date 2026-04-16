<?php
/**
 * Checkout.com Pro for Gravity Forms Bootstrap.
 *
 * @package checkout-com-pro-for-gravity-forms
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bootstrap class for the plugin.
 */
class GF_Checkout_Com_Pro_Bootstrap {


	/**
	 * Load plugin dependencies and register addon.
	 */
	public static function load() {
		if ( ! method_exists( 'GFForms', 'include_payment_addon_framework' ) ) {
			return;
		}

		load_plugin_textdomain( 'checkout-com-pro-for-gravity-forms', false, dirname( plugin_basename( GF_CHECKOUT_COM_PRO_FILE ) ) . '/languages/' );

		require_once GF_CHECKOUT_COM_PRO_DIR . 'includes/class-checkout-com-api-client.php';
		require_once GF_CHECKOUT_COM_PRO_DIR . 'includes/class-checkout-com-webhook-handler.php';
		require_once GF_CHECKOUT_COM_PRO_DIR . 'includes/class-gf-checkout-com-pro-gateway.php';

		GFAddOn::register( 'GF_Checkout_Com_Pro_Gateway' );
	}
}

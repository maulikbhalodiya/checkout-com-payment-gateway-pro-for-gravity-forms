<?php
/**
 * Plugin Name:       Gravity Forms Checkout.com Payment Gateway Pro
 * Plugin URI:        https://wpgateways.com/products/checkout-com-gateway-gravity-forms/
 * Description:       Professional Checkout.com payment gateway for Gravity Forms with Frame and Component support.
 * Version:           1.0.0
 * Author:            Maulik Bhalodiya
 * Author URI:        https://wpgateways.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       gravityforms-checkout-com-pro
 * Domain Path:       /languages
 * Requires at least: 5.0
 * Tested up to:      6.4
 * Requires PHP:      7.4
 * Network:           false
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin constants.
 */
define( 'GF_CHECKOUT_COM_PRO_VERSION', '1.0.0' );
define( 'GF_CHECKOUT_COM_PRO_FILE', __FILE__ );
define( 'GF_CHECKOUT_COM_PRO_DIR', plugin_dir_path( __FILE__ ) );
define( 'GF_CHECKOUT_COM_PRO_URL', plugin_dir_url( __FILE__ ) );
define( 'GF_CHECKOUT_COM_PRO_BASENAME', plugin_basename( __FILE__ ) );
define( 'GF_CHECKOUT_COM_PRO_MIN_GF_VERSION', '2.4.0' );

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

		load_plugin_textdomain( 'gravityforms-checkout-com-pro', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

		require_once 'includes/class-checkout-com-api-client.php';
		require_once 'includes/class-checkout-com-webhook-handler.php';
		require_once 'includes/class-gf-checkout-com-pro-gateway.php';

		GFAddOn::register( 'GF_Checkout_Com_Pro_Gateway' );
	}
}

// Hook to load when Gravity Forms is ready.
add_action( 'gform_loaded', array( 'GF_Checkout_Com_Pro_Bootstrap', 'load' ), 5 );

/**
 * Get gateway instance.
 *
 * @return GF_Checkout_Com_Pro_Gateway|false
 */
function gf_checkout_com_pro() {
	return class_exists( 'GF_Checkout_Com_Pro_Gateway' ) ? GF_Checkout_Com_Pro_Gateway::get_instance() : false;
}

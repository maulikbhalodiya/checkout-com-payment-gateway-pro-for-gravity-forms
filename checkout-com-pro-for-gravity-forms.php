<?php
/**
 * Main plugin file.
 *
 * @package checkout-com-pro-for-gravity-forms
 *
 * Plugin Name: Checkout.com Pro for Gravity Forms
 * Plugin URI:  https://github.com/maulikbhalodiya/checkout-com-pro-for-gravity-forms
 * Description: Seamlessly integrate Checkout.com with Gravity Forms. Supports Frames, Web Components, and 3D Secure 2.0.
 * Version:     1.0.0
 * Author:      Maulik Bhalodiya
 * Author URI:  https://github.com/maulikbhalodiya
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: checkout-com-pro-for-gravity-forms
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * Network: false
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'GF_CHECKOUT_COM_PRO_VERSION', '1.0.0' );
define( 'GF_CHECKOUT_COM_PRO_FILE', __FILE__ );
define( 'GF_CHECKOUT_COM_PRO_DIR', plugin_dir_path( __FILE__ ) );
define( 'GF_CHECKOUT_COM_PRO_URL', plugin_dir_url( __FILE__ ) );
define( 'GF_CHECKOUT_COM_PRO_BASENAME', plugin_basename( __FILE__ ) );
define( 'GF_CHECKOUT_COM_PRO_MIN_GF_VERSION', '2.4.0' );

// Load bootstrap.
require_once GF_CHECKOUT_COM_PRO_DIR . 'includes/class-gf-checkout-com-pro-bootstrap.php';

// Hook to load when Gravity Forms is ready.
add_action( 'gform_loaded', array( 'GF_Checkout_Com_Pro_Bootstrap', 'load' ), 5 );

/**
 * Get gateway instance.
 *
 * @since 1.0.0
 * @return GF_Checkout_Com_Pro_Gateway|false
 */
function gf_checkout_com_pro() {
	return class_exists( 'GF_Checkout_Com_Pro_Gateway' ) ? GF_Checkout_Com_Pro_Gateway::get_instance() : false;
}

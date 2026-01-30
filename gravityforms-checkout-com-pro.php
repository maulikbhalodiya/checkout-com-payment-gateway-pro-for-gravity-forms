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
 * Main plugin bootstrap class.
 */
final class GF_Checkout_Com_Pro_Bootstrap {

	/**
	 * Single instance.
	 *
	 * @var GF_Checkout_Com_Pro_Bootstrap
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return GF_Checkout_Com_Pro_Bootstrap
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init' ) );
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
	}

	/**
	 * Initialize plugin.
	 */
	public function init() {
		if ( ! $this->check_dependencies() ) {
			return;
		}

		load_plugin_textdomain( 'gravityforms-checkout-com-pro', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
		add_action( 'gform_loaded', array( $this, 'load_addon' ), 5 );
	}

	/**
	 * Check dependencies.
	 *
	 * @return bool
	 */
	private function check_dependencies() {
		if ( ! class_exists( 'GFForms' ) ) {
			add_action( 'admin_notices', array( $this, 'gf_missing_notice' ) );
			return false;
		}

		if ( ! version_compare( GFForms::$version, GF_CHECKOUT_COM_PRO_MIN_GF_VERSION, '>=' ) ) {
			add_action( 'admin_notices', array( $this, 'gf_version_notice' ) );
			return false;
		}

		return true;
	}

	/**
	 * Load addon.
	 */
	public function load_addon() {
		if ( ! method_exists( 'GFForms', 'include_payment_addon_framework' ) ) {
			return;
		}

		require_once GF_CHECKOUT_COM_PRO_DIR . 'includes/class-gf-checkout-com-pro-gateway.php';
		GFAddOn::register( 'GF_Checkout_Com_Pro_Gateway' );
	}

	/**
	 * Activation hook.
	 */
	public function activate() {
		update_option( 'gf_checkout_com_pro_version', GF_CHECKOUT_COM_PRO_VERSION );
	}

	/**
	 * Deactivation hook.
	 */
	public function deactivate() {
		// Cleanup if needed.
	}

	/**
	 * GF missing notice.
	 */
	public function gf_missing_notice() {
		$message = sprintf(
			/* translators: %s: plugin name */
			esc_html__( '%s requires Gravity Forms to be installed and activated.', 'gravityforms-checkout-com-pro' ),
			'<strong>Gravity Forms Checkout.com Payment Gateway Pro</strong>'
		);
		printf( '<div class="notice notice-error"><p>%s</p></div>', wp_kses_post( $message ) );
	}

	/**
	 * GF version notice.
	 */
	public function gf_version_notice() {
		$message = sprintf(
			/* translators: %1$s: plugin name, %2$s: required version */
			esc_html__( '%1$s requires Gravity Forms version %2$s or higher.', 'gravityforms-checkout-com-pro' ),
			'<strong>Gravity Forms Checkout.com Payment Gateway Pro</strong>',
			GF_CHECKOUT_COM_PRO_MIN_GF_VERSION
		);
		printf( '<div class="notice notice-error"><p>%s</p></div>', wp_kses_post( $message ) );
	}
}

// Initialize plugin.
GF_Checkout_Com_Pro_Bootstrap::instance();

/**
 * Get gateway instance.
 *
 * @return GF_Checkout_Com_Pro_Gateway|false
 */
function gf_checkout_com_pro() {
	return class_exists( 'GF_Checkout_Com_Pro_Gateway' ) ? GF_Checkout_Com_Pro_Gateway::get_instance() : false;
}

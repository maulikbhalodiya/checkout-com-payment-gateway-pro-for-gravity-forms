<?php
/**
 * Checkout.com Pro Gateway for Gravity Forms - Simplified Version.
 *
 * @package GravityForms_Checkout_Com_Pro
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// CRITICAL: Register static hook like working plugin
add_action( 'wp', array( 'GF_Checkout_Com_Pro_Gateway', 'maybe_process_checkout_com_page' ), 5 );

// Include payment addon framework.
GFForms::include_payment_addon_framework();

// Include modular payment method classes.
require_once plugin_dir_path( __FILE__ ) . 'class-gf-checkout-com-frame.php';
require_once plugin_dir_path( __FILE__ ) . 'class-gf-checkout-com-component.php';

/**
 * Main gateway class - simplified unified approach.
 */
class GF_Checkout_Com_Pro_Gateway extends GFPaymentAddOn {

	/**
	 * Plugin version.
	 */
	protected $_version = GF_CHECKOUT_COM_PRO_VERSION;

	/**
	 * Minimum GF version.
	 */
	protected $_min_gravityforms_version = GF_CHECKOUT_COM_PRO_MIN_GF_VERSION;

	/**
	 * Plugin slug.
	 */
	protected $_slug = 'checkout-com-pro';

	/**
	 * Plugin path.
	 */
	protected $_path = GF_CHECKOUT_COM_PRO_BASENAME;

	/**
	 * Full path.
	 */
	protected $_full_path = GF_CHECKOUT_COM_PRO_FILE;

	/**
	 * Plugin URL.
	 */
	protected $_url = 'https://wpgateways.com/products/checkout-com-gateway-gravity-forms/';

	/**
	 * Plugin title.
	 */
	protected $_title = 'Checkout.com Pro';

	/**
	 * Short title.
	 */
	protected $_short_title = 'Checkout.com Pro';

	/**
	 * Requires credit card.
	 */
	protected $_requires_credit_card = false;

	/**
	 * Supports callbacks.
	 *
	 * @var bool
	 */
	protected $_supports_callbacks = true;

	/**
	 * Requires smallest unit.
	 *
	 * @var bool
	 */
	protected $_requires_smallest_unit = true;

	/**
	 * Capabilities.
	 *
	 * @var array
	 */
	protected $_capabilities = array(
		'gravityforms_checkout_com_pro',
		'gravityforms_checkout_com_pro_uninstall',
	);

	/**
	 * Settings page capability.
	 *
	 * @var string
	 */
	protected $_capabilities_settings_page = 'gravityforms_checkout_com_pro';

	/**
	 * Form settings capability.
	 */
	protected $_capabilities_form_settings = 'gravityforms_checkout_com_pro';

	/**
	 * Uninstall capability.
	 */
	protected $_capabilities_uninstall = 'gravityforms_checkout_com_pro_uninstall';

	/**
	 * Checkout.com API URLs.
	 */
	// Frame method - Direct payments endpoint
	const CHECKOUT_COM_URL_LIVE = 'https://api.checkout.com/payments/';
	const CHECKOUT_COM_URL_TEST = 'https://api.sandbox.checkout.com/payments/';

	// Component method - Payment sessions endpoint
	const CHECKOUT_COM_SESSIONS_URL_LIVE = 'https://api.checkout.com/payment-sessions';
	const CHECKOUT_COM_SESSIONS_URL_TEST = 'https://api.sandbox.checkout.com/payment-sessions';

	/**
	 * Payment page rendering properties.
	 */
	protected $is_payment_page_load = false;
	protected $payment_page_form    = null;
	protected $payment_page_entry   = null;
	protected $payment_page_error   = null;

	/**
	 * Instance.
	 */
	private static $_instance = null;

	/**
	 * API client.
	 */
	private $api_client = null;

	/**
	 * Webhook handler.
	 */
	private $webhook_handler = null;

	/**
	 * Component handler.
	 */
	private $component_handler = null;

	/**
	 * Frame handler.
	 */
	private $frame_handler = null;

	/**
	 * Get instance.
	 */
	public static function get_instance() {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$this->init_components();
	}

	/**
	 * Initialize components.
	 */
	private function init_components() {
		if ( null === $this->webhook_handler ) {
			$this->webhook_handler = new Checkout_Com_Webhook_Handler( $this );
		}

		// Initialize Component handler (registers its own AJAX hooks)
		if ( null === $this->component_handler ) {
			$this->component_handler = new GF_Checkout_Com_Component_Handler( $this );
		}

		// Initialize Frame handler
		if ( null === $this->frame_handler ) {
			$this->frame_handler = new GF_Checkout_Com_Frame_Handler( $this );
		}
	}

	/**
	 * Initialize addon.
	 */
	public function init() {
		parent::init();

		// Hook into 'the_content' to render the payment page when necessary.
		add_filter( 'the_content', array( $this, 'maybe_render_payment_page' ) );
	}

	/**
	 * Static method to process payment page (like working plugin).
	 */
	public static function maybe_process_checkout_com_page() {
		$instance = self::get_instance();

		if ( ! $instance->is_gravityforms_supported() ) {
			return;
		}

		if ( $str = rgget( 'gf_checkout_com_pro_return' ) ) {
			$str = $instance->base64_decode( $str );
			$instance->log_debug( __METHOD__ . '(): Payment return request received. Starting to process.' );

			parse_str( $str, $query );
			$callback_action = false;

			if ( $query['hash'] !== wp_hash( 'ids=' . $query['ids'] ) ) {
				$instance->log_error( __METHOD__ . '(): Payment return request hash invalid. Aborting.' );
				return;
			}

			list( $form_id, $lead_id ) = explode( '|', $query['ids'] );

			$form  = GFAPI::get_form( $form_id );
			$entry = GFAPI::get_entry( $lead_id );

			if ( is_wp_error( $entry ) || ! $form ) {
				$instance->log_error( __METHOD__ . '(): Form or Entry not found. Aborting.' );
				return;
			}

			$payment_status = rgar( $entry, 'payment_status' );

			if ( 'Paid' === $payment_status ) {
				$instance->log_debug( __METHOD__ . '(): Entry is already marked as Paid. Skipping to confirmation.' );
				// If already paid, let GF handle the standard confirmation.
				if ( ! class_exists( 'GFFormDisplay' ) ) {
					require_once GFCommon::get_base_path() . '/form_display.php';
				}
				$confirmation = GFFormDisplay::handle_confirmation( $form, $entry, false );
				if ( is_array( $confirmation ) && isset( $confirmation['redirect'] ) ) {
					wp_redirect( $confirmation['redirect'] );
					exit;
				}
				GFFormDisplay::$submission[ $form_id ] = array(
					'is_confirmation'      => true,
					'confirmation_message' => $confirmation,
					'form'                 => $form,
					'lead'                 => $entry,
				);
				return;
			}

			// --- Logic for handling the return FROM Checkout.com after a 3DS redirect or token submission ---
			if ( rgget( 'cko-session-id' ) || rgpost( 'cko_session_id' ) || rgpost( 'payment_token' ) ) {
				$callback_action = $instance->checkout_com_callback( $form, $entry );
				$instance->log_debug( __METHOD__ . '(): Result from gateway callback => ' . print_r( $callback_action, true ) );

				if ( is_wp_error( $callback_action ) ) {
					// A hard error occurred during the API call (e.g., card declined).
					$instance->payment_page_error = $callback_action->get_error_message();

					// Store the error message in meta to display it on the payment form.
					gform_update_meta( $entry['id'], 'checkout_com_payment_error', $callback_action->get_error_message() );

					// Set flags to reload the payment page.
					$instance->is_payment_page_load = true;
					$instance->payment_page_form    = $form;
					$instance->payment_page_entry   = $entry;
					return; // IMPORTANT: Stop further execution.

				} elseif ( isset( $callback_action ) && is_array( $callback_action ) && rgar( $callback_action, 'type' ) && ! rgar( $callback_action, 'abort_callback' ) ) {
					$instance->log_debug( 'Checkout.com Pro: Processing callback action: ' . rgar( $callback_action, 'type' ) );

					// CRITICAL: Process callback action for ALL types (like component plugin)
					$result = $instance->checkout_com_process_callback_action( $callback_action );

					if ( is_wp_error( $result ) ) {
						$instance->log_error( 'Checkout.com Pro: Callback action error: ' . $result->get_error_message() );
						$instance->payment_page_error = $result->get_error_message();
						gform_update_meta( $entry['id'], 'checkout_com_payment_error', $result->get_error_message() );
						$instance->is_payment_page_load = true;
						$instance->payment_page_form    = $form;
						$instance->payment_page_entry   = $entry;
						return;
					} elseif ( ! $result ) {
						$instance->log_error( 'Checkout.com Pro: Callback action failed' );
						// Use the specific error message from the callback action if available
						$error_message                = isset( $callback_action['error_message'] ) ? $callback_action['error_message'] : __( 'Unable to validate your payment, please try again.', 'gravityforms-checkout-com-pro' );
						$instance->payment_page_error = $error_message;
						gform_update_meta( $entry['id'], 'checkout_com_payment_error', $instance->payment_page_error );
						$instance->is_payment_page_load = true;
						$instance->payment_page_form    = $form;
						$instance->payment_page_entry   = $entry;
						return;
					} elseif ( 'complete_payment' === rgar( $callback_action, 'type' ) ) {
						$instance->log_debug( 'Checkout.com Pro: Payment successful, proceeding to confirmation' );
						// Payment successful - proceed to confirmation (PRESERVE EXISTING FLOW)
						if ( ! class_exists( 'GFFormDisplay' ) ) {
							require_once GFCommon::get_base_path() . '/form_display.php';
						}

						$confirmation = GFFormDisplay::handle_confirmation( $form, $entry, false );
						if ( is_array( $confirmation ) && isset( $confirmation['redirect'] ) ) {
							wp_redirect( $confirmation['redirect'] );
							exit;
						}
						GFFormDisplay::$submission[ $form_id ] = array(
							'is_confirmation'      => true,
							'confirmation_message' => $confirmation,
							'form'                 => $form,
							'lead'                 => $entry,
						);
						return;
					} else {
						$instance->log_error( 'Checkout.com Pro: Payment failed, showing error message' );
						// Payment failed/pending - show payment page with error (but entry status is now updated)
						$instance->payment_page_error = rgar( $callback_action, 'error_message' );

						// Store error in session (temporary) instead of meta (persistent)
						if ( ! session_id() ) {
							session_start();
						}
						$_SESSION[ 'checkout_com_payment_error_' . $entry['id'] ] = $instance->payment_page_error;

						$instance->is_payment_page_load = true;
						$instance->payment_page_form    = $form;
						$instance->payment_page_entry   = $entry;
						return;
					}
				}
			}

			// --- NEW LOGIC: Set a flag to render the payment page via 'the_content' filter ---
			// This will be true if it's the initial load of the payment page (no token/session)
			// OR if there was an error processing the payment and we need to show the form again.
			if ( ! $callback_action || is_wp_error( $callback_action ) || ( is_array( $callback_action ) && 'fail_payment' === rgar( $callback_action, 'type' ) ) ) {
				$instance->is_payment_page_load = true;
				$instance->payment_page_form    = $form;
				$instance->payment_page_entry   = $entry;
			}
		}
	}

	/**
	 * Initialize frontend.
	 */
	public function init_frontend() {
		parent::init_frontend();
		add_filter( 'gform_disable_post_creation', array( $this, 'delay_post' ), 10, 3 );
	}

	/**
	 * Delay post creation until payment is complete.
	 */
	public function delay_post( $is_disabled, $form, $entry ) {
		$feed            = $this->get_payment_feed( $entry );
		$submission_data = $this->get_submission_data( $feed, $form, $entry );

		if ( ! $feed || empty( $submission_data['payment_amount'] ) ) {
			return $is_disabled;
		}
		return ! rgempty( 'delayPost', $feed['meta'] );
	}

	/**
	 * Initialize admin functionality.
	 */
	public function init_admin() {
		parent::init_admin();
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
	}

	/**
	 * Enqueue admin scripts.
	 */
	public function enqueue_admin_scripts( $hook ) {
		if ( false === strpos( $hook, 'gf_settings' ) ) {
			return;
		}

		if ( ! isset( $_GET['subview'] ) || $this->_slug !== $_GET['subview'] ) {
			return;
		}

		wp_enqueue_script(
			'gf-checkout-com-pro-admin',
			GF_CHECKOUT_COM_PRO_URL . 'assets/js/admin-settings.js',
			array( 'jquery' ),
			GF_CHECKOUT_COM_PRO_VERSION,
			true
		);
	}

	/**
	 * Validate plugin settings.
	 */
	public function plugin_settings_validation( $settings ) {
		$mode = rgar( $settings, 'mode', 'test' );

		$required_fields = array(
			$mode . '_public_key',
			$mode . '_secret_key',
			$mode . '_processing_channel_id',
			$mode . '_webhook_secret',
		);

		foreach ( $required_fields as $field ) {
			if ( empty( $settings[ $field ] ) ) {
				$this->set_field_error( $field, esc_html__( 'This field is required.', 'gravityforms-checkout-com-pro' ) );
			}
		}

		return $settings;
	}

	/**
	 * Get payment method for feed.
	 */
	public function get_payment_method( $feed ) {
		$feed_method = rgars( $feed, 'meta/payment_method' );

		if ( ! empty( $feed_method ) ) {
			return $feed_method;
		}

		$settings       = $this->get_plugin_settings();
		$default_method = rgar( $settings, 'default_payment_method', 'frame' );
		return $default_method;
	}





	/**
	 * Get 3DS setting for feed.
	 */
	public function get_3ds_setting( $feed ) {
		$feed_3ds = rgars( $feed, 'meta/enable_3ds' );

		// If feed has specific setting, use it
		if ( '' !== $feed_3ds ) {
			return '1' === $feed_3ds;
		}

		// Otherwise use global setting
		$settings = $this->get_plugin_settings();
		return '1' === rgar( $settings, 'enable_3ds' );
	}

	/**
	 * Get API client instance.
	 */
	public function get_api_client( $feed = null ) {
		if ( null === $this->api_client ) {
			$settings         = $this->get_api_settings( $feed );
			$this->api_client = new Checkout_Com_API_Client( $settings );
		}
		return $this->api_client;
	}

	/**
	 * Redirect URL for payment processing (EXACT copy from working plugin).
	 */
	public function redirect_url( $feed, $submission_data, $form, $entry ) {
		// Prepare payment amount
		$payment_amount = rgar( $submission_data, 'payment_amount' );

		// Updating lead's payment_status to Processing
		GFAPI::update_entry_property( $entry['id'], 'payment_status', 'Processing' );
		GFAPI::update_entry_property( $entry['id'], 'payment_amount', $payment_amount );

		$return_url = $this->return_url( $form['id'], $entry['id'] );

		$url = gf_apply_filters( 'gform_checkout_com_pro_request', $form['id'], $return_url, $form, $entry, $feed, $submission_data );

		gform_update_meta( $entry['id'], 'checkout_com_payment_url', $url );
		gform_update_meta( $entry['id'], 'payment_amount', $payment_amount );
		gform_update_meta( $entry['id'], 'submission_data', $submission_data );

		$this->log_debug( __METHOD__ . "(): Sending to Checkout.com paymentbox: {$url}" );

		return $url;
	}

	/**
	 * Get submission data (EXACT copy from working plugin).
	 */
	public function get_submission_data( $feed, $form, $entry ) {
		$submission_data          = parent::get_submission_data( $feed, $form, $entry );
		$submission_data['entry'] = $entry;
		return $submission_data;
	}

	/**
	 * Generate return URL for 3DS redirects.
	 */
	public function return_url( $form_id, $entry_id, $type = false ) {
		// For 3DS redirects, we need the actual form page URL, not admin-ajax.php
		$entry      = GFAPI::get_entry( $entry_id );
		$source_url = rgar( $entry, 'source_url' );

		if ( empty( $source_url ) ) {
			// Fallback to current page if source_url not available
			$pageURL     = GFCommon::is_ssl() ? 'https://' : 'http://';
			$server_port = apply_filters( 'gform_checkout_com_pro_return_url_port', $_SERVER['SERVER_PORT'] );

			if ( false === strpos( $server_port, '80' ) ) {
				$pageURL .= $_SERVER['SERVER_NAME'] . ':' . $server_port . $_SERVER['REQUEST_URI'];
			} else {
				$pageURL .= $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
			}
		} else {
			$pageURL = $source_url;
		}

		if ( 'cancel' === $type ) {
			$url = remove_query_arg( array( 'gf_checkout_com_pro_return' ), $pageURL );
			return apply_filters( 'gform_checkout_com_pro_cancel_url', $url, $form_id, $entry_id );
		}

		$ids_query  = "ids={$form_id}|{$entry_id}";
		$ids_query .= '&hash=' . wp_hash( $ids_query );

		$url = add_query_arg( 'gf_checkout_com_pro_return', $this->base64_encode( $ids_query ), $pageURL );

		return apply_filters( 'gform_checkout_com_pro_return_url', $url, $form_id, $entry_id );
	}

	/**
	 * Base64 encode (URL safe).
	 */
	public function base64_encode( $string ) {
		return str_replace( array( '+', '/', '=' ), array( '-', '_', '' ), base64_encode( $string ) );
	}

	/**
	 * Base64 decode (URL safe).
	 */
	public function base64_decode( $string ) {
		return base64_decode( str_replace( array( '-', '_' ), array( '+', '/' ), $string ) );
	}

	/**
	 * Maybe process payment page (unified for both Frame and Component).
	 */
	public function maybe_process_payment_page() {
		if ( ! isset( $_GET['gf_checkout_com_pro_return'] ) ) {
			return;
		}

		$str = rgget( 'gf_checkout_com_pro_return' );
		$str = $this->base64_decode( $str );
		$this->log_debug( __METHOD__ . '(): Payment return request received. Starting to process.' );

		parse_str( $str, $query );

		if ( $query['hash'] !== wp_hash( 'ids=' . $query['ids'] ) ) {
			$this->log_error( __METHOD__ . '(): Payment return request hash invalid. Aborting.' );
			return;
		}

		list( $form_id, $entry_id ) = explode( '|', $query['ids'] );

		if ( ! $form_id || ! $entry_id ) {
			return;
		}

		$entry = GFAPI::get_entry( $entry_id );
		$form  = GFAPI::get_form( $form_id );

		if ( is_wp_error( $entry ) || ! $form ) {
			$this->log_error( __METHOD__ . '(): Form or Entry not found. Aborting.' );
			return;
		}

		$payment_status = rgar( $entry, 'payment_status' );

		if ( 'Paid' === $payment_status ) {
			$this->log_debug( __METHOD__ . '(): Entry is already marked as Paid. Skipping to confirmation.' );
			// Handle confirmation for already paid entries
			if ( ! class_exists( 'GFFormDisplay' ) ) {
				require_once GFCommon::get_base_path() . '/form_display.php';
			}
			$confirmation = GFFormDisplay::handle_confirmation( $form, $entry, false );
			if ( is_array( $confirmation ) && isset( $confirmation['redirect'] ) ) {
				wp_redirect( $confirmation['redirect'] );
				exit;
			}
			GFFormDisplay::$submission[ $form_id ] = array(
				'is_confirmation'      => true,
				'confirmation_message' => $confirmation,
				'form'                 => $form,
				'lead'                 => $entry,
			);
			return;
		}

		// Handle payment return with token or session
		if ( rgget( 'cko-session-id' ) || rgpost( 'payment_token' ) ) {
			$callback_action = $this->checkout_com_callback( $form, $entry );
			$this->log_debug( __METHOD__ . '(): Result from gateway callback => ' . print_r( $callback_action, true ) );

			if ( is_wp_error( $callback_action ) ) {
				// Hard error occurred
				$this->payment_page_error = $callback_action->get_error_message();
				gform_update_meta( $entry['id'], 'checkout_com_payment_error', $callback_action->get_error_message() );
				$this->is_payment_page_load = true;
				$this->payment_page_form    = $form;
				$this->payment_page_entry   = $entry;
				return;

			} elseif ( is_array( $callback_action ) && 'fail_payment' === rgar( $callback_action, 'type' ) ) {
				// Payment failed gracefully
				$this->payment_page_error = $callback_action['error_message'];
				gform_update_meta( $entry['id'], 'checkout_com_payment_error', $callback_action['error_message'] );
				$this->is_payment_page_load = true;
				$this->payment_page_form    = $form;
				$this->payment_page_entry   = $entry;
				return;

			} elseif ( is_array( $callback_action ) && 'complete_payment' === rgar( $callback_action, 'type' ) ) {
				// Payment successful
				$result = $this->checkout_com_process_callback_action( $callback_action );

				if ( ! is_wp_error( $result ) && $result ) {
					if ( ! class_exists( 'GFFormDisplay' ) ) {
						require_once GFCommon::get_base_path() . '/form_display.php';
					}
					gform_delete_meta( $entry['id'], 'checkout_com_payment_error' );

					$confirmation = GFFormDisplay::handle_confirmation( $form, $entry, false );
					if ( is_array( $confirmation ) && isset( $confirmation['redirect'] ) ) {
						wp_redirect( $confirmation['redirect'] );
						exit;
					}
					GFFormDisplay::$submission[ $form_id ] = array(
						'is_confirmation'      => true,
						'confirmation_message' => $confirmation,
						'form'                 => $form,
						'lead'                 => $entry,
					);
					return;
				}
			}
		}

		// Set flag to render payment page
		if ( ! isset( $callback_action ) || is_wp_error( $callback_action ) || ( is_array( $callback_action ) && 'fail_payment' === rgar( $callback_action, 'type' ) ) ) {
			$this->is_payment_page_load = true;
			$this->payment_page_form    = $form;
			$this->payment_page_entry   = $entry;
		}
	}

	/**
	 * Checkout.com callback processing.
	 */
	public function checkout_com_callback( $form, $entry, $payment_response = array() ) {
		$this->log_debug( __METHOD__ . '(): Processing Checkout.com callback.' );

		$feed = $this->get_payment_feed( $entry );
		if ( ! $feed ) {
			return new WP_Error( 'no_feed', 'No payment feed found for this entry.' );
		}

		// Check for session ID from component method (POST or GET) - support both AJAX and form submission
		$session_id = rgpost( 'cko_session_id' );
		if ( ! $session_id ) {
			$session_id = rgpost( 'session_id' );
		}
		if ( ! $session_id ) {
			$session_id = rgget( 'cko-session-id' );
		}
		if ( ! $session_id ) {
			$session_id = rgget( 'cko-payment-id' ); // 3DS return parameter
		}

		if ( $session_id ) {
			// Component method - verify session with API
			$payment_response = $this->get_payment_details_by_session( $session_id, $feed, $entry );
		} elseif ( rgpost( 'payment_token' ) ) {
			// Frame method - process token
			$payment_response = $this->process_payment( $form, $feed, $entry );
		} else {
			return new WP_Error( 'missing_payment_data', 'No payment session ID or token found.' );
		}

		if ( is_wp_error( $payment_response ) ) {
			return $payment_response;
		}

		// Save session ID if available
		if ( $session_id ) {
			gform_update_meta( $entry['id'], 'checkout_com_session_id', $session_id );
		}

		// Process the callback
		return $this->process_callback( $feed, $entry, $payment_response );
	}

	/**
	 * Get payment details by session ID (component method).
	 */
	public function get_payment_details_by_session( $session_id, $feed, $entry ) {
		$this->log_debug( __METHOD__ . "(): Verifying session: {$session_id}" );
		$api_settings = $this->get_api_settings( $feed );

		$api_url = ( 'test' === $api_settings['mode'] )
			? "https://api.sandbox.checkout.com/payments/{$session_id}"
			: "https://api.checkout.com/payments/{$session_id}";

		$response = wp_remote_get(
			$api_url,
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $api_settings['secret_key'] ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		// Security check: verify amount and currency match
		$entry_amount_cents = $this->get_amount_export( rgar( $entry, 'payment_amount' ), rgar( $entry, 'currency' ) );

		if ( ! isset( $body['amount'] ) || (int) $body['amount'] !== $entry_amount_cents || $body['currency'] !== rgar( $entry, 'currency' ) ) {
			$this->log_error( __METHOD__ . '(): Session verification failed. Amount/currency mismatch.' );
			return new WP_Error( 'validation_error', 'Payment validation failed due to amount mismatch.' );
		}

		$this->log_debug( __METHOD__ . '(): Session verified successfully.' );
		return $body;
	}

	/**
	 * Get 3DS response after authentication.
	 */
	public function get_3ds_response( $feed, $entry ) {
		$this->log_debug( 'Checkout.com Pro: Getting 3DS response for session: ' . rgget( 'cko-session-id' ) );

		$api_settings = $this->get_api_settings( $feed );

		// Getting 3ds response
		$checkout_url = ( 'test' === rgar( $api_settings, 'mode' ) ? self::CHECKOUT_COM_URL_TEST : self::CHECKOUT_COM_URL_LIVE ) . rgget( 'cko-session-id' );

		$headers = array(
			'Authorization' => $api_settings['secret_key'],
			'Content-Type'  => 'application/json',
		);

		$this->log_debug( 'Checkout.com Pro: Making 3DS API request to: ' . $checkout_url );

		$response = wp_remote_get(
			$checkout_url,
			array(
				'headers' => $headers,
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log_error( 'Checkout.com Pro: 3DS API request failed: ' . $response->get_error_message() );
			return $response;
		}

		$response_code    = wp_remote_retrieve_response_code( $response );
		$body             = wp_remote_retrieve_body( $response );
		$payment_response = json_decode( $body, true );

		$this->log_debug( 'Checkout.com Pro: 3DS API response code: ' . $response_code );
		$this->log_debug( 'Checkout.com Pro: 3DS API response body: ' . $body );

		if ( 200 !== $response_code ) {
			$error_message = isset( $payment_response['error_type'] ) ? $payment_response['error_type'] : '3DS response failed';
			$this->log_error( 'Checkout.com Pro: 3DS API error: ' . $error_message );
			return new WP_Error( '3ds_error', $error_message );
		}

		$this->log_debug( 'Checkout.com Pro: 3DS response retrieved successfully' );
		return $payment_response;
	}

	/**
	 * Process payment via Checkout.com API.
	 */
	public function process_payment( $form, $feed, $entry ) {
		$submission_data = $this->get_submission_data( $feed, $form, $entry );
		$this->log_debug( __METHOD__ . '(): Processing payment via API.' );

		$payment_token = rgpost( 'payment_token' );
		$this->log_debug( 'Checkout.com Pro: Starting payment processing for entry ' . $entry['id'] );

		if ( empty( $payment_token ) ) {
			$this->log_error( 'Checkout.com Pro: ERROR - No payment token provided' );
			return new WP_Error( 'no_token', 'No payment token provided' );
		}

		try {
			$api_settings = $this->get_api_settings( $feed );

			$headers      = array(
				'Authorization' => $api_settings['secret_key'],
				'Content-Type'  => 'application/json',
			);
			$checkout_url = 'test' === rgar( $api_settings, 'mode' ) ? self::CHECKOUT_COM_URL_TEST : self::CHECKOUT_COM_URL_LIVE;

			$payment_args = array(
				'source'                => array(
					'type'  => 'token',
					'token' => $payment_token,
				),
				'metadata'              => array(
					'form_id'     => $form['id'],
					'entry_id'    => $entry['id'],
					'website_url' => home_url(),
				),
				'amount'                => $this->get_amount_export( rgar( $entry, 'payment_amount' ), rgar( $entry, 'currency' ) ),
				'currency'              => rgar( $entry, 'currency' ),
				'reference'             => rgar( $submission_data, 'reference' ) ? rgar( $submission_data, 'reference' ) : uniqid(),
				'description'           => rgar( $submission_data, 'order_summary' ) ? rgar( $submission_data, 'order_summary' ) : $form['title'],
				'capture'               => ! rgars( $feed, 'meta/auth_only' ) ? true : false,
				'success_url'           => $this->return_url( $form['id'], $entry['id'] ),
				'failure_url'           => $this->return_url( $form['id'], $entry['id'] ),
				'payment_ip'            => GFFormsModel::get_ip(),
				'processing_channel_id' => $api_settings['processing_channel_id'],
			);

			$this->log_debug( 'Checkout.com Pro: Payment amount: ' . $payment_args['amount'] . ' ' . $payment_args['currency'] );

			// Add 3DS configuration if enabled
			if ( $this->get_3ds_setting( $feed ) ) {
				$payment_args['3ds'] = array(
					'enabled'     => true,
					'attempt_n3d' => true,
					'version'     => '2.0.1',
				);
			}

			// Add customer data if available
			if ( rgar( $submission_data, 'firstName' ) || rgar( $submission_data, 'lastName' ) ) {
				$payment_args['customer']['name'] = trim( rgar( $submission_data, 'firstName' ) . ' ' . rgar( $submission_data, 'lastName' ) );
			}
			
			/**
			 * Filter the payment arguments before sending to Checkout.com.
			 *
			 * @since 1.2.0
			 *
			 * @param array $payment_args The payment arguments array.
			 * @param array $form         The Form Object.
			 * @param array $entry        The Entry Object.
			 * @param array $feed         The Feed Object.
			 */
			$payment_args = apply_filters( 'gform_checkout_payment_args', $payment_args, $form, $entry, $feed );
			if ( rgar( $submission_data, 'email' ) ) {
				$payment_args['customer']['email'] = rgar( $submission_data, 'email' );
			}

			$response = wp_remote_post(
				$checkout_url,
				array(
					'headers' => $headers,
					'body'    => wp_json_encode( $payment_args ),
					'timeout' => 30,
				)
			);

			if ( is_wp_error( $response ) ) {
				$this->log_error( 'Checkout.com Pro: API request failed: ' . $response->get_error_message() );
				return $response;
			}

			$response_code    = wp_remote_retrieve_response_code( $response );
			$body             = wp_remote_retrieve_body( $response );
			$payment_response = json_decode( $body, true );

			if ( 200 !== $response_code && 201 !== $response_code && 202 !== $response_code ) {
				$error_message = isset( $payment_response['error_type'] ) ? $payment_response['error_type'] : 'Payment processing failed';
				if ( isset( $payment_response['error_codes'] ) ) {
					$error_message .= ' - ' . implode( ', ', $payment_response['error_codes'] );
				}
				$this->log_error( 'Checkout.com Pro: API error: ' . $error_message );
				return new WP_Error( 'api_error', $error_message );
			}

			// Check if this is a 3DS redirect response
			if ( isset( $payment_response['3ds']['is_redirect'] ) && true === $payment_response['3ds']['is_redirect'] && isset( $payment_response['_links']['redirect']['href'] ) ) {
				$this->log_debug( 'Checkout.com Pro: 3DS redirect required, redirecting user to authentication' );
				$redirect_url = $payment_response['_links']['redirect']['href'];

				// Store transaction ID for when user returns from 3DS
				if ( isset( $payment_response['id'] ) ) {
					GFAPI::update_entry_property( $entry['id'], 'transaction_id', $payment_response['id'] );
				}

				wp_redirect( $redirect_url );
				exit;
			}

			$this->log_debug( 'Checkout.com Pro: Payment processed successfully. Payment ID: ' . rgar( $payment_response, 'id' ) );

			$this->log_debug( __METHOD__ . '(): Payment processed successfully.' );
			return $payment_response;

		} catch ( Exception $e ) {
			$this->log_error( __METHOD__ . '(): Exception: ' . $e->getMessage() );
			return new WP_Error( 'exception', $e->getMessage() );
		}
	}

	/**
	 * Maybe render payment page (EXACT copy from working plugin).
	 */
	public function maybe_render_payment_page( $content ) {
		// Only run if our flag is set and we are in the main WordPress loop.
		if ( ! $this->is_payment_page_load || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		// Check that we have the necessary data.
		if ( ! $this->payment_page_form || ! $this->payment_page_entry ) {
			return $content;
		}

		// Reset the flag so this logic doesn't run on subsequent 'the_content' calls on the same page.
		$this->is_payment_page_load = false;

		// Return the payment box HTML, which will replace the original page content.
		return $this->checkout_com_paymentbox( $this->payment_page_form, $this->payment_page_entry );
	}

	/**
	 * Checkout.com payment box (EXACT copy from working plugin structure).
	 */
	public function checkout_com_paymentbox( $form, $entry ) {
		$feed           = $this->get_payment_feed( $entry );
		$payment_method = $this->get_payment_method( $feed );

		if ( $payment_method === 'frame' ) {
			$this->frame_handler->enqueue_scripts( $feed, $form, $entry );
			return $this->frame_handler->render_payment_form( $form, $entry, $feed );
		} else {
			$this->component_handler->enqueue_scripts( $feed, $form, $entry );
			return $this->component_handler->render_payment_form( $form, $entry, $feed );
		}
	}

	/**
	 * Process payment callback (when token is received).
	 */
	/**
	 * Process callback from Checkout.com.
	 */
	public function process_callback( $feed, $entry, $payment_response ) {
		$this->log_debug( 'Checkout.com Pro: CALLBACK - process_callback called for entry ' . $entry['id'] );
		$this->log_debug( 'Checkout.com Pro: CALLBACK - Payment response: ' . wp_json_encode( $payment_response ) );

		$amount = rgar( $entry, 'payment_amount' );

		$status         = rgar( $payment_response, 'status' );
		$transaction_id = rgar( $payment_response, 'id' );
		$response_code  = rgar( $payment_response, 'response_code' );
		$reference      = rgar( $payment_response, 'reference' );

		$this->log_debug( 'Checkout.com Pro: CALLBACK - Status: ' . $status . ', Transaction ID: ' . $transaction_id . ', Response Code: ' . $response_code );

		$action = array();

		// Extract response summary from payment response (prioritize response_summary)
		$response_summary = '';
		if ( isset( $payment_response['response_summary'] ) && ! empty( $payment_response['response_summary'] ) ) {
			$response_summary = $payment_response['response_summary'];
		} elseif ( isset( $payment_response['actions'][0]['response_summary'] ) ) {
			$response_summary = $payment_response['actions'][0]['response_summary'];
		} elseif ( isset( $payment_response['processing']['partner_response_code'] ) ) {
			// Convert partner response code to user-friendly message
			$partner_code     = $payment_response['processing']['partner_response_code'];
			$response_summary = $this->get_friendly_error_message( $partner_code );
		}

		switch ( strtolower( $status ) ) {

			case 'authorized':
			case 'captured':
			case 'paid':
			case 'card verified':
				$action['id']               = $transaction_id . '_' . $reference;
				$action['type']             = 'complete_payment';
				$action['transaction_id']   = $transaction_id;
				$action['amount']           = $amount;
				$action['entry_id']         = $entry['id'];
				$action['payment_date']     = gmdate( 'y-m-d H:i:s' );
				$action['payment_method']   = 'checkout-com-pro';
				$action['ready_to_fulfill'] = ! $entry['is_fulfilled'] ? true : false;

				$this->log_debug( 'Checkout.com Pro: SUCCESS - Payment completed for entry ' . $entry['id'] . ', Transaction ID: ' . $transaction_id );
				return $action;

			case 'declined':
			case 'canceled':
				// Use the response_summary we extracted earlier, or get from payment_response if not available
				if ( empty( $response_summary ) ) {
					$response_code    = rgar( $payment_response, 'response_code' );
					$response_summary = $this->get_error_message( $response_code, rgar( $payment_response, 'response_summary' ) );
				}

				$action['id']             = $transaction_id;
				$action['type']           = 'fail_payment';
				$action['transaction_id'] = $transaction_id;
				$action['entry_id']       = $entry['id'];
				$action['amount']         = $amount;
				$amount_formatted         = GFCommon::to_money( $action['amount'], $entry['currency'] );
				$action['note']           = sprintf( __( 'Payment failed. Amount: %1$s. Transaction ID: %2$s. Reason: %3$s', 'gravityforms-checkout-com-pro' ), $amount_formatted, $transaction_id, $response_summary );
				$action['error_message']  = sprintf( __( 'Payment failed. Reason: %s Please try again.', 'gravityforms-checkout-com-pro' ), $response_summary );

				$this->log_error( 'Checkout.com Pro: FAILED - Payment failed for entry ' . $entry['id'] . ', Transaction ID: ' . $transaction_id . ', Reason: ' . $response_summary );
				return $action;

			case 'pending':
				$action['id']             = $transaction_id . '_' . $reference;
				$action['type']           = 'add_pending_payment';
				$action['transaction_id'] = $transaction_id;
				$action['amount']         = $amount;
				$action['entry_id']       = $entry['id'];
				$amount_formatted         = GFCommon::to_money( $action['amount'], $entry['currency'] );
				$action['note']           = sprintf( __( 'Payment is pending. Amount: %1$s. Transaction ID: %2$s.', 'gravityforms-checkout-com-pro' ), $amount_formatted, $action['transaction_id'] );
				$action['error_message']  = __( 'Your payment is currently pending, it will be updated in our system when we received a confirmation from our processor.', 'gravityforms-checkout-com-pro' );

				$this->log_debug( 'Checkout.com Pro: PENDING - Payment pending for entry ' . $entry['id'] . ', Transaction ID: ' . $transaction_id );
				return $action;

			default:
				$this->log_error( 'Checkout.com Pro: UNKNOWN - Unhandled payment status: ' . $status . ' for entry ' . $entry['id'] . ', Transaction ID: ' . $transaction_id );
				return false;
		}
	}

	/**
	 * Get error message based on response code (official Checkout.com codes).
	 */
	private function get_error_message( $response_code, $response_summary ) {
		$error_messages = array(
			// Most common decline codes
			'20005' => 'Declined - Do not honour',
			'20014' => 'Invalid account number (no such number)',
			'20051' => 'Insufficient funds',
			'20054' => 'Expired card',
			'20057' => 'Transaction not permitted to cardholder',
			'20059' => 'Suspected fraud',
			'20061' => 'Activity amount limit exceeded',
			'20062' => 'Restricted card',
			'20065' => 'Exceeds withdrawal frequency limit',
			'20087' => 'Bad track data (invalid CVV and/or expiry date)',

			// 3DS specific codes
			'20150' => 'Card not 3D Secure (3DS) enabled',
			'20151' => 'Cardholder failed 3D-Secure authentication',
			'20152' => 'Initial 3DS transaction not completed within 15 minutes',
			'20153' => '3DS system malfunction',
			'20154' => '3DS authentication required',

			// Technical/system errors
			'20068' => 'Response received too late / Timeout',
			'20091' => 'Issuer unavailable or switch is inoperative',
			'20096' => 'System malfunction',

			// Hard declines (30xxx)
			'30004' => 'Pick up card (No fraud)',
			'30007' => 'Pick up card - Special conditions',
			'30015' => 'No such issuer',
		);

		return isset( $error_messages[ $response_code ] ) ? $error_messages[ $response_code ] : $response_summary;
	}

	/**
	 * Get user-friendly error message from partner response code.
	 * Partner response codes are 2-digit codes from the card issuer.
	 *
	 * @param string $code Partner response code (e.g., "51", "05", "14").
	 * @return string User-friendly error message.
	 */
	private function get_friendly_error_message( $code ) {
		// Map of partner response codes to user-friendly messages
		// These are standard ISO 8583 response codes used by card networks
		$error_messages = array(
			// Common decline codes
			'00' => 'Approved',
			'01' => 'Refer to card issuer',
			'03' => 'Invalid merchant',
			'04' => 'Pick up card',
			'05' => 'Do not honor',
			'12' => 'Invalid transaction',
			'13' => 'Invalid amount',
			'14' => 'Invalid card number',
			'15' => 'Invalid issuer',
			'30' => 'Format error',
			'41' => 'Lost card - pick up',
			'43' => 'Stolen card - pick up',
			'51' => 'Insufficient funds',
			'54' => 'Expired card',
			'55' => 'Incorrect PIN',
			'57' => 'Transaction not permitted to cardholder',
			'58' => 'Transaction not permitted to terminal',
			'59' => 'Suspected fraud',
			'61' => 'Exceeds withdrawal amount limit',
			'62' => 'Restricted card',
			'63' => 'Security violation',
			'65' => 'Exceeds withdrawal frequency limit',
			'75' => 'PIN tries exceeded',
			'76' => 'Invalid/nonexistent account',
			'78' => 'Invalid/nonexistent account',
			'82' => 'Negative CAM, dCVV, iCVV, or CVV results',
			'83' => 'Unable to verify PIN',
			'85' => 'No reason to decline',
			'91' => 'Issuer or switch is inoperative',
			'92' => 'Unable to route transaction',
			'93' => 'Transaction cannot be completed - violation of law',
			'94' => 'Duplicate transmission',
			'96' => 'System malfunction',
		);

		// Return friendly message if code exists, otherwise return "Payment declined (code: XX)"
		if ( isset( $error_messages[ $code ] ) ) {
			return $error_messages[ $code ];
		}

		// If no mapping found, return the code with a generic message
		return sprintf( 'Payment declined (code: %s)', $code );
	}


	/**
	 * Process callback action (renamed to match working plugin).
	 */
	public function checkout_com_process_callback_action( $action ) {
		$this->log_debug( __METHOD__ . '(): Processing callback action.' );
		$action = wp_parse_args(
			$action,
			array(
				'type'             => false,
				'amount'           => false,
				'transaction_type' => false,
				'transaction_id'   => false,
				'entry_id'         => false,
				'payment_status'   => false,
				'note'             => false,
			)
		);

		$result = false;

		if ( rgar( $action, 'id' ) && $this->is_duplicate_callback( $action['id'] ) ) {
			return new WP_Error( 'duplicate', sprintf( esc_html__( 'This callback has already been processed (Event Id: %s)', 'gravityforms' ), $action['id'] ) );
		}

		$entry = GFAPI::get_entry( $action['entry_id'] );
		if ( ! $entry || is_wp_error( $entry ) ) {
			return $result;
		}

		/**
		 * Performs actions before the the payment action callback is processed.
		 *
		 * @since Unknown
		 *
		 * @param array $action The action array.
		 * @param array $entry  The Entry Object.
		 */
		do_action( 'gform_action_pre_payment_callback', $action, $entry );
		if ( has_filter( 'gform_action_pre_payment_callback' ) ) {
			$this->log_debug( __METHOD__ . '(): Executing functions hooked to gform_action_pre_payment_callback.' );
		}
		
		// Re-enabled for centralized Webhook & Direct processing
		switch ( $action['type'] ) {
			case 'complete_payment':
				// check already completed or not.
				if ( 'Paid' === rgar( $entry, 'payment_status' ) ) {
					$this->log_debug( 'Checkout.com Pro: ACTION - Payment already completed for entry ' . $entry['id'] . '. Skipping.' );
					$this->log_debug( __METHOD__ . '(): Payment already completed. Skipping.' );
					break;
				}
				$this->log_debug( 'Checkout.com Pro: ACTION - Processing complete_payment for entry ' . $entry['id'] );
				$result = $this->complete_payment( $entry, $action );
				break;
			case 'fail_payment':
				// Store transaction ID manually (Gravity Forms doesn't do it for failed payments).
				if ( rgar( $action, 'transaction_id' ) ) {
					GFAPI::update_entry_property( $action['entry_id'], 'transaction_id', rgar( $action, 'transaction_id' ) );
				}

				// Store transaction ID manually (Gravity Forms doesn't do it for failed payments).
				if ( rgar( $action, 'transaction_id' ) ) {
					GFAPI::update_entry_property( $action['entry_id'], 'transaction_id', rgar( $action, 'transaction_id' ) );
					$this->log_debug( 'Checkout.com Pro: ACTION - Updated transaction ID: ' . rgar( $action, 'transaction_id' ) );
				}

				// Update payment status to Failed (this also adds the note automatically).
				$result = $this->fail_payment( $entry, $action );
				$this->log_debug( 'Checkout.com Pro: ACTION - fail_payment result: ' . ( $result ? 'SUCCESS' : 'FAILED' ) );
				break;
			case 'add_pending_payment':
				// Prevent duplicate pending payment processing.
				if ( 'Processing' === rgar( $entry, 'payment_status' ) || 'Pending' === rgar( $entry, 'payment_status' ) ) {
					$this->log_debug( 'Checkout.com Pro: ACTION - Payment already pending for entry ' . $entry['id'] . '. Skipping.' );
					$this->log_debug( __METHOD__ . '(): Payment already pending. Skipping.' );
					break;
				}

				$this->log_debug( 'Checkout.com Pro: ACTION - Processing add_pending_payment for entry ' . $entry['id'] );

				// Store transaction ID since add_pending_payment() doesn't do it automatically
				if ( rgar( $action, 'transaction_id' ) ) {
					GFAPI::update_entry_property( $action['entry_id'], 'transaction_id', rgar( $action, 'transaction_id' ) );
					$this->log_debug( 'Checkout.com Pro: ACTION - Stored transaction ID for pending payment: ' . rgar( $action, 'transaction_id' ) );
				}

				$result = $this->add_pending_payment( $entry, $action );
				$this->log_debug( 'Checkout.com Pro: ACTION - add_pending_payment result: ' . ( $result ? 'SUCCESS' : 'FAILED' ) );
				break;
			default:
				$this->log_error( 'Checkout.com Pro: ACTION - Unknown action type: ' . rgar( $action, 'type' ) );
				// Handle custom events.
				if ( is_callable( array( $this, rgar( $action, 'callback' ) ) ) ) {
					$result = call_user_func_array( array( $this, $action['callback'] ), array( $entry, $action ) );
				}
				break;
		}

		if ( rgar( $action, 'id' ) && $result ) {
			$this->register_callback( $action['id'], $action['entry_id'] );
		}

		/**
		 * Fires right after the payment callback.
		 *
		 * @since Unknown
		 *
		 * @param array $entry The Entry Object
		 * @param array $action {
		 *     The action performed.
		 *
		 *     @type string $type             The callback action type. Required.
		 *     @type string $transaction_id   The transaction ID to perform the action on. Required if the action is a payment.
		 *     @type string $amount           The transaction amount. Typically required.
		 *     @type int    $entry_id         The ID of the entry associated with the action. Typically required.
		 *     @type string $transaction_type The transaction type to process this action as. Optional.
		 *     @type string $payment_status   The payment status to set the payment to. Optional.
		 *     @type string $note             The note to associate with this payment action. Optional.
		 * }
		 * @param mixed $result The Result Object.
		 */
		do_action( 'gform_post_payment_callback', $entry, $action, $result );

		if ( has_filter( 'gform_post_payment_callback' ) ) {
			$this->log_debug( __METHOD__ . '(): Executing functions hooked to gform_post_payment_callback.' );
		}

		return $result;
	}

	/**
	 * Plugin settings fields.
	 */
	public function plugin_settings_fields() {
		return array(
			array(
				'title'  => esc_html__( 'Checkout.com Pro Settings', 'gravityforms-checkout-com-pro' ),
				'fields' => array(
					array(
						'name'          => 'mode',
						'label'         => esc_html__( 'Mode', 'gravityforms-checkout-com-pro' ),
						'type'          => 'radio',
						'choices'       => array(
							array(
								'label' => esc_html__( 'Test', 'gravityforms-checkout-com-pro' ),
								'value' => 'test',
							),
							array(
								'label' => esc_html__( 'Live', 'gravityforms-checkout-com-pro' ),
								'value' => 'live',
							),
						),
						'default_value' => 'test',
						'tooltip'       => esc_html__( 'Select Test for testing or Live for production.', 'gravityforms-checkout-com-pro' ),
					),
					array(
						'name'    => 'test_public_key',
						'label'   => esc_html__( 'Test Public Key', 'gravityforms-checkout-com-pro' ),
						'type'    => 'text',
						'class'   => 'medium',
						'tooltip' => esc_html__( 'Enter your Checkout.com test public key.', 'gravityforms-checkout-com-pro' ),
					),
					array(
						'name'       => 'test_secret_key',
						'label'      => esc_html__( 'Test Secret Key', 'gravityforms-checkout-com-pro' ),
						'type'       => 'text',
						'input_type' => 'password',
						'class'      => 'medium',
						'tooltip'    => esc_html__( 'Enter your Checkout.com test secret key.', 'gravityforms-checkout-com-pro' ),
					),
					array(
						'name'    => 'test_processing_channel_id',
						'label'   => esc_html__( 'Test Processing Channel ID', 'gravityforms-checkout-com-pro' ),
						'type'    => 'text',
						'class'   => 'medium',
						'tooltip' => esc_html__( 'Enter your Checkout.com test processing channel ID.', 'gravityforms-checkout-com-pro' ),
					),
					array(
						'name'       => 'test_webhook_secret',
						'label'      => esc_html__( 'Test Webhook Secret Key', 'gravityforms-checkout-com-pro' ),
						'type'       => 'text',
						'input_type' => 'password',
						'class'      => 'medium',
						'tooltip'    => esc_html__( 'Enter your Checkout.com test webhook secret key.', 'gravityforms-checkout-com-pro' ),
					),
					array(
						'name'    => 'live_public_key',
						'label'   => esc_html__( 'Live Public Key', 'gravityforms-checkout-com-pro' ),
						'type'    => 'text',
						'class'   => 'medium',
						'tooltip' => esc_html__( 'Enter your Checkout.com live public key.', 'gravityforms-checkout-com-pro' ),
					),
					array(
						'name'       => 'live_secret_key',
						'label'      => esc_html__( 'Live Secret Key', 'gravityforms-checkout-com-pro' ),
						'type'       => 'text',
						'input_type' => 'password',
						'class'      => 'medium',
						'tooltip'    => esc_html__( 'Enter your Checkout.com live secret key.', 'gravityforms-checkout-com-pro' ),
					),
					array(
						'name'    => 'live_processing_channel_id',
						'label'   => esc_html__( 'Live Processing Channel ID', 'gravityforms-checkout-com-pro' ),
						'type'    => 'text',
						'class'   => 'medium',
						'tooltip' => esc_html__( 'Enter your Checkout.com live processing channel ID.', 'gravityforms-checkout-com-pro' ),
					),
					array(
						'name'       => 'live_webhook_secret',
						'label'      => esc_html__( 'Live Webhook Secret Key', 'gravityforms-checkout-com-pro' ),
						'type'       => 'text',
						'input_type' => 'password',
						'class'      => 'medium',
						'tooltip'    => esc_html__( 'Enter your Checkout.com live webhook secret key.', 'gravityforms-checkout-com-pro' ),
					),
					array(
						'name'          => 'webhook_url_display',
						'label'         => esc_html__( 'Webhook URL', 'gravityforms-checkout-com-pro' ),
						'type'          => 'text',
						'class'         => 'large',
						'default_value' => Checkout_Com_Webhook_Handler::get_webhook_url(),
						'readonly'      => true,
						'tooltip'       => esc_html__( 'Copy this URL to your Checkout.com webhook configuration.', 'gravityforms-checkout-com-pro' ),
					),
					array(
						'name'          => 'default_payment_method',
						'label'         => esc_html__( 'Default Payment Method', 'gravityforms-checkout-com-pro' ),
						'type'          => 'radio',
						'choices'       => array(
							array(
								'label' => esc_html__( 'Checkout Frame', 'gravityforms-checkout-com-pro' ),
								'value' => 'frame',
							),
							array(
								'label' => esc_html__( 'Checkout Component', 'gravityforms-checkout-com-pro' ),
								'value' => 'component',
							),
						),
						'default_value' => 'frame',
						'tooltip'       => esc_html__( 'Select the default payment method for all forms. This can be overridden per form.', 'gravityforms-checkout-com-pro' ),
					),
					array(
						'name'    => 'enable_3ds',
						'label'   => esc_html__( '3D Secure', 'gravityforms-checkout-com-pro' ),
						'type'    => 'checkbox',
						'choices' => array(
							array(
								'label' => esc_html__( 'Enable 3D Secure authentication by default', 'gravityforms-checkout-com-pro' ),
								'name'  => 'enable_3ds',
							),
						),
						'tooltip' => esc_html__( 'Enable 3D Secure authentication for enhanced security. This can be overridden per form.', 'gravityforms-checkout-com-pro' ),
					),
					array(
						'name'    => 'disable_css',
						'label'   => esc_html__( 'Frontend Styling', 'gravityforms-checkout-com-pro' ),
						'type'    => 'checkbox',
						'choices' => array(
							array(
								'label' => esc_html__( 'Disable default CSS', 'gravityforms-checkout-com-pro' ),
								'name'  => 'disable_css',
							),
						),
						'tooltip' => esc_html__( 'Check this box to prevent the plugin from loading its default CSS files. Use this if you want to style the payment form entirely with your own theme.', 'gravityforms-checkout-com-pro' ),
					),
				),
			),
		);
	}

	/**
	 * Validate plugin settings.
	 */
	public function is_valid_plugin_settings() {
		$settings = $this->get_plugin_settings();
		$mode     = rgar( $settings, 'mode', 'test' );

		return $this->is_setting_valid( rgar( $settings, $mode . '_secret_key' ) ) &&
				$this->is_setting_valid( rgar( $settings, $mode . '_public_key' ) );
	}

	/**
	 * Validate setting value.
	 */
	public function is_setting_valid( $value ) {
		return ! empty( $value );
	}

	/**
	 * Check if feeds can be created.
	 */
	public function can_create_feed() {
		return $this->is_valid_plugin_settings();
	}

	/**
	 * Get API settings.
	 */
	/**
	 * Get current payment page error message.
	 */
	public function get_payment_page_error() {
		return $this->payment_page_error;
	}

	public function get_api_settings( $feed = false ) {
		$feed = false === $feed ? $this->current_feed : $feed;

		// Use feed-specific settings if enabled, otherwise use plugin settings
		if ( rgars( $feed, 'meta/apiSettingsEnabled' ) ) {
			return array(
				'secret_key'            => rgars( $feed, 'meta/overrideSecretKey' ),
				'public_key'            => rgars( $feed, 'meta/overridePublicKey' ),
				'processing_channel_id' => rgars( $feed, 'meta/overrideProcessingChannelId' ),
				'webhook_secret_key'    => rgars( $feed, 'meta/overrideWebhookSecretKey' ),
				'mode'                  => rgars( $feed, 'meta/overrideMode' ),
				'payment_method'        => rgars( $feed, 'meta/paymentMethod' ),
			);
		} else {
			$settings = $this->get_plugin_settings();
			$mode     = rgar( $settings, 'mode', 'test' );

			return array(
				'secret_key'            => rgar( $settings, $mode . '_secret_key' ),
				'public_key'            => rgar( $settings, $mode . '_public_key' ),
				'processing_channel_id' => rgar( $settings, $mode . '_processing_channel_id' ),
				'webhook_secret_key'    => rgar( $settings, $mode . '_webhook_secret' ),
				'mode'                  => $mode,
				'payment_method'        => rgar( $settings, 'default_payment_method' ),
			);
		}
	}

	/**
	 * Add note to entry (like working plugin).
	 */
	public function add_note( $entry_id, $note, $note_type = 'success' ) {
		GFAPI::add_note( $entry_id, 0, 'Checkout.com Pro', $note, 'GFCheckoutComPro', $note_type );
		return true;
	}

	/**
	 * Add supported notification events.
	 */
	public function supported_notification_events( $form ) {
		if ( ! $this->has_feed( $form['id'] ) ) {
			return array();
		}
		return array(
			'complete_payment'    => esc_html__( 'Payment Completed', 'gravityforms-checkout-com-pro' ),
			'fail_payment'        => esc_html__( 'Payment Failed', 'gravityforms-checkout-com-pro' ),
			'add_pending_payment' => esc_html__( 'Pending Payment Added', 'gravityforms-checkout-com-pro' ),
		);
	}

	/**
	 * Feed settings fields.
	 */


	public function feed_settings_fields() {
		$default_settings = parent::feed_settings_fields();

		$payment_method_field = array(
			'name'          => 'payment_method',
			'label'         => esc_html__( 'Payment Method', 'gravityforms-checkout-com-pro' ),
			'type'          => 'radio',
			'choices'       => array(
				array(
					'label' => esc_html__( 'Use Global Default', 'gravityforms-checkout-com-pro' ),
					'value' => '',
				),
				array(
					'label' => esc_html__( 'Checkout Frame', 'gravityforms-checkout-com-pro' ),
					'value' => 'frame',
				),
				array(
					'label' => esc_html__( 'Checkout Component', 'gravityforms-checkout-com-pro' ),
					'value' => 'component',
				),
			),
			'default_value' => '',
			'tooltip'       => esc_html__( 'Override the global payment method for this form.', 'gravityforms-checkout-com-pro' ),
		);

		$enable_3ds_field = array(
			'name'          => 'enable_3ds',
			'label'         => esc_html__( '3D Secure', 'gravityforms-checkout-com-pro' ),
			'type'          => 'radio',
			'choices'       => array(
				array(
					'label' => esc_html__( 'Use Global Default', 'gravityforms-checkout-com-pro' ),
					'value' => '',
				),
				array(
					'label' => esc_html__( 'Enable', 'gravityforms-checkout-com-pro' ),
					'value' => '1',
				),
				array(
					'label' => esc_html__( 'Disable', 'gravityforms-checkout-com-pro' ),
					'value' => '0',
				),
			),
			'default_value' => '',
			'tooltip'       => esc_html__( 'Override the global 3D Secure setting for this form.', 'gravityforms-checkout-com-pro' ),
		);



		$api_settings_field = array(
			'name'    => 'apiSettingsEnabled',
			'label'   => esc_html__( 'Override API Settings', 'gravityforms-checkout-com-pro' ),
			'type'    => 'checkbox',
			'choices' => array(
				array(
					'label' => esc_html__( 'Use custom API settings for this feed', 'gravityforms-checkout-com-pro' ),
					'name'  => 'apiSettingsEnabled',
				),
			),
			'tooltip' => esc_html__( 'Enable to use different API settings for this specific form.', 'gravityforms-checkout-com-pro' ),
		);

		$override_settings = array(
			array(
				'name'          => 'overrideMode',
				'label'         => esc_html__( 'Mode', 'gravityforms-checkout-com-pro' ),
				'type'          => 'radio',
				'choices'       => array(
					array(
						'label' => esc_html__( 'Live', 'gravityforms-checkout-com-pro' ),
						'value' => 'production',
					),
					array(
						'label' => esc_html__( 'Sandbox', 'gravityforms-checkout-com-pro' ),
						'value' => 'test',
					),
				),
				'default_value' => 'test',
				'dependency'    => array(
					'field'  => 'apiSettingsEnabled',
					'values' => array( '1' ),
				),
			),
			array(
				'name'       => 'overrideSecretKey',
				'label'      => esc_html__( 'Secret Key', 'gravityforms-checkout-com-pro' ),
				'type'       => 'text',
				'input_type' => 'password',
				'class'      => 'medium',
				'dependency' => array(
					'field'  => 'apiSettingsEnabled',
					'values' => array( '1' ),
				),
			),
			array(
				'name'       => 'overridePublicKey',
				'label'      => esc_html__( 'Public Key', 'gravityforms-checkout-com-pro' ),
				'type'       => 'text',
				'class'      => 'medium',
				'dependency' => array(
					'field'  => 'apiSettingsEnabled',
					'values' => array( '1' ),
				),
			),
			array(
				'name'       => 'overrideProcessingChannelId',
				'label'      => esc_html__( 'Processing Channel ID', 'gravityforms-checkout-com-pro' ),
				'type'       => 'text',
				'class'      => 'medium',
				'dependency' => array(
					'field'  => 'apiSettingsEnabled',
					'values' => array( '1' ),
				),
			),
			array(
				'name'       => 'overrideWebhookSecretKey',
				'label'      => esc_html__( 'Webhook Secret Key', 'gravityforms-checkout-com-pro' ),
				'type'       => 'text',
				'input_type' => 'password',
				'class'      => 'medium',
				'dependency' => array(
					'field'  => 'apiSettingsEnabled',
					'values' => array( '1' ),
				),
			),
		);

		// Insert payment method field after feed name
		array_splice( $default_settings[0]['fields'], 1, 0, array( $payment_method_field ) );

		// Insert 3DS field after payment method
		array_splice( $default_settings[0]['fields'], 2, 0, array( $enable_3ds_field ) );

		// Add API override section
		$default_settings[] = array(
			'title'  => esc_html__( 'API Settings Override', 'gravityforms-checkout-com-pro' ),
			'fields' => array_merge( array( $api_settings_field ), $override_settings ),
		);

		return apply_filters( 'gform_checkout_com_pro_feed_settings_fields', $default_settings, $this->get_current_form() );
	}
}

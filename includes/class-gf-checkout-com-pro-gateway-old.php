<?php
/**
 * Checkout.com Pro Gateway for Gravity Forms.
 *
 * @package GravityForms_Checkout_Com_Pro
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Include payment addon framework.
GFForms::include_payment_addon_framework();

/**
 * Main gateway class.
 */
class GF_Checkout_Com_Pro_Gateway extends GFPaymentAddOn {

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	protected $_version = GF_CHECKOUT_COM_PRO_VERSION;

	/**
	 * Minimum GF version.
	 *
	 * @var string
	 */
	protected $_min_gravityforms_version = GF_CHECKOUT_COM_PRO_MIN_GF_VERSION;

	/**
	 * Plugin slug.
	 *
	 * @var string
	 */
	protected $_slug = 'checkout-com-pro';

	/**
	 * Plugin path.
	 *
	 * @var string
	 */
	protected $_path = GF_CHECKOUT_COM_PRO_BASENAME;

	/**
	 * Full path.
	 *
	 * @var string
	 */
	protected $_full_path = GF_CHECKOUT_COM_PRO_FILE;

	/**
	 * Plugin URL.
	 *
	 * @var string
	 */
	protected $_url = 'https://wpgateways.com/products/checkout-com-gateway-gravity-forms/';

	/**
	 * Plugin title.
	 *
	 * @var string
	 */
	protected $_title = 'Checkout.com Pro';

	/**
	 * Short title.
	 *
	 * @var string
	 */
	protected $_short_title = 'Checkout.com Pro';

	/**
	 * Requires credit card.
	 *
	 * @var bool
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
	 *
	 * @var string
	 */
	protected $_capabilities_form_settings = 'gravityforms_checkout_com_pro';

	/**
	 * Uninstall capability.
	 *
	 * @var string
	 */
	protected $_capabilities_uninstall = 'gravityforms_checkout_com_pro_uninstall';

	/**
	 * Instance.
	 *
	 * @var GF_Checkout_Com_Pro_Gateway
	 */
	private static $_instance = null;

	/**
	 * API client.
	 *
	 * @var Checkout_Com_API_Client
	 */
	private $api_client = null;

	/**
	 * Webhook handler.
	 *
	 * @var Checkout_Com_Webhook_Handler
	 */
	private $webhook_handler = null;

	/**
	 * Payment handlers.
	 *
	 * @var array
	 */
	private $payment_handlers = array();

	/**
	 * Get instance.
	 *
	 * @return GF_Checkout_Com_Pro_Gateway
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
		// Initialize webhook handler
		if ( null === $this->webhook_handler ) {
			$this->webhook_handler = new Checkout_Com_Webhook_Handler( $this );
		}

		// Initialize payment handlers
		$this->payment_handlers['frame'] = new Checkout_Com_Frame_Handler( $this );
		$this->payment_handlers['component'] = new Checkout_Com_Component_Handler( $this );
	}

	/**
	 * Initialize addon.
	 */
	public function init() {
		parent::init();
		
		// Handle payment page requests
		add_action( 'wp', array( $this, 'maybe_process_payment_page' ), 5 );
		
		// Handle AJAX payment processing
		add_action( 'wp_ajax_gf_checkout_com_process_payment', array( $this, 'ajax_process_payment' ) );
		add_action( 'wp_ajax_nopriv_gf_checkout_com_process_payment', array( $this, 'ajax_process_payment' ) );
	}

	/**
	 * Enqueue admin scripts and styles.
	 */
	public function enqueue_admin_scripts( $hook ) {
		// Only load on Gravity Forms settings pages
		if ( strpos( $hook, 'gf_settings' ) === false ) {
			return;
		}

		// Check if we're on our addon's settings page
		if ( ! isset( $_GET['subview'] ) || $_GET['subview'] !== $this->_slug ) {
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
	 * Plugin settings fields.
	 *
	 * @return array
	 */
	public function plugin_settings_fields() {
		return array(
			array(
				'title'  => esc_html__( 'Checkout.com Pro Settings', 'gravityforms-checkout-com-pro' ),
				'fields' => array(
					array(
						'name'    => 'mode',
						'label'   => esc_html__( 'Mode', 'gravityforms-checkout-com-pro' ),
						'type'    => 'radio',
						'choices' => array(
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
						'name'       => 'test_processing_channel_id',
						'label'      => esc_html__( 'Test Processing Channel ID', 'gravityforms-checkout-com-pro' ),
						'type'       => 'text',
						'class'      => 'medium',
						'tooltip'    => esc_html__( 'Enter your Checkout.com test processing channel ID.', 'gravityforms-checkout-com-pro' ),
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
						'name'       => 'live_processing_channel_id',
						'label'      => esc_html__( 'Live Processing Channel ID', 'gravityforms-checkout-com-pro' ),
						'type'       => 'text',
						'class'      => 'medium',
						'tooltip'    => esc_html__( 'Enter your Checkout.com live processing channel ID.', 'gravityforms-checkout-com-pro' ),
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
						'name'    => 'webhook_url_display',
						'label'   => esc_html__( 'Webhook URL', 'gravityforms-checkout-com-pro' ),
						'type'    => 'text',
						'class'   => 'large',
						'value'   => Checkout_Com_Webhook_Handler::get_webhook_url(),
						'readonly' => true,
						'tooltip' => esc_html__( 'Copy this URL to your Checkout.com webhook configuration.', 'gravityforms-checkout-com-pro' ),
					),
					array(
						'name'    => 'default_payment_method',
						'label'   => esc_html__( 'Default Payment Method', 'gravityforms-checkout-com-pro' ),
						'type'    => 'radio',
						'choices' => array(
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
				),
			),
		);
	}

	/**
	 * Feed settings fields.
	 *
	 * @return array
	 */
	public function feed_settings_fields() {
		$default_settings = parent::feed_settings_fields();

		// Add payment method override.
		$payment_method_field = array(
			'name'    => 'payment_method',
			'label'   => esc_html__( 'Payment Method', 'gravityforms-checkout-com-pro' ),
			'type'    => 'radio',
			'choices' => array(
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

		// Add 3DS option.
		$enable_3ds_field = array(
			'name'    => 'enable_3ds',
			'label'   => esc_html__( '3D Secure', 'gravityforms-checkout-com-pro' ),
			'type'    => 'checkbox',
			'choices' => array(
				array(
					'label' => esc_html__( 'Enable 3D Secure authentication', 'gravityforms-checkout-com-pro' ),
					'name'  => 'enable_3ds',
				),
			),
			'tooltip' => esc_html__( 'Enable 3D Secure authentication for enhanced security.', 'gravityforms-checkout-com-pro' ),
		);

		// Add fields after transaction type.
		$default_settings = $this->add_field_after( 'transactionType', $payment_method_field, $default_settings );
		$default_settings = $this->add_field_after( 'payment_method', $enable_3ds_field, $default_settings );

		return $default_settings;
	}

	/**
	 * Get API settings based on current mode.
	 *
	 * @param array $feed Feed data.
	 * @return array
	 */
	public function get_api_settings( $feed = null ) {
		$settings = $this->get_plugin_settings();
		$mode     = rgar( $settings, 'mode', 'test' );

		return array(
			'mode'                   => $mode,
			'public_key'             => rgar( $settings, $mode . '_public_key' ),
			'secret_key'             => rgar( $settings, $mode . '_secret_key' ),
			'processing_channel_id'  => rgar( $settings, $mode . '_processing_channel_id' ),
			'webhook_secret'         => rgar( $settings, $mode . '_webhook_secret' ),
		);
	}

	/**
	 * Get payment method for feed.
	 *
	 * @param array $feed Feed data.
	 * @return string
	 */
	public function get_payment_method( $feed ) {
		$feed_method = rgars( $feed, 'meta/payment_method' );
		
		if ( ! empty( $feed_method ) ) {
			return $feed_method;
		}

		$settings = $this->get_plugin_settings();
		return rgar( $settings, 'default_payment_method', 'frame' );
	}

	/**
	 * Get API client instance.
	 *
	 * @param array $feed Feed data.
	 * @return Checkout_Com_API_Client
	 */
	public function get_api_client( $feed = null ) {
		if ( null === $this->api_client ) {
			$settings = $this->get_api_settings( $feed );
			$this->api_client = new Checkout_Com_API_Client( $settings );
		}
		return $this->api_client;
	}

	/**
	 * Validate plugin settings.
	 *
	 * @param array $settings Settings array.
	 * @return array
	 */
	public function plugin_settings_validation( $settings ) {
		$mode = rgar( $settings, 'mode', 'test' );

		// Validate required fields based on mode.
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
	 * Initialize admin functionality.
	 */
	public function init_admin() {
		parent::init_admin();
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
	}

	/**
	 * Maybe process payment page.
	 */
	public function maybe_process_payment_page() {
		if ( ! isset( $_GET['gf_checkout_com_pro_return'] ) ) {
			return;
		}

		error_log( 'GF Checkout.com Pro: Processing payment page request' );

		$str = rgget( 'gf_checkout_com_pro_return' );
		$str = $this->base64_decode( $str );
		
		parse_str( $str, $query );
		
		if ( wp_hash( 'ids=' . $query['ids'] ) != $query['hash'] ) {
			error_log( 'GF Checkout.com Pro: Invalid hash' );
			return;
		}

		list( $form_id, $entry_id ) = explode( '|', $query['ids'] );
		
		error_log( "GF Checkout.com Pro: Form ID: {$form_id}, Entry ID: {$entry_id}" );

		if ( ! $form_id || ! $entry_id ) {
			error_log( 'GF Checkout.com Pro: Missing form_id or entry_id' );
			return;
		}

		$entry = GFAPI::get_entry( $entry_id );
		$form = GFAPI::get_form( $form_id );

		if ( is_wp_error( $entry ) ) {
			error_log( 'GF Checkout.com Pro: Entry error: ' . $entry->get_error_message() );
			return;
		}

		if ( is_wp_error( $form ) ) {
			error_log( 'GF Checkout.com Pro: Form error: ' . $form->get_error_message() );
			return;
		}

		error_log( 'GF Checkout.com Pro: Entry and form loaded successfully' );

		// Check if already paid
		$payment_status = rgar( $entry, 'payment_status' );
		if ( 'Paid' === $payment_status ) {
			error_log( 'GF Checkout.com Pro: Entry already paid, showing confirmation' );
			$this->handle_payment_return( $form, $entry, 'success' );
			return;
		}

		error_log( 'GF Checkout.com Pro: Rendering payment page' );
		$this->render_payment_page( $form, $entry );
	}

	/**
	 * Render payment page.
	 *
	 * @param array $form Form data.
	 * @param array $entry Entry data.
	 */
	private function render_payment_page( $form, $entry ) {
		$feed_id = gform_get_meta( $entry['id'], 'checkout_com_feed_id' );
		error_log( "GF Checkout.com Pro: Feed ID from meta: {$feed_id}" );

		$feed = $this->get_feed( $feed_id );

		if ( ! $feed ) {
			error_log( 'GF Checkout.com Pro: Feed not found' );
			wp_die( esc_html__( 'Payment feed not found.', 'gravityforms-checkout-com-pro' ) );
		}

		error_log( 'GF Checkout.com Pro: Feed loaded successfully' );

		$handler = $this->get_payment_handler( $feed );
		$payment_method = $handler->get_payment_method();
		error_log( "GF Checkout.com Pro: Using payment method: {$payment_method}" );

		$handler->enqueue_frontend_scripts( $form, $feed );
		error_log( 'GF Checkout.com Pro: Frontend scripts enqueued' );

		// Replace page content with payment form
		add_filter( 'the_content', function() use ( $handler, $form, $entry, $feed ) {
			error_log( 'GF Checkout.com Pro: Rendering payment form content' );
			return $handler->render_payment_form( $form, $entry, $feed );
		});
	}

	/**
	 * Handle payment return.
	 *
	 * @param array  $form Form data.
	 * @param array  $entry Entry data.
	 * @param string $type Return type.
	 */
	private function handle_payment_return( $form, $entry, $type ) {
		if ( 'success' === $type ) {
			// Redirect to confirmation page
			$confirmation = GFFormDisplay::handle_confirmation( $form, $entry, false );
			if ( is_array( $confirmation ) && isset( $confirmation['redirect'] ) ) {
				wp_redirect( $confirmation['redirect'] );
				exit;
			}
		} elseif ( 'cancel' === $type ) {
			// Redirect back to form with error
			$form_url = get_permalink( $form['id'] );
			wp_redirect( add_query_arg( 'payment_cancelled', '1', $form_url ) );
			exit;
		}
	}

	/**
	 * AJAX handler for processing payments.
	 */
	public function ajax_process_payment() {
		error_log( 'GF Checkout.com Pro: AJAX payment processing started' );
		error_log( 'GF Checkout.com Pro: POST data: ' . print_r( $_POST, true ) );

		// Verify nonce
		if ( ! wp_verify_nonce( rgar( $_POST, 'gf_checkout_com_nonce' ), 'gf_checkout_com_process_payment' ) ) {
			error_log( 'GF Checkout.com Pro: Nonce verification failed' );
			wp_die( esc_html__( 'Security check failed.', 'gravityforms-checkout-com-pro' ) );
		}

		$form_id = absint( rgar( $_POST, 'form_id' ) );
		$entry_id = absint( rgar( $_POST, 'entry_id' ) );
		$token = sanitize_text_field( rgar( $_POST, 'checkout_com_token' ) );

		error_log( "GF Checkout.com Pro: Form ID: {$form_id}, Entry ID: {$entry_id}, Token: " . substr( $token, 0, 10 ) . '...' );

		if ( ! $form_id || ! $entry_id || ! $token ) {
			error_log( 'GF Checkout.com Pro: Missing required data' );
			wp_die( esc_html__( 'Missing required data.', 'gravityforms-checkout-com-pro' ) );
		}

		$entry = GFAPI::get_entry( $entry_id );
		$form = GFAPI::get_form( $form_id );

		if ( is_wp_error( $entry ) || is_wp_error( $form ) ) {
			error_log( 'GF Checkout.com Pro: Invalid form or entry' );
			wp_die( esc_html__( 'Invalid form or entry.', 'gravityforms-checkout-com-pro' ) );
		}

		$feed_id = gform_get_meta( $entry['id'], 'checkout_com_feed_id' );
		$feed = $this->get_feed( $feed_id );

		if ( ! $feed ) {
			error_log( 'GF Checkout.com Pro: Payment feed not found' );
			wp_die( esc_html__( 'Payment feed not found.', 'gravityforms-checkout-com-pro' ) );
		}

		error_log( 'GF Checkout.com Pro: Starting payment processing' );

		// Process payment
		$result = $this->process_payment_with_token( $form, $entry, $feed, $token );

		if ( is_wp_error( $result ) ) {
			error_log( 'GF Checkout.com Pro: Payment processing failed: ' . $result->get_error_message() );
			wp_die( esc_html( $result->get_error_message() ) );
		}

		error_log( 'GF Checkout.com Pro: Payment processed successfully, redirecting' );

		// Redirect to success page
		wp_redirect( $this->get_return_url( $form['id'], $entry['id'], 'success' ) );
		exit;
	}

	/**
	 * Process payment with token.
	 *
	 * @param array  $form Form data.
	 * @param array  $entry Entry data.
	 * @param array  $feed Feed data.
	 * @param string $token Payment token.
	 * @return bool|WP_Error
	 */
	private function process_payment_with_token( $form, $entry, $feed, $token ) {
		error_log( 'GF Checkout.com Pro: Building payment data' );

		$handler = $this->get_payment_handler( $feed );
		$submission_data = gform_get_meta( $entry['id'], 'checkout_com_payment_data' );
		
		error_log( 'GF Checkout.com Pro: Submission data: ' . print_r( $submission_data, true ) );
		
		// Build payment data
		$payment_data = $handler->build_payment_data( $feed, $submission_data, $form, $entry );
		$payment_data['source'] = array(
			'type' => 'token',
			'token' => $token,
		);

		error_log( 'GF Checkout.com Pro: Payment data: ' . print_r( $payment_data, true ) );

		// Make API call
		$api_client = $this->get_api_client( $feed );
		error_log( 'GF Checkout.com Pro: Making API call to Checkout.com' );
		
		$response = $api_client->create_payment( $payment_data );

		if ( is_wp_error( $response ) ) {
			error_log( 'GF Checkout.com Pro: API call failed: ' . $response->get_error_message() );
			$this->log_error( 'Payment failed: ' . $response->get_error_message() );
			return $response;
		}

		error_log( 'GF Checkout.com Pro: API response: ' . print_r( $response, true ) );

		// Update entry with payment details
		$payment_id = rgar( $response, 'id' );
		$status = rgar( $response, 'status' );

		error_log( "GF Checkout.com Pro: Payment ID: {$payment_id}, Status: {$status}" );

		GFAPI::update_entry_property( $entry['id'], 'transaction_id', $payment_id );
		
		if ( 'Authorized' === $status || 'Captured' === $status ) {
			GFAPI::update_entry_property( $entry['id'], 'payment_status', 'Paid' );
			GFAPI::update_entry_property( $entry['id'], 'payment_date', gmdate( 'Y-m-d H:i:s' ) );
			error_log( 'GF Checkout.com Pro: Entry updated with payment success' );
		} else {
			error_log( "GF Checkout.com Pro: Unexpected payment status: {$status}" );
		}

		$this->log_debug( 'Payment processed successfully. ID: ' . $payment_id );
		return true;
	}

	/**
	 * Get return URL.
	 *
	 * @param int    $form_id Form ID.
	 * @param int    $entry_id Entry ID.
	 * @param string $type URL type.
	 * @return string
	 */
	private function get_return_url( $form_id, $entry_id, $type = 'success' ) {
		$query_args = array(
			'gf_checkout_com_return' => '1',
			'form_id'                => $form_id,
			'entry_id'               => $entry_id,
			'type'                   => $type,
		);

		return add_query_arg( $query_args, home_url( '/' ) );
	}

	/**
	 * Redirect URL for payment processing (required by GF).
	 *
	 * @param array $feed Feed data.
	 * @param array $submission_data Submission data.
	 * @param array $form Form data.
	 * @param array $entry Entry data.
	 * @return string
	 */
	public function redirect_url( $feed, $submission_data, $form, $entry ) {
		error_log( 'GF Checkout.com Pro: redirect_url method called' );
		error_log( 'GF Checkout.com Pro: Entry ID: ' . $entry['id'] );
		error_log( 'GF Checkout.com Pro: Payment amount: ' . rgar( $submission_data, 'payment_amount' ) );
		
		// Update entry status and amount
		GFAPI::update_entry_property( $entry['id'], 'payment_status', 'Processing' );
		GFAPI::update_entry_property( $entry['id'], 'payment_amount', rgar( $submission_data, 'payment_amount' ) );

		// Store payment data and feed ID
		gform_update_meta( $entry['id'], 'checkout_com_payment_data', $submission_data );
		gform_update_meta( $entry['id'], 'checkout_com_feed_id', $feed['id'] );

		// Generate return URL (similar to your existing plugins)
		$return_url = $this->return_url( $form['id'], $entry['id'] );
		
		// Store the payment URL
		gform_update_meta( $entry['id'], 'checkout_com_payment_url', $return_url );
		
		error_log( "GF Checkout.com Pro: Redirect URL: {$return_url}" );
		
		return $return_url;
	}

	/**
	 * Generate return URL (exactly like existing plugins).
	 *
	 * @param int    $form_id Form ID.
	 * @param int    $entry_id Entry ID.
	 * @param string $type URL type.
	 * @return string
	 */
	public function return_url( $form_id, $entry_id, $type = false ) {
		$pageURL = GFCommon::is_ssl() ? 'https://' : 'http://';

		$server_port = apply_filters( 'gform_checkout_com_pro_return_url_port', $_SERVER['SERVER_PORT'] );

		if ( strpos( $server_port, '80' ) === false ) {
			$pageURL .= $_SERVER['SERVER_NAME'] . ':' . $server_port . $_SERVER['REQUEST_URI'];
		} else {
			$pageURL .= $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
		}

		if ( 'cancel' === $type ) {
			$url = remove_query_arg( array( 'gf_checkout_com_pro_return' ), $pageURL );
			return apply_filters( 'gform_checkout_com_pro_cancel_url', $url, $form_id, $entry_id );
		}

		$ids_query = "ids={$form_id}|{$entry_id}";
		$ids_query .= '&hash=' . wp_hash( $ids_query );

		$url = add_query_arg( 'gf_checkout_com_pro_return', $this->base64_encode( $ids_query ), $pageURL );

		$query = 'gf_checkout_com_pro_return=' . $this->base64_encode( $ids_query );
		
		error_log( "GF Checkout.com Pro: Generated return URL: {$url}" );

		return apply_filters( 'gform_checkout_com_pro_return_url', $url, $form_id, $entry_id, $query );
	}

	/**
	 * Base64 encode (URL safe).
	 *
	 * @param string $string String to encode.
	 * @return string
	 */
	public function base64_encode( $string ) {
		return str_replace( array( '+', '/', '=' ), array( '-', '_', '' ), base64_encode( $string ) );
	}

	/**
	 * Base64 decode (URL safe).
	 *
	 * @param string $string String to decode.
	 * @return string
	 */
	public function base64_decode( $string ) {
		return base64_decode( str_replace( array( '-', '_' ), array( '+', '/' ), $string ) );
	}

	/**
	 * Get payment handler for feed.
	 *
	 * @param array $feed Feed data.
	 * @return Abstract_Checkout_Com_Payment_Handler
	 */
	public function get_payment_handler( $feed ) {
		$payment_method = $this->get_payment_method( $feed );
		return isset( $this->payment_handlers[ $payment_method ] ) ? $this->payment_handlers[ $payment_method ] : $this->payment_handlers['frame'];
	}

}

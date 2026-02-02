<?php
/**
 * Checkout.com Webhook Handler
 *
 * @package GravityForms_Checkout_Com_Pro
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Webhook handler class.
 */
class Checkout_Com_Webhook_Handler {

	/**
	 * Gateway instance.
	 *
	 * @var GF_Checkout_Com_Pro_Gateway
	 */
	private $gateway;

	/**
	 * Constructor.
	 *
	 * @param GF_Checkout_Com_Pro_Gateway $gateway Gateway instance.
	 */
	public function __construct( $gateway ) {
		$this->gateway = $gateway;
		$this->init();
	}

	/**
	 * Initialize webhook handler.
	 */
	private function init() {
		add_action( 'rest_api_init', array( $this, 'register_webhook_endpoint' ) );
	}

	/**
	 * Register webhook REST API endpoint.
	 */
	public function register_webhook_endpoint() {
		// Register main webhook endpoint (POST for webhooks, GET for testing)
		register_rest_route(
			'gravityforms-checkout-com-pro/v1',
			'/webhook',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle_webhook' ),
					'permission_callback' => array( $this, 'verify_webhook_signature' ),
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'webhook_info' ),
					'permission_callback' => '__return_true',
				),
			)
		);
		
		// Register test endpoint
		register_rest_route(
			'gravityforms-checkout-com-pro/v1',
			'/test',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'test_endpoint' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Webhook info endpoint (for GET requests).
	 *
	 * @return WP_REST_Response
	 */
	public function webhook_info() {
		return new WP_REST_Response( array( 
			'status' => 'ready',
			'message' => 'Checkout.com Pro webhook endpoint is ready to receive POST requests',
			'endpoint' => 'gravityforms-checkout-com-pro/v1/webhook',
			'methods' => array( 'POST' ),
			'timestamp' => current_time( 'mysql' )
		), 200 );
	}

	/**
	 * Test endpoint.
	 *
	 * @return WP_REST_Response
	 */
	public function test_endpoint() {
		return new WP_REST_Response( array( 
			'status' => 'success',
			'message' => 'Checkout.com Pro webhook endpoint is working',
			'timestamp' => current_time( 'mysql' )
		), 200 );
	}

	/**
	 * Verify webhook signature.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	public function verify_webhook_signature( $request ) {
		$headers = $request->get_headers();
		$signature = isset( $headers['authorization'] ) ? $headers['authorization'][0] : '';

		if ( empty( $signature ) ) {
			$this->gateway->log_error( 'Webhook: Missing signature' );
			return false;
		}

		$settings = $this->gateway->get_plugin_settings();
		$webhook_secret = rgar( $settings, 'webhook_secret' );

		if ( empty( $webhook_secret ) ) {
			$this->gateway->log_error( 'Webhook: No webhook secret configured' );
			return false;
		}

		if ( $signature !== $webhook_secret ) {
			$this->gateway->log_error( 'Webhook: Invalid signature' );
			return false;
		}

		return true;
	}

	/**
	 * Handle webhook request.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function handle_webhook( $request ) {
		$body = $request->get_json_params();

		if ( empty( $body ) ) {
			$this->gateway->log_error( 'Webhook: Empty request body' );
			return new WP_REST_Response( array( 'error' => 'Empty request body' ), 400 );
		}

		$this->gateway->log_debug( 'Webhook received: ' . wp_json_encode( $body ) );

		$event_type = rgar( $body, 'type' );
		$payment_data = rgar( $body, 'data' );

		if ( empty( $event_type ) || empty( $payment_data ) ) {
			$this->gateway->log_error( 'Webhook: Missing event type or payment data' );
			return new WP_REST_Response( array( 'error' => 'Invalid webhook data' ), 400 );
		}

		// Get entry information from metadata
		$metadata = rgar( $payment_data, 'metadata', array() );
		$entry_id = rgar( $metadata, 'entry_id' );
		$form_id = rgar( $metadata, 'form_id' );

		if ( empty( $entry_id ) || empty( $form_id ) ) {
			$this->gateway->log_error( 'Webhook: Missing entry or form ID in metadata' );
			return new WP_REST_Response( array( 'error' => 'Missing entry information' ), 400 );
		}

		// Get entry
		$entry = GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $entry ) ) {
			$this->gateway->log_error( 'Webhook: Entry not found: ' . $entry_id );
			return new WP_REST_Response( array( 'error' => 'Entry not found' ), 404 );
		}

		// Process webhook based on event type
		$result = $this->process_webhook_event( $event_type, $payment_data, $entry );

		if ( is_wp_error( $result ) ) {
			$this->gateway->log_error( 'Webhook processing failed: ' . $result->get_error_message() );
			return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 500 );
		}

		return new WP_REST_Response( array( 'status' => 'success' ), 200 );
	}

	/**
	 * Process webhook event.
	 *
	 * @param string $event_type Event type.
	 * @param array  $payment_data Payment data.
	 * @param array  $entry Entry data.
	 * @return bool|WP_Error
	 */
	private function process_webhook_event( $event_type, $payment_data, $entry ) {
		$payment_id = rgar( $payment_data, 'id' );
		$amount = rgar( $payment_data, 'amount' );
		$currency = rgar( $payment_data, 'currency' );

		switch ( $event_type ) {
			case 'payment_approved':
				return $this->handle_payment_approved( $entry, $payment_id, $amount, $currency );

			case 'payment_declined':
				return $this->handle_payment_declined( $entry, $payment_id );

			case 'payment_canceled':
				return $this->handle_payment_canceled( $entry, $payment_id );

			case 'payment_captured':
				return $this->handle_payment_captured( $entry, $payment_id, $amount );

			case 'payment_refunded':
				return $this->handle_payment_refunded( $entry, $payment_id, $amount );

			default:
				$this->gateway->log_debug( 'Webhook: Unhandled event type: ' . $event_type );
				return true; // Return success for unhandled events
		}
	}

	/**
	 * Handle payment approved.
	 *
	 * @param array  $entry Entry data.
	 * @param string $payment_id Payment ID.
	 * @param int    $amount Amount.
	 * @param string $currency Currency.
	 * @return bool
	 */
	private function handle_payment_approved( $entry, $payment_id, $amount, $currency ) {
		$this->gateway->log_debug( "Payment approved for entry {$entry['id']}: {$payment_id}" );

		// Update entry
		GFAPI::update_entry_property( $entry['id'], 'payment_status', 'Paid' );
		GFAPI::update_entry_property( $entry['id'], 'payment_amount', $amount / 100 ); // Convert from cents
		GFAPI::update_entry_property( $entry['id'], 'currency', $currency );
		GFAPI::update_entry_property( $entry['id'], 'transaction_id', $payment_id );
		GFAPI::update_entry_property( $entry['id'], 'payment_date', gmdate( 'Y-m-d H:i:s' ) );

		// Add note
		$this->gateway->add_note( $entry['id'], sprintf( 'Payment approved. Transaction ID: %s', $payment_id ) );

		// Trigger Gravity Forms payment completion actions
		do_action( 'gform_post_payment_completed', $entry, array(
			'type'           => 'complete_payment',
			'transaction_id' => $payment_id,
			'amount'         => $amount / 100,
			'payment_status' => 'Paid',
		) );

		return true;
	}

	/**
	 * Handle payment declined.
	 *
	 * @param array  $entry Entry data.
	 * @param string $payment_id Payment ID.
	 * @return bool
	 */
	private function handle_payment_declined( $entry, $payment_id ) {
		$this->gateway->log_debug( "Payment declined for entry {$entry['id']}: {$payment_id}" );

		GFAPI::update_entry_property( $entry['id'], 'payment_status', 'Failed' );
		$this->gateway->add_note( $entry['id'], sprintf( 'Payment declined. Transaction ID: %s', $payment_id ) );

		return true;
	}

	/**
	 * Handle payment canceled.
	 *
	 * @param array  $entry Entry data.
	 * @param string $payment_id Payment ID.
	 * @return bool
	 */
	private function handle_payment_canceled( $entry, $payment_id ) {
		$this->gateway->log_debug( "Payment canceled for entry {$entry['id']}: {$payment_id}" );

		GFAPI::update_entry_property( $entry['id'], 'payment_status', 'Cancelled' );
		$this->gateway->add_note( $entry['id'], sprintf( 'Payment canceled. Transaction ID: %s', $payment_id ) );

		return true;
	}

	/**
	 * Handle payment captured.
	 *
	 * @param array  $entry Entry data.
	 * @param string $payment_id Payment ID.
	 * @param int    $amount Amount.
	 * @return bool
	 */
	private function handle_payment_captured( $entry, $payment_id, $amount ) {
		$this->gateway->log_debug( "Payment captured for entry {$entry['id']}: {$payment_id}" );

		GFAPI::update_entry_property( $entry['id'], 'payment_status', 'Paid' );
		$this->gateway->add_note( $entry['id'], sprintf( 'Payment captured. Amount: %s. Transaction ID: %s', $amount / 100, $payment_id ) );

		return true;
	}

	/**
	 * Handle payment refunded.
	 *
	 * @param array  $entry Entry data.
	 * @param string $payment_id Payment ID.
	 * @param int    $amount Amount.
	 * @return bool
	 */
	private function handle_payment_refunded( $entry, $payment_id, $amount ) {
		$this->gateway->log_debug( "Payment refunded for entry {$entry['id']}: {$payment_id}" );

		GFAPI::update_entry_property( $entry['id'], 'payment_status', 'Refunded' );
		$this->gateway->add_note( $entry['id'], sprintf( 'Payment refunded. Amount: %s. Transaction ID: %s', $amount / 100, $payment_id ) );

		return true;
	}

	/**
	 * Get webhook URL.
	 *
	 * @return string
	 */
	public static function get_webhook_url() {
		return rest_url( 'gravityforms-checkout-com-pro/v1/webhook' );
	}
}

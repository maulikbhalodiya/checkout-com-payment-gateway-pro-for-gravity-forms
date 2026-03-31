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
		
		// Try alternative header for local env/Beeceptor
		if ( empty( $signature ) && isset( $headers['x_authorization'] ) ) {
			$signature = $headers['x_authorization'][0];
		}

		if ( empty( $signature ) ) {
			$this->gateway->log_error( 'Checkout.com Pro: Webhook - Missing signature' );
			return false;
		}

		$settings = $this->gateway->get_plugin_settings();
		
		$test_secret = rgar( $settings, 'test_webhook_secret' );
		$live_secret = rgar( $settings, 'live_webhook_secret' );

		// Check against both secrets to allow testing across environments
		$is_valid_test = ! empty( $test_secret ) && $test_secret === $signature;
		$is_valid_live = ! empty( $live_secret ) && $live_secret === $signature;

		if ( ! $is_valid_test && ! $is_valid_live ) {
			$this->gateway->log_error( 'Checkout.com Pro: Webhook - Invalid signature' );
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
			$this->gateway->log_error( 'Checkout.com Pro: Webhook - Empty request body' );
			return new WP_REST_Response( array( 'error' => 'Empty request body' ), 400 );
		}

		$this->gateway->log_debug( 'Checkout.com Pro: Webhook received: ' . wp_json_encode( $body ) );

		$event_type = rgar( $body, 'type' );
		$payment_data = rgar( $body, 'data' );

		if ( empty( $event_type ) || empty( $payment_data ) ) {
			$this->gateway->log_error( 'Checkout.com Pro: Webhook - Missing event type or payment data' );
			return new WP_REST_Response( array( 'error' => 'Invalid webhook data' ), 400 );
		}


		// Get entry information from metadata
		$metadata = rgar( $payment_data, 'metadata', array() );
		$entry_id = rgar( $metadata, 'entry_id' );
		$form_id  = rgar( $metadata, 'form_id' );
		$site_url = rgar( $metadata, 'website_url' );

		// Validate Site URL to prevent cross-site contamination
		// This is crucial when multiple sites share the same Checkout.com account.
		$current_site_url = site_url();
		
		// Normalize URLs for comparison (remove trailing slashes)
		$site_url_clean = untrailingslashit( $site_url );
		$current_site_url_clean = untrailingslashit( $current_site_url );

		if ( $site_url_clean !== $current_site_url_clean ) {
			$this->gateway->log_error( 'Checkout.com Pro: Webhook - URL Mismatch. Metadata URL: ' . $site_url . ' | Current Site URL: ' . $current_site_url );
			return new WP_REST_Response( array( 'error' => 'Site URL mismatch' ), 400 );
		}

		if ( empty( $entry_id ) || empty( $form_id ) ) {
			$this->gateway->log_error( 'Checkout.com Pro: Webhook - Missing entry or form ID in metadata' );
			return new WP_REST_Response( array( 'error' => 'Missing entry information' ), 400 );
		}

		// Get entry
		$entry = GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $entry ) ) {
			$this->gateway->log_error( 'Checkout.com Pro: Webhook - Entry not found: ' . $entry_id );
			return new WP_REST_Response( array( 'error' => 'Entry not found' ), 404 );
		}

		// Process webhook based on event type
		$result = $this->process_webhook_event( $event_type, $payment_data, $entry );

		if ( is_wp_error( $result ) ) {
			$this->gateway->log_error( 'Checkout.com Pro: Webhook - Processing failed: ' . $result->get_error_message() );
			return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 500 );
		}

		return new WP_REST_Response( array( 'status' => 'success' ), 200 );
	}

	/**
	 * Process webhook event.
	 *
	 * @param string $event_type   Event type.
	 * @param array  $payment_data Payment data.
	 * @param array  $entry        Entry data.
	 * @return bool|array|WP_Error
	 */
	private function process_webhook_event( $event_type, $payment_data, $entry ) {
		$payment_id       = rgar( $payment_data, 'id' );
		$amount           = rgar( $payment_data, 'amount' );
		$currency         = rgar( $payment_data, 'currency' );
		$response_summary = rgar( $payment_data, 'response_summary' ); // Detailed reason
		$response_code    = rgar( $payment_data, 'response_code' );    // specific code

		// Log which event we are processing
		$this->gateway->log_debug( 'Checkout.com Pro: Webhook - Processing event: ' . $event_type . ' for entry ' . $entry['id'] );

		// Normalize amount (convert from cents if needed, but Gravity Forms expects float)
		if ( $amount ) {
			$amount = $amount / 100; // Convert to main unit
		} else {
			$amount = rgar( $entry, 'payment_amount' );
		}

		$action = array();
		$action['entry_id']       = $entry['id'];
		$action['transaction_id'] = $payment_id;

		switch ( $event_type ) {
			case 'payment_approved':
			case 'payment_captured':
				// Duplicate check: If Paid and same Transaction ID, skip
				if ( 'Paid' === rgar( $entry, 'payment_status' ) && $payment_id === rgar( $entry, 'transaction_id' ) ) {
					$this->gateway->log_debug( 'Checkout.com Pro: Webhook - Duplicate Approved/Captured event. Skipping.' );
					return true;
				}

				$action['type']           = 'complete_payment';
				$action['amount']         = $amount;
				$action['payment_date']   = gmdate( 'y-m-d H:i:s' );
				$action['payment_method'] = 'checkout-com';
				$action['note']           = sprintf( 'Payment approved (by Webhook). Transaction ID: %s', $payment_id );
				break;

			case 'payment_declined':
			case 'payment_failed':
			case 'payment_canceled':
				// Duplicate check: If already Failed/Cancelled and same Transaction ID, skip.
				// This prevents double notes if Direct Response has already handled it.
				$current_status = rgar( $entry, 'payment_status' );
				if ( ( 'Failed' === $current_status || 'Cancelled' === $current_status ) && $payment_id === rgar( $entry, 'transaction_id' ) ) {
					$this->gateway->log_debug( 'Checkout.com Pro: Webhook - Duplicate Failed/Declined event (Already updated by Direct Response). Skipping.' );
					return true;
				}
				
				$action['type']   = 'fail_payment';
				$action['amount'] = $amount;
				
				// Build detailed note
				$note = sprintf( 'Payment failed (by Webhook). Transaction ID: %s', $payment_id );
				if ( ! empty( $response_summary ) ) {
					$note .= sprintf( '. Reason: %s', $response_summary );
				}
				if ( ! empty( $response_code ) ) {
					$note .= sprintf( ' (Code: %s)', $response_code );
				}
				$action['note'] = $note;
				break;

			case 'payment_refunded':
				// Duplicate check
				if ( rgar( $entry, 'payment_status' ) === 'Refunded' && rgar( $entry, 'transaction_id' ) === $payment_id ) {
					return true;
				}
				
				$action['type']   = 'refund_payment'; // or custom handling if GF doesn't have standard refund action in base
				$action['amount'] = $amount;
				$action['note']   = sprintf( 'Payment refunded (by Webhook). Transaction ID: %s', $payment_id );
				
				// Standard GF gateway doesn't always have 'refund_payment' action switch.
				// But we are passing to checkout_com_process_callback_action which has a switch.
				// Based on the switch we just uncommented:
				// It handles: complete_payment, fail_payment, add_pending_payment.
				// It DOES NOT handle 'refund_payment'. 
				// The user's example code also didn't explicitly handle refund in the switch either (it showed pending/fail/success).
				// So for Refund, we might need to stick to manual update OR add a case to the Gateway switch.
				// Let's stick to manual update for Refund for now to be safe, OR map it to a callback.
				
				// Actually, better to keep the manual method for Refund to avoid breaking the pattern if the gateway switch doesn't support it.
				return $this->handle_payment_refunded( $entry, $payment_id, $amount * 100 );

			default:
				$this->gateway->log_error( 'Checkout.com Pro: Webhook - Unhandled event type for action creation: ' . $event_type );
				return true;
		}
		
		/**
		 * Filter the webhook action before processing.
		 *
		 * @since 1.2.0
		 *
		 * @param array  $action       The action array.
		 * @param string $event_type   The event type.
		 * @param array  $entry        The Entry Object.
		 * @param array  $payment_data The payment data from webhook.
		 */
		$action = apply_filters( 'gform_checkout_webhook_action', $action, $event_type, $entry, $payment_data );

		// Process the action using the main Gateway method
		return $this->gateway->checkout_com_process_callback_action( $action );
	}

	/**
	 * Handle payment refunded (Keeping separate as it's not in the main switch).
	 *
	 * @param array  $entry Entry data.
	 * @param string $payment_id Payment ID.
	 * @param int    $amount Amount in cents.
	 * @return bool
	 */
	private function handle_payment_refunded( $entry, $payment_id, $amount ) {
		if ( 'Refunded' === rgar( $entry, 'payment_status' ) && $payment_id === rgar( $entry, 'transaction_id' ) ) {
			return true;
		}

		GFAPI::update_entry_property( $entry['id'], 'payment_status', 'Refunded' );
		GFAPI::update_entry_property( $entry['id'], 'transaction_id', $payment_id );
		
		$this->gateway->add_note( $entry['id'], sprintf( 'Payment refunded (by Webhook). Amount: %s. Transaction ID: %s', $amount / 100, $payment_id ), 'success' );

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

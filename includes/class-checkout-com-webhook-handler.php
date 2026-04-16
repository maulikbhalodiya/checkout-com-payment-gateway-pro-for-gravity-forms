<?php
/**
 * Checkout.com Webhook Handler.
 *
 * @package checkout-com-pro-for-gravity-forms
 * @since   1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Webhook handler class.
 *
 * @since 1.0.0
 */
class Checkout_Com_Webhook_Handler {


	/**
	 * Gateway instance.
	 *
	 * @since 1.0.0
	 * @var   GF_Checkout_Com_Pro_Gateway
	 */
	private $gateway;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param GF_Checkout_Com_Pro_Gateway $gateway Gateway instance.
	 */
	public function __construct( $gateway ) {
		$this->gateway = $gateway;
		$this->init();
	}

	/**
	 * Initialize webhook handler.
	 *
	 * @since 1.0.0
	 */
	private function init() {
		add_action( 'rest_api_init', array( $this, 'register_webhook_endpoint' ) );
	}

	/**
	 * Register webhook REST API endpoint.
	 *
	 * @since 1.0.0
	 */
	public function register_webhook_endpoint() {
		register_rest_route(
			'checkout-com-pro-for-gravity-forms/v1',
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

		register_rest_route(
			'checkout-com-pro-for-gravity-forms/v1',
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
	 * @since 1.0.0
	 * @return WP_REST_Response
	 */
	public function webhook_info() {
		return new WP_REST_Response(
			array(
				'status'    => 'ready',
				'message'   => 'Checkout.com Pro webhook endpoint is ready',
				'endpoint'  => 'checkout-com-pro-for-gravity-forms/v1/webhook',
				'timestamp' => current_time( 'mysql' ),
			),
			200
		);
	}

	/**
	 * Test endpoint.
	 *
	 * @since 1.0.0
	 * @return WP_REST_Response
	 */
	public function test_endpoint() {
		return new WP_REST_Response(
			array(
				'status'    => 'success',
				'message'   => 'Checkout.com Pro webhook test endpoint is working',
				'timestamp' => current_time( 'mysql' ),
			),
			200
		);
	}

	/**
	 * Verify webhook signature.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	public function verify_webhook_signature( $request ) {
		$headers   = $request->get_headers();
		$signature = isset( $headers['authorization'] ) ? $headers['authorization'][0] : '';

		if ( empty( $signature ) && isset( $headers['x_authorization'] ) ) {
			$signature = $headers['x_authorization'][0];
		}

		if ( empty( $signature ) ) {
			$this->gateway->log_error( 'Checkout.com Pro: Webhook - Missing signature' );
			return false;
		}

		$settings    = $this->gateway->get_plugin_settings();
		$test_secret = rgar( $settings, 'test_webhook_secret' );
		$live_secret = rgar( $settings, 'live_webhook_secret' );

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
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function handle_webhook( $request ) {
		$body = $request->get_json_params();

		if ( empty( $body ) ) {
			return new WP_REST_Response( array( 'error' => 'Empty request body' ), 400 );
		}

		$this->gateway->log_debug( 'Checkout.com Pro: Webhook received: ' . wp_json_encode( $body ) );

		$event_type   = rgar( $body, 'type' );
		$payment_data = rgar( $body, 'data' );

		if ( empty( $event_type ) || empty( $payment_data ) ) {
			return new WP_REST_Response( array( 'error' => 'Invalid webhook data' ), 400 );
		}

		$metadata = rgar( $payment_data, 'metadata', array() );
		$entry_id = rgar( $metadata, 'entry_id' );
		$site_url = rgar( $metadata, 'website_url' );

		$current_site_url       = site_url();
		$site_url_clean         = untrailingslashit( $site_url );
		$current_site_url_clean = untrailingslashit( $current_site_url );

		if ( $site_url_clean !== $current_site_url_clean ) {
			$this->gateway->log_error( 'Checkout.com Pro: Webhook - URL Mismatch. Metadata URL: ' . $site_url );
			return new WP_REST_Response( array( 'error' => 'Site URL mismatch' ), 400 );
		}

		if ( empty( $entry_id ) ) {
			return new WP_REST_Response( array( 'error' => 'Missing entry information' ), 400 );
		}

		$entry = GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $entry ) ) {
			return new WP_REST_Response( array( 'error' => 'Entry not found' ), 404 );
		}

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
	 * @since 1.0.0
	 * @param string $event_type   Event type.
	 * @param array  $payment_data Payment data.
	 * @param array  $entry        Entry data.
	 * @return bool|array|WP_Error
	 */
	private function process_webhook_event( $event_type, $payment_data, $entry ) {
		$payment_id       = rgar( $payment_data, 'id' );
		$amount           = rgar( $payment_data, 'amount' );
		$response_summary = rgar( $payment_data, 'response_summary' );
		$response_code    = rgar( $payment_data, 'response_code' );

		$this->gateway->log_debug( 'Checkout.com Pro: Webhook - Processing event: ' . $event_type . ' for entry ' . $entry['id'] );

		if ( $amount ) {
			$amount = $amount / 100;
		} else {
			$amount = rgar( $entry, 'payment_amount' );
		}

		$action                   = array();
		$action['entry_id']       = $entry['id'];
		$action['transaction_id'] = $payment_id;

		switch ( $event_type ) {
			case 'payment_approved':
			case 'payment_captured':
				if ( 'Paid' === rgar( $entry, 'payment_status' ) && rgar( $entry, 'transaction_id' ) === $payment_id ) {
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
				$current_status = rgar( $entry, 'payment_status' );
				if ( ( 'Failed' === $current_status || 'Cancelled' === $current_status ) && rgar( $entry, 'transaction_id' ) === $payment_id ) {
					return true;
				}

				$action['type']   = 'fail_payment';
				$action['amount'] = $amount;
				$note             = sprintf( 'Payment failed (by Webhook). Transaction ID: %s', $payment_id );

				if ( ! empty( $response_summary ) ) {
					$note .= sprintf( '. Reason: %s', $response_summary );
				}
				if ( ! empty( $response_code ) ) {
					$note .= sprintf( ' (Code: %s)', $response_code );
				}
				$action['note'] = $note;
				break;

			case 'payment_refunded':
				if ( 'Refunded' === rgar( $entry, 'payment_status' ) && rgar( $entry, 'transaction_id' ) === $payment_id ) {
					return true;
				}
				return $this->handle_payment_refunded( $entry, $payment_id, $amount * 100 );

			default:
				return true;
		}

		$action = apply_filters( 'gform_checkout_webhook_action', $action, $event_type, $entry, $payment_data );
		return $this->gateway->checkout_com_process_callback_action( $action );
	}

	/**
	 * Handle payment refunded.
	 *
	 * @since 1.0.0
	 * @param array  $entry      Entry data.
	 * @param string $payment_id Payment ID.
	 * @param int    $amount     Amount in cents.
	 * @return bool
	 */
	private function handle_payment_refunded( $entry, $payment_id, $amount ) {
		if ( 'Refunded' === rgar( $entry, 'payment_status' ) && rgar( $entry, 'transaction_id' ) === $payment_id ) {
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
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_webhook_url() {
		return rest_url( 'checkout-com-pro-for-gravity-forms/v1/webhook' );
	}
}

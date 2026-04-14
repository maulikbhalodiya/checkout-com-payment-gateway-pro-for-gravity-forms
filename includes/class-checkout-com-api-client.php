<?php
/**
 * Checkout.com API Client
 *
 * @package GravityForms_Checkout_Com_Pro
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checkout.com API client class.
 */
class Checkout_Com_API_Client {

	/**
	 * API endpoints.
	 */
	const API_URL_LIVE = 'https://api.checkout.com/';
	const API_URL_TEST = 'https://api.sandbox.checkout.com/';

	/**
	 * API settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param array $settings API settings.
	 */
	public function __construct( $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Get API base URL.
	 *
	 * @return string
	 */
	private function get_api_url() {
		return 'live' === $this->settings['mode'] ? self::API_URL_LIVE : self::API_URL_TEST;
	}

	/**
	 * Get authorization headers.
	 *
	 * @return array
	 */
	private function get_headers() {
		return array(
			'Authorization' => $this->settings['secret_key'],
			'Content-Type'  => 'application/json',
		);
	}

	/**
	 * Make API request.
	 *
	 * @param string $endpoint API endpoint.
	 * @param array  $data Request data.
	 * @param string $method HTTP method.
	 * @return array|WP_Error
	 */
	private function make_request( $endpoint, $data = array(), $method = 'POST' ) {
		$url = $this->get_api_url() . ltrim( $endpoint, '/' );

		$args = array(
			'method'  => $method,
			'headers' => $this->get_headers(),
			'timeout' => 30,
		);

		if ( ! empty( $data ) && 'POST' === $method ) {
			$args['body'] = wp_json_encode( $data );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$code = wp_remote_retrieve_response_code( $response );

		$decoded = json_decode( $body, true );

		if ( $code >= 200 && $code < 300 ) {
			return $decoded;
		}

		return new WP_Error(
			'api_error',
			isset( $decoded['error_type'] ) ? $decoded['error_type'] : 'API request failed',
			array(
				'status'   => $code,
				'response' => $decoded,
			)
		);
	}

	/**
	 * Create payment.
	 *
	 * @param array $payment_data Payment data.
	 * @return array|WP_Error
	 */
	public function create_payment( $payment_data ) {
		return $this->make_request( 'payments', $payment_data );
	}

	/**
	 * Get payment details.
	 *
	 * @param string $payment_id Payment ID.
	 * @return array|WP_Error
	 */
	public function get_payment( $payment_id ) {
		return $this->make_request( "payments/{$payment_id}", array(), 'GET' );
	}

	/**
	 * Capture payment.
	 *
	 * @param string $payment_id Payment ID.
	 * @param array  $capture_data Capture data.
	 * @return array|WP_Error
	 */
	public function capture_payment( $payment_id, $capture_data = array() ) {
		return $this->make_request( "payments/{$payment_id}/captures", $capture_data );
	}

	/**
	 * Void payment.
	 *
	 * @param string $payment_id Payment ID.
	 * @param array  $void_data Void data.
	 * @return array|WP_Error
	 */
	public function void_payment( $payment_id, $void_data = array() ) {
		return $this->make_request( "payments/{$payment_id}/voids", $void_data );
	}

	/**
	 * Refund payment.
	 *
	 * @param string $payment_id Payment ID.
	 * @param array  $refund_data Refund data.
	 * @return array|WP_Error
	 */
	public function refund_payment( $payment_id, $refund_data ) {
		return $this->make_request( "payments/{$payment_id}/refunds", $refund_data );
	}

	/**
	 * Test API connection.
	 *
	 * @return bool|WP_Error
	 */
	public function test_connection() {
		// Make a simple request to test the connection
		$response = $this->make_request( 'payments', array(), 'GET' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}
}

<?php
/**
 * Checkout.com Frame Payment Handler
 *
 * @package GravityForms_Checkout_Com_Pro
 * @since   1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frame payment method handler.
 *
 * @since 1.0.0
 */
class GF_Checkout_Com_Frame_Handler {

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
	}

	/**
	 * Render Frame payment form.
	 *
	 * @since 1.0.0
	 * @param array $form  The form object.
	 * @param array $entry The entry object.
	 * @param array $feed  The feed object.
	 * @return string Payment form HTML.
	 */
	public function render_payment_form( $form, $entry, $feed ) {
		$payment_amount   = rgar( $entry, 'payment_amount' );
		$currency         = rgar( $entry, 'currency' );
		$amount_formatted = GFCommon::to_money( $payment_amount, $currency );
		$return_url       = $this->gateway->return_url( $form['id'], $entry['id'] );

		// Get error message from payment_page_error property.
		$error_message = '';
		if ( ! empty( $this->gateway->get_payment_page_error() ) ) {
			$error_message = $this->gateway->get_payment_page_error();
		}

		ob_start();
		?>
		<div id="checkout-com-payment-container" class="checkout-payment-container">
			<h2><?php esc_html_e( 'Complete Your Payment', 'gravityforms-checkout-com-pro' ); ?></h2>
			
			<div class="order-summary">
				<h3><?php esc_html_e( 'Order Summary', 'gravityforms-checkout-com-pro' ); ?></h3>
				<div class="order-total">
					<strong><?php echo esc_html( $amount_formatted ); ?></strong>
				</div>
			</div>

			<form id="payment-form" method="POST" action="<?php echo esc_url( $return_url ); ?>" data-entry-id="<?php esc_attr( $entry['id'] ); ?>" data-form-id="<?php esc_attr( $form['id'] ); ?>">
				<div class="card-frame hidden"></div>
				<input id="checkout_payment_token" type="hidden" name="payment_token" value="" />
				
				<div id="checkout-loader">
					<div class="spinner"></div>
					<span>Processing payment...</span>
				</div>
				
				<button id="pay-button" type="submit" disabled>
					<?php esc_html_e( 'Pay Now', 'gravityforms-checkout-com-pro' ); ?>
				</button>
				<?php if ( ! empty( $error_message ) ) : ?>
					<div class="checkout-error-message">
						<strong>Payment Error:</strong> <?php echo esc_html( $error_message ); ?>
					</div>
				<?php endif; ?>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Enqueue Frame scripts and styles.
	 *
	 * @since 1.0.0
	 * @param array $feed The feed object.
	 * @param array $form The form object.
	 * @param array $entry The entry object.
	 */
	public function enqueue_scripts( $feed, $form, $entry ) {
		// Enqueue Checkout.com Frames library.
		wp_enqueue_script( 'checkout-frames', 'https://cdn.checkout.com/js/framesv2.min.js', array(), '2.0', true );

		// Enqueue Frame-specific CSS.
		wp_enqueue_style(
			'checkout-frame-styles',
			plugin_dir_url( __DIR__ ) . 'assets/css/checkout-frame.css',
			array(),
			'1.0'
		);

		// Enqueue Frame handler script.
		wp_enqueue_script(
			'checkout-frame-handler',
			plugin_dir_url( __DIR__ ) . 'assets/js/checkout-frame.js',
			array( 'jquery', 'checkout-frames' ),
			'1.0',
			true
		);

		// Localize script with configuration.
		$api_settings = $this->gateway->get_api_settings( $feed );
		wp_localize_script(
			'checkout-frame-handler',
			'checkoutComFrame',
			array(
				'publicKey' => $api_settings['public_key'],
				'form_id'   => $form['id'],
				'entry_id'  => $entry['id'],
			)
		);

		// Enqueue styles.
		wp_enqueue_style(
			'checkout-payment-styles',
			plugin_dir_url( __DIR__ ) . 'assets/css/checkout-payment.css',
			array(),
			'1.0'
		);
	}

	/**
	 * Process Frame payment.
	 *
	 * @since 1.0.0
	 * @param array $form  The form object.
	 * @param array $feed  The feed object.
	 * @param array $entry The entry object.
	 * @return array|WP_Error Payment response or error.
	 */
	public function process_payment( $form, $feed, $entry ) {
		$submission_data = $this->gateway->get_submission_data( $feed, $form, $entry );
		$this->gateway->log_debug( __METHOD__ . '(): Processing Frame payment via API.' );

		$payment_token = rgpost( 'payment_token' );
		error_log( 'Checkout.com Pro: Starting Frame payment processing for entry ' . $entry['id'] );
		error_log( 'Checkout.com Pro: Payment token received: ' . ( $payment_token ? 'Yes' : 'No' ) );

		if ( empty( $payment_token ) ) {
			error_log( 'Checkout.com Pro: ERROR - No payment token provided' );
			return new WP_Error( 'no_token', 'No payment token provided' );
		}

		try {
			$api_settings = $this->gateway->get_api_settings( $feed );
			error_log( 'Checkout.com Pro: Using mode: ' . rgar( $api_settings, 'mode' ) );

			$headers      = array(
				'Authorization' => $api_settings['secret_key'],
				'Content-Type'  => 'application/json',
			);
			$checkout_url = 'test' === rgar( $api_settings, 'mode' ) ? $this->gateway::CHECKOUT_COM_URL_TEST : $this->gateway::CHECKOUT_COM_URL_LIVE;
			error_log( 'Checkout.com Pro: API URL: ' . $checkout_url );

			$payment_args = array(
				'source'                => array(
					'type'  => 'token',
					'token' => $payment_token,
				),
				'metadata'              => array(
					'form_id'        => $form['id'],
					'entry_id'       => $entry['id'],
					'website_url'    => home_url(),
					'payment_method' => 'frame',
					'environment'    => rgar( $api_settings, 'mode' ),
				),
				'amount'                => $this->gateway->get_amount_export( rgar( $entry, 'payment_amount' ), rgar( $entry, 'currency' ) ),
				'currency'              => rgar( $entry, 'currency' ),
				'reference'             => rgar( $submission_data, 'reference' ) ? rgar( $submission_data, 'reference' ) : uniqid(),
				'description'           => rgar( $submission_data, 'order_summary' ) ? rgar( $submission_data, 'order_summary' ) : $form['title'],
				'capture'               => ! rgars( $feed, 'meta/auth_only' ) ? true : false,
				'success_url'           => $this->gateway->return_url( $form['id'], $entry['id'] ),
				'failure_url'           => $this->gateway->return_url( $form['id'], $entry['id'] ),
				'payment_ip'            => GFFormsModel::get_ip(),
				'processing_channel_id' => $api_settings['processing_channel_id'],
			);

			error_log( 'Checkout.com Pro: Payment amount: ' . $payment_args['amount'] . ' ' . $payment_args['currency'] );

			// Add 3DS configuration if enabled.
			if ( $this->gateway->get_3ds_setting( $feed ) ) {
				$payment_args['3ds'] = array(
					'enabled'     => true,
					'attempt_n3d' => true,
					'version'     => '2.0.1',
				);
				error_log( 'Checkout.com Pro: 3D Secure enabled for this payment' );
			}

			// Add customer data if available.
			if ( rgar( $submission_data, 'firstName' ) || rgar( $submission_data, 'lastName' ) ) {
				$payment_args['customer']['name'] = trim( rgar( $submission_data, 'firstName' ) . ' ' . rgar( $submission_data, 'lastName' ) );
			}
			if ( rgar( $submission_data, 'email' ) ) {
				$payment_args['customer']['email'] = rgar( $submission_data, 'email' );
			}

			error_log( 'Checkout.com Pro: Making API request to Checkout.com' );
			$response = wp_remote_post(
				$checkout_url,
				array(
					'headers' => $headers,
					'body'    => wp_json_encode( $payment_args ),
					'timeout' => 30,
				)
			);

			if ( is_wp_error( $response ) ) {
				error_log( 'Checkout.com Pro: API request failed: ' . $response->get_error_message() );
				$this->gateway->log_error( __METHOD__ . '(): API request failed: ' . $response->get_error_message() );
				return $response;
			}

			$response_code    = wp_remote_retrieve_response_code( $response );
			$body             = wp_remote_retrieve_body( $response );
			$payment_response = json_decode( $body, true );

			error_log( 'Checkout.com Pro: API response code: ' . $response_code );
			error_log( 'Checkout.com Pro: API response body: ' . $body );

			if ( ! in_array( $response_code, array( 200, 201, 202 ), true ) ) {
				$error_message = isset( $payment_response['error_type'] ) ? $payment_response['error_type'] : 'Payment processing failed';
				if ( isset( $payment_response['error_codes'] ) ) {
					$error_message .= ' - ' . implode( ', ', $payment_response['error_codes'] );
				}
				error_log( 'Checkout.com Pro: API error: ' . $error_message );
				$this->gateway->log_error( __METHOD__ . '(): API error: ' . $error_message );
				return new WP_Error( 'api_error', $error_message );
			}

			// Check if 3DS redirect is required.
			if ( isset( $payment_response['3ds']['is_redirect'] ) && true === $payment_response['3ds']['is_redirect'] && isset( $payment_response['_links']['redirect']['href'] ) ) {
				error_log( 'Checkout.com Pro: 3DS redirect required, redirecting user to authentication.' );
				$redirect_url = $payment_response['_links']['redirect']['href'];

				// Store transaction ID for when user returns from 3DS.
				if ( isset( $payment_response['id'] ) ) {
					GFAPI::update_entry_property( $entry['id'], 'transaction_id', $payment_response['id'] );
				}

				wp_redirect( $redirect_url );
				exit;
			}

			error_log( 'Checkout.com Pro: Payment processed successfully. Payment ID: ' . $payment_response['id'] );
			return $payment_response;

		} catch ( Exception $e ) {
			error_log( 'Checkout.com Pro: Exception during payment processing: ' . $e->getMessage() );
			$this->gateway->log_error( __METHOD__ . '(): Exception: ' . $e->getMessage() );
			return new WP_Error( 'payment_exception', $e->getMessage() );
		}
	}
}

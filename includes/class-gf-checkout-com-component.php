<?php
/**
 * Checkout.com Component Payment Handler
 *
 * @package GravityForms_Checkout_Com_Pro
 * @since   1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Component payment method handler.
 *
 * @since 1.0.0
 */
class GF_Checkout_Com_Component_Handler {

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

		add_action( 'wp_ajax_gf_checkout_com_create_session', array( $this, 'ajax_create_checkout_session' ) );
		add_action( 'wp_ajax_nopriv_gf_checkout_com_create_session', array( $this, 'ajax_create_checkout_session' ) );
		add_action( 'wp_ajax_gf_checkout_com_process_callback', array( $this, 'ajax_process_callback' ) );
		add_action( 'wp_ajax_nopriv_gf_checkout_com_process_callback', array( $this, 'ajax_process_callback' ) );
	}

	/**
	 * Render Component payment form.
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
			<h2><?php esc_html_e( 'Complete Your Payment', 'checkout-com-payment-gateway-pro-for-gravity-forms' ); ?></h2>
			
			<form id="payment-form" method="POST" action="<?php echo esc_url( $return_url ); ?>" data-entry-id="<?php echo $entry['id']; ?>" data-form-id="<?php echo $form['id']; ?>">
				<div id="checkout-loader">
							<div class="spinner"></div>
							<p><?php esc_html_e( 'Processing payment...', 'checkout-com-payment-gateway-pro-for-gravity-forms' ); ?></p>
						</div>
						<div id="checkout-component-container"></div>
						<input id="cko_session_id" type="hidden" name="cko_session_id" value="" />
						<button id="pay-button" type="submit" class="hidden">
							<?php esc_html_e( 'Pay Now', 'checkout-com-payment-gateway-pro-for-gravity-forms' ); ?>
						</button>
						<?php if ( ! empty( $error_message ) ) : ?>
							<div class="checkout-error-message">
								<strong>Payment Error:</strong> <?php echo esc_html( $error_message ); ?>
							</div>
						<?php endif; ?>
					</form>
					<?php
					/**
					 * Fires after the payment form.
					 *
					 * @since 1.2.0
					 *
					 * @param array $form  The Form Object.
					 * @param array $entry The Entry Object.
					 */
					do_action( 'gform_checkout_after_payment_form', $form, $entry );
					?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Enqueue Component scripts and styles.
	 *
	 * @since 1.0.0
	 * @param array $feed The feed object.
	 * @param array $form The form object.
	 * @param array $entry The entry object.
	 */
	public function enqueue_scripts( $feed, $form, $entry ) {
		// Enqueue Checkout.com Web Components library.
		wp_enqueue_script( 'checkout-web-components', 'https://checkout-web-components.checkout.com/index.js', array(), null, true );

		// Check if CSS should be disabled.
		$disable_css = $this->gateway->get_plugin_setting( 'disable_css' );

		if ( ! $disable_css ) {
			// Enqueue Component-specific CSS.
			wp_enqueue_style(
				'checkout-component-styles',
				plugin_dir_url( __DIR__ ) . 'assets/css/checkout-component.css',
				array(),
				GF_CHECKOUT_COM_PRO_VERSION
			);
			wp_enqueue_style(
				'checkout-payment-styles',
				plugin_dir_url( __DIR__ ) . 'assets/css/checkout-payment.css',
				array(),
				GF_CHECKOUT_COM_PRO_VERSION
			);
		}

		// Enqueue Component handler script.
		wp_enqueue_script(
			'checkout-component-handler',
			plugin_dir_url( __DIR__ ) . 'assets/js/checkout-component.js',
			array( 'jquery', 'checkout-web-components' ),
			GF_CHECKOUT_COM_PRO_VERSION,
			true
		);

		// Localize script with configuration.
		$api_settings = $this->gateway->get_api_settings( $feed );
		wp_localize_script(
			'checkout-component-handler',
			'checkoutComComponent',
			array(
				'publicKey'    => $api_settings['public_key'],
				'ajax_url'     => admin_url( 'admin-ajax.php' ),
				'create_nonce' => wp_create_nonce( 'gf_checkout_com_create_session' ),
				'form_id'      => $form['id'],
				'entry_id'     => $entry['id'],
			)
		);


		/**
		 * Action to enqueue scripts after the main plugin scripts.
		 *
		 * @since 1.2.0
		 *
		 * @param array $form  The Form Object.
		 * @param array $feed  The Feed Object.
		 * @param array $entry The Entry Object.
		 */
		do_action( 'gform_checkout_post_enqueue_scripts', $form, $feed, $entry );
	}

	/**
	 * Process Component payment (verify session).
	 *
	 * @since 1.0.0
	 * @param array $form  The form object.
	 * @param array $feed  The feed object.
	 * @param array $entry The entry object.
	 * @return array|WP_Error Payment response or error.
	 */
	public function process_payment( $form, $feed, $entry ) {
		$session_id = rgpost( 'cko_session_id' );
		$this->gateway->log_debug( 'Checkout.com Payment Gateway Pro: Starting Component payment processing for entry ' . $entry['id'] );
		$this->gateway->log_debug( 'Checkout.com Payment Gateway Pro: Session ID received: ' . ( $session_id ? 'Yes' : 'No' ) );

		if ( empty( $session_id ) ) {
			$this->gateway->log_error( 'Checkout.com Payment Gateway Pro: ERROR - No session ID provided' );
			return new WP_Error( 'no_session', 'No session ID provided' );
		}

		// For Component method, payment is already processed via session.
		// We just need to verify the payment status.
		return $this->gateway->get_payment_details_by_session( $session_id, $feed, $entry );
	}

	/**
	 * AJAX handler for creating checkout session (for component method).
	 */
	public function ajax_create_checkout_session() {
		check_ajax_referer( 'gf_checkout_com_create_session', 'nonce' );

		$entry_id = isset( $_POST['entry_id'] ) ? intval( $_POST['entry_id'] ) : 0;
		$form_id  = isset( $_POST['form_id'] ) ? intval( $_POST['form_id'] ) : 0;

		$entry = GFAPI::get_entry( $entry_id );
		$form  = GFAPI::get_form( $form_id );

		if ( is_wp_error( $entry ) || is_wp_error( $form ) ) {
			wp_send_json_error( array( 'message' => 'Invalid entry or form' ) );
		}

		$feed = $this->gateway->get_payment_feed( $entry );
		if ( ! $feed ) {
			wp_send_json_error( array( 'message' => 'No payment feed found' ) );
		}

		// Create payment session.
		$session_data = $this->create_payment_session( $form, $entry, $feed );

		if ( is_wp_error( $session_data ) ) {
			wp_send_json_error( array( 'message' => $session_data->get_error_message() ) );
		}

		wp_send_json_success( $session_data );
	}

	/**
	 * AJAX handler for processing payment callbacks.
	 */
	public function ajax_process_callback() {
		check_ajax_referer( 'gf_checkout_com_create_session', 'nonce' );

		$entry_id   = isset( $_POST['entry_id'] ) ? intval( $_POST['entry_id'] ) : 0;
		$form_id    = isset( $_POST['form_id'] ) ? intval( $_POST['form_id'] ) : 0;
		$session_id = isset( $_POST['session_id'] )
			? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';

		$entry = GFAPI::get_entry( $entry_id );
		$form  = GFAPI::get_form( $form_id );

		if ( is_wp_error( $entry ) || is_wp_error( $form ) ) {
			wp_send_json_error( array( 'message' => 'Invalid entry or form' ) );
		}

		// Process the callback.
		$callback_action = $this->gateway->checkout_com_callback( $form, $entry );

		if ( is_wp_error( $callback_action ) ) {
			wp_send_json_error( array( 'message' => $callback_action->get_error_message() ) );
		}

		if ( is_array( $callback_action ) && rgar( $callback_action, 'type' ) ) {
			$result = $this->gateway->checkout_com_process_callback_action( $callback_action );

			if ( $result ) {
				$confirmation = GFFormDisplay::handle_confirmation( $form, $entry, false );

				if ( is_array( $confirmation ) && isset( $confirmation['redirect'] ) ) {
					wp_send_json_success( array( 'redirect_url' => $confirmation['redirect'] ) );
				} else {
					// Return to form with confirmation message.
					wp_send_json_success( array( 'redirect_url' => $this->gateway->return_url( $form_id, $entry_id ) ) );
				}
			} else {
				wp_send_json_error( array( 'message' => 'Payment processing failed' ) );
			}
		} else {
			wp_send_json_error( array( 'message' => 'Invalid callback action' ) );
		}
	}

	/**
	 * Create payment session for component method.
	 *
	 * @param array $form  The form object/array.
	 * @param array $entry The entry data.
	 * @param array $feed  The feed configuration.
	 */
	private function create_payment_session( $form, $entry, $feed ) {
		$api_settings = $this->gateway->get_api_settings( $feed );
		$amount_cents = intval( floatval( $entry['payment_amount'] ) * 100 );

		// Get billing information from entry.
		$billing_info = $this->get_billing_info_from_entry( $entry );

		$session_data = array(
			'amount'                => $amount_cents,
			'currency'              => rgar( $entry, 'currency', 'USD' ),
			'reference'             => "GF-{$form['id']}-{$entry['id']}",
			'processing_channel_id' => rgar( $api_settings, 'processing_channel_id' ),

			'billing'               => array(
				'address' => array(
					'address_line1' => $billing_info['address']['address_line_1'],
					'city'          => $billing_info['address']['city'],
					'state'         => $billing_info['address']['state'],
					'zip'           => $billing_info['address']['zip'],
					'country'       => $billing_info['address']['country'],
				),
			),
			'customer'              => array(
				'email' => $this->gateway->get_field_value( $entry, $feed, 'email' ),
				'name'  => trim( $this->gateway->get_field_value( $entry, $feed, 'firstName' ) . ' ' . $this->gateway->get_field_value( $entry, $feed, 'lastName' ) ),
			),
			'success_url'           => esc_url_raw( $this->gateway->return_url( $form['id'], $entry['id'] ) ),
			'failure_url'           => esc_url_raw( $this->gateway->return_url( $form['id'], $entry['id'] ) ),
			'metadata'              => array(
				'form_id'     => $form['id'],
				'entry_id'    => $entry['id'],
				'website_url' => home_url(),
			),
		);

		// Add 3DS configuration if enabled.
		if ( $this->gateway->get_3ds_setting( $feed ) ) {
			$session_data['3ds'] = array(
				'enabled'     => true,
				'attempt_n3d' => true,
			);
		}

		// Only add address_line2 if it has a value (API doesn't like empty strings).
		if ( ! empty( $billing_info['address']['address_line_2'] ) ) {
			$session_data['billing']['address']['address_line2'] = $billing_info['address']['address_line_2'];
		}

		// Only add phone if it has a valid value (API doesn't like empty or invalid phone numbers).
		$phone_number = $this->gateway->get_field_value( $entry, $feed, 'phone' );
		$phone_clean  = preg_replace( '/[^0-9+]/', '', $phone_number );
		if ( ! empty( $phone_clean ) && strlen( $phone_clean ) >= 10 && ! filter_var( $phone_number, FILTER_VALIDATE_EMAIL ) ) {
			$session_data['billing']['phone'] = array(
				'number' => $phone_clean,
			);
		}

		// Ensure customer email is valid.
		if ( empty( $session_data['customer']['email'] ) || ! is_email( $session_data['customer']['email'] ) ) {
			$session_data['customer']['email'] = 'test@example.com';
		}

		$api_url = ( 'test' === $api_settings['mode'] )
		? $this->gateway::CHECKOUT_COM_SESSIONS_URL_TEST
		: $this->gateway::CHECKOUT_COM_SESSIONS_URL_LIVE;

		$response = wp_remote_post(
			$api_url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_settings['secret_key'],
					'Content-Type'  => 'application/json;charset=UTF-8',
				),
				'body'    => wp_json_encode( $session_data ),
				'timeout' => 60,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'api_error', 'Failed to create payment session: ' . $response->get_error_message() );
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$body          = wp_remote_retrieve_body( $response );
		$data          = json_decode( $body, true );

		if ( 201 !== $response_code ) {
			return new WP_Error( 'api_error', 'Payment session creation failed: ' . rgar( $data, 'error_type', 'Unknown error' ) );
		}

		// Add environment to response for JavaScript.
		$data['environment'] = 'test' === $api_settings['mode'] ? 'sandbox' : 'production';

		return $data;
	}

	/**
	 * Get billing information from entry using feed field mappings.
	 *
	 * @param array $entry The entry data.
	 */
	private function get_billing_info_from_entry( $entry ) {
		$feed = $this->gateway->get_payment_feed( $entry );

		// Use Gravity Forms standard method to get billing info.
		$billing_info = array(
			'address' => array(
				'address_line_1' => $this->gateway->get_field_value( $entry, $feed, 'billingInformation_address_line_1' ),
				'address_line_2' => $this->gateway->get_field_value( $entry, $feed, 'billingInformation_address_line_2' ),
				'city'           => $this->gateway->get_field_value( $entry, $feed, 'billingInformation_city' ),
				'state'          => $this->gateway->get_field_value( $entry, $feed, 'billingInformation_state' ),
				'zip'            => $this->gateway->get_field_value( $entry, $feed, 'billingInformation_zip' ),
				'country'        => $this->gateway->get_field_value( $entry, $feed, 'billingInformation_country' ),
			),
		);

		// If no billing info found from feed mappings, use realistic defaults.
		if ( empty( $billing_info['address']['address_line_1'] ) && empty( $billing_info['address']['city'] ) ) {
			$billing_info['address'] = array(
				'address_line_1' => '123 Main Street',
				'address_line_2' => '',
				'city'           => 'Los Angeles',
				'state'          => 'CA',
				'zip'            => '90210',
				'country'        => 'US',
			);
		}

		if ( empty( $billing_info['address']['address_line_1'] ) ) {
			$billing_info['address']['address_line_1'] = '123 Main Street';
		}
		if ( ! isset( $billing_info['address']['address_line_2'] ) ) {
			$billing_info['address']['address_line_2'] = '';
		}
		if ( empty( $billing_info['address']['city'] ) ) {
			$billing_info['address']['city'] = 'Los Angeles';
		}
		if ( empty( $billing_info['address']['state'] ) ) {
			$billing_info['address']['state'] = 'CA';
		}
		if ( empty( $billing_info['address']['zip'] ) ) {
			$billing_info['address']['zip'] = '90210';
		}
		if ( empty( $billing_info['address']['country'] ) ) {
			$billing_info['address']['country'] = 'US';
		}

		return $billing_info;
	}
}

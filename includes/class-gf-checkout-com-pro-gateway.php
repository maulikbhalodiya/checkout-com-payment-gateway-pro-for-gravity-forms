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
						'name'    => 'test_secret_key',
						'label'   => esc_html__( 'Test Secret Key', 'gravityforms-checkout-com-pro' ),
						'type'    => 'text',
						'class'   => 'medium',
						'tooltip' => esc_html__( 'Enter your Checkout.com test secret key.', 'gravityforms-checkout-com-pro' ),
					),
					array(
						'name'    => 'test_processing_channel_id',
						'label'   => esc_html__( 'Test Processing Channel ID', 'gravityforms-checkout-com-pro' ),
						'type'    => 'text',
						'class'   => 'medium',
						'tooltip' => esc_html__( 'Enter your Checkout.com test processing channel ID.', 'gravityforms-checkout-com-pro' ),
					),
					array(
						'name'    => 'live_public_key',
						'label'   => esc_html__( 'Live Public Key', 'gravityforms-checkout-com-pro' ),
						'type'    => 'text',
						'class'   => 'medium',
						'tooltip' => esc_html__( 'Enter your Checkout.com live public key.', 'gravityforms-checkout-com-pro' ),
					),
					array(
						'name'    => 'live_secret_key',
						'label'   => esc_html__( 'Live Secret Key', 'gravityforms-checkout-com-pro' ),
						'type'    => 'text',
						'class'   => 'medium',
						'tooltip' => esc_html__( 'Enter your Checkout.com live secret key.', 'gravityforms-checkout-com-pro' ),
					),
					array(
						'name'    => 'live_processing_channel_id',
						'label'   => esc_html__( 'Live Processing Channel ID', 'gravityforms-checkout-com-pro' ),
						'type'    => 'text',
						'class'   => 'medium',
						'tooltip' => esc_html__( 'Enter your Checkout.com live processing channel ID.', 'gravityforms-checkout-com-pro' ),
					),
					array(
						'name'    => 'webhook_secret',
						'label'   => esc_html__( 'Webhook Secret Key', 'gravityforms-checkout-com-pro' ),
						'type'    => 'text',
						'class'   => 'medium',
						'tooltip' => esc_html__( 'Enter your Checkout.com webhook secret key.', 'gravityforms-checkout-com-pro' ),
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
			'webhook_secret'         => rgar( $settings, 'webhook_secret' ),
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
		);

		foreach ( $required_fields as $field ) {
			if ( empty( $settings[ $field ] ) ) {
				$this->set_field_error( $field, esc_html__( 'This field is required.', 'gravityforms-checkout-com-pro' ) );
			}
		}

		return $settings;
	}
}

<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Tabby Installments payment method integration
 *
 */
final class WC_Blocks_Tabby_Installments extends AbstractPaymentMethodType {
    private $gateway;
	/**
	 * Payment method name/id/slug (matches id in WC_Gateway_Tabby_Installments in core).
	 *
	 * @var string
	 */
	protected $name = WC_Gateway_Tabby_Installments::METHOD_CODE;

	/**
	 * An instance of the Asset Api
	 *
	 * @var Api
	 */
	private $asset_api;

	/**
	 * Constructor
	 *
	 * @param Api $asset_api An instance of Api.
	 */
	public function __construct() {
        $this->gateway = new WC_Gateway_Tabby_Installments();
	}

	/**
	 * Initializes the payment method type.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_'.$this->name.'_settings', [] );
	}

	/**
	 * Returns if this payment method should be active. If false, the scripts will not be enqueued.
	 *
	 * @return boolean
	 */
	public function is_active() {
		return filter_var( $this->get_setting( 'enabled', false ), FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Returns an array of scripts/handles to be registered for this payment method.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles() {
		wp_enqueue_script(
			'wc-payment-method-tabby-installments',
			plugins_url('assets/blocks/tabby-installments/index.js',
            dirname(__FILE__)),
            ['jquery', 'react', 'react-dom', 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-i18n', 'wp-html-entities'],
            MODULE_TABBY_CHECKOUT_VERSION,
            true
		);
		return [ 'wc-payment-method-tabby-installments' ];
	}

	/**
	 * Returns an array of key=>value pairs of data made available to the payment methods script.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		return [
			'title'       => $this->gateway->get_title(),
			'icon_src'    => $this->gateway->icon,
			'config'      => $this->gateway->get_description_config(),
			'supports'    => $this->get_supported_features(),
		];
	}
}

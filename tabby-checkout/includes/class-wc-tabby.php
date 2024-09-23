<?php

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;

class WC_Tabby {
    public static function init() {
        WC_Settings_Tab_Tabby::init();
        WC_Tabby_AJAX::init();
        WC_Tabby_Promo::init();
        WC_Tabby_Cron::init();
        WC_REST_Tabby_Controller::init();

        static::init_methods();

        add_action( 'init', array( __CLASS__, 'init_textdomain'));

        register_activation_hook  ( 'tabby-checkout/tabby-checkout.php', array( __CLASS__, 'on_activation'  ));
        register_deactivation_hook( 'tabby-checkout/tabby-checkout.php', array( __CLASS__, 'on_deactivation'));
    }
    public static function init_methods() {
        add_filter( 'woocommerce_payment_gateways', array(__CLASS__, 'add_checkout_methods'));

        add_action( 'woocommerce_blocks_loaded', array(__CLASS__, 'woocommerce_blocks_support'));

    }
    public static function woocommerce_blocks_support() {
        if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
            add_action(
                'woocommerce_blocks_payment_method_type_registration',
                function( PaymentMethodRegistry $payment_method_registry ) {
                    $payment_method_registry->register( new WC_Blocks_Tabby_Installments );
                }
            );
        }
        if ( interface_exists( 'Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface' ) && (get_option('tabby_promo_cart') !== 'no')) {
            add_action(
                'woocommerce_blocks_cart_block_registration',
                function( $integration_registry ) {
                    $integration_registry->register( new WC_Blocks_Tabby_Cart_Promotion() );
                }
            );
        }
    }
    public static function add_checkout_methods( $methods ) {
        if (get_option('tabby_checkout_mode', 'payment') == 'payment') {
            $methods[] = 'WC_Gateway_Tabby_Installments';
            if ( !isset( $_REQUEST['page'] ) ||  'wc-settings' !== $_REQUEST['page'] ) {
                $methods[] = 'WC_Gateway_Tabby_PayLater';
                $methods[] = 'WC_Gateway_Tabby_Credit_Card_Installments';
            }
        }
        return $methods;
    }

    public static function on_activation() {
        wp_schedule_single_event( time() + 60 , 'woocommerce_tabby_cancel_unpaid_orders' );
        WC_Tabby_Webhook::register();
    }

    public static function on_deactivation() {
        wp_clear_scheduled_hook( 'woocommerce_tabby_cancel_unpaid_orders' );
        WC_Tabby_Webhook::unregister();
    }
    
    public static function init_textdomain() {
        load_plugin_textdomain( 'tabby-checkout', false, plugin_basename( dirname(__DIR__) ) . '/i18n/languages' );
    }

}

<?php
/**
 * Plugin Name: Tabby Checkout
 * Plugin URI: https://tabby.ai/
 * Description: Tabby Checkout
 * Version: 5.6.2
 * Author: Tabby
 * Author URI: https://tabby.ai
 * License: GPLv2
 * Text Domain: tabby-checkout
 * Domain Path: /i18n/languages/
 */

defined( 'ABSPATH' ) || exit;

define ('MODULE_TABBY_CHECKOUT_VERSION', '5.6.2');
define ('TABBY_CHECKOUT_DOMAIN', 'checkout.tabby.ai');
define ('TABBY_CHECKOUT_API_DOMAIN', 'api.tabby.ai');
define ('TABBY_FEED_API_DOMAIN', 'plugins-api.tabby.ai');

include 'includes/functions.php';

WC_Tabby::init();

add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

    

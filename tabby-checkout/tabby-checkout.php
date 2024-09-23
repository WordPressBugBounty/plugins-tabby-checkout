<?php
/**
 * Plugin Name: Tabby Checkout
 * Plugin URI: https://tabby.ai/
 * Description: Tabby Checkout
 * Version: 4.10.6
 * Author: Tabby
 * Author URI: https://tabby.ai
 * License: GPLv2
 * Text Domain: tabby-checkout
 * Domain Path: /i18n/languages/
 */

defined( 'ABSPATH' ) || exit;

define ('MODULE_TABBY_CHECKOUT_VERSION', '4.10.6');
define ('TABBY_CHECKOUT_DOMAIN', 'checkout.tabby.ai');
define ('TABBY_CHECKOUT_API_DOMAIN', 'api.tabby.ai');

include 'includes/functions.php';

WC_Tabby::init();

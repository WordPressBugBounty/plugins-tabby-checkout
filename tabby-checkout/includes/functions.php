<?php

function woocommerce_tabby_get_order_reference_id($order) {
    return apply_filters('woocommerce_tabby_get_order_reference_id', $order->get_id(), $order);
}
function woocommerce_tabby_get_order_by_reference_id($reference_id) {
    return apply_filters('woocommerce_tabby_get_order_by_reference_id', wc_get_order($reference_id), $reference_id);
}

spl_autoload_register(function ($name) {
    $filename = __DIR__ . '/class-' . strtolower(str_replace('_', '-', $name)) . '.php';

    if (file_exists($filename)) {
        require_once($filename);
        return true;
    }

    return false;
});

// bypass default woocommerce logic for stock for tabby orders
function tabby_check_order_paid($canDelete, $order) {
    $gateway = wc_get_payment_gateway_by_order($order);

    if ($gateway instanceof WC_Gateway_Tabby_Checkout_Base) {
        $canDelete = false;
    }

    return $canDelete;
}

add_filter( 'woocommerce_cancel_unpaid_order', 'tabby_check_order_paid', 10, 2);

// check order authorized on thank you page
add_filter('woocommerce_thankyou_order_id', 'tabby_thankyou_order_id');
// block themes render the order confirmation with blocks that never apply the filter above,
// so run the same check when the order-received endpoint is opened (no-op if already handled)
add_action('template_redirect', 'tabby_order_received_page_check');
function tabby_order_received_page_check() {
    if (!function_exists('is_order_received_page') || !is_order_received_page()) return;
    $order_id = absint(get_query_var('order-received'));
    if (!$order_id) return;
    $order = wc_get_order($order_id);
    if (!$order || !(wc_get_payment_gateway_by_order($order) instanceof WC_Gateway_Tabby_Checkout_Base)) return;
    tabby_thankyou_order_id($order_id);
}
function tabby_order_key_matches($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return false;
    // the order key, as WooCommerce itself reads it (custom thank-you pages can supply it via the filter)
    $key = (isset($_GET['key']) && is_string($_GET['key'])) ? wc_clean(wp_unslash($_GET['key'])) : '';
    $key = apply_filters('woocommerce_thankyou_order_key', $key);
    if (is_string($key) && $key !== '' && hash_equals($order->get_order_key(), $key)) return true;
    // Tabby appends payment_id to every success redirect; it matches only the buyer's own payment
    $payment_id = (isset($_GET['payment_id']) && is_string($_GET['payment_id'])) ? wc_clean(wp_unslash($_GET['payment_id'])) : '';
    $stored = (string)($order->get_meta('_tabby_payment', true) ?: $order->get_transaction_id());
    return $payment_id !== '' && $stored !== '' && hash_equals($stored, $payment_id);
}
function tabby_thankyou_order_id($order_id) {
    global $wp;

    if (!$order_id) {
        $current_session_order_id = isset( WC()->session->order_awaiting_payment ) ? absint( WC()->session->order_awaiting_payment ) : 0;
        if (!$current_session_order_id) {
            $current_session_order_id = isset( WC()->session->tabby_order_id ) ? absint( WC()->session->tabby_order_id ) : 0;
            unset(WC()->session->tabby_order_id);
        }
        if ($current_session_order_id) {
            $order_id = $current_session_order_id;

            $order = wc_get_order( $order_id );

            if (!$order) return $order_id;

            if (home_url( $wp->request ) != $order->get_checkout_order_received_url()) {
                wp_redirect($order->get_checkout_order_received_url());

                exit();
            }
        }
    }

    // the block-theme hook and the classic filter can both fire in one request: check once
    static $checked = [];
    if ($order_id && isset($checked[$order_id])) return $order_id;
    if ($order_id) $checked[$order_id] = true;

    // WooCommerce applies this filter before it validates the order key, so only the buyer
    // holding the key (the Tabby redirect carries it) may trigger the payment check
    if ($order_id && !tabby_order_key_matches($order_id)) return $order_id;

    if ($order_id) {
        $lock = new WC_Tabby_Lock();
        if ($lock->lock($order_id)) {
            $order = wc_get_order( $order_id );
            if ($order && $order->has_status('pending')) WC_Tabby_Cron::tabby_check_order_paid_real(true, $order, 'thank you page');
            $lock->unlock($order_id);
        }
    }

    return $order_id;
}


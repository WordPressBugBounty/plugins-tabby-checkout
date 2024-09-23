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

    if ($order_id) {
        $lock = new WC_Tabby_Lock();
        if ($lock->lock($order_id)) {
            $order = wc_get_order( $order_id );
            if ($order->has_status('pending')) WC_Tabby_Cron::tabby_check_order_paid_real(true, $order, 'thank you page');
            $lock->unlock($order_id);
        }
    }

    return $order_id;
}


<?php

class WC_Tabby_Cron {

    public static function init() {
        add_action( 'woocommerce_tabby_cancel_unpaid_orders', array(__CLASS__, 'tabby_cancel_unpaid_orders') );

        /*
        $cron = (array) _get_cron_array();
        $task_found = false;
        foreach ($cron as $t => $task) {
                if (array_key_exists('woocommerce_tabby_cancel_unpaid_orders', $task) && $t < (time() - 10*60)) {
                        wp_clear_scheduled_hook( 'woocommerce_tabby_cancel_unpaid_orders' );
                        add_action( 'woocommerce_after_register_post_type', 'tabby_cancel_unpaid_orders' );
                        $task_found = true;
                }
        }
        if (!$task_found) wp_schedule_single_event( time() + 120, 'woocommerce_tabby_cancel_unpaid_orders' );
        */
    }
    public static function tabby_check_order_paid_real($canDelete, $order, $source = 'cron') {
        $gateway = wc_get_payment_gateway_by_order($order);
    
        if ($gateway instanceof WC_Gateway_Tabby_Checkout_Base) {
            try {
                $payment_id = $gateway->get_tabby_payment_id($order->get_id());
                WC_Tabby_Api::ddlog("info", "Try to authorize order by " . $source, null, [
                    'payment.id'    => $payment_id,
                    'order.reference_id'      => woocommerce_tabby_get_order_reference_id($order)
                ]);
                // delete orders without payment id assigned
                if (empty($payment_id)) return true;

                if ($gateway->authorize($order, $payment_id)) {
                    $order->payment_complete($payment_id);
                    $canDelete = false;
                }
                if (!$gateway->is_payment_expired($order, $payment_id)) {
                    $canDelete = false;
                }
            } catch (\Exception $e) {
                $canDelete = false;
            }
        } else {
            $canDelete = false;
        }
    
        return $canDelete;
    }

    /**
    * Cancel/delete all unpaid orders after tabby timeout
    */
    public static function tabby_cancel_unpaid_orders() {
    
        wp_clear_scheduled_hook( 'woocommerce_tabby_cancel_unpaid_orders' );
        wp_schedule_single_event( time() + 120, 'woocommerce_tabby_cancel_unpaid_orders' );
    
        $timeout = get_option( 'tabby_checkout_order_timeout' );
        $lock = new WC_Tabby_Lock();
    
        if ( $timeout < 1 || 'yes' !== get_option( 'woocommerce_manage_stock' ) ) {
            return;
        }
    
        $data_store    = WC_Data_Store::load( 'order' );
        $unpaid_orders = $data_store->get_unpaid_orders( strtotime( '-' . absint( $timeout ) . ' MINUTES', current_time( 'timestamp' ) ) );
    
        if ( $unpaid_orders ) {
            foreach ( $unpaid_orders as $unpaid_order ) {
                if ($lock->lock($unpaid_order)) {
                    $order = wc_get_order( $unpaid_order );
    
                    if ( $order->has_status('pending') && static::tabby_check_order_paid_real( 'checkout' === $order->get_created_via(), $order )) {
                        // restock order
                        $order->update_status( 'cancelled', __( 'Tabby unpaid order cancelled - time limit reached.', 'tabby-checkout' ) );
                        // delete order
                        $failed_action = get_option('tabby_checkout_failed_action', 'delete');
                        if (in_array($failed_action, ['delete', 'trash'])) {
                            $data_store->delete($order, ['force_delete' => $failed_action == 'delete']);
                        }
                    }
                    $lock->unlock($unpaid_order);
                } else {
                    // cannot lock
                    // just ignore
                }
            }
        }
    }
}


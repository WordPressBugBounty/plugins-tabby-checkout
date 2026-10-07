<?php

use Automattic\WooCommerce\Caches\OrderCache;

class WC_REST_Tabby_Controller {
    const NS = 'tabby/v1';

    const BASE = 'webhook';

    public static function init() {
        add_filter( 'woocommerce_rest_api_get_rest_namespaces', array('WC_REST_Tabby_Controller', 'register'));
    }

    const MISSED_OPTION = 'tabby_webhook_missed';
    const MISSED_KEEP   = 200;

    /**
     * True the first time a payment id is seen with no matching order (last MISSED_KEEP ids remembered).
     */
    protected static function first_miss($payment_id) {
        if (!preg_match('/^[0-9a-f-]{36}$/i', $payment_id)) return false;
        $seen = get_option(self::MISSED_OPTION, []);
        if (!is_array($seen)) $seen = [];
        if (in_array($payment_id, $seen, true)) return false;
        $seen[] = $payment_id;
        if (count($seen) > self::MISSED_KEEP) $seen = array_slice($seen, -self::MISSED_KEEP);
        update_option(self::MISSED_OPTION, $seen, false);
        return true;
    }

    public function webhook($data) {
        
        try {
            $txn = json_decode($data->get_body());

            if (is_object($txn) && property_exists($txn, 'id') && property_exists($txn, 'order') && is_object($txn->order) && property_exists($txn->order, 'reference_id')) {

                WC_Tabby_Api::ddlog('info', 'webhook received', null, [
                    'payment.id'         => $txn->id,
                    'order.reference_id' => $txn->order->reference_id,
                    'payment.status'     => property_exists($txn, 'status') ? $txn->status : null,
                    'payment.amount'     => property_exists($txn, 'amount') ? $txn->amount : null,
                    'payment.currency'   => property_exists($txn, 'currency') ? $txn->currency : null,
                    'payment.is_test'    => property_exists($txn, 'is_test') ? $txn->is_test : null,
                ]);
            
                if ($order = woocommerce_tabby_get_order_by_reference_id( $txn->order->reference_id )) {
                    // check order payed with Tabby
                    $gateway = wc_get_payment_gateway_by_order($order);
                    if (!($gateway instanceof WC_Gateway_Tabby_Checkout_Base)) {
                        throw new \Exception('Order payment gateway is set to "' . get_class($gateway) . '". Ignoring.');
                    }
                    if ($gateway->get_tabby_payment_id($order) != $txn->id) {
                        WC_Tabby_Api::ddlog('info', 'webhook wrong transaction id for order', null, [
                            'payment.id'            => $txn->id,
                            'order.reference_id'    => $txn->order->reference_id,
                            'order.transaction_id'  => $gateway->get_tabby_payment_id($order)
                        ]);
                    } else {
                        $lock = new WC_Tabby_Lock();
                        if ($lock->lock($order->get_id())) {
                            if (class_exists('OrderCache')) {
                                $order_cache = wc_get_container()->get( OrderCache::class );
                                $order_cache->remove($order->get_id());
                            }
                            $order = woocommerce_tabby_get_order_by_reference_id( $txn->order->reference_id );
                            if ($order->has_status(  wc_get_is_pending_statuses() )) {
                                WC_Tabby_Cron::tabby_check_order_paid_real(true, $order, 'webhook');
                            }
                        } else {
                            throw new \Exception("Cannot get lock on order " . $order->get_id());
                        }
                        $lock->unlock($order->get_id());
                    }
                } else {
                    // usual causes: the unpaid order was already deleted by the timeout cron, or the payment
                    // belongs to another site on the same Tabby keys (staging copy, second domain, app).
                    // Answer 503 as before so Tabby keeps retrying (the order may just not be visible yet),
                    // but log each payment once; the seen list is one capped option, so requests cannot grow the DB.
                    if (static::first_miss((string)$txn->id)) {
                        WC_Tabby_Api::ddlog_with('warn', 'webhook exception', [
                            'payment.id'         => $txn->id,
                            'order.reference_id' => $txn->order->reference_id,
                        ], ['reason' => 'order_not_found']);
                    }
                    return new WP_Error('tabby_webhook_error', __('Webhook execution error'), array('status' => 503));
                }
            } else {
                throw new \Exception("Not valid data posted");
            }

        } catch (\Throwable $e) {
            WC_Tabby_Api::ddlog('info', 'webhook exception', $e, [
                'body'               => $data->get_body()
            ]);
            return new WP_Error(
                'tabby_webhook_error',
                __('Webhook execution error'),
                array('status'  => 503)
            );
        }
        return ['result' => 'success'];
    }

    public function register_routes() {
        register_rest_route(
            self::NS,
            '/' . self::BASE,
            array(
                'methods' => array('POST'),
                'callback' => array( $this, 'webhook' ),
                'permission_callback' => '__return_true',
            )
        );
    }
    public static function register($controllers) {
        $controllers[self::NS][self::BASE] = __CLASS__;
        return $controllers;
    }
    public static function getEndpointUrl() {
        return get_rest_url(null, self::NS . '/' . self::BASE);
    }
}

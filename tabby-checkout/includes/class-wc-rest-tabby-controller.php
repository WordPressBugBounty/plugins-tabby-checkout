<?php


class WC_REST_Tabby_Controller {
    const NS = 'tabby/v1';

    const BASE = 'webhook';

    public static function init() {
        add_filter( 'woocommerce_rest_api_get_rest_namespaces', array('WC_REST_Tabby_Controller', 'register'));
    }

    public function webhook($data) {
        
        try {
            $txn = json_decode($data->get_body());

            if ($txn && property_exists($txn, 'order') && property_exists($txn->order, 'reference_id')) {

                WC_Tabby_Api::ddlog('info', 'webhook received', null, [
                    'payment.id'         => $txn->id,
                    'order.reference_id' => $txn->order->reference_id,
                    'body'               => $txn
                ]);
            
                if ($order = woocommerce_tabby_get_order_by_reference_id( $txn->order->reference_id )) {
                    $lock = new WC_Tabby_Lock();
                    if ($lock->lock($order->get_id())) {
                        $order = woocommerce_tabby_get_order_by_reference_id( $txn->order->reference_id );
                        if ($order->has_status('pending')) WC_Tabby_Cron::tabby_check_order_paid_real(true, $order, 'webhook');
                    }
                    $lock->unlock($order->get_id());
                } else {
                    throw new \Exception("Not order found with reference id: " . $txn->order->reference_id);
                }
            } else {
                throw new \Exception("Not valid data posted");
            }

        } catch (\Exception $e) {
            WC_Tabby_Api::ddlog('info', 'webhook exception', $e, [
                'body'               => $data->get_body()
            ]);
            return new WP_Error(
                'tabby_webhook_error',
                __('Webhook execution error: ' . $e->getMessage()),
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

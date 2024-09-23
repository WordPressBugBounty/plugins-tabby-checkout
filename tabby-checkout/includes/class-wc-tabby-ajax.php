<?php
class WC_Tabby_AJAX {
    public static function init() {
        add_action( 'wc_ajax_get_order_history',   array( __CLASS__, 'get_order_history' ) );
        add_filter( 'query_vars',                  array( __CLASS__, 'query_vars'        ) );
        add_filter( 'woocommerce_get_script_data', array( __CLASS__, 'get_script_data'   ) , 10, 2);
    }
    public static function get_script_data($params, $handle) {
        if ($handle == 'wc-checkout') {
            $params['get_order_history_nonce'] = wp_create_nonce( 'get_order_history' );
        }
        return $params;
    }
    public static function query_vars( $vars ) {
        $vars[] = 'email';
        $vars[] = 'phone';
        return $vars;
    }
    public static function get_order_history() {

        check_ajax_referer( 'get_order_history', 'security' );

        $email = get_query_var('email', false);
        $phone = get_query_var('phone', false);

        $data = [
            "email" => $email,
            "phone" => $phone,
            "order_history" => self::getOrderHistoryObject($email, $phone)
        ];

        wp_send_json( $data );
    }
    public static function getOrderHistoryObject($email, $phone) {
        $result = [];
        if (!$email) return $result;

        $sha256 = hash('sha256', json_encode([$email, $phone]));
        $tr_name = 'tabby_oh_' . $sha256;
        if (($order_history = get_transient($tr_name)) !== false) {
            return $order_history;
        }

        $wc2tabby = [
            //'pending' => 'processing',
            //'processing' => 'processing',
            //'on-hold' => 'processing',
            'completed' => 'complete',
            'cancelled' => 'canceled',
            'refunded' => 'refunded',
            'failed' => 'canceled',
        ];

        $ids = wc_get_orders(['return' => 'ids', 'email' => $email, 'status' => array_keys($wc2tabby)]);
        
        if ($phone) {
            $ids = array_merge($ids, wc_get_orders(['return' => 'ids', 'billing_phone' => $phone, 'status' => array_keys($wc2tabby)]));
            $ids = array_unique($ids);
        }
        rsort($ids, SORT_NUMERIC);
        $ids = array_slice($ids, 0, 10);
        $orders = array_filter( array_map( 'wc_get_order', $ids ) );
        foreach ($orders as $order) {
            if (array_key_exists($order->get_status(), $wc2tabby)) {
                $result[] = self::getOrderHistoryOrderObject($order, $wc2tabby[$order->get_status()]);
            }
        }

        set_transient($tr_name, $result, HOUR_IN_SECONDS);

        return $result;
    }
    protected static function getOrderHistoryOrderObject($order, $tabby_status) {

        return [
            "amount"            => $order->get_total(),
            "payment_method"    => $order->get_payment_method(),
            "purchased_at"      => date(\DateTime::RFC3339, strtotime($order->get_date_created())),
            "status"            => $tabby_status,
            "buyer"             => self::getOrderHistoryOrderBuyerObject($order),
            "shipping_address"  => self::getOrderHistoryOrderShippingAddressObject($order),
            "items"             => self::getOrderHistoryOrderItemsObject($order)
        ];
    }
    protected static function getOrderHistoryOrderBuyerObject($order) {
        return [
            "name"  => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            "phone" => $order->get_billing_phone(),
            "email" => $order->get_billing_email()
        ];
    }
    protected static function getOrderHistoryOrderShippingAddressObject($order) {
        $address = $order->get_shipping_address_1() . ($order->get_shipping_address_2() ? ', '.$order->get_shipping_address_2() :'');
        if (empty($address)) {
            $address = $order->get_billing_address_1() . ($order->get_billing_address_2() ? ', '.$order->get_billing_address_2() :'');
        };
        $city = $order->get_shipping_city();
        if (empty($city)) {
            $city = $order->get_billing_city();
        }
        return [
            "address"   => $address,
            "city"      => $city
        ];
    }
    protected static function getOrderHistoryOrderItemsObject($order) {
        $items = [];
        foreach ($order->get_items() as $item) {
            $items[] = [
                "quantity"      => $item->get_quantity(),
                "title"         => $item->get_name(),
                "unit_price"    => $order->get_item_total($item, true),
                "reference_id"  => '' . $item->get_product_id() .
                        ( $item->get_variation_id() ? '|' . $item->get_variation_id() : '' ),
                "ordered"       => $item->get_quantity(),
                "captured"      => $item->get_quantity(),
                "shipped"       => $item->get_quantity() - $order->get_qty_refunded_for_item($item),
                "refunded"      => $order->get_qty_refunded_for_item($item)
            ];
        }
        return $items;
    }
}

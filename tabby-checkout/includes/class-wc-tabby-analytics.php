<?php
/**
 * Store insights: real-time order / refund / status events for every payment
 * method, with the basket inside the event. Sent to the same Datadog log intake
 * as the rest of the plugin logs (service:woo), with "evt" as the event type.
 * No customer names, emails, phones or addresses are sent.
 *
 * Controlled by the "Share store insights" setting (tabby_share_insights).
 */
class WC_Tabby_Analytics {
    const OPTION_ENABLED    = 'tabby_share_insights';
    const META_PLACED       = '_tabby_evt_placed';
    const META_REFUND_SENT  = '_tabby_evt_refund_sent';
    const MAX_ITEMS         = 50;

    const PLACED_STATUSES   = ['processing', 'completed', 'on-hold'];
    const TRACKED_STATUSES  = ['cancelled', 'failed', 'refunded', 'completed'];

    // payment_method id prefix => family; first match wins, checked in order
    const GATEWAY_FAMILIES = [
        'tabby'         => 'tabby',
        'tamara'        => 'tamara',
        'postpay'       => 'bnpl_other',
        'spotii'        => 'bnpl_other',
        'cashew'        => 'bnpl_other',
        'madfu'         => 'bnpl_other',
        'mispay'        => 'bnpl_other',
        'emkan'         => 'bnpl_other',
        'cod'           => 'cod',
        'bacs'          => 'bank_transfer',
        'cheque'        => 'bank_transfer',
        'apple_pay'     => 'wallet',
        'applepay'      => 'wallet',
        'google_pay'    => 'wallet',
        'stc'           => 'wallet',
        'stripe'        => 'card',
        'paytabs'       => 'card',
        'tap'           => 'card',
        'moyasar'       => 'card',
        'hyperpay'      => 'card',
        'checkout'      => 'card',
        'telr'          => 'card',
        'myfatoorah'    => 'card',
        'paymob'        => 'card',
        'ppcp'          => 'card',
        'paypal'        => 'card',
        'woocommerce_payments' => 'card',
        'urway'         => 'card',
        'geidea'        => 'card',
        'payfort'       => 'card',
        'amazon'        => 'card',
        'ngenius'       => 'card',
        'network'       => 'card',
        'noon'          => 'card',
        'clickpay'      => 'card',
        'edfapay'       => 'card',
        'rajhi'         => 'card',
        'mada'          => 'card',
    ];

    public static function init() {
        add_action('woocommerce_order_status_changed', [__CLASS__, 'order_status_changed'], 20, 4);
        add_action('woocommerce_order_refunded',       [__CLASS__, 'order_refunded'], 20, 2);
    }

    public static function is_enabled() {
        return get_option(self::OPTION_ENABLED, 'yes') === 'yes' && !WC_Tabby_Api::needs_setup();
    }

    public static function order_status_changed($order_id, $from, $to, $order = null) {
        try {
            if (!static::is_enabled()) return;
            if (!($order instanceof WC_Order)) $order = wc_get_order($order_id);
            if (!($order instanceof WC_Order)) return;

            if (in_array($to, self::PLACED_STATUSES, true) && !$order->get_meta(self::META_PLACED, true)) {
                $order->update_meta_data(self::META_PLACED, gmdate('c'));
                $order->save_meta_data();
                $method = (string)$order->get_payment_method();
                static::emit('order.placed', array_merge(static::order_ref($order), [
                    'order_status'          => $to,
                    'financial_status'      => static::financial_status($order, $method, $to),
                    'total'                 => (float)$order->get_total(),
                    'discount'              => (float)$order->get_discount_total(),
                    'shipping'              => (float)$order->get_shipping_total(),
                    'tabby_availability'    => static::tabby_availability($order),
                    'tabby_payment_id'      => static::family($method) === 'tabby' ? (string)$order->get_meta('_tabby_payment', true) : null,
                ], static::items($order->get_items(), $order)));
                return;
            }

            if (in_array($to, self::TRACKED_STATUSES, true) && $from !== $to) {
                static::emit('order.status_changed', array_merge(static::order_ref($order), [
                    'status_from'   => $from,
                    'status_to'     => $to,
                ]));
            }
        } catch (\Throwable $e) {
            WC_Tabby_Api::ddlog('warn', 'insights: order event failed', $e, ['order_id' => $order_id]);
        }
    }

    public static function order_refunded($order_id, $refund_id) {
        try {
            if (!static::is_enabled()) return;
            $order  = wc_get_order($order_id);
            $refund = wc_get_order($refund_id);
            if (!($order instanceof WC_Order) || !($refund instanceof WC_Order_Refund)) return;

            $sent = $order->get_meta(self::META_REFUND_SENT, true);
            if (!is_array($sent)) $sent = [];
            if (in_array((int)$refund_id, $sent, true)) return;
            $sent[] = (int)$refund_id;
            $order->update_meta_data(self::META_REFUND_SENT, $sent);
            $order->save_meta_data();

            static::emit('order.refunded', array_merge(static::order_ref($order), [
                'refund_amount' => (float)$refund->get_amount(),
                'is_full'       => abs((float)$order->get_total() - (float)$order->get_total_refunded()) < 0.01,
            ], static::items($refund->get_items(), $refund)));
        } catch (\Throwable $e) {
            WC_Tabby_Api::ddlog('warn', 'insights: refund event failed', $e, ['order_id' => $order_id]);
        }
    }

    protected static function order_ref(WC_Order $order) {
        $method = (string)$order->get_payment_method();
        return [
            'order_id'          => (int)$order->get_id(),
            'payment_method'    => $method,
            'gateway_family'    => static::family($method),
            'currency'          => (string)$order->get_currency(),
            'merchant_code'     => WC_Tabby_Config::getMerchantCode($order),
        ];
    }

    /**
     * Basket lines for an order or a refund (refund quantities and totals are negative in
     * WooCommerce, sent as positive numbers). Capped at MAX_ITEMS lines; items_count is the full count.
     */
    protected static function items($order_items, $order) {
        $items = [];
        $count = 0;
        foreach ($order_items as $item) {
            if (!$item->get_quantity()) continue;
            $count++;
            if (count($items) >= self::MAX_ITEMS) continue;
            $pid = (int)$item->get_product_id();
            $product = $item->get_product();
            $terms = get_the_terms($pid, 'product_cat');
            $qty = abs((int)$item->get_quantity());
            $total = abs((float)$item->get_total());
            $items[] = [
                'product_id'    => $pid,
                'sku'           => $product ? (string)$product->get_sku() : '',
                'name'          => (string)$item->get_name(),
                'category'      => (is_array($terms) && !empty($terms)) ? $terms[0]->name : '',
                'qty'           => $qty,
                'price'         => round($total / max(1, $qty), 2),
                'total'         => $total,
            ];
        }
        return ['items_count' => $count, 'items' => $items];
    }

    public static function family($method) {
        $m = strtolower((string)$method);
        if ($m === '') return 'none';
        foreach (self::GATEWAY_FAMILIES as $prefix => $family) {
            if (strpos($m, $prefix) !== false) return $family;
        }
        return 'other';
    }

    /**
     * WooCommerce treats every processing order as paid, which is wrong for cash on
     * delivery and bank transfer: the money arrives later (or never).
     */
    protected static function financial_status(WC_Order $order, $method, $to) {
        if (in_array(static::family($method), ['cod', 'bank_transfer'], true)) {
            return $to === 'completed' ? 'paid_offline' : 'unpaid_offline';
        }
        if ($to === 'on-hold') return 'on_hold_unpaid';
        return $order->is_paid() ? 'paid' : 'pending';
    }

    /**
     * Tabby availability for this buyer/basket, read from the cache the Tabby
     * gateway already writes when it checks availability on checkout. Never
     * calls the Tabby API.
     */
    protected static function tabby_availability(WC_Order $order) {
        if (static::family($order->get_payment_method()) === 'tabby') return 'selected';
        if (!WC_Tabby_Config::isAvailableForCurrency($order->get_currency())) return 'unsupported_currency';
        $request = [
            'lang'          => WC_Tabby_Config::get_lang(),
            'merchant_code' => WC_Tabby_Config::getMerchantCode($order),
            'payment'       => [
                'amount'    => number_format((float)$order->get_total(), wc_get_price_decimals(), '.', ''),
                'currency'  => $order->get_currency(),
                'buyer'     => [
                    'email' => $order->get_billing_email(),
                    'phone' => str_replace('+', '', $order->get_billing_phone()),
                ],
            ],
        ];
        $key = 'tabby_api_cache_' . hash('sha256', json_encode([
            'lang'          => $request['lang'],
            'merchant_code' => $request['merchant_code'],
            'amount'        => $request['payment']['amount'],
            'currency'      => $request['payment']['currency'],
            'email'         => $request['payment']['buyer']['email'],
            'phone'         => $request['payment']['buyer']['phone'],
        ]));
        $cached = get_transient($key);
        if ($cached === false) return 'unknown';
        if (is_object($cached) && property_exists($cached, WC_Gateway_Tabby_Installments::TABBY_METHOD_CODE)) return 'available';
        return 'not_available';
    }

    protected static function emit($evt, array $data) {
        WC_Tabby_Api::event($evt, $data);
    }
}

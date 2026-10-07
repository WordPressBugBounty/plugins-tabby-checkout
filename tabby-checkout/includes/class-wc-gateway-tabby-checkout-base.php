<?php

use Automattic\WooCommerce\Caches\OrderCache;

class WC_Gateway_Tabby_Checkout_Base extends WC_Payment_Gateway {
    const METHOD_CODE = 'tabby_base';
    const TABBY_METHOD_CODE = 'base';
    const METHOD_NAME = 'Tabby Base';
    const METHOD_DESC = 'Tabby Base Class';
    //
    const TABBY_STATUS_FIELD = '_tabby_status';
    const TABBY_PAYMENT_FIELD = '_tabby_payment';
    const STATUS_AUTH = 'authorized';
    const STATUS_CAPTURED = 'captured';
    const STATUS_REFUNDED = 'refunded';
    const STATUS_CLOSED   = 'refunded';

    // method description
    const METHOD_DESCRIPTION_TYPE = 1;

    // cookie track
    const TRACK_COOKIE_KEY = 'xxx111otrckid';

    public static $payments = [];

    public function __construct() {
        $this->id = static::METHOD_CODE;

        $this->has_fields = true;

        $this->init_form_fields();
        $this->init_settings();

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        if ($this->get_api_option('capture', 'no') == 'yes') {
            add_action( 'woocommerce_order_status_processing', array( $this, 'capture_payment' ) );
        }
        add_action( 'woocommerce_order_status_completed' , array( $this, 'capture_payment' ) );
        add_action( 'woocommerce_order_status_cancelled' , array( $this, 'cancel_payment' ) );

        $this->icon = plugin_dir_url( dirname( __FILE__ ) ) . 'images/logo_green.png?v=' . MODULE_TABBY_CHECKOUT_VERSION;
        $this->title = $this->method_title = __($this->get_option('title', static::METHOD_NAME), 'tabby-checkout');
        $this->method_description = static::METHOD_DESC;

        $this->supports           = array(
            'products',
            'refunds',
        );

    }

    public static function clean_order_transaction_id($order) {
        if ($order->get_transaction_id() === $order->get_meta(static::TABBY_PAYMENT_FIELD, true)) {
            $order->set_transaction_id('');
            $order->delete_meta_data(static::TABBY_PAYMENT_FIELD);
            $order->save();
        }
    }

    /**
     * Check if the gateway is available for use.
     *
     * @return bool
     */
    public function is_available() {
        $is_available = parent::is_available();

        if (!WC()->customer) {
            $is_available = true;
        } else {
            if (!($country = WC()->customer->get_shipping_country())) {
                $country = WC()->customer->get_billing_country();
            }

            if ($country && !WC_Tabby_Config::isAvailableForCountry($country)) {
                $is_available = false;
            }

            if ($country == 'undefined') $is_available = true;
        }

        // only for supported currencies
        if (!WC_Tabby_Config::isAvailableForCurrency()) $is_available = false;

        // check for disabled_for_sku products
        if (!WC_Tabby_Config::isEnabledForCartSKUs()) $is_available = false;

        if (!WC()->cart || WC()->cart->is_empty()) return $is_available;

        // if available, create session
        if ($is_available && !is_admin()) {
            // always enabled on old checkout
            $is_available = self::is_classic_checkout_enabled() || $this->get_is_available_from_api();
        }

        return $is_available;
    }

    public function init_form_fields() {
        if (!$this->needs_setup()) {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __( 'Enable/Disable', 'tabby-checkout' ),
                    'type' => 'checkbox',
                    'label' => __( 'Enable ' . static::METHOD_NAME, 'tabby-checkout' ),
                    'default' => 'no'
                ),
                'title' => array(
                    'title' => __( 'Title', 'tabby-checkout' ),
                    'type' => 'text',
                    'description' => __( 'This controls the title which the user sees during checkout.', 'tabby-checkout' ),
                    'default' => __( static::METHOD_NAME, 'tabby-checkout' ),
                    'desc_tip'      => true,
                ),
                'description_type' => array(
                    'title' => __( 'Method Description', 'tabby-checkout' ),
                    'type' => 'select',
                    'options'  => [
                        //0   => __('PromoCardWide'    , 'tabby-checkout'),
                        1   => __('PromoCard'        , 'tabby-checkout'),
                        //2   => __('Text description' , 'tabby-checkout'),
                        3   => __('Blanc description', 'tabby-checkout')
                    ],
                    'description' => __( 'This controls the description which the user sees during checkout.', 'tabby-checkout' ),
                    'default' => __( static::METHOD_DESCRIPTION_TYPE, 'tabby-checkout' ),
                ),
                'inherit_bg' => array(
                    'title' => __( 'PromoCard background inherit', 'tabby-checkout' ),
                    'type' => 'checkbox',
                    'default'   => 'no'
                )
            );
        } else {
            $this->form_fields = array(
                'test'  => array(
                    'type'  => 'title',
                    'title' => __('Tabby API Public or Secret key is not configured.', 'tabby-checkout'),
                    /* translators: %s is replaced with the url of Tabby settings page */
                    'description'   => sprintf(__('Please configure Tabby API settings <a href="%s">here</a>.', 'tabby-checkout'), admin_url( 'admin.php?page=wc-settings&tab=settings_tab_tabby' ))
                )
            );
        }
    }

    public function getDescriptionType() {
        $dt = $this->get_option('description_type', static::METHOD_DESCRIPTION_TYPE);

        return in_array($dt, [1,3]) ? $dt : 1;
    }

    public function getShouldInheritBg() {
        return $this->get_option('inherit_bg', 'no') !== 'no';
    }
    public function get_description_config() {
        $res = [];
        switch ($this->getDescriptionType()) {
            case 0:
            case 1:
                $divId = static::TABBY_METHOD_CODE . 'Card';
                $jsClass = 'TabbyCard';
                if (static::TABBY_METHOD_CODE == 'creditCardInstallments') $jsClass = 'TabbyPaymentMethodSnippetCCI';
                $res = [
                    'id'  => $divId,
                    'class' => $jsClass,
                    'jsClass' => $jsClass,
                    'jsConf' =>  $this->getTabbyCardJsonConfig($divId)
                ];
                break;
            case 2:
                $res = [
                    'class' => "tabbyDesc",
                    'html'  => __(static::METHOD_DESC, 'tabby-checkout')
                ];
                break;
            default:
                $res['class'] = 'empty';

        }
        return $res;
    }
    public function get_order_total($order = null) {
        if ($order) return (float)$order->get_total();
        return WC()->cart ? parent::get_order_total() : 0;
    }

    public function payment_fields() {
        $config = $this->getFrontTabbyConfig();
        if ($pay_page = is_checkout_pay_page()) {
            $order_id = get_query_var('order-pay', false);
            if (empty($order_id)) {
                $order_key = get_query_var('key');
                $order_id = wc_get_order_id_by_order_key( $order_key );
            }
            $config['buyer'] = [];
            if (!empty($order_id) && ($order = wc_get_order($order_id))) {
                $customer = new \WC_Customer($order->get_customer_id());
                $config['buyer'] = $this->getBuyerObject($order);
                $config['order_key'] = $order->get_order_key();
            }
        }
        echo '<script>window.tabbyConfig = '.json_encode($config).'</script>';
        switch ($this->getDescriptionType()) {
            case 0:
            case 1:
                $divId = static::TABBY_METHOD_CODE . 'Card';
                $jsClass = 'TabbyCard';
                if (static::TABBY_METHOD_CODE == 'creditCardInstallments') $jsClass = 'TabbyPaymentMethodSnippetCCI';
                echo '<div id="'.esc_attr($divId).'"></div>';
                echo '<script>';
                echo $pay_page ? 'window.addEventListener("load", (event) => {' : '';
                echo '  if (typeof '.esc_js($jsClass).' !== \'undefined\') new '.esc_js($jsClass).'(' . $this->getTabbyCardJsonConfig($divId) .');';
                echo $pay_page ? '});' : '';
                echo '</script>';
                break;
            case 2:
                echo '<div class="tabbyDesc">' . __(static::METHOD_DESC, 'tabby-checkout') . '</div>';
                break;

        }
    }
    public function getTabbyCardJsonConfig($divId) {
        return json_encode([
            'selector'  => '#' . $divId,
            'publicKey' => $this->get_api_option('public_key'),
            'merchantCode' => WC_Tabby_Config::getPromoMerchantCode(),
            'currency'  => WC_Tabby_Config::getTabbyCurrency(),
            'lang'      => WC_Tabby_Config::get_lang(),
            'price'     => $this->formatAmount($this->get_order_total()),
            'shouldInheritBg'   => $this->getShouldInheritBg(),
        ]);
    }

    public function getFrontTabbyConfig() {
        return [
            'debug'         => $this->get_api_option('debug') == 'yes' ? 1 : 0,
            'order_key'     => false,
            'hideMethods'   => $this->get_api_option('hide_methods') == 'yes',
            'ignoreEmail'   => apply_filters('tabby_checkout_ignore_email', false),
            'language'      => $this->getLanguage(),
            'locale'        => WC_Tabby_Config::get_lang(),
            'localeSource'  => $this->get_api_option('locale_html') == 'yes' ? 'html' : '',
            'notAvailableMessage' => __('Sorry Tabby is unable to approve this purchase, please use an alternative payment method for your order.', 'tabby-checkout'),
        ];
    }
    public function getTabbyConfig($order = null, $with_history = true) {
        $config = $this->getFrontTabbyConfig();
        $config['apiKey']  = $this->get_api_option('public_key');
        $config['merchantCode'] = WC_Tabby_Config::getMerchantCode($order);
// used to ignore email on checkout
        $config['buyer_history'] = null;
        // buyer and shipping address for pay_for_order functionality
        if (is_checkout_pay_page()) {
            $order_id = get_query_var('order-pay', false);
            if (empty($order_id)) {
                $order_key = get_query_var('key', false);
                $order_id = wc_get_order_id_by_order_key( $order_key );
            }
            $order = wc_get_order($order_id);
            $customer = new \WC_Customer($order->get_customer_id());
            $config['buyer'] = $this->getBuyerObject($order);
            $config['shipping_address'] = $this->getShippingAddressObject($order);
            $config['buyer_history'] = $with_history ? $this->getBuyerHistoryObject($customer) : null;
        } elseif ($order) {
            $customer = new \WC_Customer($order->get_customer_id());
            $config['buyer'] = $this->getBuyerObject($order);
            $config['shipping_address'] = $this->getShippingAddressObject($order);
            $config['buyer_history'] = $with_history ? $this->getBuyerHistoryObject($customer) : null;
        } elseif ($customer = WC()->customer) {
            $config['buyer'] = $this->getFrontBuyerObject();
            $config['shipping_address'] = $this->getShippingAddressObject($customer);
            $config['buyer_history'] = $with_history ? $this->getBuyerHistoryObject($customer) : null;
        }
        $config['payment'] = $this->getPaymentObject($order);
        $config['merchantUrls'] = WC_Tabby_Config::getMerchantUrls($order);

        return json_encode($config);
    }

    public function getBuyerHistoryObject($customer) {
        if ($customer && $customer->get_date_created()) {
            return [
                'registered_since'  => $customer->get_date_created()->date("c"),
                'loyalty_level'     => $this->getLoyaltyLevel($customer)
            ];
        }
        return null;
    }

    public function getLoyaltyLevel($customer) {
        return count(wc_get_orders([
            'customer'  => $customer->get_email(),
            'status'    => ['wc-completed', 'wc-refunded']
        ]));
    }

    public function getBuyerObject($order) {
        $billing = $order->get_address();
        return array(
            'email' => $billing['email'],
            'name'  => $billing['first_name'] . ' ' . $billing['last_name'],
            'phone' => str_replace("+", "", $billing['phone'])
        );
    }

    public function getShippingAddressObject($order) {
        $street1 = $order->get_shipping_address_1();
        $street2 = $order->get_shipping_address_2();
        return array(
            'address'   => $street1 . (!empty($street2) ? (', ' . $street2) : ''),
            'city'      => $order->get_shipping_city()
        );
    }

    public function getLanguage() {
        return $this->get_api_option('popup_language', 'auto');
    }

    public static function is_classic_checkout_enabled() {
        if ( class_exists( 'WC_Blocks_Utils' ) && WC_Blocks_Utils::has_block_in_page( wc_get_page_id( 'checkout' ), 'woocommerce/checkout' ) ) {
            // The site is using the modern BLOCK checkout
            return false;
        }
        // The site is using the CLASSIC shortcode checkout
        return true;
    }
    public function get_is_available_from_api() {
        $config = json_decode(static::getTabbyConfig(null, false), true);
        // show module on checkout if there is no email/phone entered
        if (empty($config['buyer']['email']) || empty($config['buyer']['phone'])) {
            return true;
        }
        $config['payment']['buyer'] = $this->getFrontBuyerObject();
        $config['payment']['shipping_address'] = $config['shipping_address'];
        $modules = [];
        $request = [
            'payment'       => $config['payment'],
            'lang'          => WC_Tabby_Config::get_lang(),
            'merchant_code' => $config['merchantCode'],
            'merchant_urls' => $config['merchantUrls']
        ];

        $is_available = false;
        
        $available_products = static::get_cached_availability_request($request);

        if (is_object($available_products) && property_exists($available_products, static::TABBY_METHOD_CODE)) {
            $is_available = true;
        }

        return $is_available;
    }
    const AVAILABILITY_TTL = HOUR_IN_SECONDS;
    const REJECTION_TTL    = 15 * MINUTE_IN_SECONDS;

    /**
     * Availability (prescoring) check with a cache.
     *
     * The decision depends on the amount and the buyer: the phone when it is set (the email is then
     * ignored), otherwise the email. Approvals are cached for an hour, rejections for 15 minutes, so
     * checkout refreshes do not call the API again. A buyer-level rejection (not_available,
     * order_amount_too_high) also blocks the same or a bigger basket for 15 minutes and wins over an older
     * cached approval; a smaller basket is checked again, and its approval lifts a not_available block.
     * Keys include a hash of the secret key, so sandbox answers do not survive a switch to live keys.
     */
    public static function get_cached_availability_request($request) {
        // an empty cart (amount 0) is always rejected by the API with 400 "should be positive"
        $amount = (float)$request["payment"]["amount"];
        if ($amount <= 0) {
            return new \StdClass();
        }
        $buyer_key = static::get_buyer_rejection_key($request);
        $block = $buyer_key ? get_transient($buyer_key) : false;
        if (is_array($block) && $amount >= (float)$block['amount']) {
            return new \StdClass();
        }
        $tr_name = static::get_availability_cache_key($request);
        if (($available_products = get_transient($tr_name)) !== false) {
            return $available_products;
        }

        $result = (new WC_Tabby_Api($request['merchant_code']))->request('checkout', 'POST', $request);

        if (is_object($result) && property_exists($result, 'status') && $result->status == 'created') {
            $available_products = $result->configuration->available_products;
            set_transient($tr_name, $available_products, static::AVAILABILITY_TTL);
            // an approval proves "buyer not eligible" is stale (a limit block stays: smaller baskets fit it)
            if (is_array($block) && $block['reason'] === 'not_available') {
                foreach ((array)($block['amounts'] ?? []) as $rejected) {
                    $stale = $request;
                    $stale['payment']['amount'] = $rejected;
                    delete_transient(static::get_availability_cache_key($stale));
                }
                delete_transient($buyer_key);
            }
        } elseif (is_object($result) && property_exists($result, 'status') && $result->status == 'rejected') {
            $available_products = new \StdClass();
            // without a phone or an email the answer is not tied to a buyer: do not cache it
            if ($buyer_key) {
                set_transient($tr_name, $available_products, static::REJECTION_TTL);
                $reason = isset($result->configuration->products->installments->rejection_reason)
                    ? (string)$result->configuration->products->installments->rejection_reason : '';
                // only buyer-level answers: the buyer is not eligible, or the basket is above their limit
                if (in_array($reason, ['not_available', 'order_amount_too_high'], true)) {
                    // remember the rejected amounts too, so lifting the block also clears their cached rejections
                    $amounts = is_array($block) ? (array)($block['amounts'] ?? []) : [];
                    $amounts = array_slice(array_values(array_unique(array_merge($amounts, [$request["payment"]["amount"]]))), -20);
                    $lowest  = is_array($block) && (float)$block['amount'] <= $amount;
                    set_transient($buyer_key, [
                        'amount'  => $lowest ? (float)$block['amount'] : $amount,
                        'reason'  => $lowest ? $block['reason'] : $reason,
                        'amounts' => $amounts,
                    ], static::REJECTION_TTL);
                }
            }
        } else {
            // API or network error: not cached, the next checkout refresh asks again
            $available_products = new \StdClass();
        }

        return $available_products;
    }

    /**
     * Cached availability for a request without calling the API: true / false, or null when unknown.
     */
    public static function get_cached_availability($request) {
        $amount = (float)$request["payment"]["amount"];
        if ($amount <= 0) return false;
        $buyer_key = static::get_buyer_rejection_key($request);
        $block = $buyer_key ? get_transient($buyer_key) : false;
        if (is_array($block) && $amount >= (float)$block['amount']) return false;
        $cached = get_transient(static::get_availability_cache_key($request));
        if ($cached !== false) return is_object($cached) && property_exists($cached, WC_Gateway_Tabby_Installments::TABBY_METHOD_CODE);
        return null;
    }

    protected static function get_buyer_id($request) {
        $buyer = isset($request["payment"]["buyer"]) && is_array($request["payment"]["buyer"]) ? $request["payment"]["buyer"] : [];
        $phone = preg_replace('/\D+/', '', (string)($buyer["phone"] ?? ''));
        if ($phone !== '') return 'p:' . $phone;
        $email = strtolower(trim((string)($buyer["email"] ?? '')));
        return $email !== '' ? 'e:' . $email : '';
    }

    protected static function get_availability_cache_key($request) {
        return 'tabby_api_cache_' . hash('sha256', json_encode([
            static::key_scope(),
            $request["merchant_code"],
            $request["payment"]["currency"],
            $request["payment"]["amount"],
            static::get_buyer_id($request),
        ]));
    }

    protected static function get_buyer_rejection_key($request) {
        $buyer = static::get_buyer_id($request);
        if ($buyer === '') return '';
        return 'tabby_api_rej_' . hash('sha256', json_encode([static::key_scope(), $request["merchant_code"], $request["payment"]["currency"], $buyer]));
    }

    protected static function key_scope() {
        return substr(hash('sha256', (string)WC_Tabby_Api::get_api_option('secret_key')), 0, 12);
    }

    public function getFrontBuyerObject() {
        $email = $name = $phone = null;
        if (WC()->customer) {
            $email = WC()->customer->get_billing_email()
                ? WC()->customer->get_billing_email()
                : WC()->customer->get_email();
            $fname = WC()->customer->get_billing_first_name()
                ? WC()->customer->get_billing_first_name()
                : (WC()->customer->get_shipping_first_name()
                    ? WC()->customer->get_shipping_first_name()
                    : WC()->customer->get_first_name());
            $lname = WC()->customer->get_billing_last_name()
                ? WC()->customer->get_billing_last_name()
                : (WC()->customer->get_shipping_last_name()
                    ? WC()->customer->get_shipping_last_name()
                    : WC()->customer->get_last_name());
            $phone = WC()->customer->get_billing_phone()
                ? WC()->customer->get_billing_phone()
                : (method_exists(WC()->customer, 'get_shipping_phone')
                    ? WC()->customer->get_shipping_phone()
                    : '');
        }
        return array(
            'email' => $email,
            'name'  => $fname . ' ' . $lname,
            'phone' => str_replace("+", "", $phone)
        );
    }

    public function getPaymentObject($order = null) {
        return [
            "amount"            => $this->formatAmount($this->get_order_total($order)),
            "currency"          => WC_Tabby_Config::getTabbyCurrency(),
            "description"       => get_bloginfo("name") . ' Order',
            "order"             => $this->getOrderObject($order),
            "meta"              => [
                "tabby_plugin_platform" => 'woocommerce',
                "tabby_plugin_version"  => MODULE_TABBY_CHECKOUT_VERSION
            ],
        ];
    }

    protected function getOrderObject($order = null) {
        return [
            'reference_id'      => (string) (
                $order == null
                    ? hash("sha512", (json_encode($this->getOrderItemsObject($order))))
                    : woocommerce_tabby_get_order_reference_id($order)
            ),
            'shipping_amount'   => $this->formatAmount(
                $order == null
                    ? (float)WC()->cart->get_shipping_total() + (float)WC()->cart->get_shipping_tax() :
                    ($order->get_shipping_total() + $order->get_shipping_tax())
            ),
            'discount_amount'   => $this->formatAmount(
                $order == null
                    ? (float)WC()->cart->get_discount_total()
                    : $order->get_discount_total()
            ),
            'tax_amount'        => $this->formatAmount(
                $order == null
                    ? array_sum(WC()->cart->get_taxes())
                    : $order->get_total_tax()
            ),
            'items'             => $this->getOrderItemsObject($order)
        ];
    }

    protected function getOrderItemsObject($order = null) {
        $items = [];
        if ($order == null) {
            foreach (WC()->cart->get_cart() as $item => $values) {
                $items[] = $this->getOrdersItemsItemObject($values['data']->get_id(), $values['quantity']);
            }
        } else {
            foreach ($order->get_items() as $item_id => $item_data) {
                if (!$item_data->get_product()) continue;
                $items[] = $this->getOrdersItemsItemObject($item_data->get_product()->get_id(), $item_data->get_quantity());
            }
        }
        return $items;
    }
    protected function getOrdersItemsItemObject($product_id, $quantity) {
        $_product =  wc_get_product( $product_id );
        $image_id = $_product->get_image_id();
        $category = '';
        $terms = get_the_terms( $product_id, 'product_cat' );
        if (is_a($_product, 'WC_Product_Variation')) {
            $terms = get_the_terms( $_product->get_parent_id(), 'product_cat' );
        }
        if ($terms !== false && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                if ($term->taxonomy == 'product_cat') {
                    $category = $term->name;
                    break;
                }
            }
        }
        $brand_name = null;
        if (function_exists('wc_get_product_brands')) {
            $brands = wc_get_product_brands($_product->get_id());
            if (!empty($brands)) {
                foreach ($brands as $brand) {
                    $brand_name = $brand->name;
                    break;
                }
            }
        }

        if (is_null($brand_name)) {
            $brands = get_the_terms( $product_id, 'product_brand' );
            if ( ! is_wp_error( $brands ) && ! empty( $brands ) ) {
                foreach ( $brands as $brand ) {
                    $brand_name = $brand->name;
                    break;
                }
            }
        }
        $sku = $_product->get_sku();

        return [
            'quantity'      => (int)$quantity,
            'title'         => $_product->get_title(),
            'category'      => $category,
            'reference_id'  => (string) ($sku ?: $_product->get_id()),
            'brand'         => $brand_name,
            'description'   => $_product->get_description(),
            'image_url'     => $image_id ? wp_get_attachment_image_url( $image_id, 'full') : wc_placeholder_img_src( 'full' ),
            'product_url'   => get_permalink( $_product->get_id() ),
            'unit_price'    => $this->formatAmount(wc_get_price_including_tax( $_product ))
        ];
    }
    public function process_admin_options() {
        $saved = parent::process_admin_options();
    }

    public function process_payment( $order_id ) {
        try {
            global $woocommerce;
            $order = new WC_Order( $order_id );

            // note current order id in session
            WC()->session->set( 'tabby_order_id', $order_id );

            $redirect_url = $this->getTabbyRedirectUrl($order);
            if (!is_wp_error($redirect_url)) {
                // Add tracking cookie
                if (isset($_COOKIE[self::TRACK_COOKIE_KEY])) {
                    $redirect_url .= '&' . self::TRACK_COOKIE_KEY . '=' . urlencode($_COOKIE[self::TRACK_COOKIE_KEY]);
                }
                return array(
                    'result' => 'success',
                    'redirect' => $redirect_url
                );
            } else {
                wc_add_notice( $redirect_url->get_error_message(), 'error' );
                return [
                    'result'    => 'failure',
                    'message'   => 'Sorry Tabby is unable to approve this purchase, please use an alternative payment method for your order.'
                ];
            }
        } catch (\Exception $e) {
            $this->ddlog("error", "could not process payment", $e);
        }
    }
    public function update_payment_reference_id($order, $payment_id, $reference_id) {
        $request = [
            'order' => [
                'reference_id'  => (string)$reference_id
            ]
        ];
        return $this->request($order, $payment_id, 'PUT', $request);
    }
    protected function getTabbyRedirectUrl($order) {
        // create payment object
        $config = json_decode(static::getTabbyConfig($order), true);
        $request = [
            'payment'       => $config['payment'],
            'lang'          => WC_Tabby_Config::get_lang(),
            'merchant_code' => $config['merchantCode'],
            'merchant_urls' => $config['merchantUrls']
        ];
        $request['payment']['order_history'] = WC_Tabby_AJAX::getOrderHistoryObject($order->get_billing_email(), $order->get_billing_phone());
        $request['payment']['buyer'] = $config['buyer'];
        $request['payment']['buyer_history'] = $config['buyer_history'];
        $request['payment']['shipping_address'] = $config['shipping_address'];
        $result = (new WC_Tabby_Api($request['merchant_code']))->request('checkout', 'POST', $request);

        if ($result && property_exists($result, 'status') && $result->status == 'created') {
            if (property_exists($result->configuration->available_products, static::TABBY_METHOD_CODE)) {
                // register new payment id for order
                $this->update_order_payment_id($order, $result->payment->id);

                return $result->configuration->available_products->{static::TABBY_METHOD_CODE}[0]->web_url;
            } else {
                return new WP_Error( 'error', __( 'Api error, please try again later.', 'tabby-checkout' ) );;
            }
       } else {
            return new WP_Error( 'error', __( 'Api error, please try again later.', 'tabby-checkout' ) );;
       }
    }

    /**
     * Update order payment ID for internal use and from api
     */
    public function update_order_payment_id($order, $payment_id) {
        if ($this->get_tabby_payment_id($order) != $payment_id) {
            /* translators: %s is replaced with Tabby payment ID */
            $order->add_order_note( sprintf( __( 'Payment assigned. ID: %s', 'tabby-checkout' ), $payment_id ) );
            // set woo transaction id
            $order->set_transaction_id($payment_id);
            // set internal transaction id
            $order->update_meta_data(static::TABBY_PAYMENT_FIELD, $payment_id);
            // update status to pending when payment id changed
            $order->update_status( 'pending' );
            $order->save();
            // remove order from cache
            if (class_exists('OrderCache')) {
                $order_cache = wc_get_container()->get( OrderCache::class );
                $order_cache->remove($order->get_id());
            }
        }
    }

    public function needs_setup() {
        if ($this->get_api_option('public_key') && $this->get_api_option('secret_key')) {
            return false;
        }
        return true;
    }

    public function get_api_option($option, $default = null) {
        return get_option('tabby_checkout_' . $option, $default);
    }

    public function can_refund_order( $order ) {
        return $order && $this->get_tabby_payment_id($order) && !$this->needs_setup() && $this->get_order_capture_id($order);
    }

    public function get_order_capture_id($order) {
        return $order->get_meta('_capture_id', true);
    }

    public function set_order_capture_id($order, $capture_id) {
        $order->update_meta_data('_capture_id', $capture_id);
        $order->save();
    }

    public function authorize($order, $payment_id) {
        try {
          $logData = array(
              "payment.id" => $payment_id,
              "order.reference_id" => woocommerce_tabby_get_order_reference_id($order)
          );
          $this->ddlog("info", "authorize payment", null, $logData);

          // TODO: Do we really need assign transaction id on every authorize 
          $this->update_order_payment_id($order, $payment_id);

          // cache payment to avoid duplicate api queries
          if (!array_key_exists($payment_id, self::$payments)) {
            $res = $this->request($order, $payment_id);
            self::$payments[$payment_id] = $res;
          } else {
            $res = self::$payments[$payment_id];
          }

          if (!empty($res)) {
              if ($res->status == 'error') {
                return false;
              }

              if ($res->order->reference_id != woocommerce_tabby_get_order_reference_id($order)) {
                $data = ["order" => [
                    "reference_id"  => (string)woocommerce_tabby_get_order_reference_id($order)
                ]];

                $result = $this->request($order, $payment_id, 'PUT', $data);
                $this->debug(['authorize - update order #  - ', (array)$result]);
              }

              if ($res->status == 'CREATED') {
                  if ($this->get_tabby_payment_id($order) != $payment_id) {
                    /* translators: %s is replaced with Tabby payment ID */
                    $order->add_order_note( sprintf( __( 'Payment created. ID: %s', 'tabby-checkout' ), $payment_id ) );
                  }
              } elseif ($res->status == 'REJECTED') {
                  /* translators: %s is replaced with Tabby payment ID  */
                  $order->set_status( 'failed', sprintf( __( 'Payment %s is REJECTED', 'tabby-checkout' ), $payment_id ) );
                  $order->save();
                  return false;
              } elseif ($res->status == 'EXPIRED') {
                  /* translators: %s is replaced with Tabby payment ID  */
                  $order->set_status( 'cancelled', sprintf( __( 'Payment %s is EXPIRED', 'tabby-checkout' ), $payment_id ) );
                  $order->save();
                  return false;
              } elseif ($order->get_total() == $res->amount && $order->get_currency() == $res->currency) {
                  if ($order->get_meta(static::TABBY_STATUS_FIELD, true) == '') {
                    $order->update_meta_data(static::TABBY_STATUS_FIELD, static::STATUS_AUTH);

                    /* translators: %s is replaced with Tabby payment ID */
                    $order->add_order_note( sprintf( __( 'Payment authorized. ID: %s', 'tabby-checkout' ), $payment_id ) );
                  }

                  return true;
              } else {
                  /* translators: %1$s is replaced with Tabby payment ID, %2$s is replaced with payment currency */
                  $order->set_status( 'failed', sprintf(
                    __( 'Payment failed. ID: %1$s. Total missmatch. Transaction amount: %2$s', 'tabby-checkout' ),
                    $payment_id, $res->amount . $res->currency )
                  );

                  $order->save();

                  return false;
              }
          }
        } catch (\Exception $e) {
            $this->ddlog("error", "could not authorize payment", $e);
        }


        return false;
    }
    public function is_payment_expired($order, $payment_id) {
        $res = $this->request($order, $payment_id);
        $timeout = get_option( 'tabby_checkout_order_timeout' );
        if ($res && property_exists($res, 'created_at') && (time() - strtotime($res->created_at) > $timeout * 60)) {
            $this->ddlog("info", "payment is expired", null, [
                'payment.id'    => $payment_id,
                'created_at'    => $res->created_at,
                'timeout'       => $timeout,
                'order_created' => $order->get_date_created(),
                'time'          => gmdate("Y-m-d\TH:i:s\Z")
            ]);
            return true;
        }
        return false;
    }

    public function get_tabby_payment_id($order) {
        return $order->get_transaction_id() ?: $order->get_meta(static::TABBY_PAYMENT_FIELD, true);
    }

    public function capture_payment($order_id ) {
        try {
          $order = wc_get_order( $order_id );

          $gateway = wc_get_payment_gateway_by_order($order);

          if (!($gateway instanceof WC_Gateway_Tabby_Checkout_Base)) return;

          $payment_id = $this->get_tabby_payment_id($order);

          if (!$payment_id) return;

          $logData = array(
              "payment.id" => $payment_id,
              "order.reference_id" => woocommerce_tabby_get_order_reference_id($order)
          );

          if ($this->can_capture($order)) {

              $this->ddlog("info", "capture payment", null, $logData);

              $data = [
                  "amount"            => $this->formatAmount($order->get_total()),
                  "tax_amount"        => $this->formatAmount($order->get_total_tax()),
                  "shipping_amount"   => $this->formatAmount($order->get_shipping_total()),
                  "created_at"        => null
              ];

              $data['items'] = [];
              foreach ($order->get_items() as $item_id => $item_data) {
                  $data['items'][] = [
                      'title'         => $item_data->get_name(),
                      'description'   => $item_data->get_name(),
                      'quantity'      => (int)$item_data->get_quantity(),
                      'unit_price'    => $this->formatAmount($item_data->get_total() / $item_data->get_quantity()),
                      'reference_id'  => ''.$item_data->get_product()->get_id()
                  ];
              }


              $this->debug(['capture', $payment_id, $data]);
              $result = $this->request($order, $payment_id . '/captures', 'POST', $data);
              $this->debug(['capture - result', (array)$result]);

              if (property_exists($result, 'captures') && is_array($result->captures)) {
                $txn = array_pop($result->captures);

                $this->set_order_capture_id($order, $txn->id);

                $order->update_meta_data(static::TABBY_STATUS_FIELD, static::STATUS_CAPTURED);
                /* translators: %s is replaced with Tabby capture ID */
                $order->add_order_note( sprintf( __( 'Payment captured. ID: %s', 'tabby-checkout' ), $txn->id ) );
              } else {
                throw new \Exception("No captures found");
              }
          }
        } catch (\Exception $e) {
            $this->ddlog("error", "could not capture payment", $e);
        }
    }

    public function cancel_payment($order_id) {
        try {
          $order = wc_get_order($order_id );
          if ($this->can_cancel($order)) {
              $this->cancel($order);
          } else {
              if ($this->is_captured($order)) {
                  $logData = array(
                      "order.reference_id" => woocommerce_tabby_get_order_reference_id($order)
                  );
                  $this->ddlog("info", "could not cancel payment, needs refund", null, $logData);
                  throw new \Exception( __( 'Order payment captured. Please refund order.', 'tabby-checkout') );
              }
          }
        } catch (\Exception $e) {
            $this->ddlog("error", "could not cancel payment", $e);
        }
    }

    public function is_captured($order) {
        return $order->get_meta(static::TABBY_STATUS_FIELD, true) == static::STATUS_CAPTURED;
    }

    public function is_closed($order) {
        return $order->get_meta(static::TABBY_STATUS_FIELD, true) == static::STATUS_CLOSED;
    }

    public function can_cancel($order) {
        return $order->get_meta(static::TABBY_STATUS_FIELD, true) == static::STATUS_AUTH;
    }

    public function cancel($order) {
        if (!$this->is_closed($order)) {
            $payment_id = $this->get_tabby_payment_id($order);

            $logData = array(
                "payment.id" => $payment_id,
                "order.reference_id" => woocommerce_tabby_get_order_reference_id($order)
            );
            $this->ddlog("info", "cancel payment", null, $logData);

            $this->request($order, $payment_id . '/close', 'POST');
            $order->update_meta_data(static::TABBY_STATUS_FIELD, static::STATUS_CLOSED);
            $order->add_order_note(__( 'Tabby payment closed', 'tabby-checkout' ));
            $order->save();
        }
    }

    public function can_capture($order) {
        $result = $order->get_meta(static::TABBY_STATUS_FIELD, true) == static::STATUS_AUTH;

        // check payment status on Tabby
        $payment = $this->get_tabby_payment($order);
        if (property_exists($payment, 'status')) {
            $result = ($payment->status == 'AUTHORIZED');

            // if captured, update internal status
            if ($payment->status == 'CLOSED' && property_exists($payment, 'captures') && count($payment->captures) > 0) {
                $order->update_meta_data(static::TABBY_STATUS_FIELD, static::STATUS_CAPTURED);
                $order->save();
            }
        }

        return $result;
    }

    public function get_tabby_payment($order) {
        $payment_id = $this->get_tabby_payment_id($order);

        return $this->request($order, $payment_id);
    }

    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        try {
          $order = wc_get_order( $order_id );
          $payment_id = $this->get_tabby_payment_id($order);
          $refunds = $order->get_refunds();

          $logData = array(
              "payment.id" => $payment_id,
              "order.reference_id" => woocommerce_tabby_get_order_reference_id($order)
          );
          $this->ddlog("info", "refund payment", null, $logData);

          $refund = false;
          // get first refund with same amount and reason
          foreach ($refunds as $ref) {
              if (($ref->get_reason() == $reason) && ($ref->get_amount() == $amount) && !$ref->get_refunded_payment()) $refund = $ref;
          }

          if ( ! $refund) throw new \Exception( __('Cannot find refund object', 'tabby-checkout') );

          if ( ! $this->can_refund_order( $order ) ) {
              return new WP_Error( 'error', __( 'Refund failed.', 'tabby-checkout' ) );
          }

          $data = [
              "capture_id"        => $this->get_order_capture_id($order),
              "amount"            => $this->formatAmount($refund->get_amount()),
              "reason"            => $refund->get_reason()
          ];

          $data['items'] = [];

          foreach ($refund->get_items() as $item) {
              if ($item->get_quantity() == 0) continue;
              $data['items'][] = [
                  'title'         => $item->get_name(),
                  'description'   => $item->get_name(),
                  'quantity'      => (int)$item->get_quantity(),
                  'unit_price'    => $this->formatAmount($item->get_total() / $item->get_quantity()),
                  'reference_id'  => $item->get_product()->get_id() . ''
              ];
          }
          $this->debug(['refund', $payment_id, $data]);
          $result = $this->request($order, $payment_id . '/refunds', 'POST', $data);
          $this->debug(['refund - result', (array)$result]);

          $txn = array_pop($result->refunds);
          if (!$txn) {
              throw new \Exception( __("Something wrong", 'tabby-checkout'));
          }

          if ($txn->id) {
              $order->add_order_note(
                  /* translators: %1$s is replaced with payment amount, %2$s is replaced with Tabby refund ID */
                  sprintf( __( 'Refunded %1$s - Refund ID: %2$s', 'tabby-checkout' ), $txn->amount, $txn->id )
              );
              $order->update_meta_data(static::TABBY_STATUS_FIELD, static::STATUS_REFUNDED);
              $order->save();
              return true;
          }

          return isset( $result->status ) ? new WP_Error( 'error', $result->error ) : false;
        } catch (\Exception $e) {
            $this->ddlog("error", "could not refund payment", $e);
        }
    }

    protected function formatAmount($amount) {
        return number_format($amount, wc_get_price_decimals(), '.', '');
    }

    public function request($order, $endpoint, $method = 'GET', $data = null) {
        $country = WC_Tabby_Config::getMerchantCode($order);
        return (new WC_Tabby_Api($country))->request('payments/' . $endpoint, $method, $data);
    }

    protected function debug($data) {
        WC_Tabby_Api::debug($data);
    }

    protected function ddlog($status = "error", $message = "Something went wrong", $e = null, $data = null) {
        WC_Tabby_Api::ddlog($status, $message, $e, $data);
    }
}

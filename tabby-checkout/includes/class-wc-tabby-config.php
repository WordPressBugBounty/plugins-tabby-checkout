<?php

class WC_Tabby_Config {
    const ALLOWED_CURRENCIES = ['AED','SAR','BHD','KWD', 'QAR'];
    const ALLOWED_COUNTRIES  = [ 'AE', 'SA', 'BH', 'KW',  'QA'];

    public static function getDefaultMerchantCode() {
        $currency_code = static::getTabbyCurrency();

        if (($index = array_search($currency_code, static::ALLOWED_CURRENCIES)) !== false) {
            return static::ALLOWED_COUNTRIES[$index];
        }

        return 'default';
    }
    public static function isAvailableForCountry($country_code) {
        if (($allowed = static::getConfiguredCountries()) === false) {
            $allowed = static::ALLOWED_COUNTRIES;
        };
        return in_array($country_code, $allowed);
    }
    public static function getConfiguredCountries() {
        return get_option('tabby_countries', false);
    }
    public static function getShareFeed() {
        return get_option('tabby_share_feed', 'yes') == 'yes';
    }
    public static function isAvailableForCurrency($currency_code = null) {
        if (is_null($currency_code)) {
            $currency_code = static::getTabbyCurrency();
        }
        return in_array($currency_code, static::ALLOWED_CURRENCIES);
    }
    public static function getTabbyCurrency() {
        return apply_filters("tabby_checkout_tabby_currency", get_woocommerce_currency());
    }
    // Disabled for SKUs
    public static function isEnabledForProductSKU() {
        return !static::isDisabledForSku(wc_get_product()->get_sku());
    }
    public static function isEnabledForCartSKUs() {
        if (WC()->cart) {
            foreach (WC()->cart->get_cart_contents() as $item) {
                if (static::isDisabledForSku($item['data']->get_sku())) return false;
            }
        }
        return true;
    }
    public static function isDisabledForSKU($sku) {
        $disabled_skus = array_map(
            function ($item) {return trim($item);},
            array_filter(explode("\n", get_option('tabby_checkout_disable_for_sku', '')))
        );
        return in_array($sku, $disabled_skus);
    }
    public static function getPromoMerchantCode() {
        $currency = self::getTabbyCurrency();

        $merchantCode = self::ALLOWED_COUNTRIES[0];
        if (($index = array_search($currency, self::ALLOWED_CURRENCIES)) !== false) {
            $merchantCode = self::ALLOWED_COUNTRIES[$index];
        }

        return $merchantCode;
    }
    public static function getMerchantCode($order = null) {
        if ($order) {
            $code = $order->get_billing_country() ?: $order->get_shipping_country();
        } else {
            $code = WC()->customer->get_billing_country() ?: WC()->customer->get_shipping_country();
        }
        if ($code == 'undefined' || empty($code)) $code = wc_get_base_location()['country'];
        return $code;
    }
    public static function getMerchantUrls($order = null) {

        return [
            'success'   => is_checkout_pay_page() && $order
                ? $order->get_checkout_order_received_url()
                : ($order 
                    ? $order->get_checkout_order_received_url()
                    : wc_get_endpoint_url( 'order-received', '', wc_get_checkout_url() )
                ),
            'cancel'    => is_checkout_pay_page() && $order ? $order->get_checkout_payment_url() : wc_get_checkout_url(),
            'failure'   => is_checkout_pay_page() && $order ? $order->get_checkout_payment_url() : wc_get_checkout_url()
        ];
    }
    public static function get_lang() {
        $lang = strtolower(substr(get_locale(), 0, 2));

        if (!in_array($lang, ['ar', 'en'])) $lang = 'en';
        return $lang;
    }
}

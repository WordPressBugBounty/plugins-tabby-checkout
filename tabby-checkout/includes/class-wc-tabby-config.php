<?php

class WC_Tabby_Config {
    const ALLOWED_CURRENCIES = ['AED','SAR','BHD','KWD', 'QAR'];
    const ALLOWED_COUNTRIES  = [ 'AE', 'SA', 'BH', 'KW',  'QA'];

    public static function isAvailableForCountry($country_code) {
        if (($allowed = static::getConfiguredCountries()) === false) {
            $allowed = static::ALLOWED_COUNTRIES;
        };
        return in_array($country_code, $allowed);
    }
    public static function getConfiguredCountries() {
        return get_option('tabby_countries', false);
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
        $disabled_skus = array_filter(explode("\n", get_option('tabby_checkout_disable_for_sku', '')));
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
}

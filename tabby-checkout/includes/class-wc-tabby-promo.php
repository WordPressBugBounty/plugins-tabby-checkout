<?php
class WC_Tabby_Promo {
    public static $inum = 0;
    public static function init() {
        add_action('woocommerce_proceed_to_checkout'    , array( __CLASS__, 'product_promotion'), 15 );
        add_action('woocommerce_before_add_to_cart_form', array( __CLASS__, 'product_promotion')     );
        add_action('wp_enqueue_scripts'                 , array( __CLASS__, 'wp_enqueue_scripts')    );
        add_action('admin_enqueue_scripts'              , array( __CLASS__, 'admin_enqueue_scripts') );
    }
    public static function wp_enqueue_scripts() {
        if (is_checkout()) {
            // css
            wp_enqueue_style('tabby-checkout', plugins_url('css/tabby.css', dirname(__FILE__)));
            // js
            wp_enqueue_script('tabby-checkout-integration', 'https://'.TABBY_CHECKOUT_DOMAIN.'/cms-plugins.js', [], null, true);
            if (WC_Gateway_Tabby_Checkout_Base::is_classic_checkout_enabled()) {
                wp_enqueue_script('tabby-checkout-tabby-js', plugins_url('js/tabby.js', dirname(__FILE__)), [], MODULE_TABBY_CHECKOUT_VERSION, true);
            }
        };

        if ((is_product() || is_cart()) && static::is_tabby_promo_enabled()) {
            wp_enqueue_script('tabby-checkout-promo', 'https://'.TABBY_CHECKOUT_DOMAIN.'/tabby-promo.js', [], null, true);
            if (!static::is_blocks_cart_enabled() || is_product()) {
                static::product_promotion_scripts();
            }
        };
    }
    public static function admin_enqueue_scripts() {
        $current_screen = get_current_screen();
        if (method_exists($current_screen, 'is_block_editor') && $current_screen->is_block_editor()) {
            wp_enqueue_script('tabby-checkout-promo', 'https://'.TABBY_CHECKOUT_DOMAIN.'/tabby-promo.js', [], null, true);
        }
    }
    public static function is_tabby_promo_enabled() {
        return 
            (static::is_tabby_promo_enabled_for_product() || static::is_tabby_promo_enabled_for_cart()) &&
            WC_Tabby_Config::isAvailableForCurrency() &&
            (static::getMaxPrice() == 0 || static::getPrice() <= static::getMaxPrice()) &&
            (static::getMinTotal() == 0 || static::getPrice() >= static::getMinTotal());
    }
    public static function is_blocks_cart_enabled() {
        if ($cart_id = get_option('woocommerce_cart_page_id', false)) {
            $cart = get_post($cart_id);
            if (preg_match("/wp:woocommerce\/cart/is", $cart->post_content)) {
                // new blocks checkout enabled
                return true;
            };
        };
        return false;
    }
    public static function is_tabby_promo_enabled_for_product() {
        return (get_option('tabby_promo') !== 'no') && is_product() && WC_Tabby_Config::isEnabledForProductSKU();
    }
    public static function is_tabby_promo_enabled_for_cart() {
        return (get_option('tabby_promo_cart') !== 'no') && is_cart() && WC_Tabby_Config::isEnabledForCartSKUs();
    }

    public static function product_promotion_scripts() {
        if (static::is_tabby_promo_enabled()) {
            $script = "
function initTabbyPromotions() {
    var tabbyConf = " . json_encode(static::getConfig()) . ";
    var price = jQuery('#tabbyPromo').attr('data-tabby-price');
    var currency = jQuery('#tabbyPromo').attr('data-tabby-currency');
    if (price) {
        tabbyConf.price = price;
        tabbyConf.currency = currency;
    }
    if (tabbyConf.localeSource == 'html') tabbyConf.lang = document.documentElement.lang;
    var tabbyPromo = new TabbyPromo(tabbyConf);
    jQuery('.tabbyPromo').each(function () {
        let tabbyConfNew = {...tabbyConf};
        tabbyConfNew.selector = '#' + jQuery(this).attr('id');
        console.log(tabbyConfNew);
        new TabbyPromo(tabbyConfNew);
    });
}
if (typeof TabbyPromo == 'undefined') {
    document.addEventListener('DOMContentLoaded', () => {
        initTabbyPromotions();
    });
} else {
    initTabbyPromotions();
}
jQuery(document.body).on('updated_wc_div', initTabbyPromotions);
// addon for product variations
document.addEventListener('DOMContentLoaded', () => {
    if (jQuery('.variations_form').length) {
        jQuery('.variations_form').on('show_variation', function (target, variation, purchasable) {
            if (jQuery('.variations_form').length == 1) {
                jQuery('#tabbyPromo').attr('data-tabby-price', variation.display_price);
            } else {
                jQuery(target.currentTarget).parent().find('#tabbyPromo').attr('data-tabby-price', variation.display_price);
            }
            initTabbyPromotions();
        })
    }
});
";
            wp_add_inline_script('tabby-checkout-promo', $script);
        }
    }
    public static function product_promotion() {
    if (static::is_tabby_promo_enabled()) {
?>
   <div id="tabbyPromo<?php echo self::$inum++?:''; ?>" class="tabbyPromo" style="margin-bottom: 20px" data-tabby-price="<?php echo esc_attr(static::getPrice()); ?>" data-tabby-currency="<?php echo esc_attr(static::getCurrency()); ?>"></div>
<?php
    }
}
    public static function getConfig() {
        return [
            "selector"  => "#tabbyPromo",
            "merchantCode" => self::getMerchantCode(),
            "publicKey" => self::getPublicKey(),
            "lang"      => self::getLocale(),
            "localeSource"=> get_option('tabby_checkout_locale_html') == 'yes' ? 'html' : '',
            "currency"  => self::getCurrency(),
            "price"     => self::getPrice(),
            "email"     => self::getEmail(),
            "phone"     => self::getPhone(),
            "source"    => self::getSource(),
            "sourcePlugin"=> 'woo',
            "theme"     => self::getTheme(),
            "installmentsCount" => self::getInstallmentsCount(),
            "productType"=> self::getProductType(),
        ];
    }
    public static function getProductType() {
        return self::isCreditCardInstallmentsActive() && (!self::isInstallmentsOrPayLaterActive() || self::isCreditCardInstallmentsActiveByPrice()) ? 'creditCardInstallments' : 'installments';
    }
    public static function isInstallmentsOrPayLaterActive() {
        require_once dirname(__FILE__) . '/class-wc-gateway-tabby-installments.php';
        $installments = new WC_Gateway_Tabby_Installments();
        $installments->init_settings();
        return ($installments->enabled == 'yes');
    }
    public static function isCreditCardInstallmentsActive() {
        require_once dirname(__FILE__) . '/class-wc-gateway-tabby-credit-card-installments.php';
        $cci = new WC_Gateway_Tabby_Credit_Card_Installments();
        $cci->init_settings();
        return $cci->enabled == 'yes';
    }
    public static function getTabbyCheckoutPromoTypePrice() {
        //return get_option('tabby_checkout_promo_type_price', -1);
        return -1;
    }
    public static function isCreditCardInstallmentsActiveByPrice() {
        $type_price = self::getTabbyCheckoutPromoTypePrice();;
        
        return $type_price >= 0 && self::getPrice() >= $type_price;
    }
    public static function getPromoThemeConfig() {
        $theme = explode(':', get_option('tabby_checkout_promo_theme', ''));

        return [
            'theme'     => array_shift($theme),
            'installmentsCount' => !empty($theme) ? 0 : 4
        ];
    }
    public static function getTheme() {
        return self::getPromoThemeConfig()['theme'];
    }
    public static function getInstallmentsCount() {
        return self::getProductType() == 'installments' ? self::getPromoThemeConfig()['installmentsCount'] : 4;
    }
    public static function getSource() {
        return is_admin() ? 'adminBlockEditor' : (is_product() ? 'product' : 'cart');
    }
    public static function getMerchantCode() {
        return WC_Tabby_Config::getPromoMerchantCode();
    }
    public static function getPublicKey() {
        return get_option('tabby_checkout_public_key');
    }
    public static function getLocale() {
        return get_locale();
    }
    public static function getCurrency() {
        return WC_Tabby_Config::getTabbyCurrency();
    }
    public static function getPrice() {
        if (is_product()) {
            $product = wc_get_product();
            $price = number_format(wc_get_price_to_display( $product ), 2, '.', '');
        } else {
            $price = number_format(self::get_order_total(), 2, '.', '');
        }
        return $price;
    }
    public static function get_order_total() {

        $total    = 0;
        $order_id = absint( get_query_var( 'order-pay' ) );

        // Gets order total from "pay for order" page.
        if ( 0 < $order_id ) {
            $order = wc_get_order( $order_id );
            $total = (float) $order->get_total();

            // Gets order total from cart/checkout.
        } elseif ( WC()->cart ) {
            $total = (float) WC()->cart->total;
        } else {
            $total = 10;
        }

        return $total;
    }
    public static function getMaxPrice() {
        return (int)get_option('tabby_checkout_promo_price', 0);
    }
    public static function getMinTotal() {
        return (int)get_option('tabby_checkout_promo_min_total', 0);
    }
    public static function getEmail() {
        $current_user = wp_get_current_user();
        return $current_user && $current_user->user_email ? $current_user->user_email : null;
    }
    public static function getPhone() {
        $current_user_id = get_current_user_id();
        $phones = [];
        $delimiter = '|';
        if ($current_user_id) {
            $metas = get_user_meta($current_user_id);
            foreach ($metas as $name => $meta) {
                if (preg_match("/phone/", $name)) {
                    $phones[] = implode($delimiter, $meta);
                }
            }
        }
        return $current_user_id ? implode($delimiter, array_filter($phones)) : null;
    }
}

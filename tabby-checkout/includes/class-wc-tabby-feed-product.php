<?php

class WC_Tabby_Feed_Product {
    public const LANGUAGES = ['en', 'ar'];
    public const TABBY_LANGUAGES = ['eng', 'ara'];

    public static function getFeedRecord($product) {
        $langs = self::getLanguages();
        $countries = self::getAllowedCountries();
        $data = [
            'id'            => (string)$product->get_id(),
            'images'        => [],
        ];
        $variation = $product;
        if ($product->get_parent_id()) {
            $data['group_id'] = (string)$product->get_parent_id();
            $product = wc_get_product($product->get_parent_id());
        }
        $data['isAvailable'] = self::getTabbyisAvailable($product);
        if ($product->get_image_id()) {
            $data['images'][] = wp_get_attachment_image_src($product->get_image_id(), 'large')[0];
        }
        $gallery_image_ids = $product->get_gallery_image_ids('edit');
        if (($additional_image_id = array_shift($gallery_image_ids)) !== null) {
            $data['images'][] = wp_get_attachment_image_src($additional_image_id, 'large')[0];
        }
        $data['price'] = (string)($variation->get_regular_price() ?: $variation->get_price());
        $data['salePrice'] = (string)($variation->get_sale_price() ? $variation->get_price() : '');
        $lang = substr(get_locale(), 0, 2);
        if (!in_array($lang, self::LANGUAGES)) $lang = 'ar';
        $tabby_lang = self::TABBY_LANGUAGES[array_search($lang, self::LANGUAGES)];
        $data[$tabby_lang] = [
            'title'         => $product->get_name(),
            'description'   => $product->get_description() ?: $product->get_short_description(),
            'categories'    => self::getTabbyCategoryPath($product),
            'attributes'    => self::getTabbyAttributes($variation),
            'link'          => $product->get_permalink(),
        ];

        foreach ($data['images'] as $index => $image) {
            if (is_null($image)) unset($data['images'][$index]);
        }
        // images and price check
        if (empty($data['images']) || empty($data['price'])) {
            throw new \WC_Tabby_Feed_Product_Exception('No images for product');
        }
        $data['price'] = get_woocommerce_currency() . ' ' . $data['price'];
        if (!empty($data['salePrice'])) {
            $data['salePrice'] = get_woocommerce_currency() . ' ' . $data['salePrice'];
        }
        // empty categories array
        if (empty($data[$tabby_lang]['categories'])) {
            throw new \WC_Tabby_Feed_Product_Exception('No categories for product');
        }
        // check for empty attributes values
        foreach ($data[$tabby_lang]['attributes'] as $index => $attribute) {
            if (empty($attribute['values'])) {
                throw new \WC_Tabby_Feed_Product_Exception('Empty values array for attribute');
            }
        }
        return $data;
    }
    public static function getTabbyIsAvailable($product) {
        $isAvailable = in_array($product->get_catalog_visibility(), ['visible', 'catalog']);

        if ($product->get_status() != 'publish') {
            $isAvailable = false;
        }

        if ( $product->get_parent_id() ) {
            $parent_product = wc_get_product( $product->get_parent_id() );

            if ($parent_product && 'publish' !== $parent_product->get_status()) {
                $isAvailable = false;
            }
        }

        return $isAvailable && (self::getTabbyStock($product) > 0);
    }
    public static function getTabbyStock($product) {
        $stock = 0;

        if ($product->get_manage_stock()) {
            $stock = $product->get_stock_quantity();
        } else {
            $stock = $product->get_stock_status() == 'instock' ? 10 : 0;
        }

        return $stock;
    }

    public static function getTabbyCategoryPath($product) {
        $categories = [];
        $terms = get_the_terms($product->get_id(), 'product_cat');
        if (is_array($terms)) {
            foreach ($terms as $term) {
                $categories[] = [
                    'path'  => self::getCategoryPath($term)
                ];
            }
        }
        if (empty($categories)) {
            $categories[] = ['path' => ['Uncategorized']];
        }
        return $categories;
    }
    public static function getCategoryPath($cat) {
        $path = [];
        if ($cat->parent != 0) {
            if ($parent = get_term_by('id', $cat->parent, 'product_cat')) {
                $path = self::getCategoryPath($parent);
            }
        }
        $path[] = $cat->name;
        return $path;
    }
    public static function getTabbyAttributes($product) {
        $result = [];
        foreach ($product->get_attributes() as $code => $attribute) {
            if (is_object($attribute)) {
                $values = array_map(function ($item) {return (string)$item;}, array_values($attribute->get_options()));
                if (!empty($values)) {
                    $result[] = [
                        'name'      => $attribute->get_name(),
                        'values'    => $values
                    ];
                }
            } else {
                $result[] = [
                    'name'      => $code,
                    'values'    => [(string)$attribute]
                ];
            }
        }
        return $result;
    }
    public static function getTabbyBrand($product, $lang) {
        return null;
    }
    public static function getAllowedCountries() {
        $countries = [];
        foreach (WC_Tabby_Config::ALLOWED_COUNTRIES as $code) {
            if (WC_Tabby_Config::isAvailableForCountry($code)) {
                $countries[] = strtolower($code);
            }
        }
        return $countries;
    }
    public static function getLanguages() {
    
        $langs = get_available_languages();
        $langs[] = substr(get_locale(), 0, 2);
        return array_unique(array_intersect(self::LANGUAGES, $langs));
    }

}

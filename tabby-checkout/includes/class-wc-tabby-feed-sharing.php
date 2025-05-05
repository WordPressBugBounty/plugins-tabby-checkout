<?php

class WC_Tabby_Feed_Sharing {
    public const CRON_JOB_NAME = 'tabby_feed_sharing_service';
    private static $updates = [
        'availability'  => [],
        'updated'       => []
    ];

    public static function init() {
        if (!WC_Tabby_Api_Feed::canOperate()) return;

        if (!WC_Tabby_Config::getShareFeed()) {
            if (WC_Tabby_Api_Feed::isRegistered()) {
                self::unregister();
            }
            return;
        }
        if (!WC_Tabby_Api_Feed::isRegistered()) {
            // register feed only
            add_action( 'shutdown', array( __CLASS__, 'register' ), 15);
            return;
        }
        add_action(self::CRON_JOB_NAME, array(__CLASS__, 'cron_service'));
        add_action( 'shutdown', array( __CLASS__, 'shutdown' ), 20);
        add_action( 'woocommerce_new_product', array(WC_Tabby_Feed_Sharing::class, 'update_product'), 10, 1 );
        add_action( 'woocommerce_update_product', array(WC_Tabby_Feed_Sharing::class, 'update_product'), 10, 1 );
        add_action( 'woocommerce_before_delete_product', array(WC_Tabby_Feed_Sharing::class, 'delete_product'), 10, 1 );
        add_action( 'woocommerce_trash_product', array(WC_Tabby_Feed_Sharing::class, 'delete_product'), 10, 1 );
        add_action( 'woocommerce_before_delete_product_variation', array(WC_Tabby_Feed_Sharing::class, 'delete_product'), 10, 1 );
        add_action( 'woocommerce_trash_product_variation', array(WC_Tabby_Feed_Sharing::class, 'delete_product'), 10, 1 );
        add_action( 'transition_post_status', array( __CLASS__, 'transition_post_status' ), 10, 3 );
    }
    public static function add_every_five_minutes( $schedules ) {
        $schedules['every_five_minutes'] = array(
            'interval'  => 60 * 5,
            'display'   => __( 'Every 5 Minutes', 'textdomain' )
        );
        return $schedules;
    }

    public static function registerCronJob() {
        if (!wp_next_scheduled(self::CRON_JOB_NAME)) {
            wp_schedule_event(time(), 'every_five_minutes', self::CRON_JOB_NAME);
        }
    } 
    public static function unregisterCronJob() {
        if (($timestamp = wp_next_scheduled(self::CRON_JOB_NAME)) !== false) {
            wp_unschedule_event($timestamp, self::CRON_JOB_NAME);
        }
    }
    public static function update_product($product_id) {
        self::$updates['updated'][] = $product_id;

    }
    public static function transition_post_status($new_status, $old_status, $post) {
        if (in_array( $post->post_type, array( 'product', 'product_variation' ), true )) {
            if ($product = wc_get_product($post->ID)) {
                self::$updates['availability'][(string)$post->ID] = WC_Tabby_Feed_Product::getTabbyisAvailable($product);
            }
        }
    }
    public static function delete_product($post_id) {
        self::$updates['availability'][(string)$post_id] = false;
        $product = wc_get_product($post_id);
        if ($product->is_type('variable')) {
            foreach ($product->get_children() as $id) {
                self::$updates['availability'][(string)$id] = false;
            }
        }
    }
    public static function api_change_availability($data) {
        $api = new WC_Tabby_Api_Feed();

        $res = $api->request('availability', 'POST', ['availabilityInfo' => $data]);

        if (!is_object($res) || property_exists($res, 'errors')) {
            return false;
        }
        return true;
    }
    public static function share_product_feed() {
        $args = [
            'posts_per_page' => -1,
            'return' => 'ids'
        ];
        $products = wc_get_products($args);

        self::saveFailedToDatabase('update', $products);
    }
    public static function update_products($ids) {
        $ids = array_unique($ids);
        $args = [
            'posts_per_page' => -1,
            'include'        => $ids
        ];

        $failed = [];
        $ignored = 0;

        $data = [];
        $products = wc_get_products($args);
        foreach ($products as $product) {
            if ($product->is_type('variable')) {
                foreach ($product->get_available_variations() as $p) {
                    try {
                        $data[] = WC_Tabby_Feed_Product::getFeedRecord(wc_get_product($p['variation_id']));
                    } catch (\WC_Tabby_Feed_Product_Exception $e) {
                        $ignored++;
                    }
                }
            } else {
                try {
                    $data[] = WC_Tabby_Feed_Product::getFeedRecord($product);
                } catch (\WC_Tabby_Feed_Product_Exception $e) {
                    $ignored++;
                }
            }
        }
        if (!empty($data)) {
            $chunked = array_chunk($data, 100);
            foreach ($chunked as $chunk) {
                if (!self::api_update_products($chunk)) {
                    $failed = array_merge($failed, array_map(function ($item) {return (int)$item["id"];}, $chunk));
                };
            }
        }
        return $failed;
    }
    public static function api_update_products($data) {
        $api = new WC_Tabby_Api_Feed();
        $res = $api->request('products', 'POST', ['products' => $data]);

        if (!is_object($res) || property_exists($res, 'errors')) {
            return false;
        }
        return true;
    }
    public static function register() {
        if (!WC_Tabby_Config::getShareFeed()) return false;

        $api = new WC_Tabby_Api_Feed();

        if ($api->register()) {
            self::share_product_feed();
            self::registerCronJob();
            return true;
        }

        return false;
    }
    public static function unregister() {
        $api = new WC_Tabby_Api_Feed();

        $api->uninstall();

        self::unregisterCronJob();
    }
    public static function shutdown() {
        // run cron service on every 100 request
        if (rand(1, 1000) <= 10) {
            self::cron_service();
        }

        $processed = [];
        // build product updates queries
        $ids = self::$updates['updated'];
        if (!empty($ids)) {
            $failed = self::update_products($ids);
            if (!empty($failed)) {
                self::saveFailedToDatabase('update', $failed);
            }
            $processed = array_diff($ids, $failed);
        }
        if (!empty(self::$updates['availability'])) {
            $data = [];
            foreach (self::$updates['availability'] as $id => $status) {
                if (in_array((int)$id, $processed)) continue;
                $data[] = [
                    'id'            => (string)$id,
                    'isAvailable'   => $status
                ];
            }
            if (!empty($data)) {
                if (!self::api_change_availability($data)) {
                    self::saveFailedToDatabase('availability', array_map(function ($item) {return (int)$item->id;}, $data));
                };
            }
        }
    }
    public static function cron_service() {
        $option_name = 'tabby_feed_sharing_update';
        $lock = new WC_Tabby_Lock();
        $processed = [];
        if ($lock->lock($option_name)) {
            $ids = get_option($option_name, []);
            $failed = [];
            if (!empty($ids)) {
                $failed = self::update_products($ids);
                update_option($option_name, $failed);
            }
            $processed = array_diff($ids, $failed);

            $lock->unlock($option_name);
        }
        $option_name = 'tabby_feed_sharing_availability';
        if ($lock->lock($option_name)) {
            $ids = get_option($option_name, []);
            if (!empty($ids)) {
                $args = [
                    'posts_per_page'    => -1,
                    'include'           => $ids
                ];
                $products = wc_get_products($args);
                $data = [];
                foreach ($products as $product) {
                    $data[] = [
                        'id'            => (string)$product->get_id(),
                        'isAvailable'   => WC_Tabby_Feed_Product::getTabbyisAvailable($product)
                    ];
                    if ($product->is_type('variable')) {
                        foreach ($product->get_children() as $variation_id) {
                            $variation = wc_get_product($variation_id);
                            $data[] = [
                                'id'            => (string)$variation->get_id(),
                                'isAvailable'   => WC_Tabby_Feed_Product::getTabbyisAvailable($variation)
                            ];
                        }
                    }
                }
                if (!empty($data)) {
                    if (self::api_change_availability($data)) {
                        $ids = [];
                    }
                }
            }
            update_option($option_name, $ids);

            $lock->unlock($option_name);
        }
    }
    public static function saveFailedToDatabase($type, $ids) {
        $lock = new WC_Tabby_Lock();
        $option_name = 'tabby_feed_sharing_' . $type;

        if ($lock->lock($option_name)) {
            $value = get_option($option_name, []);
            $new_value = array_unique(array_merge($value, $ids));
            update_option($option_name, $new_value);

            $lock->unlock($option_name);
        }
    }
}

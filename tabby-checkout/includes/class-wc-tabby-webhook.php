<?php
require_once (__DIR__ . '/class-wc-rest-tabby-controller.php');

class WC_Tabby_Webhook {
    private const CRON_JOB_NAME = 'tabby_webhook_service';
    public static function init() {
        add_action(self::CRON_JOB_NAME, [__CLASS__, 'cron_service']);
        //add_filter( 'action_scheduler_groups', [__CLASS__, 'wc_custom_register_action_group'] );

        add_action('action_scheduler_init', function () {
            // once at 33 page loads
            if (mt_rand(1, 1000) < 31) {
                static::registerCronJob();
            }
        });
    }
    public static function wc_custom_register_action_group( $groups ) {
        $groups['tabby-checkout'] = __( 'Tabby', 'textdomain' );
        return $groups;
    }
    public static function registerCronJob() {
        // use Woo scheduled action
        if ( false === as_next_scheduled_action( self::CRON_JOB_NAME ) ) {
            as_schedule_recurring_action( time(), 3600 * 6, self::CRON_JOB_NAME, array(), 'tabby-checkout');
        }
    }
    public static function unregisterCronJob() {
        // use Woo scheduled action
        as_unschedule_all_actions(self::CRON_JOB_NAME);
    }
    public static function cron_service() {
        if (!WC_Tabby_Api::needs_setup()) {
            static::register();
        }
    }
    public static function register() {
        static::registerCronJob();

        if (WC_Tabby_Api::needs_setup()) {
            static::ddlog("warn", "Tabby is not configured, but webhook 'register' called. Possible first module installation.");
            return;
        }
        // get webhook url
        $url = WC_REST_Tabby_Controller::getEndpointUrl();

        // request all webhooks
        foreach (WC_Tabby_Config::getConfiguredCountries() as $country) {
            // the key is not allowed for this country: do not ask again for a day (or until the keys change)
            if (static::isCachedNotAuthorized($country)) continue;
            // get list of registered hooks
            $hooks = static::getWebhooks($country);
            // bypass not authorized errors
            if (static::isNotAuthorized($hooks) || (int)WC_Tabby_Api::$last_status === 401) {
                static::cacheNotAuthorized($country);
                static::ddlog("info", "Store code not authorized for merchant", null, ['code' => $country]);
                continue;
            }
            if (!is_array($hooks)) {
                $hooks = [$hooks];
            }
            $registered = false;
            foreach ($hooks as $hook) {
                if (!is_object($hook)) continue;
                if (property_exists($hook, 'url') && $hook->url == $url) {
                    if ($hook->is_test !== static::getIsTest()) {
                        static::updateWebhook($hook, $country, $url);
                    }
                    $registered = true;
                }
            }
            if (!$registered) {
                static::registerWebhook($country, $url);
            }
        }
    }
    protected static function notAuthorizedKey($country) {
        return 'tabby_webhook_na_' . $country . '_' . substr(hash('sha256', (string)WC_Tabby_Api::get_api_option('secret_key')), 0, 12);
    }
    public static function isCachedNotAuthorized($country) {
        return false !== get_transient(static::notAuthorizedKey($country));
    }
    public static function cacheNotAuthorized($country) {
        set_transient(static::notAuthorizedKey($country), 1, DAY_IN_SECONDS);
    }
    public static function isNotAuthorized($response) {
        if (is_object($response) && property_exists($response, 'errorType') && in_array($response->errorType, ['not_authorized', 'not_found'])) return true;
        return false;
    }
    public static function registerWebhook($code, $url) {
        $data = ['url' => $url, 'is_test' => static::getIsTest()];
        static::ddlog("info", "Registering webhook", null, $data);
        return (new WC_Tabby_Api($code))->request('webhooks', 'POST', $data, $code);
    }
    public static function updateWebhook($hook, $code, $url) {
        $data = ['url' => $url, 'is_test' => static::getIsTest()];
        static::ddlog("info", "Updating webhook", null, $data);
        return (new WC_Tabby_Api($code))->request('webhooks/' . $hook->id, 'PUT', $data, $code);
    }
    public static function deleteWebhook($hook, $code) {
        return (new WC_Tabby_Api($code))->request('webhooks/' . $hook->id, 'DELETE', null, $code);
    }
    public static function getWebhooks($code) {
        return (new WC_Tabby_Api($code))->request('webhooks', 'GET', null, $code);
    }
    public static function getIsTest() {
        return (bool)preg_match('#^sk_test#', WC_Tabby_Api::get_api_option('secret_key'));
    }
    public static function unregister() {
        static::unregisterCronJob();
        if (WC_Tabby_Api::needs_setup()) {
            static::ddlog("warn", "Tabby is not configured, but webhook 'unregister' called. Possible wrong module configuration.");
            return;
        }
        // get webhook url
        $url = WC_REST_Tabby_Controller::getEndpointUrl();
        
        // request all webhooks
        foreach (WC_Tabby_Config::ALLOWED_COUNTRIES as $country) {
            // get list of registered hooks
            $hooks = static::getWebhooks($country);
            // bypass not authorized errors
            if (static::isNotAuthorized($hooks)) {
                static::ddlog("info", "unregister: Store code not authorized for merchant", null, ['code' => $country]);
                continue;
            }
            if (!is_array($hooks)) {
                $hooks = [$hooks];
            }
            foreach ($hooks as $hook) {
                if (!is_object($hook)) continue;
                if (property_exists($hook, 'url') && $hook->url == $url && $hook->is_test === static::getIsTest()) {
                    static::deleteWebhook($hook, $country);
                }
            }
        }
    }
    public static function ddlog($status = "error", $message = "Something went wrong", $e = null, $data = null) {
        return WC_Tabby_Api::ddlog($status, $message, $e, $data);
    }
}


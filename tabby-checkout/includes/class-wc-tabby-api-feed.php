<?php

class WC_Tabby_Api_Feed {
    private $last_status = null;
    const TABBY_CHECKOUT_FEED_TOKEN_OPTION = 'tabby_checkout_feed_token';
    const TABBY_CHECKOUT_FEED_CRED_OPTION = 'tabby_checkout_feed_cred';
    const TABBY_CHECKOUT_FEED_REG_ATTEMPT = 'tabby_checkout_feed_reg_attempt';
    const TABBY_CHECKOUT_FEED_UNREG_ATTEMPT = 'tabby_checkout_feed_unreg_attempt';
    const TABBY_CHECKOUT_FEED_CODES = ['AE', 'SA'];

    public static function canOperate() {
        // only for production keys
        if (!WC_Tabby_Api::isSecretKeyProduction()) return false;
        // only for 3 countries
        if (!in_array(self::getMerchantCode(), self::TABBY_CHECKOUT_FEED_CODES)) return false;

        return true;
    }

    public static function isRegistered() {
        return !is_null(get_option(self::TABBY_CHECKOUT_FEED_TOKEN_OPTION, null));
    }
    public function uninstall() {
        if (!static::isRegistered()) {
            delete_option(self::TABBY_CHECKOUT_FEED_REG_ATTEMPT);
            return true;
        }
        // check if there is previous uninstall attempt
        if (time() < (int)get_option(self::TABBY_CHECKOUT_FEED_UNREG_ATTEMPT, 0)) {
            // bypass request
            return false;
        }
        $cred = json_decode(get_option(self::TABBY_CHECKOUT_FEED_CRED_OPTION, json_encode($this->getFeedCredentials())), true);
        unset($cred['secretKey']);
        $result = $this->request('uninstall', 'POST', $cred);
        // successful
        if (sizeof((array)$result) == 0) {
            delete_option(self::TABBY_CHECKOUT_FEED_TOKEN_OPTION);
            delete_option(self::TABBY_CHECKOUT_FEED_CRED_OPTION);
            delete_option(self::TABBY_CHECKOUT_FEED_REG_ATTEMPT);
            delete_option(self::TABBY_CHECKOUT_FEED_UNREG_ATTEMPT);
        } else {
            // unregister failed
            update_option(self::TABBY_CHECKOUT_FEED_UNREG_ATTEMPT, time() + 2 * HOUR_IN_SECONDS);
        }

        return true;
    }
    public function register() {
        // check if there is previous registration attempt
        if (time() < (int)get_option(self::TABBY_CHECKOUT_FEED_REG_ATTEMPT, 0)) {
            // bypass request
            return false;
        }

        $result = $this->request(
            'register',
            'POST',
            $this->getFeedCredentials()
        );

        if (is_object($result) && property_exists($result, 'token')) {
            update_option(self::TABBY_CHECKOUT_FEED_TOKEN_OPTION, $result->token);
            update_option(self::TABBY_CHECKOUT_FEED_CRED_OPTION, json_encode($this->getFeedCredentials()));
            return true;
        } else {
            // a business answer (paused, declined, not available for marketplace) holds for days: retry daily;
            // a network error or a 5xx is transient: retry in 4 hours as before
            $transient = $this->last_status === 'error' || (int)$this->last_status >= 500 || in_array((int)$this->last_status, [408, 425, 429], true);
            update_option(self::TABBY_CHECKOUT_FEED_REG_ATTEMPT, time() + ($transient ? 4 * HOUR_IN_SECONDS : DAY_IN_SECONDS));

            // log site logo for failed registrations
            if (has_custom_logo()) {
                $data['logo'] = $this->get_custom_logo_link(get_custom_logo());
            } else {
                $data['msg'] = "Custom logo not set";
            }

            // the gateway answers the same way for hours (e.g. registration paused): log once a day
            if (false === get_transient('tabby_feed_reg_failed_logged')) {
                set_transient('tabby_feed_reg_failed_logged', 1, DAY_IN_SECONDS);
                WC_Tabby_Api::ddlog("info", "Feed registration failed", null, $data);
            }
        }

        return false;
    }
    private function get_custom_logo_link($html) {
        $link = '';
        try {
            $dom = new \DOMDocument();
            $dom->loadHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html);
            foreach ($dom->getElementsByTagName('img') as $img) {
                if ($link = $img->getAttribute('src')) {
                    break;
                }
            }
        } catch (\Exception $e) {
            $link = "Error parsing html: " . $html;
        }
        return $link;
    }
    private function getFeedCredentials() {
        return [
            'secretKey'     => $this->getSecretKey(),
            'merchantCode'  => $this->getMerchantCode(),
            'domain'        => $this->getStoreDomain()
        ];
    }
    private function getSecretKey() {
        return WC_Tabby_Api::get_api_option('secret_key');
    }
    private function getStoreDomain() {
        $storeURL = parse_url(get_site_url());
        return $storeURL['host'];
    }
    private static function getMerchantCode() {
        return WC_Tabby_Config::getDefaultMerchantCode();
    }

    private function get_api_url($country) {
        return sprintf('https://plugins-api.%s/webhooks/v1/tabby/', WC_Tabby_Config::get_tabby_domain($country));
    }
    public function request($endpoint, $method = 'GET', $data = []) {
        if (!$this->getSecretKey()) {
            WC_Tabby_Api::ddlog("info", "Secret key not set, ignore request", null, []);
            return false;
        }

        if (($endpoint != 'register') && !$this->isRegistered()) {
            return false;
        }

        // do not process feed on test credentials
        if (strstr($this->getSecretKey(), 'sk_test_') !== false) {
            WC_Tabby_Api::ddlog("info", "Test credentials, ignore request", null, [
                'endpoint'  => $endpoint,
                'method'    => $method
            ]);
            return false;
        }

        $client = new \WP_Http();

        $url = $this->get_api_url($this->getMerchantCode()) . $endpoint;

        $args = array();
        $args['timeout'] = 60;
        $args['method' ] = $method;
        $args['headers'] = array();
        $args['headers']['X-Tabby-Plugin-Platform'] = 'woo';
        $args['headers']['X-Tabby-Plugin-Version'] = MODULE_TABBY_CHECKOUT_VERSION;
        if ($endpoint != 'register') {
            $args['headers']['X-Tabby-store-domain'] = $this->getStoreDomain();
            $args['headers']['X-Tabby-merchant-code'] = $this->getMerchantCode();
        }
        if ($data && ($endpoint != 'register')) {
            $args['headers']['X-Tabby-Sign'] = $this->getSignature($data);
        }

        if ($method !== 'GET') {
            $args['headers']['Content-Type'] = 'application/json';
            $params = json_encode($data);
            $args['body'] = $params;
        }


        $response = $client->request($url, $args);
        $this->last_status = is_wp_error($response) ? 'error' : (int)$response["response"]["code"];
        $er = error_reporting(E_ERROR);
        $logData = array(
            "request.url"       => $url,
            "request.body"      => $args["body"],
            "request.method"    => $args["method"],
            "request.headers"   => $args["headers"],
            "response.body"     => is_wp_error($response) ? '' : $response["body"],
            "response.status"   => is_wp_error($response) ? 'error' : $response["response"]["code"],
            "response.error"    => is_wp_error($response) ? $response->get_error_message() : ''
        );
        error_reporting($er);

        // success is the normal case (1M+ lines a week fleet-wide): log failures only, never the register body
        $feed_failed = is_wp_error($response)
            || (int)$response["response"]["code"] >= 300
            || (is_object($decoded = json_decode($response["body"])) && property_exists($decoded, 'errors'));
        // the gateway answers the same way for hours (registration paused, store not found): log each
        // endpoint/status once a day, without the request body (credentials on register, the catalogue otherwise)
        if ($feed_failed) {
            $key = 'tabby_feed_err_' . md5($endpoint . '|' . $logData["response.status"]);
            if (false === get_transient($key)) {
                set_transient($key, 1, DAY_IN_SECONDS);
                unset($logData["request.body"]);
                $logData["response.body"] = function_exists('mb_substr') ? mb_substr((string)$logData["response.body"], 0, 500) : substr((string)$logData["response.body"], 0, 500);
                WC_Tabby_Api::ddlog("info", "feed api: " . $endpoint, null, $logData);
            }
        }

        $result = [];

        if (is_wp_error($response)) {
            //throw new \Exception( $response->get_error_message() );
            return new \StdClass();
        }

        switch ($response['response']['code']) {
            case 200:
                $result = json_decode($response["body"]);
                break;
            default:
                $result = json_decode($response["body"]);
                break;
        }

        return $result;
    }
    private function getSignature($data) {
        if (!$this->isRegistered()) {
            WC_Tabby_Api::ddlog("info", "Signature required, but feed not registerd", null, []);
            return null;
        }

        if (is_array($data)) {
            $data = json_encode($data);
        }
        return base64_encode(hash_hmac('sha256', $data, get_option(self::TABBY_CHECKOUT_FEED_TOKEN_OPTION, null), true));
    }
}

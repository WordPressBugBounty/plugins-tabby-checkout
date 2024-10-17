<?php

class WC_Tabby_Api_Feed {
    const API_URI = 'https://' . TABBY_FEED_API_DOMAIN . '/webhooks/v1/tabby/';
    const TABBY_CHECKOUT_FEED_TOKEN_OPTION = 'tabby_checkout_feed_token';
    const TABBY_CHECKOUT_FEED_CRED_OPTION = 'tabby_checkout_feed_cred';
    const TABBY_CHECKOUT_FEED_REG_ATTEMPT = 'tabby_checkout_feed_reg_attempt';
    const TABBY_CHECKOUT_FEED_CODES = ['AE', 'SA', 'KW'];

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
            return true;
        }
        $cred = json_decode(get_option(self::TABBY_CHECKOUT_FEED_CRED_OPTION, json_encode($this->getFeedCredentials())), true);
        unset($cred['secretKey']);
        $result = $this->request('uninstall', 'POST', $cred);
        delete_option(self::TABBY_CHECKOUT_FEED_TOKEN_OPTION);
        delete_option(self::TABBY_CHECKOUT_FEED_CRED_OPTION);
        delete_option(self::TABBY_CHECKOUT_FEED_REG_ATTEMPT);

        return true;
    }
    public function register() {
        // check if there is previous registration attempt
        $reg_attempt_name = self::TABBY_CHECKOUT_FEED_REG_ATTEMPT;
        if (time() < (int)get_option($reg_attempt_name, 0)) {
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
            // registration failed - set transient to 4 hours
            update_option($reg_attempt_name, time() + 4 * HOUR_IN_SECONDS);

            // log site logo for failed registrations
            if (has_custom_logo()) {
                $data['logo'] = $this->get_custom_logo_link(get_custom_logo());
            } else {
                $data['msg'] = "Custom logo not set";
            }

            WC_Tabby_Api::ddlog("info", "Feed registration failed", null, $data);
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
                'method'    => $method,
                'data'      => $data
            ]);
            return false;
        }

        $client = new \WP_Http();

        $url = static::API_URI . $endpoint;

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
        $er = error_reporting(E_ERROR);
        $logData = array(
            "request.url"       => $url,
            "request.body"      => $args["body"],
            "request.method"    => $args["method"],
            "request.headers"   => $args["headers"],
            "response.body"     => is_wp_error($response) ? '' : $response["body"],
            "response.status"   => is_wp_error($response) ? '' : $response["response"]["code"],
            "response.error"    => is_wp_error($response) ? $response->get_error_message() : ''
        );
        error_reporting($er);

        WC_Tabby_Api::ddlog("info", "feed api: " . $endpoint, null, $logData);

        $result = [];

        if (is_wp_error($response)) {
            throw new \Exception( $response->get_error_message() );
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

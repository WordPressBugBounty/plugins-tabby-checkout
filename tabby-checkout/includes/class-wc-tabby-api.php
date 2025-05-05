<?php
class WC_Tabby_Api {
    const API_URI  = 'https://'.TABBY_CHECKOUT_API_DOMAIN.'/api/v1/';
    const API2_URI = 'https://'.TABBY_CHECKOUT_API_DOMAIN.'/api/v2/';

    public static function needs_setup() {
        if (static::get_api_option('public_key') && static::get_api_option('secret_key')) {
            return false;
        }
        return true;
    }

    public static function isSecretKeyProduction() {
        $secret_key = self::get_api_option('secret_key', "");
        return preg_match("#^sk_[\da-f]{8}\-[\da-f]{4}\-[\da-f]{4}\-[\da-f]{4}\-[\da-f]{12}$#", $secret_key);
    }

    public static function get_api_option($option, $default = null) {
        return get_option('tabby_checkout_' . $option, $default);
    }

    public static function request($endpoint, $method = 'GET', $data = null, $merch_code = null) {

        if (!static::get_api_option('secret_key')) {
            return null;
        }

        $client = new \WP_Http();


        $url = static::API_URI . $endpoint;

        if (($method == 'GET' && $endpoint != 'webhooks') || $endpoint == 'checkout') {
            $url = static::API2_URI . $endpoint;
        }

        $args = array();
        $args['timeout'] = 60;
        $args['method' ] = $method;
        $args['headers'] = array();
        $args['headers']['Authorization'] = "Bearer " . static::get_api_option('secret_key');
        if ($merch_code) {
            $args['headers']['X-Merchant-Code'] = $merch_code;
        }

        static::debug(['request', $endpoint, $method, (array)$args]);

        if ($method !== 'GET') {
            $args['headers']['Content-Type'] = 'application/json';
            $params = json_encode($data);
            static::debug(['request - params', $params]);
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
        static::ddlog("info", "api call", null, $logData);

        $result = [];
        static::debug(['response', (array)$response]);

        if (is_wp_error($response)) {
            throw new \Exception( $response->get_error_message() );
        }

        switch ($response['response']['code']) {
        case 200:
            $result = json_decode($response["body"]);
            static::debug(['response - success data', (array)$result]);
            break;
        default:
            $body = $response["body"];
            $msg = "Server returned: " . $response['response']['code'] . '. ';
            if (!empty($body)) {
                $result = json_decode($body);
                if (!property_exists($result, 'error')) {
                    $result->error = '';
                    $msg .= $result->errorType . ': ' . $result->error;
                }
                static::debug(['response - body - ', (array)$result]);
            }
            //wc_add_notice( __($msg), 'error');
            //throw new \Exception( $msg );
            break;
        }

        return $result;
    }

    public static function debug($data) {
        if (static::get_api_option('debug', 'no') == 'yes') {
            if (!file_exists(__DIR__ . '/../log')) mkdir (__DIR__ . '/../log', 0777);
            $fp = fopen(__DIR__ . '/../log/tabby.log', "a+");
            fputs($fp, date("[Y-m-d H:i:s] ") . print_r($data, true));
            fclose($fp);
        } else {
            //if log file exists, delete it
            if (file_exists(__DIR__ . '/../log/tabby.log')) {
                unlink(__DIR__ . '/../log/tabby.log');
                rmdir(__DIR__ . '/../log/');
            }
        };
    }

    public static function ddlog($status = "error", $message = "Something went wrong", $e = null, $data = null) {
        $client = new \WP_Http();

        $args = array();
        $args['method'] = "POST";
        $args['headers'] = array();
        $args['headers']['DD-API-KEY'] = "pub52c39090d2b6827fe4bad20d337da6ae";
        $args['headers']['Content-Type'] = 'application/json';

        $storeURL = parse_url(get_site_url());

        $woo_dir = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
        $woo_data = get_plugin_data($woo_dir);

        $log = array(
            "status"  => $status,
            "message" => $message,

            "service"  => "woo",
            "sversion" => $woo_data['Version'],
            "hostname" => $storeURL["host"],

            "ddsource" => "php",
            "ddtags"   => "env:prod,version:" . MODULE_TABBY_CHECKOUT_VERSION
        );

        if ($e) {
            $log["error.kind"]    = $e->getCode();
            $log["error.message"] = $e->getMessage();
            $log["error.stack"]   = $e->getTraceAsString();
        }

        if ($data) {
            $log["data"] = $data;
        }

        $params = json_encode($log);

        $args['body'] = $params;

        $client->request("https://logs.browser-intake-datadoghq.eu/api/v2/logs", $args);
    }
}

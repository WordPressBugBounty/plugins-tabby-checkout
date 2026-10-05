<?php
class WC_Tabby_Api {

    var $country = 'AE';
    var $version = '2';

    // HTTP status of the last request() call, for callers that only get the decoded body
    public static $last_status = null;

    public function __construct($country) {
        $this->country = $country;
    }

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
    public function get_endpoint_url($endpoint = '') {
        $version = $this->version;

        if (substr($endpoint, 0, 8) == 'webhooks') {
            $version = 1;
        }

        return sprintf("https://api.%s/api/v%s/%s", WC_Tabby_Config::get_tabby_domain($this->country), $version, $endpoint);
    }

    public function request($endpoint, $method = 'GET', $data = null, $merch_code = null) {

        if (!static::get_api_option('secret_key')) {
            return null;
        }

        $client = new \WP_Http();

        $url = $this->get_endpoint_url($endpoint);

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

        $started = microtime(true);
        $response = $client->request($url, $args);
        $duration = (int)round((microtime(true) - $started) * 1000);
$er = error_reporting(E_ERROR);
        $status_code = is_wp_error($response) ? 'error' : $response["response"]["code"];
        static::$last_status = $status_code;
        $logData = array(
            "request.url"       => $url,
            "request.method"    => $args["method"],
            "request.headers"   => $args["headers"],
            "response.status"   => $status_code,
            "response.error"    => is_wp_error($response) ? $response->get_error_message() : ''
        );
        // bodies only where they help debugging a payment; webhook/feed bodies are noise
        if (static::log_bodies_for($endpoint)) {
            $logData["request.body"]  = $args["body"];
            $logData["response.body"] = is_wp_error($response) ? '' : $response["body"];
        } elseif (!is_wp_error($response) && (int)$status_code >= 400) {
            // error responses are short and say why (wrong key, wrong merchant code)
            $logData["response.body"] = function_exists('mb_substr') ? mb_substr((string)$response["body"], 0, 500) : substr((string)$response["body"], 0, 500);
        }
error_reporting($er);
        static::ddlog_with('info', "api call", $logData, [
            'endpoint'      => preg_replace('#[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}#', '{id}', $endpoint),
            'http_method'   => $method,
            'http_status'   => $status_code,
            'duration_ms'   => $duration,
            'merchant_code' => $merch_code ?: $this->country,
        ]);

        $result = [];
        static::debug(['response', (array)$response]);

        if (is_wp_error($response)) {
            //throw new \Exception( $response->get_error_message() );
            return new \StdClass();
        }

        switch ($response['response']['code']) {
        case 200:
            $result = json_decode($response["body"]) ?: new \StdClass();
            static::debug(['response - success data', (array)$result]);
            break;
        case 403:
            if (substr(trim($response["body"]), 0, 15) == '<!DOCTYPE html>') {
                static::ddlog('error', 'API html response detected', null, $logData);
            }
        default:
            $body = $response["body"];
            $msg = "Server returned: " . $response['response']['code'] . '. ';
            if (!empty($body)) {
                $result = json_decode($body) ?: new \StdClass();
                static::debug(['response - body - ', (array)$result]);
            }
            break;
        }

        return $result;
    }

    public static function debug($data) {
        if (static::get_api_option('debug', 'no') == 'yes') {
            if (!file_exists(__DIR__ . '/../log')) mkdir (__DIR__ . '/../log', 0777);
            $fp = fopen(__DIR__ . '/../log/tabby.log', "a+");
            $msg = self::mask_secret_key(print_r($data, true));
            fputs($fp, date("[Y-m-d H:i:s] ") . $msg);
            fclose($fp);
        } else {
            //if log file exists, delete it
            if (file_exists(__DIR__ . '/../log/tabby.log')) {
                unlink(__DIR__ . '/../log/tabby.log');
                rmdir(__DIR__ . '/../log/');
            }
        };
    }
    public static function mask_secret_key($str) {
        return preg_replace("#Bearer sk_[^\n]*\-[0-9a-f]{8}([0-9a-f]{4})#s", "Bearer sk_XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXX\\1\n", $str);
    }

    const DD_INTAKE_URL   = "https://logs.browser-intake-datadoghq.eu/api/v2/logs";
    const DD_CLIENT_TOKEN = "pubc0ef0c7691ebf9eb4c1d08d6c711cfc5";
    const DD_BATCH_SIZE   = 200;  // entries per intake request
    const DD_BUFFER_MAX   = 1000; // entries kept per PHP request, extra entries are dropped

    protected static $dd_buffer = [];
    protected static $dd_shutdown_registered = false;
    protected static $dd_context = null;

    /**
     * Legacy entry point, kept for every existing call site.
     */
    public static function ddlog($status = "error", $message = "Something went wrong", $e = null, $data = null) {
        $log = static::build_log($status, $message, $e);
        if ($data) {
            $log["data"] = $data;
        }
        static::enqueue_log($log);
    }

    /**
     * Analytics-style event: every field goes to the top level of the log
     * (Datadog facets), "evt" carries the event type.
     */
    public static function event($evt, $fields = [], $status = 'info') {
        $log = static::build_log($status, $evt, null);
        $log['evt'] = $evt;
        foreach ((array)$fields as $k => $v) $log[$k] = $v;
        static::enqueue_log($log);
    }

    /**
     * Legacy message with nested "data" plus extra top-level facets.
     */
    public static function ddlog_with($status, $message, $data = null, $top = [], $e = null) {
        $log = static::build_log($status, $message, $e);
        if ($data) $log['data'] = $data;
        foreach ((array)$top as $k => $v) $log[$k] = $v;
        static::enqueue_log($log);
    }

    protected static function build_log($status, $message, $e = null) {
        $log = array_merge(array(
            "status"   => $status,
            "message"  => $message,
            "service"  => "woo",
            "ddsource" => "php",
            "ddtags"   => "env:prod,version:" . MODULE_TABBY_CHECKOUT_VERSION,
        ), static::get_log_context());

        if ($e) {
            $log["error.kind"]    = $e->getCode();
            $log["error.message"] = $e->getMessage();
            $log["error.stack"]   = $e->getTraceAsString();
        }
        return $log;
    }

    protected static function get_log_context() {
        if (static::$dd_context === null) {
            $storeURL = parse_url(get_site_url());
            $pk = (string)static::get_api_option('public_key', '');
            static::$dd_context = array(
                "hostname"       => isset($storeURL["host"]) ? $storeURL["host"] : '',
                "sversion"       => defined('WC_VERSION') ? WC_VERSION : '',
                "wp_version"     => get_bloginfo('version'),
                "php_version"    => PHP_VERSION,
                "pk"             => preg_match('#^pk_(test_)?[0-9a-f-]{36}$#', $pk) ? $pk : '',
                "is_test"        => strpos((string)static::get_api_option('secret_key', ''), 'sk_test_') === 0,
                "store_currency" => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '',
            );
        }
        return static::$dd_context;
    }

    protected static function enqueue_log($log) {
        // PII & secrets masking (PDPL log obfuscation), single point for every log call
        if (count(static::$dd_buffer) >= static::DD_BUFFER_MAX) return;
        $entry = json_decode(json_encode($log, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR), true);
        if (!is_array($entry)) return;
        static::$dd_buffer[] = static::maskData($entry);

        if (!static::$dd_shutdown_registered) {
            static::$dd_shutdown_registered = true;
            add_action('shutdown', array(__CLASS__, 'flush_logs'), 1000);
        }
    }

    /**
     * One short request per batch at the end of the PHP request, instead of one
     * request (up to 5 s each) per log line in the middle of the checkout.
     * Non-blocking WP_Http over HTTPS drops data before the TLS handshake ends, so
     * this stays blocking with a tight timeout.
     */
    public static function flush_logs() {
        if (empty(static::$dd_buffer)) return;
        $buffer = static::$dd_buffer;
        static::$dd_buffer = [];

        // hand the response to the buyer first where the server allows it, then send the logs
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }

        $client = new \WP_Http();
        foreach (array_chunk($buffer, static::DD_BATCH_SIZE) as $batch) {
            $client->request(static::DD_INTAKE_URL, array(
                'method'    => 'POST',
                'timeout'   => 2,
                'headers'   => array(
                    'DD-API-KEY'    => static::DD_CLIENT_TOKEN,
                    'Content-Type'  => 'application/json',
                ),
                'body'      => json_encode($batch, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            ));
        }
    }

    protected static function log_bodies_for($endpoint) {
        return strpos($endpoint, 'checkout') === 0 || strpos($endpoint, 'payments') === 0;
    }

    public static function maskData($data, $parent = '') {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = is_array($value)
                    ? static::maskData($value, is_string($key) ? $key : $parent)
                    : static::maskScalar($key, $value, $parent);
            }
            return $data;
        }
        return static::maskScalar('', $data, $parent);
    }

    protected static function maskScalar($key, $value, $parent = '') {
        if (is_null($value) || $value === '') return $value;
        $k = strtolower((string)$key);
        if ($k == 'email') return static::maskEmail($value);
        if ($k == 'phone' || $k == 'telephone') return '***' . substr((string)$value, -3);
        if (in_array($k, array('dob', 'address', 'address_1', 'address_2', 'first_name', 'last_name'))) return '***';
        if ($k == 'name' && strtolower($parent) == 'buyer') return '***';
        if (is_string($value)) return static::maskString($value);
        return $value;
    }

    protected static function maskEmail($value) {
        if (!is_string($value) || ($pos = strrpos($value, '@')) === false) return '***';
        // keep the domain - an allowed plaintext fragment
        return '***' . substr($value, $pos);
    }

    protected static function maskString($value) {
        // secret keys (Authorization headers, request bodies)
        $value = preg_replace('/sk_[0-9a-zA-Z_.\-]+([0-9a-zA-Z]{4})/', 'sk_...$1', $value);
        // PII fields inside JSON-encoded bodies; keep email domain and phone tail as debug fragments
        $value = preg_replace('/("email"\s*:\s*")((?:\\\\.|[^"\\\\])*)(@(?:\\\\.|[^"\\\\])*")/u', '$1***$3', $value);
        $value = preg_replace('/("(?:phone|telephone)"\s*:\s*")((?:\\\\.|[^"\\\\])*?)([0-9]{0,3}")/u', '$1***$3', $value);
        $value = preg_replace('/("(?:name|first_name|last_name|dob|address|address_1|address_2)"\s*:\s*")((?:\\\\.|[^"\\\\])*)(")/u', '$1***$3', $value);
        return $value;
    }
}

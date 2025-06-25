<?php
// includes/class-vss-zakeke-api.php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class VSS_Zakeke_API {

    const TOKEN_URL = "https://api.zakeke.com/token";
    const API_BASE_URL = "https://api.zakeke.com";
    const TOKEN_TRANSIENT_KEY = 'vss_zakeke_access_token';

    public static function init() {
    }

    private static function get_credentials() {
        $options = get_option('vss_zakeke_settings');
        return [
            'client_id' => isset($options['client_id']) ? trim($options['client_id']) : '',
            'client_secret' => isset($options['client_secret']) ? trim($options['client_secret']) : '',
        ];
    }

    public static function get_access_token() {
        $cached_token = get_transient(self::TOKEN_TRANSIENT_KEY);
        if ($cached_token) {
            return $cached_token;
        }

        $creds = self::get_credentials();
        if (empty($creds['client_id']) || empty($creds['client_secret'])) {
            error_log('VSS Zakeke API: Client ID or Secret not configured in settings.');
            return null;
        }

        $payload = ['grant_type' => 'client_credentials', 'access_type' => 'S2S'];
        $auth_header = 'Basic ' . base64_encode($creds['client_id'] . ':' . $creds['client_secret']);

        $response = wp_remote_post(self::TOKEN_URL, [
            'method'    => 'POST',
            'headers'   => [
                'Authorization' => $auth_header,
                'Content-Type'  => 'application/x-www-form-urlencoded',
                'Accept'        => 'application/json',
            ],
            'body'      => $payload,
            'timeout'   => 20,
        ]);

        if (is_wp_error($response)) {
            error_log('VSS Zakeke API Token Error (WP Error): ' . $response->get_error_message());
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        $http_code = wp_remote_retrieve_response_code($response);

        if ($http_code !== 200 || empty($data['access_token'])) { 
            error_log('VSS Zakeke API Token Error (' . $http_code . '): ' . $body);
            return null;
        }

        $token = $data['access_token'];
        $expires_in = isset($data['expires_in']) ? intval($data['expires_in']) - 120 : 35880; 
        set_transient(self::TOKEN_TRANSIENT_KEY, $token, $expires_in);
        return $token;
    }
    
    public static function get_zakeke_order_details_by_wc_order_id($wc_order_id) {
        $token = self::get_access_token();
        if (!$token) {
            error_log('VSS Zakeke API: Failed to get access token for fetching order details for WC Order ' . $wc_order_id);
            return null;
        }
        $api_url = self::API_BASE_URL . "/v2/order/" . $wc_order_id; 

        $response = wp_remote_get($api_url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            error_log('VSS Zakeke API Order Details Error (WP Error) for WC Order ' . $wc_order_id . ': ' . $response->get_error_message());
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        $http_code = wp_remote_retrieve_response_code($response);

        if ($http_code === 404) {
            return null; 
        } elseif ($http_code !== 200) {
            error_log('VSS Zakeke API Order Details Error (' . $http_code . ') for WC Order ' . $wc_order_id . ': ' . $body);
            return null;
        }
        return $data; 
    }
}
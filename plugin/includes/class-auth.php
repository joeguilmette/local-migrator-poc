<?php
/**
 * Authentication and access control
 *
 * @package LocalPOC
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles access key validation and request authentication
 */
class LocalPOC_Auth {

    /**
     * Retrieves the stored access key
     *
     * @return string The access key
     */
    public static function get_access_key() {
        return (string) get_option(LocalPOC_Plugin::OPTION_ACCESS_KEY, '');
    }

    /**
     * Validates a provided access key string
     *
     * @param string $provided_key The key to validate
     * @return true|WP_Error True if valid, WP_Error otherwise
     */
    public static function validate_access_key($provided_key) {
        $expected_key = self::get_access_key();

        if (empty($provided_key) || !hash_equals($expected_key, $provided_key)) {
            return new WP_Error(
                'localpoc_forbidden',
                __('Invalid access key.', 'localpoc'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * Retrieves the key from the request headers or params
     *
     * @return string The access key from the request
     */
    public static function get_request_key() {
        $header_key = '';
        if (isset($_SERVER['HTTP_X_LOCALPOC_KEY'])) {
            $header_key = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_LOCALPOC_KEY']));
        } elseif (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (isset($headers['X-Localpoc-Key'])) {
                $header_key = sanitize_text_field(wp_unslash($headers['X-Localpoc-Key']));
            }
        }

        if (!empty($header_key)) {
            return $header_key;
        }

        if (isset($_REQUEST['localpoc_key'])) { // phpcs:ignore WordPress.Security.NonceVerification
            return sanitize_text_field(wp_unslash($_REQUEST['localpoc_key']));
        }

        return '';
    }
}

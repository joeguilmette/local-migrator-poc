<?php
/**
 * HTTP request and response handling utilities
 *
 * @package LocalPOC
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles HTTP request parsing and response formatting
 */
class LocalPOC_Request_Handler {

    /**
     * Sends a JSON error response and exits
     *
     * @param WP_Error $error The error to send
     */
    public static function ajax_send_error(WP_Error $error) {
        $status = $error->get_error_data('status');
        if ($status) {
            status_header((int) $status);
        }

        wp_send_json([
            'error'   => $error->get_error_code(),
            'message' => $error->get_error_message(),
        ]);
    }

    /**
     * Reads and decodes JSON from request body
     *
     * @return array|WP_Error Decoded JSON or WP_Error on failure
     */
    public static function get_json_body() {
        $raw = file_get_contents('php://input');
        if ($raw === false) {
            return new WP_Error(
                'localpoc_bad_request',
                __('Unable to read request body.', 'localpoc'),
                ['status' => 400]
            );
        }

        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error(
                'localpoc_bad_json',
                __('Invalid JSON payload.', 'localpoc'),
                ['status' => 400]
            );
        }

        return $data;
    }

    /**
     * Normalizes and constrains pagination parameters
     *
     * @param int $offset Offset value
     * @param int $limit  Limit value
     * @return array Normalized [offset, limit]
     */
    public static function normalize_pagination($offset, $limit) {
        $offset = max(0, (int) $offset);
        $limit = (int) $limit;
        if ($limit <= 0) {
            $limit = 5000;
        }
        $limit = min($limit, 20000);

        return [$offset, $limit];
    }
}

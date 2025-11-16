<?php
/**
 * AJAX endpoint handlers
 *
 * @package LocalPOC
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles all AJAX endpoint requests
 */
class LocalPOC_Ajax_Handlers {

    /**
     * AJAX: Returns paginated file manifest
     */
    public static function files_manifest() {
        $auth_result = LocalPOC_Auth::validate_access_key(LocalPOC_Auth::get_request_key());
        if ($auth_result instanceof WP_Error) {
            LocalPOC_Request_Handler::ajax_send_error($auth_result);
        }

        $offset = isset($_REQUEST['offset']) ? (int) $_REQUEST['offset'] : 0; // phpcs:ignore
        $limit = isset($_REQUEST['limit']) ? (int) $_REQUEST['limit'] : 5000; // phpcs:ignore
        [$offset, $limit] = LocalPOC_Request_Handler::normalize_pagination($offset, $limit);

        $job_id = isset($_REQUEST['job_id']) ? sanitize_text_field(wp_unslash($_REQUEST['job_id'])) : ''; // phpcs:ignore
        $manifest = LocalPOC_Manifest_Manager::get_manifest_slice($job_id, $offset, $limit);
        if ($manifest instanceof WP_Error) {
            LocalPOC_Request_Handler::ajax_send_error($manifest);
        }

        wp_send_json($manifest);
    }

    /**
     * AJAX: Returns batch of files as ZIP
     */
    public static function files_batch_zip() {
        $auth_result = LocalPOC_Auth::validate_access_key(LocalPOC_Auth::get_request_key());
        if ($auth_result instanceof WP_Error) {
            LocalPOC_Request_Handler::ajax_send_error($auth_result);
        }

        $payload = LocalPOC_Request_Handler::get_json_body();
        if ($payload instanceof WP_Error) {
            LocalPOC_Request_Handler::ajax_send_error($payload);
        }

        $paths = LocalPOC_Batch_Processor::normalize_paths_input($payload);
        if ($paths instanceof WP_Error) {
            LocalPOC_Request_Handler::ajax_send_error($paths);
        }

        $files = LocalPOC_Batch_Processor::prepare_batch_files($paths);
        if (empty($files)) {
            LocalPOC_Request_Handler::ajax_send_error(new WP_Error(
                'localpoc_no_files',
                __('No valid files to include.', 'localpoc'),
                ['status' => 400]
            ));
        }

        $result = LocalPOC_Batch_Processor::stream_zip_archive($files);
        if ($result instanceof WP_Error) {
            LocalPOC_Request_Handler::ajax_send_error($result);
        }
    }

    /**
     * AJAX: Initializes manifest job
     */
    public static function job_init() {
        $auth_result = LocalPOC_Auth::validate_access_key(LocalPOC_Auth::get_request_key());
        if ($auth_result instanceof WP_Error) {
            LocalPOC_Request_Handler::ajax_send_error($auth_result);
        }

        $mode = isset($_REQUEST['mode']) ? sanitize_text_field(wp_unslash($_REQUEST['mode'])) : 'default'; // phpcs:ignore
        $result = LocalPOC_Manifest_Manager::create_manifest_job($mode);
        if ($result instanceof WP_Error) {
            LocalPOC_Request_Handler::ajax_send_error($result);
        }

        wp_send_json($result);
    }

    /**
     * AJAX: Cleans up manifest job
     */
    public static function job_finish() {
        $auth_result = LocalPOC_Auth::validate_access_key(LocalPOC_Auth::get_request_key());
        if ($auth_result instanceof WP_Error) {
            LocalPOC_Request_Handler::ajax_send_error($auth_result);
        }

        $job_id = isset($_REQUEST['job_id']) ? sanitize_text_field(wp_unslash($_REQUEST['job_id'])) : ''; // phpcs:ignore
        $result = LocalPOC_Manifest_Manager::finish_manifest_job($job_id);
        if ($result instanceof WP_Error) {
            LocalPOC_Request_Handler::ajax_send_error($result);
        }

        wp_send_json(['ok' => true]);
    }

    /**
     * AJAX: Returns database metadata
     */
    public static function db_meta() {
        $auth_result = LocalPOC_Auth::validate_access_key(LocalPOC_Auth::get_request_key());
        if ($auth_result instanceof WP_Error) {
            LocalPOC_Request_Handler::ajax_send_error($auth_result);
        }

        wp_send_json(LocalPOC_Database_Exporter::get_db_meta_data());
    }

    /**
     * AJAX: Streams an individual file
     */
    public static function file() {
        $auth_result = LocalPOC_Auth::validate_access_key(LocalPOC_Auth::get_request_key());
        if ($auth_result instanceof WP_Error) {
            LocalPOC_Request_Handler::ajax_send_error($auth_result);
        }

        $relative_path = isset($_REQUEST['path']) ? sanitize_text_field(wp_unslash($_REQUEST['path'])) : '';
        if ($relative_path === '') {
            LocalPOC_Request_Handler::ajax_send_error(new WP_Error(
                'localpoc_invalid_path',
                __('File path is required.', 'localpoc'),
                ['status' => 400]
            ));
        }

        $resolved_path = LocalPOC_Path_Resolver::resolve_relative_path($relative_path);
        if ($resolved_path instanceof WP_Error) {
            LocalPOC_Request_Handler::ajax_send_error($resolved_path);
        }

        $filesize = filesize($resolved_path);
        $basename = basename($resolved_path);

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        if (!headers_sent()) {
            header('Content-Type: application/octet-stream');
            if ($filesize !== false) {
                header('Content-Length: ' . $filesize);
            }
            header('Content-Disposition: attachment; filename="' . addslashes($basename) . '"');
            header('Cache-Control: no-store, no-transform');
        }

        $handle = fopen($resolved_path, 'rb');
        if (!$handle) {
            LocalPOC_Request_Handler::ajax_send_error(new WP_Error(
                'localpoc_stream_error',
                __('Unable to open file for reading.', 'localpoc'),
                ['status' => 500]
            ));
        }

        $output = fopen('php://output', 'wb');
        if ($output === false) {
            fclose($handle);
            LocalPOC_Request_Handler::ajax_send_error(new WP_Error(
                'localpoc_stream_error',
                __('Unable to open output stream.', 'localpoc'),
                ['status' => 500]
            ));
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        if (stream_copy_to_stream($handle, $output) === false) {
            fclose($handle);
            fclose($output);
            LocalPOC_Request_Handler::ajax_send_error(new WP_Error(
                'localpoc_stream_error',
                __('Unable to stream file.', 'localpoc'),
                ['status' => 500]
            ));
        }

        fclose($handle);
        fclose($output);
        exit;
    }

    /**
     * AJAX: Streams SQL export of database
     */
    public static function db_stream() {
        $auth_result = LocalPOC_Auth::validate_access_key(LocalPOC_Auth::get_request_key());
        if ($auth_result instanceof WP_Error) {
            LocalPOC_Request_Handler::ajax_send_error($auth_result);
        }

        global $wpdb;

        if (!$wpdb instanceof wpdb) {
            LocalPOC_Request_Handler::ajax_send_error(new WP_Error(
                'localpoc_db_unavailable',
                __('Database connection unavailable.', 'localpoc'),
                ['status' => 500]
            ));
        }

        $tables = $wpdb->get_col('SHOW TABLES');
        if (!is_array($tables)) {
            LocalPOC_Request_Handler::ajax_send_error(new WP_Error(
                'localpoc_db_list_failed',
                __('Unable to list database tables.', 'localpoc'),
                ['status' => 500]
            ));
        }

        if (function_exists('nocache_headers')) {
            nocache_headers();
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=' . get_option('blog_charset'));
            header('Content-Disposition: attachment; filename="localpoc-backup.sql"');
            header('Cache-Control: no-store, no-transform');
        }

        echo "-- LocalPOC database export generated at " . current_time('mysql') . "\n\n";

        foreach ($tables as $table_name) {
            LocalPOC_Database_Exporter::stream_table_structure($table_name, $wpdb);
            LocalPOC_Database_Exporter::stream_table_rows($table_name, $wpdb);
        }

        exit;
    }
}

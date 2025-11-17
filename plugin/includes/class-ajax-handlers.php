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

        $batch_result = LocalPOC_Batch_Processor::prepare_batch_files($paths);
        $files = $batch_result['files'];
        $skipped = $batch_result['skipped'];

        if (empty($files)) {
            $error_msg = __('No valid files to include.', 'localpoc');
            if (!empty($skipped)) {
                $error_msg .= ' ' . sprintf(__('Skipped %d invalid paths.', 'localpoc'), count($skipped));
            }
            LocalPOC_Request_Handler::ajax_send_error(new WP_Error(
                'localpoc_no_files',
                $error_msg,
                ['status' => 400, 'skipped' => $skipped]
            ));
        }

        // Warn client if some paths were skipped
        if (!empty($skipped) && !headers_sent()) {
            header('X-LocalPOC-Skipped: ' . count($skipped));
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

        $result = LocalPOC_Database_Job_Manager::get_db_meta_data();
        if ($result instanceof WP_Error) {
            LocalPOC_Request_Handler::ajax_send_error($result);
        }
        wp_send_json($result);
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
     * AJAX: Initializes a database export job
     */
    public static function db_job_init() {
        $auth_result = LocalPOC_Auth::validate_access_key(LocalPOC_Auth::get_request_key());
        if ($auth_result instanceof WP_Error) {
            LocalPOC_Request_Handler::ajax_send_error($auth_result);
        }

        $result = LocalPOC_Database_Job_Manager::init_job();
        if ($result instanceof WP_Error) {
            LocalPOC_Request_Handler::ajax_send_error($result);
        }

        wp_send_json($result);
    }

    /**
     * AJAX: Processes a chunk of a database export job
     */
    public static function db_job_process() {
        $auth_result = LocalPOC_Auth::validate_access_key(LocalPOC_Auth::get_request_key());
        if ($auth_result instanceof WP_Error) {
            LocalPOC_Request_Handler::ajax_send_error($auth_result);
        }

        $job_id = isset($_REQUEST['job_id']) ? sanitize_text_field(wp_unslash($_REQUEST['job_id'])) : ''; // phpcs:ignore
        if ($job_id === '') {
            LocalPOC_Request_Handler::ajax_send_error(new WP_Error(
                'localpoc_missing_job_id',
                __('Job ID is required.', 'localpoc'),
                ['status' => 400]
            ));
        }

        $time_budget = isset($_REQUEST['time_budget']) ? (int) $_REQUEST['time_budget'] : null; // phpcs:ignore

        $result = LocalPOC_Database_Job_Manager::process_job($job_id, $time_budget);
        if ($result instanceof WP_Error) {
            LocalPOC_Request_Handler::ajax_send_error($result);
        }

        wp_send_json($result);
    }

    /**
     * AJAX: Downloads completed database export
     */
    public static function db_job_download() {
        $auth_result = LocalPOC_Auth::validate_access_key(LocalPOC_Auth::get_request_key());
        if ($auth_result instanceof WP_Error) {
            LocalPOC_Request_Handler::ajax_send_error($auth_result);
        }

        $job_id = isset($_REQUEST['job_id']) ? sanitize_text_field(wp_unslash($_REQUEST['job_id'])) : ''; // phpcs:ignore
        if ($job_id === '') {
            LocalPOC_Request_Handler::ajax_send_error(new WP_Error(
                'localpoc_missing_job_id',
                __('Job ID is required.', 'localpoc'),
                ['status' => 400]
            ));
        }

        $sql_file = LocalPOC_Database_Job_Manager::get_download_path($job_id);
        if ($sql_file instanceof WP_Error) {
            LocalPOC_Request_Handler::ajax_send_error($sql_file);
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=' . get_option('blog_charset'));
            header('Content-Disposition: attachment; filename="db.sql"');
            header('Content-Length: ' . filesize($sql_file));
            header('Cache-Control: no-store, no-transform');
        }

        $handle = fopen($sql_file, 'rb');
        if (!$handle) {
            LocalPOC_Request_Handler::ajax_send_error(new WP_Error(
                'localpoc_file_read_error',
                __('Unable to open SQL file.', 'localpoc'),
                ['status' => 500]
            ));
        }

        $output = fopen('php://output', 'wb');
        stream_copy_to_stream($handle, $output);
        fclose($handle);
        fclose($output);

        exit;
    }

    /**
     * AJAX: Finishes and cleans up database export job
     */
    public static function db_job_finish() {
        $auth_result = LocalPOC_Auth::validate_access_key(LocalPOC_Auth::get_request_key());
        if ($auth_result instanceof WP_Error) {
            LocalPOC_Request_Handler::ajax_send_error($auth_result);
        }

        $job_id = isset($_REQUEST['job_id']) ? sanitize_text_field(wp_unslash($_REQUEST['job_id'])) : ''; // phpcs:ignore
        if ($job_id === '') {
            LocalPOC_Request_Handler::ajax_send_error(new WP_Error(
                'localpoc_missing_job_id',
                __('Job ID is required.', 'localpoc'),
                ['status' => 400]
            ));
        }

        $result = LocalPOC_Database_Job_Manager::finish_job($job_id);
        if ($result instanceof WP_Error) {
            LocalPOC_Request_Handler::ajax_send_error($result);
        }

        wp_send_json(['ok' => true]);
    }
}

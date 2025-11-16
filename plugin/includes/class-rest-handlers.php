<?php
/**
 * REST API endpoint handlers
 *
 * @package LocalPOC
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles REST API endpoint registration and requests
 */
class LocalPOC_Rest_Handlers {

    /**
     * Registers all REST routes
     */
    public static function register_routes() {
        register_rest_route(
            'localpoc/v1',
            '/files-manifest',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [__CLASS__, 'files_manifest'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            'localpoc/v1',
            '/job/init',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [__CLASS__, 'job_init'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            'localpoc/v1',
            '/job/finish',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [__CLASS__, 'job_finish'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            'localpoc/v1',
            '/db-meta',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [__CLASS__, 'db_meta'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            'localpoc/v1',
            '/files/batch-zip',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [__CLASS__, 'files_batch_zip'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * REST: Returns paginated file manifest
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response or error
     */
    public static function files_manifest(WP_REST_Request $request) {
        $key = $request->get_header('x-localpoc-key');
        if (empty($key)) {
            $key = $request->get_param('localpoc_key');
        }

        $auth_result = LocalPOC_Auth::validate_access_key($key);
        if ($auth_result instanceof WP_Error) {
            return $auth_result;
        }

        [$offset, $limit] = LocalPOC_Request_Handler::normalize_pagination($request->get_param('offset'), $request->get_param('limit'));
        $job_id = $request->get_param('job_id');

        $manifest = LocalPOC_Manifest_Manager::get_manifest_slice($job_id, $offset, $limit);
        if ($manifest instanceof WP_Error) {
            return $manifest;
        }

        return rest_ensure_response($manifest);
    }

    /**
     * REST: Returns batch of files as ZIP
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response or error
     */
    public static function files_batch_zip(WP_REST_Request $request) {
        $key = $request->get_header('x-localpoc-key');
        if (empty($key)) {
            $key = $request->get_param('localpoc_key');
        }

        $auth_result = LocalPOC_Auth::validate_access_key($key);
        if ($auth_result instanceof WP_Error) {
            return $auth_result;
        }

        $payload = $request->get_json_params();
        $paths = LocalPOC_Batch_Processor::normalize_paths_input($payload);
        if ($paths instanceof WP_Error) {
            return $paths;
        }

        $files = LocalPOC_Batch_Processor::prepare_batch_files($paths);
        if (empty($files)) {
            return new WP_Error(
                'localpoc_no_files',
                __('No valid files to include.', 'localpoc'),
                ['status' => 400]
            );
        }

        $result = LocalPOC_Batch_Processor::stream_zip_archive($files);
        if ($result instanceof WP_Error) {
            return $result;
        }
    }

    /**
     * REST: Initializes manifest job
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response or error
     */
    public static function job_init(WP_REST_Request $request) {
        $key = $request->get_header('x-localpoc-key');
        if (empty($key)) {
            $key = $request->get_param('localpoc_key');
        }

        $auth_result = LocalPOC_Auth::validate_access_key($key);
        if ($auth_result instanceof WP_Error) {
            return $auth_result;
        }

        $mode = $request->get_param('mode') ?: 'default';
        $result = LocalPOC_Manifest_Manager::create_manifest_job($mode);
        if ($result instanceof WP_Error) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    /**
     * REST: Cleans up manifest job
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response or error
     */
    public static function job_finish(WP_REST_Request $request) {
        $key = $request->get_header('x-localpoc-key');
        if (empty($key)) {
            $key = $request->get_param('localpoc_key');
        }

        $auth_result = LocalPOC_Auth::validate_access_key($key);
        if ($auth_result instanceof WP_Error) {
            return $auth_result;
        }

        $job_id = $request->get_param('job_id');
        $result = LocalPOC_Manifest_Manager::finish_manifest_job($job_id);
        if ($result instanceof WP_Error) {
            return $result;
        }

        return rest_ensure_response(['ok' => true]);
    }

    /**
     * REST: Returns database metadata
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response or error
     */
    public static function db_meta(WP_REST_Request $request) {
        $key = $request->get_header('x-localpoc-key');
        if (empty($key)) {
            $key = $request->get_param('localpoc_key');
        }

        $auth_result = LocalPOC_Auth::validate_access_key($key);
        if ($auth_result instanceof WP_Error) {
            return $auth_result;
        }

        return rest_ensure_response(LocalPOC_Database_Exporter::get_db_meta_data());
    }
}

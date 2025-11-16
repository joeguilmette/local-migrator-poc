<?php
/**
 * Path resolution and validation
 *
 * @package LocalPOC
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles secure path resolution and validation
 */
class LocalPOC_Path_Resolver {

    /**
     * Normalizes a relative path
     *
     * @param string $path The path to normalize
     * @return string Normalized path
     */
    public static function normalize_relative_path($path) {
        $path = (string) $path;
        $path = ltrim(str_replace('\\', '/', $path), '/');
        return $path;
    }

    /**
     * Resolves and validates a relative path against ABSPATH
     *
     * Prevents directory traversal attacks and ensures the file
     * is readable and within the WordPress installation.
     *
     * @param string $relative_path The relative path to resolve
     * @return string|WP_Error The real path if valid, WP_Error otherwise
     */
    public static function resolve_relative_path($relative_path) {
        $relative_path = (string) $relative_path;
        $relative = ltrim(str_replace('\\', '/', $relative_path), '/');
        if ($relative === '') {
            return new WP_Error(
                'localpoc_invalid_path',
                __('Invalid file path.', 'localpoc'),
                ['status' => 400]
            );
        }

        $root_realpath = realpath(ABSPATH);
        if ($root_realpath === false) {
            return new WP_Error(
                'localpoc_root_unavailable',
                __('Unable to determine site root.', 'localpoc'),
                ['status' => 500]
            );
        }

        $full_path = $root_realpath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $resolved_path = realpath($full_path);

        if ($resolved_path === false) {
            return new WP_Error(
                'localpoc_file_not_found',
                __('Requested file not found.', 'localpoc'),
                ['status' => 404]
            );
        }

        // Prevent directory traversal
        $normalized_root = wp_normalize_path($root_realpath);
        $normalized_resolved = wp_normalize_path($resolved_path);
        if (strpos($normalized_resolved . '/', trailingslashit($normalized_root)) !== 0) {
            return new WP_Error(
                'localpoc_invalid_path',
                __('Invalid file path.', 'localpoc'),
                ['status' => 400]
            );
        }

        if (!is_readable($resolved_path)) {
            return new WP_Error(
                'localpoc_unreadable_file',
                __('File is not readable.', 'localpoc'),
                ['status' => 403]
            );
        }

        return $resolved_path;
    }
}

<?php
/**
 * Batch file processing and ZIP creation
 *
 * @package LocalPOC
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles batch file operations and ZIP archive creation
 */
class LocalPOC_Batch_Processor {

    /**
     * Validates and extracts paths array from JSON payload
     *
     * @param mixed $payload The payload to validate
     * @return array|WP_Error Array of paths or WP_Error
     */
    public static function normalize_paths_input($payload) {
        if (!is_array($payload)) {
            return new WP_Error(
                'localpoc_bad_request',
                __('Invalid payload.', 'localpoc'),
                ['status' => 400]
            );
        }

        $paths = $payload['paths'] ?? [];
        if (!is_array($paths) || empty($paths)) {
            return new WP_Error(
                'localpoc_no_files',
                __('No paths provided.', 'localpoc'),
                ['status' => 400]
            );
        }

        return $paths;
    }

    /**
     * Prepares batch files by resolving and validating paths
     *
     * Returns both valid files and list of skipped paths for client reporting.
     *
     * @param array $paths Array of relative paths
     * @return array Array with 'files' (valid) and 'skipped' (invalid paths) keys
     */
    public static function prepare_batch_files(array $paths) {
        $files = [];
        $skipped = [];

        foreach ($paths as $path) {
            if (!is_string($path) || $path === '') {
                $skipped[] = $path;
                continue;
            }
            $relative = LocalPOC_Path_Resolver::normalize_relative_path($path);
            if ($relative === '') {
                $skipped[] = $path;
                continue;
            }
            $resolved = LocalPOC_Path_Resolver::resolve_relative_path($relative);
            if ($resolved instanceof WP_Error || !is_file($resolved)) {
                $skipped[] = $relative;
                continue;
            }
            $files[] = [
                'relative' => $relative,
                'resolved' => $resolved,
            ];
        }

        return [
            'files' => $files,
            'skipped' => $skipped,
        ];
    }

    /**
     * Creates and streams a ZIP archive of files
     *
     * @param array  $files    Array of files with 'relative' and 'resolved' keys
     * @param string $filename Filename for download
     * @return WP_Error|void WP_Error on failure, exits on success
     */
    public static function stream_zip_archive(array $files, $filename = 'localpoc-batch.zip') {
        if (!class_exists('ZipArchive')) {
            return new WP_Error(
                'localpoc_zip_missing',
                __('ZipArchive extension is required.', 'localpoc'),
                ['status' => 500]
            );
        }

        $tmp = wp_tempnam('local-migrator-batch');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            return new WP_Error(
                'localpoc_zip_failed',
                __('Unable to create zip archive.', 'localpoc'),
                ['status' => 500]
            );
        }

        foreach ($files as $file) {
            $zip->addFile($file['resolved'], $file['relative']);
        }
        $zip->close();

        if (!headers_sent()) {
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
            header('Content-Length: ' . filesize($tmp));
            header('Cache-Control: no-store, no-transform');
        }

        $output = fopen('php://output', 'wb');
        $input = fopen($tmp, 'rb');
        stream_copy_to_stream($input, $output);
        fclose($input);
        fclose($output);
        @unlink($tmp);

        exit;
    }
}

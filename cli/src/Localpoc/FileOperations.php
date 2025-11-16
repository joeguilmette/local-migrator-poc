<?php
declare(strict_types=1);

namespace Localpoc;

use InvalidArgumentException;
use RuntimeException;

/**
 * Handles file system operations
 */
class FileOperations
{
    /**
     * Ensures output directory exists
     *
     * @param string $path Directory path
     * @throws InvalidArgumentException If path is empty
     * @throws RuntimeException If directory cannot be created
     */
    public static function ensureOutputDir(string $path): void
    {
        if ($path === '') {
            throw new InvalidArgumentException('Output directory cannot be empty.');
        }

        if (is_dir($path)) {
            return;
        }

        if (file_exists($path) && !is_dir($path)) {
            throw new RuntimeException("Output path {$path} exists and is not a directory.");
        }

        if (!mkdir($path, 0755, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create output directory: ' . $path);
        }
    }

    /**
     * Ensures parent directory of a file exists
     *
     * @param string $path File path
     * @throws RuntimeException If directory cannot be created
     */
    public static function ensureParentDir(string $path): void
    {
        $dir = dirname($path);
        if ($dir === '' || $dir === '.' || $dir === DIRECTORY_SEPARATOR) {
            return;
        }

        if (is_dir($dir)) {
            return;
        }

        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create directory: ' . $dir);
        }
    }

    /**
     * Fetches database metadata from the server
     *
     * @param string $adminAjaxUrl Admin AJAX URL
     * @param string $key          Access key
     * @return int Total database bytes (approximate)
     */
    public static function fetchDbMeta(string $adminAjaxUrl, string $key): int
    {
        try {
            $response = Http::postJson($adminAjaxUrl, [
                'action'       => 'localpoc_db_meta',
                'localpoc_key' => $key,
            ], $key);

            return (int) ($response['total_approx_bytes'] ?? 0);
        } catch (HttpException $e) {
            fwrite(STDERR, "[localpoc] ERROR: Failed to fetch DB meta: " . $e->getMessage() . "\n");
        }

        return 0;
    }
}

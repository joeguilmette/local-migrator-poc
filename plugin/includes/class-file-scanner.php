<?php
/**
 * File scanning and exclusion logic
 *
 * @package LocalPOC
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles file system scanning with smart exclusions
 */
class LocalPOC_File_Scanner {

    /**
     * Determines if a relative path should be excluded from backup
     *
     * @param string $relative_path The relative path to check
     * @param bool   $is_dir        Whether this is a directory
     * @return bool True if should be excluded
     */
    public static function should_exclude_path($relative_path, $is_dir = false) {
        $relative = ltrim(str_replace('\\', '/', $relative_path), '/');
        if ($relative === '') {
            return false;
        }

        $basename = basename($relative);

        // Exclude file patterns
        if (!$is_dir) {
            $file_patterns = ['*.log', '*.tmp', '*.bak', '*.swp'];
            foreach ($file_patterns as $pattern) {
                if (fnmatch($pattern, $basename)) {
                    return true;
                }
            }

            if ($basename === '.DS_Store' || str_starts_with($basename, '.~')) {
                return true;
            }
        }

        // Exclude cache and backup directories
        $relative_lower = strtolower($relative);
        $dir_prefixes = [
            'wp-content/cache',
            'wp-content/uploads/cache',
            'wp-content/updraft',
            'wp-content/ai1wm-backups',
            'wp-content/backups',
        ];
        foreach ($dir_prefixes as $prefix) {
            if (strpos($relative_lower, $prefix) === 0) {
                return true;
            }
        }

        // Exclude VCS and dependency directories
        $top = strtolower(explode('/', $relative_lower)[0]);
        if (in_array($top, ['.git', '.svn', '.hg', 'node_modules'], true)) {
            return true;
        }

        // Exclude vendor directories under wp-content/plugins|themes/*/vendor
        $segments = explode('/', $relative_lower);
        if (count($segments) >= 4 && $segments[0] === 'wp-content' && in_array($segments[1], ['plugins', 'themes'], true)) {
            if ($segments[3] === 'vendor' || $segments[2] === 'vendor') {
                return true;
            }
        }

        return false;
    }

    /**
     * Scans the WordPress installation and returns list of files
     *
     * @return array Array of file info: ['path' => string, 'size' => int, 'mtime' => int]
     */
    public static function scan_file_list() {
        $root_path = function_exists('untrailingslashit') ? untrailingslashit(ABSPATH) : rtrim(ABSPATH, '/\\');
        $files = [];

        $directory_iterator = new RecursiveDirectoryIterator(
            $root_path,
            FilesystemIterator::SKIP_DOTS
        );

        $filtered_iterator = new RecursiveCallbackFilterIterator(
            $directory_iterator,
            function ($current) use ($root_path) {
                $full_path = $current->getPathname();
                $relative = ltrim(str_replace('\\', '/', substr($full_path, strlen($root_path))), '/');
                return !self::should_exclude_path($relative, $current->isDir());
            }
        );

        $iterator = new RecursiveIteratorIterator(
            $filtered_iterator,
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file_info) {
            if (!$file_info->isFile() || !$file_info->isReadable()) {
                continue;
            }

            $full_path = $file_info->getPathname();
            $relative = ltrim(str_replace('\\', '/', substr($full_path, strlen($root_path))), '/');
            if (self::should_exclude_path($relative)) {
                continue;
            }

            $files[] = [
                'path'  => $relative,
                'size'  => (int) $file_info->getSize(),
                'mtime' => (int) $file_info->getMTime(),
            ];
        }

        return $files;
    }
}

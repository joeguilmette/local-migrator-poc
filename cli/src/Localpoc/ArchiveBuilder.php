<?php
declare(strict_types=1);

namespace Localpoc;

use RuntimeException;
use ZipArchive;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use FilesystemIterator;

/**
 * Handles temporary workspace management and archive creation
 */
class ArchiveBuilder
{
    /**
     * Creates a temporary workspace directory under the output path
     */
    public static function createTempWorkspace(string $baseOutput): string
    {
        FileOperations::ensureOutputDir($baseOutput);
        $normalized = rtrim($baseOutput, "\\/");
        $tmpRoot = $normalized . DIRECTORY_SEPARATOR . '.tmp';
        FileOperations::ensureOutputDir($tmpRoot);

        $workspace = $tmpRoot . DIRECTORY_SEPARATOR . uniqid('localpoc_', true);
        if (!mkdir($workspace, 0755, true) && !is_dir($workspace)) {
            throw new RuntimeException('Unable to create temp workspace: ' . $workspace);
        }

        FileOperations::ensureOutputDir($workspace . DIRECTORY_SEPARATOR . 'wp-content');
        return $workspace;
    }

    public static function getTempWpContentDir(string $workspace): string
    {
        return rtrim($workspace, "\\/") . DIRECTORY_SEPARATOR . 'wp-content';
    }

    public static function getTempDbPath(string $workspace): string
    {
        return rtrim($workspace, "\\/") . DIRECTORY_SEPARATOR . 'db.sql';
    }

    /**
     * Creates a ZIP archive containing wp-content/ and db.sql
     */
    public static function createZipArchive(string $workspace, string $zipPath): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP ZipArchive extension is required to build archives.');
        }

        FileOperations::ensureParentDir($zipPath);

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create archive at ' . $zipPath);
        }

        $dbPath = self::getTempDbPath($workspace);
        if (is_file($dbPath)) {
            $zip->addFile($dbPath, 'db.sql');
        }

        $wpContentPath = self::getTempWpContentDir($workspace);
        if (is_dir($wpContentPath)) {
            self::addDirectoryToZip($zip, $wpContentPath, 'wp-content');
        }

        if (!$zip->close()) {
            throw new RuntimeException('Failed to finalize archive: ' . $zipPath);
        }
    }

    /**
     * Recursively deletes the workspace directory
     */
    public static function cleanupWorkspace(?string $workspace): void
    {
        if ($workspace && is_dir($workspace)) {
            FileOperations::recursiveDelete($workspace);
        }
    }

    public static function parseHostname(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        if ($host === '') {
            return 'site';
        }
        $host = preg_replace('/^www\./i', '', $host);
        $host = strtolower($host);
        $host = preg_replace('/[^a-z0-9.-]+/i', '-', $host);
        $host = trim($host, '-');
        return $host !== '' ? $host : 'site';
    }

    public static function generateArchiveName(string $hostname): string
    {
        $safeHost = $hostname !== '' ? $hostname : 'site';
        $timestamp = date('Ymd-His');
        return $safeHost . '-' . $timestamp . '.zip';
    }

    private static function addDirectoryToZip(ZipArchive $zip, string $path, string $relativeRoot): void
    {
        $directoryIterator = new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS);
        $iterator = new RecursiveIteratorIterator($directoryIterator, RecursiveIteratorIterator::SELF_FIRST);

        foreach ($iterator as $fileInfo) {
            $fullPath = $fileInfo->getPathname();
            $relative = $relativeRoot . '/' . ltrim(str_replace('\\', '/', substr($fullPath, strlen($path))), '/');

            if ($fileInfo->isDir()) {
                $zip->addEmptyDir($relative);
            } else {
                $zip->addFile($fullPath, $relative);
            }
        }
    }
}

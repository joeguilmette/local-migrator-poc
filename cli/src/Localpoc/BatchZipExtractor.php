<?php
declare(strict_types=1);

namespace Localpoc;

use RuntimeException;
use ZipArchive;

/**
 * Handles batch ZIP download and extraction
 */
class BatchZipExtractor
{
    private ProgressTracker $progressTracker;

    public function __construct(ProgressTracker $progressTracker)
    {
        $this->progressTracker = $progressTracker;
    }

    /**
     * Extracts a ZIP archive to output directory
     *
     * Includes path traversal protection.
     *
     * @param string $zipPath   Path to ZIP file
     * @param string $outputDir Output directory
     * @throws RuntimeException If extraction fails
     */
    public function extractZipArchive(string $zipPath, string $outputDir): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Unable to open batch zip archive.');
        }

        FileOperations::ensureOutputDir($outputDir);
        $base = realpath($outputDir);
        if ($base === false) {
            $zip->close();
            throw new RuntimeException('Unable to resolve output directory.');
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);
            if ($entryName === false) {
                continue;
            }

            // Normalize and validate path
            $normalized = ltrim(str_replace('\\', '/', $entryName), '/');
            if ($normalized === '' || strpos($normalized, '../') !== false) {
                continue;
            }

            // Strip wp-content prefix since output dir already points there
            if ($normalized === 'wp-content') {
                continue;
            }
            if (str_starts_with($normalized, 'wp-content/')) {
                $normalized = substr($normalized, strlen('wp-content/'));
            }

            if ($normalized === '') {
                continue;
            }

            $target = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);

            // Handle directories
            if (str_ends_with($normalized, '/')) {
                FileOperations::ensureOutputDir($target);
                continue;
            }

            // Extract file
            FileOperations::ensureParentDir($target);
            $input = $zip->getStream($entryName);
            if ($input === false) {
                continue;
            }
            $output = fopen($target, 'wb');
            if ($output === false) {
                fclose($input);
                throw new RuntimeException('Unable to write extracted file: ' . $normalized);
            }
            stream_copy_to_stream($input, $output);
            fclose($input);
            fclose($output);
        }

        $zip->close();
    }

    /**
     * Extracts a batch ZIP and reports success/failure counts
     *
     * @param string $zipPath   Path to ZIP file
     * @param string $outputDir Output directory
     * @param array  $batch     Batch file metadata
     * @return array{files_succeeded:int,files_failed:int}
     */
    public function extractBatchZip(string $zipPath, string $outputDir, array $batch): array
    {
        try {
            $this->extractZipArchive($zipPath, $outputDir);
            $this->progressTracker->markBatchSuccess($batch);

            return [
                'files_succeeded' => count($batch),
                'files_failed' => 0,
            ];
        } catch (RuntimeException $e) {
            $this->progressTracker->markBatchFailure(count($batch));
            fwrite(STDERR, "[lm] ERROR: Batch extraction failed: " . $e->getMessage() . "\n");

            return [
                'files_succeeded' => 0,
                'files_failed' => count($batch),
            ];
        }
    }
}

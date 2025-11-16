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
     * Downloads and extracts batch ZIP files
     *
     * @param string $adminAjaxUrl Admin AJAX URL
     * @param string $key          Access key
     * @param array  $batches      Array of file batches
     * @param string $outputDir    Output directory
     * @return array Results with batches, files, failed counts
     * @throws RuntimeException If ZipArchive extension not available
     */
    public function downloadBatches(string $adminAjaxUrl, string $key, array $batches, string $outputDir): array
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('PHP ZipArchive extension is required for batch downloads.');
        }

        $results = [
            'batches' => 0,
            'files'   => 0,
            'failed'  => 0,
        ];

        $batchUrl = $adminAjaxUrl . '?action=localpoc_files_batch_zip';

        foreach ($batches as $batch) {
            $results['batches']++;
            $tempZip = tempnam(sys_get_temp_dir(), 'localpoc-batch');
            if ($tempZip === false) {
                throw new RuntimeException('Unable to create temp file for batch download.');
            }

            try {
                $paths = array_column($batch, 'path');
                Http::streamToFile(
                    $batchUrl,
                    ['paths' => $paths],
                    $key,
                    $tempZip,
                    600,
                    null,
                    null,
                    true
                );
                $this->extractZipArchive($tempZip, $outputDir);
                $results['files'] += count($batch);
                $this->progressTracker->markBatchSuccess($batch);
            } catch (HttpException $e) {
                $results['failed'] += count($batch);
                $this->progressTracker->markBatchFailure(count($batch));
                fwrite(STDERR, "[localpoc] ERROR: Batch download failed: " . $e->getMessage() . "\n");
            } catch (RuntimeException $e) {
                $results['failed'] += count($batch);
                $this->progressTracker->markBatchFailure(count($batch));
                fwrite(STDERR, "[localpoc] ERROR: Batch extraction failed: " . $e->getMessage() . "\n");
            } finally {
                @unlink($tempZip);
            }
        }

        return $results;
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
    private function extractZipArchive(string $zipPath, string $outputDir): void
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
}

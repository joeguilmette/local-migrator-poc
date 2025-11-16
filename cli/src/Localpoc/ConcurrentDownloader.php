<?php
declare(strict_types=1);

namespace Localpoc;

use RuntimeException;
use CurlMultiHandle;

/**
 * Handles concurrent downloads using curl_multi
 */
class ConcurrentDownloader
{
    /** @var CurlMultiHandle|resource|null */
    private $multiHandle = null;
    private ProgressTracker $progressTracker;
    private BatchZipExtractor $batchExtractor;

    public function __construct(ProgressTracker $progressTracker, BatchZipExtractor $batchExtractor)
    {
        $this->progressTracker = $progressTracker;
        $this->batchExtractor = $batchExtractor;
    }

    /**
     * Downloads database and files concurrently
     *
     * @param string $adminAjaxUrl   Admin AJAX URL
     * @param string $key            Access key
     * @param array  $dbTransfer     Database transfer handle info
     * @param array  $batches        File batches
     * @param array  $largeFiles     Large files to download individually
     * @param string $outputDir      Output directory
     * @param int    $maxConcurrency Max concurrent downloads
     * @return array Results with success/failure counts
     */
    public function downloadConcurrently(string $adminAjaxUrl, string $key, array $dbTransfer, array $batches, array $largeFiles, string $filesOutputDir, int $maxConcurrency): array
    {
        if (!function_exists('curl_multi_init')) {
            throw new RuntimeException('Concurrent downloads require the cURL extension.');
        }

        $multi = $this->getMultiHandle();
        $maxConcurrency = max(1, $maxConcurrency);
        $active = [];
        $dbComplete = false;
        $dbSuccess = false;

        // Setup batch downloads
        $nextBatchIndex = 0;
        $totalBatches = count($batches);
        $batchResults = ['succeeded' => 0, 'failed' => 0, 'files_succeeded' => 0, 'files_failed' => 0];

        // Setup file downloads
        $nextFileIndex = 0;
        $totalFiles = count($largeFiles);
        $fileResults = ['succeeded' => 0, 'failed' => 0];

        try {
            // Add DB transfer immediately
            curl_multi_add_handle($multi, $dbTransfer['handle']);
            $dbId = (int) $dbTransfer['handle'];
            $active[$dbId] = $dbTransfer;
            // Main event loop: process DB + batches + files concurrently
            while (!$dbComplete || count($active) > 1 || $nextFileIndex < $totalFiles || $nextBatchIndex < $totalBatches) {
                // Add batch and file handles up to concurrency limit (excluding DB transfer)
                $availableSlots = $maxConcurrency - (count($active) - ($dbComplete ? 0 : 1));

                // Prioritize batches first (they're larger, start them early)
                while ($availableSlots > 0 && $nextBatchIndex < $totalBatches) {
                    $batch = $batches[$nextBatchIndex++];
                    $transfer = $this->createBatchTransfer($adminAjaxUrl, $key, $batch, $filesOutputDir);
                    $handle = $transfer['handle'];
                    curl_multi_add_handle($multi, $handle);
                    $active[(int) $handle] = $transfer;
                    $availableSlots--;
                }

                // Fill remaining slots with file transfers
                while ($availableSlots > 0 && $nextFileIndex < $totalFiles) {
                    $fileEntry = $largeFiles[$nextFileIndex++];
                    $transfer = $this->createFileTransfer($adminAjaxUrl, $key, $fileEntry, $filesOutputDir);
                    $handle = $transfer['handle'];
                    curl_multi_add_handle($multi, $handle);
                    $active[(int) $handle] = $transfer;
                    $availableSlots--;
                }

                if (empty($active)) {
                    break;
                }

                do {
                    $status = curl_multi_exec($multi, $running);
                } while ($status === CURLM_CALL_MULTI_PERFORM);

                if ($status !== CURLM_OK) {
                    throw new RuntimeException('cURL multi error: ' . $status);
                }

                // Process completed transfers
                while ($info = curl_multi_info_read($multi)) {
                    $handle = $info['handle'];
                    $id = (int) $handle;

                    if (!isset($active[$id])) {
                        curl_multi_remove_handle($multi, $handle);
                        curl_close($handle);
                        continue;
                    }

                    $transfer = $active[$id];
                    unset($active[$id]);

                    fclose($transfer['fp']);

                    $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
                    $curlErrNo = curl_errno($handle);
                    $curlErrMsg = curl_error($handle);

                    curl_multi_remove_handle($multi, $handle);
                    curl_close($handle);

                    // Handle DB transfer completion
                    if ($transfer['type'] === 'database') {
                        $dbComplete = true;
                        if ($curlErrNo !== 0 || $info['result'] !== CURLE_OK || $httpCode < 200 || $httpCode >= 300) {
                            @unlink($transfer['dest_path']);
                            fwrite(STDERR, "[localpoc] ERROR: Database download failed: " . ($curlErrMsg ?: 'HTTP ' . $httpCode) . "\n");
                            $dbSuccess = false;
                        } else {
                            $dbSuccess = true;
                        }
                        continue;
                    }

                    // Handle batch transfer completion
                    if ($transfer['type'] === 'batch') {
                        $fileCount = count($transfer['batch']);

                        if ($curlErrNo !== 0 || $info['result'] !== CURLE_OK) {
                            @unlink($transfer['temp_path']);
                            $message = 'Batch download failed: ' . ($curlErrMsg ?: 'cURL error #' . $curlErrNo);
                            $batchResults['failed']++;
                            $batchResults['files_failed'] += $fileCount;
                            fwrite(STDERR, "[localpoc] ERROR: {$message}\n");
                            $this->progressTracker->markBatchFailure($fileCount);
                            continue;
                        }

                        if ($httpCode < 200 || $httpCode >= 300) {
                            @unlink($transfer['temp_path']);
                            $message = 'Batch download returned HTTP ' . $httpCode;
                            $batchResults['failed']++;
                            $batchResults['files_failed'] += $fileCount;
                            fwrite(STDERR, "[localpoc] ERROR: {$message}\n");
                            $this->progressTracker->markBatchFailure($fileCount);
                            continue;
                        }

                        // Download succeeded - extract synchronously (outside loop)
                        try {
                            $this->batchExtractor->extractZipArchive($transfer['temp_path'], $transfer['output_dir']);
                            $batchResults['succeeded']++;
                            $batchResults['files_succeeded'] += $fileCount;
                            $this->progressTracker->markBatchSuccess($transfer['batch']);
                        } catch (RuntimeException $e) {
                            $batchResults['failed']++;
                            $batchResults['files_failed'] += $fileCount;
                            fwrite(STDERR, "[localpoc] ERROR: Batch extraction failed: " . $e->getMessage() . "\n");
                            $this->progressTracker->markBatchFailure($fileCount);
                        } finally {
                            @unlink($transfer['temp_path']);
                        }

                        continue;
                    }

                    // Handle file transfer completion
                    if ($curlErrNo !== 0 || $info['result'] !== CURLE_OK) {
                        @unlink($transfer['dest_path']);
                        $message = 'Download failed for ' . $transfer['file']['path'] . ': ' . ($curlErrMsg ?: 'cURL error #' . $curlErrNo);
                        $fileResults['failed']++;
                        fwrite(STDERR, "[localpoc] ERROR: {$message}\n");
                        $this->progressTracker->markFileFailure();
                    } elseif ($httpCode < 200 || $httpCode >= 300) {
                        @unlink($transfer['dest_path']);
                        $message = 'Server returned HTTP ' . $httpCode . ' for file ' . $transfer['file']['path'];
                        $fileResults['failed']++;
                        fwrite(STDERR, "[localpoc] ERROR: {$message}\n");
                        $this->progressTracker->markFileFailure();
                    } else {
                        $fileResults['succeeded']++;
                        $this->progressTracker->markFileSuccess((int) ($transfer['file']['size'] ?? 0));
                    }
                }

                if (!empty($active)) {
                    $select = curl_multi_select($multi, 1.0);
                    if ($select === -1) {
                        usleep(100000);
                    }
                }
            }
        } finally {
            $this->cleanupActiveTransfers($multi, $active);
        }

        return [
            'db_success' => $dbSuccess,
            'files_succeeded' => $fileResults['succeeded'],
            'files_failed' => $fileResults['failed'],
            'batch_files_succeeded' => $batchResults['files_succeeded'],
            'batch_files_failed' => $batchResults['files_failed'],
        ];
    }

    /**
     * Creates a database transfer handle
     *
     * @param string          $adminAjaxUrl Admin AJAX URL
     * @param string          $key          Access key
     * @param string          $outputDir    Output directory
     * @param callable        $progressCallback Progress callback
     * @return array Transfer info
     */
    public function createDatabaseTransfer(string $adminAjaxUrl, string $key, string $destPath, callable $progressCallback): array
    {
        FileOperations::ensureParentDir($destPath);

        $fp = fopen($destPath, 'wb');
        if ($fp === false) {
            throw new RuntimeException('Unable to open file for writing: ' . $destPath);
        }

        $handle = Http::createStreamHandle(
            $adminAjaxUrl,
            ['action' => 'localpoc_db_stream', 'localpoc_key' => $key],
            $key,
            $fp,
            $progressCallback,
            false,
            600,
            20
        );

        return [
            'handle'    => $handle,
            'fp'        => $fp,
            'dest_path' => $destPath,
            'type'      => 'database',
        ];
    }

    /**
     * Creates a batch ZIP transfer handle
     *
     * @param string $adminAjaxUrl Admin AJAX URL
     * @param string $key          Access key
     * @param array  $batch        File batch array
     * @param string $outputDir    Output directory
     * @return array Transfer info with handle, fp, temp_path, batch, type
     */
    private function createBatchTransfer(string $adminAjaxUrl, string $key, array $batch, string $filesOutputDir): array
    {
        // Create temp file for ZIP download
        $tempZip = tempnam(sys_get_temp_dir(), 'localpoc-batch');
        if ($tempZip === false) {
            throw new RuntimeException('Unable to create temp file for batch download.');
        }

        $fp = fopen($tempZip, 'wb');
        if ($fp === false) {
            @unlink($tempZip);
            throw new RuntimeException('Unable to open temp file for writing: ' . $tempZip);
        }

        // Build POST params (JSON body)
        $paths = array_column($batch, 'path');
        $params = [
            'paths' => $paths,
        ];
        $endpoint = $adminAjaxUrl . '?action=localpoc_files_batch_zip';

        // Create streaming handle with write callback
        $handle = Http::createStreamHandle(
            $endpoint,
            $params,
            $key,
            $fp,
            null,  // No progress callback needed (batches complete atomically)
            true,  // JSON body for paths array
            600,   // 10 minute timeout
            20     // 20 second connect timeout
        );

        return [
            'handle'    => $handle,
            'fp'        => $fp,
            'temp_path' => $tempZip,
            'batch'     => $batch,
            'type'      => 'batch',
            'output_dir' => $filesOutputDir,
        ];
    }

    /**
     * Creates a file transfer handle
     *
     * @param string $adminAjaxUrl Admin AJAX URL
     * @param string $key          Access key
     * @param array  $fileEntry    File entry from manifest
     * @param string $outputDir    Output directory
     * @return array Transfer info
     */
    private function createFileTransfer(string $adminAjaxUrl, string $key, array $fileEntry, string $filesOutputDir): array
    {
        if (!isset($fileEntry['path']) || !is_string($fileEntry['path']) || $fileEntry['path'] === '') {
            throw new RuntimeException('Manifest entry is missing the file path.');
        }

        $relativePath = ltrim(str_replace('\\', '/', $fileEntry['path']), '/');
        if (!str_starts_with($relativePath, 'wp-content')) {
            throw new RuntimeException('File path is outside wp-content scope: ' . $relativePath);
        }
        $relativeWithinContent = ltrim(substr($relativePath, strlen('wp-content')), '/');
        $normalizedBase = rtrim($filesOutputDir, '\\/');
        $localPath = $relativeWithinContent === ''
            ? $normalizedBase
            : $normalizedBase . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeWithinContent);

        FileOperations::ensureParentDir($localPath);

        $fp = fopen($localPath, 'wb');
        if ($fp === false) {
            throw new RuntimeException('Unable to open file for writing: ' . $localPath);
        }

        $params = [
            'action'        => 'localpoc_file',
            'path'          => $relativePath,
            'localpoc_key'  => $key,
        ];

        $handle = Http::createFileCurlHandle($adminAjaxUrl, $params, $key, $fp);

        return [
            'handle'    => $handle,
            'fp'        => $fp,
            'file'      => $fileEntry,
            'dest_path' => $localPath,
            'type'      => 'file',
        ];
    }

    /**
     * Cleans up active transfers
     *
     * @param mixed $multiHandle Multi handle
     * @param array $active      Active transfers
     */
    private function cleanupActiveTransfers($multiHandle, array $active): void
    {
        foreach ($active as $transfer) {
            // Close curl handle
            if (isset($transfer['handle'])) {
                curl_multi_remove_handle($multiHandle, $transfer['handle']);
                curl_close($transfer['handle']);
            }

            // Close file pointer
            if (isset($transfer['fp']) && is_resource($transfer['fp'])) {
                fclose($transfer['fp']);
            }

            // Delete destination file (for db/file transfers)
            if (!empty($transfer['dest_path'])) {
                @unlink($transfer['dest_path']);
            }

            // Delete temp file (for batch transfers)
            if (!empty($transfer['temp_path'])) {
                @unlink($transfer['temp_path']);
            }
        }
    }

    /**
     * Gets or creates curl_multi handle
     *
     * @return CurlMultiHandle|resource
     */
    private function getMultiHandle()
    {
        if ($this->multiHandle === null) {
            $multi = curl_multi_init();
            if ($multi === false) {
                throw new RuntimeException('Unable to initialize cURL multi handle.');
            }
            $this->multiHandle = $multi;
        }

        return $this->multiHandle;
    }

    /**
     * Cleanup on destruct
     */
    public function __destruct()
    {
        if ($this->multiHandle) {
            if (is_resource($this->multiHandle) || $this->multiHandle instanceof CurlMultiHandle) {
                curl_multi_close($this->multiHandle);
            }
            $this->multiHandle = null;
        }
    }
}

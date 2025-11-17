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
     * Downloads files only (no database) concurrently
     *
     * @param string $adminAjaxUrl   Admin AJAX URL
     * @param string $key            Access key
     * @param array  $batches        File batches
     * @param array  $largeFiles     Large files to download individually
     * @param string $filesOutputDir Output directory for files
     * @param int    $maxConcurrency Max concurrent downloads
     * @param callable|null $progressCallback Progress callback for bytes
     * @return array Results with success/failure counts
     */
    public function downloadFilesOnly(
        string $adminAjaxUrl,
        string $key,
        array $batches,
        array $largeFiles,
        string $filesOutputDir,
        int $maxConcurrency,
        ?callable $progressCallback = null,
        ?callable $tickCallback = null
    ): array
    {
        if (!function_exists('curl_multi_init')) {
            throw new RuntimeException('Concurrent downloads require the cURL extension.');
        }

        $multi = $this->getMultiHandle();
        $maxConcurrency = max(1, $maxConcurrency);
        $active = [];

        // Setup batch downloads
        $nextBatchIndex = 0;
        $totalBatches = count($batches);
        $batchResults = ['succeeded' => 0, 'failed' => 0, 'files_succeeded' => 0, 'files_failed' => 0];

        // Setup file downloads
        $nextFileIndex = 0;
        $totalFiles = count($largeFiles);
        $fileResults = ['succeeded' => 0, 'failed' => 0];

        try {
            // Main event loop: process batches + files concurrently
            while (count($active) > 0 || $nextFileIndex < $totalFiles || $nextBatchIndex < $totalBatches) {
                if ($tickCallback) {
                    $tickCallback();
                }
                // Add batch and file handles up to concurrency limit
                $availableSlots = $maxConcurrency - count($active);

                // Prioritize batches first
                while ($availableSlots > 0 && $nextBatchIndex < $totalBatches) {
                    $batchTransfer = $this->createBatchTransfer($adminAjaxUrl, $key, $batches[$nextBatchIndex], $filesOutputDir);
                    curl_multi_add_handle($multi, $batchTransfer['handle']);
                    $transferId = (int) $batchTransfer['handle'];
                    $active[$transferId] = $batchTransfer;
                    $nextBatchIndex++;
                    $availableSlots--;
                }

                // Fill remaining slots with individual files
                while ($availableSlots > 0 && $nextFileIndex < $totalFiles) {
                    $fileTransfer = $this->createFileTransfer($adminAjaxUrl, $key, $largeFiles[$nextFileIndex], $filesOutputDir);
                    curl_multi_add_handle($multi, $fileTransfer['handle']);
                    $transferId = (int) $fileTransfer['handle'];
                    $active[$transferId] = $fileTransfer;
                    $nextFileIndex++;
                    $availableSlots--;
                }

                // Execute transfers
                do {
                    $status = curl_multi_exec($multi, $runningCount);
                } while ($status === CURLM_CALL_MULTI_PERFORM);

                // Check for completed transfers
                while ($info = curl_multi_info_read($multi)) {
                    if ($info['msg'] !== CURLMSG_DONE) {
                        continue;
                    }

                    $handle = $info['handle'];
                    $transferId = (int) $handle;

                    if (!isset($active[$transferId])) {
                        continue;
                    }

                    $transfer = $active[$transferId];
                    unset($active[$transferId]);

                    $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
                    $success = $httpCode === 200 && $info['result'] === CURLE_OK;

                    if (isset($transfer['fp']) && is_resource($transfer['fp'])) {
                        fclose($transfer['fp']);
                    }

                    curl_multi_remove_handle($multi, $handle);
                    curl_close($handle);

                    if ($transfer['type'] === 'batch') {
                        if ($success && isset($transfer['temp_path']) && file_exists($transfer['temp_path'])) {
                            $result = $this->batchExtractor->extractBatchZip($transfer['temp_path'], $transfer['output_dir'], $transfer['batch']);
                            $batchResults['files_succeeded'] += $result['files_succeeded'];
                            $batchResults['files_failed'] += $result['files_failed'];
                            $batchResults['succeeded']++;

                            if ($progressCallback) {
                                $progressCallback(filesize($transfer['temp_path']));
                            }
                        } else {
                            $batchResults['failed']++;
                            $batchResults['files_failed'] += count($transfer['batch']);
                        }
                        if (isset($transfer['temp_path'])) {
                            @unlink($transfer['temp_path']);
                        }
                    } elseif ($transfer['type'] === 'file') {
                        if ($success) {
                            $fileResults['succeeded']++;
                            if ($progressCallback && isset($transfer['file']['size'])) {
                                $progressCallback((int) $transfer['file']['size']);
                            }
                        } else {
                            $fileResults['failed']++;
                            if (isset($transfer['dest_path'])) {
                                @unlink($transfer['dest_path']);
                            }
                        }
                    }
                }

                // Wait for activity
                if (!empty($active)) {
                    if ($tickCallback) {
                        $tickCallback();
                    }
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
            'files_succeeded' => $fileResults['succeeded'],
            'files_failed' => $fileResults['failed'],
            'batch_files_succeeded' => $batchResults['files_succeeded'],
            'batch_files_failed' => $batchResults['files_failed'],
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
        $tempZip = tempnam(sys_get_temp_dir(), 'local-migrator-batch');
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

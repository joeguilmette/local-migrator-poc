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
    public function downloadConcurrently(string $adminAjaxUrl, string $key, array $dbTransfer, array $batches, array $largeFiles, string $outputDir, int $maxConcurrency): array
    {
        if (!function_exists('curl_multi_init')) {
            throw new RuntimeException('Concurrent downloads require the cURL extension.');
        }

        $multi = $this->getMultiHandle();
        $maxConcurrency = max(1, $maxConcurrency);
        $active = [];
        $dbComplete = false;
        $dbSuccess = false;

        // Add DB transfer immediately
        curl_multi_add_handle($multi, $dbTransfer['handle']);
        $dbId = (int) $dbTransfer['handle'];
        $active[$dbId] = $dbTransfer;

        // Process batch zips (sequentially for now)
        $batchResults = ['count' => 0, 'failed' => 0];
        if (!empty($batches)) {
            $batchResults = $this->batchExtractor->downloadBatches($adminAjaxUrl, $key, $batches, $outputDir);
        }

        // Setup file downloads
        $nextFileIndex = 0;
        $totalFiles = count($largeFiles);
        $fileResults = ['succeeded' => 0, 'failed' => 0];

        try {
            // Main event loop: process DB + files concurrently
            while (!$dbComplete || count($active) > 1 || $nextFileIndex < $totalFiles) {
                // Add file handles up to concurrency limit (excluding DB transfer)
                $fileSlots = $maxConcurrency - (count($active) - ($dbComplete ? 0 : 1));
                while ($fileSlots > 0 && $nextFileIndex < $totalFiles) {
                    $fileEntry = $largeFiles[$nextFileIndex++];
                    $transfer = $this->createFileTransfer($adminAjaxUrl, $key, $fileEntry, $outputDir);
                    $handle = $transfer['handle'];
                    curl_multi_add_handle($multi, $handle);
                    $active[(int) $handle] = $transfer;
                    $fileSlots--;
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
            'batches_succeeded' => $batchResults['count'] - $batchResults['failed'],
            'batches_failed' => $batchResults['failed'],
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
    public function createDatabaseTransfer(string $adminAjaxUrl, string $key, string $outputDir, callable $progressCallback): array
    {
        $normalizedBase = rtrim($outputDir, '\\/');
        $destPath = $normalizedBase === '' ? 'db.sql' : $normalizedBase . DIRECTORY_SEPARATOR . 'db.sql';
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
     * Creates a file transfer handle
     *
     * @param string $adminAjaxUrl Admin AJAX URL
     * @param string $key          Access key
     * @param array  $fileEntry    File entry from manifest
     * @param string $outputDir    Output directory
     * @return array Transfer info
     */
    private function createFileTransfer(string $adminAjaxUrl, string $key, array $fileEntry, string $outputDir): array
    {
        if (!isset($fileEntry['path']) || !is_string($fileEntry['path']) || $fileEntry['path'] === '') {
            throw new RuntimeException('Manifest entry is missing the file path.');
        }

        $relativePath = ltrim(str_replace('\\', '/', $fileEntry['path']), '/');
        $normalizedBase = rtrim($outputDir, '\\/');
        $localPath = $normalizedBase === ''
            ? str_replace('/', DIRECTORY_SEPARATOR, $relativePath)
            : $normalizedBase . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

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
            if (isset($transfer['handle'])) {
                curl_multi_remove_handle($multiHandle, $transfer['handle']);
                curl_close($transfer['handle']);
            }

            if (isset($transfer['fp']) && is_resource($transfer['fp'])) {
                fclose($transfer['fp']);
            }

            if (!empty($transfer['dest_path'])) {
                @unlink($transfer['dest_path']);
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

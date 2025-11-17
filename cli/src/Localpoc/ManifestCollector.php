<?php
declare(strict_types=1);

namespace Localpoc;

use RuntimeException;

/**
 * Handles manifest collection and file partitioning
 */
class ManifestCollector
{
    private const LARGE_FILE_THRESHOLD = 20971520; // 20 MB
    private const BATCH_MAX_FILES = 75;
    private const BATCH_MAX_BYTES = 26214400; // 25 MB

    /**
     * Initializes a manifest job on the server
     *
     * @param string $adminAjaxUrl Admin AJAX URL
     * @param string $key          Access key
     * @return array Job info with job_id, total_files, total_bytes, created_at
     * @throws RuntimeException If initialization fails
     */
    public static function initializeJob(string $adminAjaxUrl, string $key): array
    {
        $response = Http::postJson($adminAjaxUrl, [
            'action'      => 'localpoc_job_init',
            'localpoc_key' => $key,
        ], $key);

        if (empty($response['job_id'])) {
            throw new RuntimeException('Failed to initialize manifest job.');
        }

        return $response;
    }

    /**
     * Finishes and cleans up a manifest job
     *
     * @param string      $adminAjaxUrl Admin AJAX URL
     * @param string      $key          Access key
     * @param string|null $jobId        Job ID
     */
    public static function finishJob(string $adminAjaxUrl, string $key, ?string $jobId): void
    {
        if (empty($jobId)) {
            return;
        }

        try {
            Http::postJson($adminAjaxUrl, [
                'action'      => 'localpoc_job_finish',
                'localpoc_key' => $key,
                'job_id'      => $jobId,
            ], $key);
        } catch (HttpException $e) {
            // Error finishing job is non-critical, continue
        }
    }

    /**
     * Collects and partitions manifest entries
     *
     * Partitions files into large files (>20MB) and batches for ZIP download.
     * Batches are created with max 75 files or 25MB each.
     *
     * @param string $adminAjaxUrl Admin AJAX URL
     * @param string $key          Access key
     * @param string $jobId        Job ID
     * @return array Array with 'large', 'batches', 'total_files', 'total_bytes'
     */
    public static function collectEntries(string $adminAjaxUrl, string $key, string $jobId): array
    {
        $offset = 0;
        $limit = 5000;
        $totalFiles = 0;
        $totalBytes = 0;
        $large = [];
        $batches = [];
        $currentBatch = [];
        $currentBytes = 0;

        while (true) {
            $response = Http::postJson($adminAjaxUrl, [
                'action'       => 'localpoc_files_manifest',
                'localpoc_key' => $key,
                'job_id'       => $jobId,
                'offset'       => $offset,
                'limit'        => $limit,
            ], $key);

            $files = $response['files'] ?? [];
            if (empty($files)) {
                break;
            }

            foreach ($files as $file) {
                if (!is_array($file) || !isset($file['path'], $file['size'])) {
                    continue;
                }

                $size = (int) $file['size'];
                $totalBytes += $size;
                $totalFiles++;

                // Large files downloaded individually
                if ($size >= self::LARGE_FILE_THRESHOLD) {
                    $large[] = $file;
                    continue;
                }

                // Small files batched together
                $currentBatch[] = $file;
                $currentBytes += $size;

                // Flush batch if limits reached
                if (count($currentBatch) >= self::BATCH_MAX_FILES || $currentBytes >= self::BATCH_MAX_BYTES) {
                    $batches[] = $currentBatch;
                    $currentBatch = [];
                    $currentBytes = 0;
                }
            }

            $offset += count($files);
            $reportedTotal = (int) ($response['total_files'] ?? 0);
            if ($reportedTotal > 0 && $offset >= $reportedTotal) {
                break;
            }
        }

        // Add remaining batch
        if (!empty($currentBatch)) {
            $batches[] = $currentBatch;
        }

        return [
            'large'       => $large,
            'batches'     => $batches,
            'total_files' => $totalFiles,
            'total_bytes' => $totalBytes,
        ];
    }
}

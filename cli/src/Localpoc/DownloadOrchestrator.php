<?php
declare(strict_types=1);

namespace Localpoc;

use RuntimeException;

/**
 * Orchestrates the download workflow
 */
class DownloadOrchestrator
{
    private ProgressTracker $progressTracker;
    private ConcurrentDownloader $downloader;

    public function __construct(ProgressTracker $progressTracker, ConcurrentDownloader $downloader)
    {
        $this->progressTracker = $progressTracker;
        $this->downloader = $downloader;
    }

    /**
     * Handles the complete download workflow
     *
     * @param array $options Download options
     * @return int Exit code
     */
    public function handleDownload(array $options): int
    {
        $url = $options['url'];
        $key = $options['key'];
        $outputDir = $options['output'];
        $concurrency = (int) $options['concurrency'];

        $this->info('Starting download command');
        $this->info('Output directory: ' . $outputDir);

        FileOperations::ensureOutputDir($outputDir);

        $adminAjaxUrl = Http::buildAdminAjaxUrl($url);
        $this->info('Using API base: ' . $adminAjaxUrl);
        $this->info('Starting manifest job...');

        $jobId = null;
        $partition = null;
        $jobTotals = ['total_files' => 0, 'total_bytes' => 0];

        $dbMetaBytes = FileOperations::fetchDbMeta($adminAjaxUrl, $key);

        try {
            $jobInfo = ManifestCollector::initializeJob($adminAjaxUrl, $key);
            $jobId = $jobInfo['job_id'];
            $jobTotals['total_files'] = (int) ($jobInfo['total_files'] ?? 0);
            $jobTotals['total_bytes'] = (int) ($jobInfo['total_bytes'] ?? 0);
            $this->info(sprintf('Manifest job %s: %d files (~%d bytes)', $jobId, $jobTotals['total_files'], $jobTotals['total_bytes']));

            $partition = ManifestCollector::collectEntries($adminAjaxUrl, $key, $jobId);
        } finally {
            ManifestCollector::finishJob($adminAjaxUrl, $key, $jobId);
        }

        if ($partition === null) {
            throw new RuntimeException('Failed to collect manifest entries.');
        }

        $fileCount = $partition['total_files'];
        $totalSize = $partition['total_bytes'];
        $largeFiles = $partition['large'];
        $batches = $partition['batches'];

        $this->progressTracker->initCounters($fileCount, $totalSize, $dbMetaBytes);

        $this->info(sprintf(
            'Manifest ready: %d files (%d bytes) -> %d large, %d batches',
            $fileCount,
            $totalSize,
            count($largeFiles),
            count($batches)
        ));

        // Create DB transfer (don't execute yet)
        $dbTransfer = $this->downloader->createDatabaseTransfer(
            $adminAjaxUrl,
            $key,
            $outputDir,
            function (int $bytes): void {
                $this->progressTracker->incrementDbBytes($bytes);
            }
        );

        $this->info('Starting concurrent downloads (DB + files)...');

        // Execute DB + batch zips + large files concurrently
        $results = $this->downloader->downloadConcurrently(
            $adminAjaxUrl,
            $key,
            $dbTransfer,
            $batches,
            $largeFiles,
            $outputDir,
            $concurrency
        );

        $dbSummary = $results['db_success'] ? 'OK' : 'FAILED';
        $filesDownloaded = $results['files_succeeded'] + $results['batches_succeeded'];
        $failures = $results['files_failed'] + $results['batches_failed'];

        $this->progressTracker->render(true, true);
        $this->info(sprintf('DB: %s', $dbSummary));
        $this->info(sprintf(
            'Files: %d/%d (failed %d)',
            $filesDownloaded,
            $fileCount,
            $failures
        ));
        $resolvedOutput = realpath($outputDir) ?: $outputDir;
        $this->info('Output directory: ' . $resolvedOutput);

        if (!$results['db_success'] || $failures > 0) {
            return 3; // EXIT_HTTP
        }

        return 0; // EXIT_SUCCESS
    }

    /**
     * Outputs informational message
     *
     * @param string $message Message to output
     */
    private function info(string $message): void
    {
        $this->progressTracker->ensureNewline();
        fwrite(STDOUT, "[localpoc] {$message}\n");
    }
}

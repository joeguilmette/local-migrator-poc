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

        $workspace = null;
        $zipPath = null;
        $dbJobId = null;
        $dbJobFinished = false;

        try {
            $workspace = ArchiveBuilder::createTempWorkspace($outputDir);
            $this->info('Working directory: ' . $workspace);

            $filesRoot = ArchiveBuilder::getTempWpContentDir($workspace);
            $dbPath = ArchiveBuilder::getTempDbPath($workspace);

            $adminAjaxUrl = Http::buildAdminAjaxUrl($url);
            $this->info('Using API base: ' . $adminAjaxUrl);

            $jobId = null;
            $partition = null;
            $jobTotals = ['total_files' => 0, 'total_bytes' => 0];

            // Start database export job (asynchronous chunks)
            $this->info('Starting database export job...');
            $dbJobInfo = DatabaseJobClient::initJob($adminAjaxUrl, $key);
            $dbJobId = $dbJobInfo['job_id'];
            $dbJobState = [
                'bytes_written'   => (int) ($dbJobInfo['bytes_written'] ?? 0),
                'reported_bytes'  => 0,
                'estimated_bytes' => (int) ($dbJobInfo['estimated_bytes'] ?? 0),
                'done'            => false,
                'total_tables'    => (int) ($dbJobInfo['total_tables'] ?? 0),
                'total_rows'      => (int) ($dbJobInfo['total_rows'] ?? 0),
            ];
            $dbJobFinished = false;

            $this->info(sprintf(
                'DB job %s: %d tables (~%d rows)',
                $dbJobId,
                $dbJobInfo['total_tables'] ?? 0,
                $dbJobInfo['total_rows'] ?? 0
            ));

            $lastDbPoll = 0.0;
            $reportDbProgress = function (int $newBytes) use (&$dbJobState): void {
                if ($newBytes <= $dbJobState['reported_bytes']) {
                    return;
                }
                $delta = $newBytes - $dbJobState['reported_bytes'];
                $dbJobState['reported_bytes'] = $newBytes;
                $this->progressTracker->incrementDbBytes($delta);
            };

            $lastDbLog = 0.0;
            $pollDbJob = function (bool $force = false) use (&$dbJobState, &$lastDbPoll, &$lastDbLog, $adminAjaxUrl, $key, $dbJobId, $reportDbProgress): void {
                if ($dbJobState['done']) {
                    return;
                }

                $now = microtime(true);
                if (!$force && ($now - $lastDbPoll) < 0.5) {
                    return;
                }
                $lastDbPoll = $now;

                $progress = DatabaseJobClient::processChunk($adminAjaxUrl, $key, $dbJobId);
                $newBytes = (int) ($progress['bytes_written'] ?? $dbJobState['bytes_written']);
                if ($newBytes > $dbJobState['bytes_written']) {
                    $dbJobState['bytes_written'] = $newBytes;
                    $reportDbProgress($newBytes);
                }

                $completed = (int) ($progress['completed_tables'] ?? 0);
                $totalTables = $dbJobState['total_tables'];
                $estimated = $dbJobState['estimated_bytes'];
                $bytesWritten = $dbJobState['bytes_written'];
                $fileSize = (int) ($progress['file_size'] ?? 0);
                $doneFlag = !empty($progress['done']);
                $nowLog = microtime(true);
                if ($nowLog - $lastDbLog >= 1.0) {
                    $lastDbLog = $nowLog;
                    $this->info(sprintf(
                        '[debug] DB chunk -> tables %d/%d, bytes %s/%s, file %s, done=%s',
                        $completed,
                        $totalTables,
                        $this->formatBytes($bytesWritten),
                        $this->formatBytes($estimated),
                        $this->formatBytes($fileSize),
                        $doneFlag ? 'yes' : 'no'
                    ));
                }

                if (!empty($progress['warnings'])) {
                    foreach ((array) $progress['warnings'] as $warning) {
                        $this->info('[debug] DB warning: ' . $warning);
                    }
                }

                if (!empty($progress['last_table']) && !empty($progress['last_batch_rows'])) {
                    $this->info(sprintf('[debug] DB batch: %s rows=%d', $progress['last_table'], (int) $progress['last_batch_rows']));
                }

                if ($doneFlag) {
                    $this->info(sprintf(
                        '[debug] DB job complete -> bytes %s file %s (%d rows)',
                        $this->formatBytes($bytesWritten),
                        $this->formatBytes($fileSize),
                        $dbJobState['total_rows']
                    ));
                    $dbJobState['done'] = true;
                }
            };

            // Kick off an initial chunk
            $pollDbJob(true);

            $this->info('Starting file manifest job...');
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

            $this->progressTracker->initCounters($fileCount, $totalSize, $dbJobState['estimated_bytes']);
            if ($dbJobState['bytes_written'] > 0) {
                $reportDbProgress($dbJobState['bytes_written']);
            }

            $this->info(sprintf(
                'Manifest ready: %d files (%d bytes) -> %d large, %d batches',
                $fileCount,
                $totalSize,
                count($largeFiles),
                count($batches)
            ));

            $this->info('Starting file downloads (DB job continues in background)...');

            // Download files only (DB job polled via callback)
            $results = $this->downloader->downloadFilesOnly(
                $adminAjaxUrl,
                $key,
                $batches,
                $largeFiles,
                $filesRoot,
                $concurrency,
                function (int $bytes): void {
                    $this->progressTracker->incrementFileBytes($bytes);
                },
                function () use ($pollDbJob): void {
                    $pollDbJob();
                }
            );

            $filesDownloaded = $results['files_succeeded'] + $results['batch_files_succeeded'];
            $failures = $results['files_failed'] + $results['batch_files_failed'];

            $this->progressTracker->render(true, true);
            $this->info('Database: ' . ($dbJobState['done'] ? 'OK' : 'IN PROGRESS'));
            $this->info(sprintf(
                'Files: %d/%d (failed %d)',
                $filesDownloaded,
                $fileCount,
                $failures
            ));
            $resolvedOutput = realpath($outputDir) ?: $outputDir;
            $this->info('Output directory: ' . $resolvedOutput);

            if ($failures > 0) {
                ArchiveBuilder::cleanupWorkspace($workspace);
                $workspace = null;
                return 3; // EXIT_HTTP
            }

            // Ensure DB job completes
            while (!$dbJobState['done']) {
                $pollDbJob(true);
                usleep(100000);
            }

            $this->info('Database export complete. Downloading SQL file...');
            $downloadedBytes = 0;
            DatabaseJobClient::downloadDatabase(
                $adminAjaxUrl,
                $key,
                $dbJobId,
                $dbPath,
                function (int $bytes) use (&$downloadedBytes): void {
                    $downloadedBytes += $bytes;
                }
            );
            if (file_exists($dbPath)) {
                $hashValue = sha1_file($dbPath) ?: 'n/a';
                $this->info(sprintf(
                    '[debug] DB download size: %s (sha1 %s)',
                    $this->formatBytes($downloadedBytes),
                    $hashValue
                ));
            } else {
                $this->info(sprintf(
                    '[debug] DB download size: %s (file missing!)',
                    $this->formatBytes($downloadedBytes)
                ));
            }
            DatabaseJobClient::finishJob($adminAjaxUrl, $key, $dbJobId);
            $dbJobFinished = true;
            $this->info('Database downloaded successfully.');

            // Build archive
            $hostname = ArchiveBuilder::parseHostname($url);
            $archivesDir = FileOperations::ensureZipDirectory($outputDir);
            $archiveName = ArchiveBuilder::generateArchiveName($hostname);
            $zipPath = $archivesDir . DIRECTORY_SEPARATOR . $archiveName;
            ArchiveBuilder::createZipArchive($workspace, $zipPath);
            $archiveSize = is_file($zipPath) ? filesize($zipPath) : 0;

            $this->info(sprintf(
                'Archive created: %s (%s)',
                $zipPath,
                $this->formatBytes($archiveSize)
            ));

            ArchiveBuilder::cleanupWorkspace($workspace);
            $workspace = null;

            return 0; // EXIT_SUCCESS
        } catch (\Throwable $e) {
            if ($zipPath && is_file($zipPath)) {
                @unlink($zipPath);
            }
            throw $e;
        } finally {
            if ($dbJobId && !$dbJobFinished) {
                try {
                    DatabaseJobClient::finishJob(Http::buildAdminAjaxUrl($url), $key, $dbJobId);
                } catch (\Throwable $ignored) {
                    // ignore cleanup errors
                }
            }
            if ($workspace !== null) {
                ArchiveBuilder::cleanupWorkspace($workspace);
            }
        }
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

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return sprintf('%.2f GB', $bytes / 1073741824);
        }
        if ($bytes >= 1048576) {
            return sprintf('%.2f MB', $bytes / 1048576);
        }
        if ($bytes >= 1024) {
            return sprintf('%.2f KB', $bytes / 1024);
        }
        if ($bytes > 0) {
            return $bytes . ' B';
        }
        return '0 B';
    }
}

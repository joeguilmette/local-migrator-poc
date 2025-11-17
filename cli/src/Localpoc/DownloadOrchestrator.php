<?php
declare(strict_types=1);

namespace Localpoc;

use RuntimeException;
use Localpoc\UI\TerminalRenderer;

/**
 * Orchestrates the download workflow
 */
class DownloadOrchestrator
{
    private ConcurrentDownloader $downloader;
    private TerminalRenderer $renderer;
    private ProgressState $progress;
    private bool $verbose = false;
    private float $lastRenderTime = 0.0;

    public function __construct(ConcurrentDownloader $downloader, ?TerminalRenderer $renderer = null, bool $verbose = false)
    {
        $this->downloader = $downloader;
        $this->renderer = $renderer ?? new TerminalRenderer();
        $this->progress = new ProgressState();
        $this->verbose = $verbose;
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

        // Initialize renderer with site URL and output directory
        $siteUrl = parse_url($url, PHP_URL_HOST) ?: $url;
        $this->renderer->initialize($siteUrl, $outputDir, $this->verbose);

        $this->debug('Starting download command');
        $this->debug('Output directory: ' . $outputDir);

        FileOperations::ensureOutputDir($outputDir);

        $workspace = null;
        $zipPath = null;
        $dbJobId = null;
        $dbJobFinished = false;

        try {
            $workspace = ArchiveBuilder::createTempWorkspace($outputDir);
            $this->debug('Working directory: ' . $workspace);

            $filesRoot = ArchiveBuilder::getTempWpContentDir($workspace);
            $dbPath = ArchiveBuilder::getTempDbPath($workspace);

            $adminAjaxUrl = Http::buildAdminAjaxUrl($url);
            $this->debug('Using API base: ' . $adminAjaxUrl);

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
                'rows_processed'  => (int) ($dbJobInfo['rows_processed'] ?? 0),
            ];
            $dbJobFinished = false;

            $this->debug(sprintf(
                'DB job %s: %d tables (~%d rows)',
                $dbJobId,
                $dbJobInfo['total_tables'] ?? 0,
                $dbJobInfo['total_rows'] ?? 0
            ));

            // Update progress state - DB tracked by rows only
            $this->progress->dbTotalRows = $dbJobState['total_rows'];
            $this->progress->dbRowsProcessed = $dbJobState['rows_processed'];
            $this->updateRenderer();

            $lastDbPoll = 0.0;

            $lastDbLog = 0.0;
            $pollDbJob = function (bool $force = false) use (&$dbJobState, &$lastDbPoll, &$lastDbLog, $adminAjaxUrl, $key, $dbJobId): void {
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
                    // Don't track DB export bytes, only rows
                }

                $rowsProcessed = (int) ($progress['rows_processed'] ?? $dbJobState['rows_processed']);
                if ($rowsProcessed > $dbJobState['rows_processed']) {
                    $dbJobState['rows_processed'] = $rowsProcessed;
                    $this->progress->dbRowsProcessed = $rowsProcessed;
                    $this->progress->currentActivity = sprintf('Exporting DB row %d/%d', $rowsProcessed, $this->progress->dbTotalRows);
                    $this->updateRenderer();
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
                    $this->debug(sprintf(
                        'DB chunk -> tables %d/%d, bytes %s/%s, file %s, done=%s',
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
                        $this->debug('DB warning: ' . $warning);
                    }
                }

                if (!empty($progress['last_table']) && !empty($progress['last_batch_rows'])) {
                    $this->debug(sprintf('DB batch: %s rows=%d', $progress['last_table'], (int) $progress['last_batch_rows']));
                }

                if ($doneFlag) {
                    $this->debug(sprintf(
                        'DB job complete -> bytes %s file %s (%d rows)',
                        $this->formatBytes($bytesWritten),
                        $this->formatBytes($fileSize),
                        $dbJobState['total_rows']
                    ));
                    $dbJobState['done'] = true;
                    $this->progress->currentActivity = 'Database export complete';
                    $this->updateRenderer();
                }
            };

            // Kick off an initial chunk
            $pollDbJob(true);

            $this->info('Starting file manifest job...');
            $this->progress->currentActivity = 'Collecting file manifest';
            $this->updateRenderer();

            try {
                $jobInfo = ManifestCollector::initializeJob($adminAjaxUrl, $key);
                $jobId = $jobInfo['job_id'];
                $jobTotals['total_files'] = (int) ($jobInfo['total_files'] ?? 0);
                $jobTotals['total_bytes'] = (int) ($jobInfo['total_bytes'] ?? 0);
                $this->debug(sprintf('Manifest job %s: %d files (~%d bytes)', $jobId, $jobTotals['total_files'], $jobTotals['total_bytes']));

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

            // Update progress state - files tracked by count only
            $this->progress->filesTotalCount = $fileCount;
            $this->updateRenderer();

            $this->debug(sprintf(
                'Manifest ready: %d files (%d bytes) -> %d large, %d batches',
                $fileCount,
                $totalSize,
                count($largeFiles),
                count($batches)
            ));

            $this->info('Starting file downloads (DB job continues in background)...');
            $this->progress->currentActivity = 'Downloading files';
            $this->updateRenderer();

            // Download files only (DB job polled via callback)
            $results = $this->downloader->downloadFilesOnly(
                $adminAjaxUrl,
                $key,
                $batches,
                $largeFiles,
                $filesRoot,
                $concurrency,
                function (int $bytes, int $filesCompleted = 0, int $filesFailed = 0, ?string $currentFile = null): void {
                    // Track actual network transfer
                    $this->progress->bytesTransferred += $bytes;

                    if ($filesCompleted > 0) {
                        $this->progress->filesCompleted = $filesCompleted;
                    }
                    if ($filesFailed > 0) {
                        $this->progress->filesFailed = $filesFailed;
                    }
                    if ($currentFile !== null) {
                        $this->progress->currentActivity = 'Downloading: ' . basename($currentFile);
                    }

                    // Use centralized throttle rendering
                    if ($this->shouldRender()) {
                        $this->updateRenderer();
                    }
                },
                function () use ($pollDbJob): void {
                    $pollDbJob();
                }
            );

            $filesDownloaded = $results['files_succeeded'] + $results['batch_files_succeeded'];
            $failures = $results['files_failed'] + $results['batch_files_failed'];

            // Update final file counts
            $this->progress->filesCompleted = $filesDownloaded;
            $this->progress->filesFailed = $failures;
            $this->updateRenderer();

            $this->debug('Database: ' . ($dbJobState['done'] ? 'OK' : 'IN PROGRESS'));
            $this->debug(sprintf(
                'Files: %d/%d (failed %d)',
                $filesDownloaded,
                $fileCount,
                $failures
            ));
            $resolvedOutput = realpath($outputDir) ?: $outputDir;
            $this->debug('Output directory: ' . $resolvedOutput);

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
            $this->progress->currentActivity = 'Downloading database SQL file';
            $this->updateRenderer();

            $downloadedBytes = 0;
            DatabaseJobClient::downloadDatabase(
                $adminAjaxUrl,
                $key,
                $dbJobId,
                $dbPath,
                function (int $bytes) use (&$downloadedBytes): void {
                    $downloadedBytes += $bytes;
                    // Track actual network transfer for DB download
                    $this->progress->bytesTransferred += $bytes;

                    // Use centralized throttle rendering
                    if ($this->shouldRender()) {
                        $this->updateRenderer();
                    }
                }
            );

            // Final DB update
            $this->progress->dbRowsProcessed = $dbJobState['total_rows'] ?: $dbJobState['rows_processed'];
            $this->updateRenderer();

            if (file_exists($dbPath)) {
                $hashValue = sha1_file($dbPath) ?: 'n/a';
                $this->debug(sprintf(
                    'DB download size: %s (sha1 %s)',
                    $this->formatBytes($downloadedBytes),
                    $hashValue
                ));
            } else {
                $this->debug(sprintf(
                    'DB download size: %s (file missing!)',
                    $this->formatBytes($downloadedBytes)
                ));
            }
            DatabaseJobClient::finishJob($adminAjaxUrl, $key, $dbJobId);
            $dbJobFinished = true;
            $this->info('Database downloaded successfully.');

            // Validate download completeness
            $validation = $this->validateDownload($fileCount, $filesDownloaded);
            if (!$validation['success']) {
                $this->debug('Warning: Download validation failed');
                if (!$validation['db_complete']) {
                    $this->debug('- Database incomplete');
                }
                if (!$validation['files_match']) {
                    $this->debug('- File count mismatch');
                }
                if (!$validation['bytes_transferred']) {
                    $this->debug('- No bytes transferred');
                }
            }

            // Build archive
            $this->progress->currentActivity = 'Creating archive';
            $this->updateRenderer();

            $hostname = ArchiveBuilder::parseHostname($url);
            $archivesDir = FileOperations::ensureZipDirectory($outputDir);
            $archiveName = ArchiveBuilder::generateArchiveName($hostname);
            $zipPath = $archivesDir . DIRECTORY_SEPARATOR . $archiveName;
            ArchiveBuilder::createZipArchive($workspace, $zipPath);
            $archiveSize = is_file($zipPath) ? filesize($zipPath) : 0;

            $this->progress->currentActivity = 'Complete';
            $this->updateRenderer();

            $this->info(sprintf(
                'Archive created: %s (%s)',
                $zipPath,
                $this->formatBytes($archiveSize)
            ));

            // Show final summary with renderer (validation already computed above)
            $this->renderer->showSummary($zipPath, $archiveSize, $this->progress, $validation);

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
     * Updates the renderer with current progress state
     */
    private function updateRenderer(): void
    {
        $this->renderer->updateProgress($this->progress);
    }

    /**
     * Outputs informational message
     *
     * @param string $message Message to output
     */
    private function info(string $message): void
    {
        $this->renderer->log($message);
    }

    /**
     * Outputs debug message (only in verbose mode)
     *
     * @param string $message Message to output
     */
    private function debug(string $message): void
    {
        if ($this->verbose) {
            $this->renderer->log('[debug] ' . $message);
        }
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

    /**
     * Check if we should render based on throttling
     */
    private function shouldRender(): bool
    {
        $now = microtime(true);
        if (($now - $this->lastRenderTime) >= 0.2) {
            $this->lastRenderTime = $now;
            return true;
        }
        return false;
    }

    /**
     * Validate download completeness
     */
    private function validateDownload(int $fileCount, int $filesDownloaded): array
    {
        $validation = [
            'db_complete' => $this->progress->dbRowsProcessed === $this->progress->dbTotalRows,
            'files_match' => $filesDownloaded === $fileCount,
            'bytes_transferred' => $this->progress->bytesTransferred > 0,
            'success' => true
        ];

        $validation['success'] = $validation['db_complete'] &&
                                 $validation['files_match'] &&
                                 $validation['bytes_transferred'];

        return $validation;
    }
}

<?php
declare(strict_types=1);

namespace Localpoc;

/**
 * Handles progress tracking and display for downloads
 */
class ProgressTracker
{
    private bool $interactive = false;
    private bool $dirty = false;
    private bool $initialized = false;
    private float $lastRender = 0.0;
    private array $progress = [
        'total_files'      => 0,
        'total_bytes'      => 0,
        'files_completed'  => 0,
        'files_failed'     => 0,
        'file_bytes'       => 0,
        'db_total'         => 0,
        'db_streamed'      => 0,
    ];

    public function __construct()
    {
        $this->interactive = $this->detectInteractiveOutput();
    }

    /**
     * Gets current progress counts
     *
     * @return array Current progress state
     */
    public function getCurrentCounts(): array
    {
        return $this->progress;
    }

    /**
     * Initializes progress counters
     *
     * @param int $fileTotal Total number of files
     * @param int $fileBytes Total bytes of files
     * @param int $dbBytes   Total database bytes
     */
    public function initCounters(int $fileTotal, int $fileBytes, int $dbBytes): void
    {
        $this->progress = [
            'total_files'      => max(0, $fileTotal),
            'total_bytes'      => max(0, $fileBytes),
            'files_completed'  => 0,
            'files_failed'     => 0,
            'file_bytes'       => 0,
            'db_total'         => max(0, $dbBytes),
            'db_streamed'      => 0,
        ];
        $this->initialized = true;
        $this->render(true, true);
    }

    /**
     * Marks a file download as successful
     *
     * @param int $bytes File size in bytes
     */
    public function markFileSuccess(int $bytes): void
    {
        if (!$this->initialized) {
            return;
        }

        $this->progress['files_completed']++;
        $this->progress['file_bytes'] += max(0, $bytes);
        $this->render(false, !$this->interactive);
    }

    /**
     * Marks file download(s) as failed
     *
     * @param int $count Number of failed files
     */
    public function markFileFailure(int $count = 1): void
    {
        if (!$this->initialized) {
            return;
        }

        $this->progress['files_failed'] += max(0, $count);
        $this->render(false, !$this->interactive);
    }

    /**
     * Marks a batch of files as successfully downloaded
     *
     * @param array $files Array of file info
     */
    public function markBatchSuccess(array $files): void
    {
        if (!$this->initialized) {
            return;
        }

        $count = count($files);
        $bytes = 0;
        foreach ($files as $file) {
            $bytes += (int) ($file['size'] ?? 0);
        }

        $this->progress['files_completed'] += $count;
        $this->progress['file_bytes'] += $bytes;
        $this->render(false, !$this->interactive);
    }

    /**
     * Marks a batch of files as failed
     *
     * @param int $count Number of failed files
     */
    public function markBatchFailure(int $count): void
    {
        $this->markFileFailure($count);
    }

    /**
     * Increments file bytes without marking a file completed
     *
     * @param int $bytes Bytes transferred
     */
    public function incrementFileBytes(int $bytes): void
    {
        if (!$this->initialized) {
            return;
        }

        $this->progress['file_bytes'] += max(0, $bytes);
        $this->render();
    }

    /**
     * Increments database bytes streamed
     *
     * @param int $bytes Bytes streamed
     */
    public function incrementDbBytes(int $bytes): void
    {
        if (!$this->initialized) {
            return;
        }

        $this->progress['db_streamed'] += max(0, $bytes);
        $this->render();
    }

    /**
     * Renders the progress line
     *
     * @param bool $final Force final rendering
     * @param bool $force Force immediate rendering
     */
    public function render(bool $final = false, bool $force = false): void
    {
        if (!$this->initialized) {
            return;
        }

        $now = microtime(true);
        $interval = $this->interactive ? 0.5 : 5.0;
        if (!$force && !$final && ($now - $this->lastRender) < $interval) {
            return;
        }

        $this->lastRender = $now;

        $dbTotal = $this->progress['db_total'];
        $dbStreamed = $this->progress['db_streamed'];
        $filesCompleted = $this->progress['files_completed'];
        $filesFailed = $this->progress['files_failed'];
        $filesTotal = $this->progress['total_files'];
        $fileBytes = $this->progress['file_bytes'];
        $totalBytes = $this->progress['total_bytes'];

        $dbPart = $dbTotal > 0
            ? sprintf('%s/%s', $this->formatBytes($dbStreamed), $this->formatBytes($dbTotal))
            : sprintf('%s/?', $this->formatBytes($dbStreamed));

        $filesPart = $filesTotal > 0
            ? sprintf('%d/%d (failed %d)', $filesCompleted, $filesTotal, $filesFailed)
            : sprintf('%d files (failed %d)', $filesCompleted, $filesFailed);

        $overall = '?';
        $denominator = $totalBytes + $dbTotal;
        if ($denominator > 0) {
            $overallPct = ($fileBytes + $dbStreamed) / $denominator * 100;
            $overall = sprintf('%.1f%%', min(100, $overallPct));
        }

        $line = sprintf('DB: %s | Files: %s | Overall: %s', $dbPart, $filesPart, $overall);

        if ($this->interactive) {
            $output = "\r[lm] " . $line;
            fwrite(STDOUT, $output);
            $this->dirty = true;
            if ($final) {
                fwrite(STDOUT, "\r[lm] {$line}\n");
                $this->dirty = false;
            }
        } elseif ($final || $force) {
            fwrite(STDOUT, "[lm] {$line}\n");
        }
    }

    /**
     * Ensures newline after interactive progress bar
     */
    public function ensureNewline(): void
    {
        if ($this->interactive && $this->dirty) {
            fwrite(STDOUT, "\n");
            $this->dirty = false;
        }
    }

    /**
     * Formats bytes to human-readable format
     *
     * @param int $bytes Bytes to format
     * @return string Formatted string
     */
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

        return $bytes . ' B';
    }

    /**
     * Detects if output is interactive (TTY)
     *
     * @return bool True if interactive
     */
    private function detectInteractiveOutput(): bool
    {
        if (function_exists('posix_isatty')) {
            return @posix_isatty(STDOUT);
        }

        return false;
    }
}

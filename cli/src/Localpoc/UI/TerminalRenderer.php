<?php
declare(strict_types=1);

namespace Localpoc\UI;

use Localpoc\ProgressState;

class TerminalRenderer
{
    private bool $interactive;
    private bool $verbose;
    private float $lastRender = 0.0;
    private float $lastCompactRender = 0.0;
    private int $lastLineCount = 0;
    private bool $headerShown = false;
    private int $terminalWidth = 80; // Cache terminal width

    private string $siteUrl = '';
    private string $outputDir = '';
    private ?ProgressState $state = null;

    public function __construct()
    {
        $this->interactive = $this->detectInteractiveOutput();
        // Get terminal width once at initialization
        $this->terminalWidth = $this->getTerminalWidth();
    }

    private function detectInteractiveOutput(): bool
    {
        if (function_exists('posix_isatty')) {
            return posix_isatty(STDOUT);
        }
        if (function_exists('stream_isatty')) {
            return stream_isatty(STDOUT);
        }
        return true;
    }

    public function initialize(string $siteUrl, string $outputDir, bool $verbose = false): void
    {
        $this->siteUrl = $siteUrl;
        $this->outputDir = $outputDir;
        $this->verbose = $verbose;

        // Show header once
        if (!$this->headerShown && $this->interactive) {
            fwrite(STDOUT, sprintf("Site: %s\n", $siteUrl));
            fwrite(STDOUT, sprintf("Output: %s\n\n", $outputDir));
            $this->headerShown = true;
        }
    }

    public function updateProgress(ProgressState $state): void
    {
        $this->state = $state;
        $this->render();
    }

    public function log(string $message): void
    {
        if ($this->interactive && $this->lastLineCount > 0) {
            $this->clearLinesSimple();
            $this->lastLineCount = 0;
        }
        fwrite(STDOUT, "[lm] {$message}\n");
        if ($this->interactive) {
            $this->render(true);
        }
    }

    public function error(string $message): void
    {
        if ($this->interactive && $this->lastLineCount > 0) {
            $this->clearLinesSimple();
            $this->lastLineCount = 0;
        }
        fwrite(STDERR, "[lm] ERROR: {$message}\n");
    }

    public function showSummary(string $archivePath, int $archiveSize, ProgressState $state, array $validation = []): void
    {
        if ($this->interactive && $this->lastLineCount > 0) {
            $this->clearLinesSimple();
            $this->lastLineCount = 0;
        }

        $elapsed = microtime(true) - $state->startTime;
        $avgSpeed = $elapsed > 0 ? $state->bytesTransferred / $elapsed : 0;

        $lines = [
            '',
            'Download complete',
            sprintf('  Time     : %s', $this->formatDuration($elapsed)),
            sprintf('  Database : %s rows', number_format($state->dbTotalRows)),
            sprintf('  Files    : %d files', $state->filesCompleted),
        ];

        if ($state->filesFailed > 0) {
            $lines[] = sprintf('  Failed   : %d files', $state->filesFailed);
        }

        $lines[] = sprintf('  Transfer : %s', $this->formatBytes($state->bytesTransferred));
        $lines[] = sprintf('  Speed    : %s/s avg', $this->formatBytes((int)round($avgSpeed)));
        $lines[] = sprintf('  Archive  : %s', $archivePath);
        $lines[] = sprintf('  Size     : %s', $this->formatBytes($archiveSize));

        // Add validation info if available
        if (!empty($validation)) {
            $lines[] = '';
            $lines[] = 'Validation';
            $dbPercent = $state->dbTotalRows > 0
                ? round(($state->dbRowsProcessed / $state->dbTotalRows) * 100, 1)
                : 0;
            $lines[] = sprintf('  Database : %s (%0.1f%%)',
                $validation['db_complete'] ? '✓' : '✗',
                $dbPercent
            );
            $lines[] = sprintf('  Files    : %s (%d/%d)',
                $validation['files_match'] ? '✓' : '✗',
                $state->filesCompleted,
                $state->filesTotalCount
            );
            $lines[] = sprintf('  Transfer : %s (%s)',
                $validation['bytes_transferred'] ? '✓' : '✗',
                $this->formatBytes($state->bytesTransferred)
            );
        }

        fwrite(STDOUT, implode("\n", $lines) . "\n\n");
    }

    private function render(bool $force = false): void
    {
        if (!$this->state) {
            return;
        }

        if (!$this->interactive) {
            $this->renderCompact($force);
            return;
        }

        $now = microtime(true);
        // Already throttled in DownloadOrchestrator, but keep minimal throttle here
        if (!$force && ($now - $this->lastRender) < 0.1) {
            return;
        }
        $this->lastRender = $now;

        $lines = [];

        // Database progress (rows only)
        $dbPercent = $this->state->dbTotalRows > 0
            ? ($this->state->dbRowsProcessed / $this->state->dbTotalRows) * 100
            : 0;
        $lines[] = sprintf(
            'DB    : %6.1f%% [%s] %s/%s rows',
            $dbPercent,
            $this->buildSimpleBar($dbPercent, 20),
            number_format($this->state->dbRowsProcessed),
            number_format($this->state->dbTotalRows)
        );

        // File progress (count only)
        $filePercent = $this->state->filesTotalCount > 0
            ? ($this->state->filesCompleted / $this->state->filesTotalCount) * 100
            : 0;
        $lines[] = sprintf(
            'Files : %6.1f%% [%s] %d/%d',
            $filePercent,
            $this->buildSimpleBar($filePercent, 20),
            $this->state->filesCompleted,
            $this->state->filesTotalCount
        );

        if ($this->state->filesFailed > 0) {
            $lines[count($lines) - 1] .= sprintf(' (%d failed)', $this->state->filesFailed);
        }

        // Speed based on actual bytes transferred
        $elapsed = microtime(true) - $this->state->startTime;
        $speed = $elapsed > 0 ? $this->state->bytesTransferred / $elapsed : 0;
        $lines[] = sprintf('Speed : %s/s', $this->formatBytes((int)round($speed)));

        // Current activity
        if ($this->state->currentActivity) {
            $activity = $this->state->currentActivity;
            if (strlen($activity) > $this->terminalWidth - 10) {
                $activity = substr($activity, 0, $this->terminalWidth - 13) . '...';
            }
            $lines[] = $activity;
        }

        $this->clearLinesSimple();
        fwrite(STDOUT, "\033[?25l"); // Hide cursor
        fwrite(STDOUT, implode("\n", $lines) . "\n");
        fwrite(STDOUT, "\033[?25h"); // Show cursor
        $this->lastLineCount = count($lines);
    }

    private function renderCompact(bool $force): void
    {
        if (!$this->state) {
            return;
        }

        $now = microtime(true);
        if (!$force && ($now - $this->lastCompactRender) < 5.0) {
            return;
        }
        $this->lastCompactRender = $now;

        $dbPercent = $this->state->dbTotalRows > 0
            ? ($this->state->dbRowsProcessed / $this->state->dbTotalRows) * 100
            : 0;

        $filesPercent = $this->state->filesTotalCount > 0
            ? ($this->state->filesCompleted / $this->state->filesTotalCount) * 100
            : 0;

        // Calculate speed
        $elapsed = microtime(true) - $this->state->startTime;
        $speed = $elapsed > 0 ? $this->state->bytesTransferred / $elapsed : 0;

        $line = sprintf(
            "[lm] DB: %.1f%% (%d rows) | Files: %.1f%% (%d/%d) | Speed: %s/s",
            $dbPercent,
            $this->state->dbRowsProcessed,
            $filesPercent,
            $this->state->filesCompleted,
            $this->state->filesTotalCount,
            $this->formatBytes((int)round($speed))
        );

        if ($this->state->filesFailed > 0) {
            $line .= sprintf(' [%d failed]', $this->state->filesFailed);
        }

        if ($this->state->currentActivity) {
            $line .= ' | ' . $this->state->currentActivity;
        }

        fwrite(STDOUT, $line . "\n");
    }

    private function buildSimpleBar(float $percent, int $width): string
    {
        $filled = (int)round($width * ($percent / 100));
        $filled = min($width, max(0, $filled));
        return str_repeat('=', $filled) . str_repeat('.', $width - $filled);
    }

    private function clearLinesSimple(): void
    {
        if ($this->interactive && $this->lastLineCount > 0) {
            // Use simple carriage return and clear to end of line
            // Much faster than complex cursor movements
            for ($i = 0; $i < $this->lastLineCount; $i++) {
                fwrite(STDOUT, "\033[1A\033[2K"); // Move up 1 line and clear it
            }
        }
    }

    private function getTerminalWidth(): int
    {
        $width = 80; // default
        if (function_exists('exec')) {
            @exec('tput cols 2>/dev/null', $output, $return);
            if ($return === 0 && isset($output[0])) {
                $width = (int)$output[0];
            }
        }
        return max(40, min(200, $width));
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = (float)$bytes;

        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        if ($i === 0) {
            return sprintf('%d %s', (int)$value, $units[$i]);
        }

        return sprintf('%.2f %s', $value, $units[$i]);
    }

    private function formatDuration(float $seconds): string
    {
        if ($seconds < 60) {
            return sprintf('%.1fs', $seconds);
        }
        if ($seconds < 3600) {
            $mins = (int)floor($seconds / 60);
            $secs = (int)floor($seconds) % 60;
            return sprintf('%dm %ds', $mins, $secs);
        }
        $hours = (int)floor($seconds / 3600);
        $mins = ((int)floor($seconds) % 3600) / 60;
        $mins = (int)floor($mins);
        return sprintf('%dh %dm', $hours, $mins);
    }
}
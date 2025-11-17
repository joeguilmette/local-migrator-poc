<?php
declare(strict_types=1);

namespace Localpoc\UI;

use Localpoc\ProgressState;

class TerminalRenderer
{
    private bool $plainMode;
    private bool $interactive;
    private bool $verbose;
    private float $lastRender = 0.0;
    private float $lastPlainRender = 0.0;
    private int $lastLineCount = 0;
    private bool $headerShown = false;

    private string $siteUrl = '';
    private string $outputDir = '';
    private ?ProgressState $state = null;

    public function __construct(bool $plainMode = false)
    {
        $this->plainMode = $plainMode;
        $this->interactive = !$plainMode && $this->detectInteractiveOutput();
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
            $this->clearLines();
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
            $this->clearLines();
            $this->lastLineCount = 0;
        }
        fwrite(STDERR, "[lm] ERROR: {$message}\n");
    }

    public function showSummary(string $archivePath, int $archiveSize, ProgressState $state): void
    {
        if ($this->interactive && $this->lastLineCount > 0) {
            $this->clearLines();
            $this->lastLineCount = 0;
        }

        $elapsed = microtime(true) - $state->startTime;
        $totalBytes = $state->fileBytesDownloaded + $state->dbBytesExported + $state->dbBytesDownloaded;
        $avgSpeed = $elapsed > 0 ? $totalBytes / $elapsed : 0;

        $lines = [
            '',
            'Download complete',
            sprintf('  Time     : %s', $this->formatDuration($elapsed)),
            sprintf('  Database : %s (%s rows)',
                $this->formatBytes($state->dbBytesExported + $state->dbBytesDownloaded),
                number_format($state->dbTotalRows)
            ),
            sprintf('  Files    : %s (%d files)',
                $this->formatBytes($state->fileBytesDownloaded),
                $state->filesCompleted
            ),
        ];

        if ($state->filesFailed > 0) {
            $lines[] = sprintf('  Failed   : %d files', $state->filesFailed);
        }

        $lines[] = sprintf('  Total    : %s', $this->formatBytes($totalBytes));
        $lines[] = sprintf('  Speed    : %s/s avg', $this->formatBytes((int)$avgSpeed));
        $lines[] = sprintf('  Archive  : %s', $archivePath);
        $lines[] = sprintf('  Size     : %s', $this->formatBytes($archiveSize));

        fwrite(STDOUT, implode("\n", $lines) . "\n\n");
    }

    private function render(bool $force = false): void
    {
        if (!$this->state) {
            return;
        }

        if ($this->plainMode || !$this->interactive) {
            $this->renderPlain($force);
            return;
        }

        $now = microtime(true);
        if (!$force && ($now - $this->lastRender) < 0.2) {
            return;
        }
        $this->lastRender = $now;

        $lines = [];

        // Database progress
        $dbTotalBytes = $this->state->dbEstimatedBytes ?: ($this->state->dbBytesExported + $this->state->dbBytesDownloaded);
        $dbCurrentBytes = $this->state->dbBytesExported + $this->state->dbBytesDownloaded;
        $dbProgress = $this->buildBar(
            'DB',
            $dbCurrentBytes,
            $dbTotalBytes,
            sprintf('%s / %s (%s rows)',
                $this->formatBytes($dbCurrentBytes),
                $this->formatBytes($dbTotalBytes),
                $this->formatCount($this->state->dbRowsProcessed)
            )
        );
        $lines[] = $dbProgress;

        // Files progress
        $filesProgress = $this->buildBar(
            'Files',
            $this->state->fileBytesDownloaded,
            $this->state->filesTotalBytes,
            sprintf('%s / %s (%d/%d files)',
                $this->formatBytes($this->state->fileBytesDownloaded),
                $this->formatBytes($this->state->filesTotalBytes),
                $this->state->filesCompleted,
                $this->state->filesTotalCount
            )
        );
        if ($this->state->filesFailed > 0) {
            $filesProgress .= sprintf(' [%d failed]', $this->state->filesFailed);
        }
        $lines[] = $filesProgress;

        // Speed and ETA
        $speed = $this->calculateSpeed($this->state);
        $eta = $this->calculateETA($this->state, $speed);
        $speedLine = sprintf('Speed: %s/s', $this->formatBytes((int)$speed));
        if ($eta) {
            $speedLine .= sprintf(' | ETA: %s', $eta);
        }
        $lines[] = $speedLine;

        // Current activity
        if ($this->state->currentActivity) {
            $activity = $this->state->currentActivity;
            $termWidth = $this->getTerminalWidth();
            if (strlen($activity) > $termWidth - 10) {
                $activity = substr($activity, 0, $termWidth - 13) . '...';
            }
            $lines[] = $activity;
        }

        $this->clearLines();
        fwrite(STDOUT, implode("\n", $lines) . "\n");
        $this->lastLineCount = count($lines);
    }

    private function renderPlain(bool $force): void
    {
        if (!$this->state) {
            return;
        }

        $now = microtime(true);
        if (!$force && ($now - $this->lastPlainRender) < 5.0) {
            return;
        }
        $this->lastPlainRender = $now;

        $dbPercent = $this->state->dbEstimatedBytes > 0
            ? ($this->state->dbBytesExported / $this->state->dbEstimatedBytes) * 100
            : 0;

        $filesPercent = $this->state->filesTotalBytes > 0
            ? ($this->state->fileBytesDownloaded / $this->state->filesTotalBytes) * 100
            : 0;

        $line = sprintf(
            "[lm] DB: %.1f%% (%s rows) | Files: %.1f%% (%d/%d)",
            $dbPercent,
            $this->formatCount($this->state->dbRowsProcessed),
            $filesPercent,
            $this->state->filesCompleted,
            $this->state->filesTotalCount
        );

        if ($this->state->filesFailed > 0) {
            $line .= sprintf(' [%d failed]', $this->state->filesFailed);
        }

        if ($this->state->currentActivity) {
            $line .= ' | ' . $this->state->currentActivity;
        }

        fwrite(STDOUT, $line . "\n");
    }

    private function buildBar(string $label, int $current, int $total, string $info): string
    {
        $termWidth = $this->getTerminalWidth();
        $availableWidth = $termWidth - strlen($label) - strlen($info) - 15; // Space for label, percentage, etc.
        $barWidth = max(10, min(50, $availableWidth));

        $percent = $total > 0 ? min(100, ($current / $total) * 100) : 0;
        $filled = (int) round($barWidth * ($percent / 100));
        $bar = '[' . str_repeat('█', $filled) . str_repeat('░', $barWidth - $filled) . ']';

        return sprintf('%-5s %s %5.1f%%  %s', $label . ':', $bar, $percent, $info);
    }

    private function clearLines(): void
    {
        if ($this->interactive && $this->lastLineCount > 0) {
            // Move cursor up and clear lines
            fwrite(STDOUT, "\033[{$this->lastLineCount}A");
            for ($i = 0; $i < $this->lastLineCount; $i++) {
                fwrite(STDOUT, "\033[2K\033[1B");
            }
            fwrite(STDOUT, "\033[{$this->lastLineCount}A");
        }
    }

    private function calculateSpeed(ProgressState $state): float
    {
        $elapsed = microtime(true) - $state->startTime;
        if ($elapsed <= 0) {
            return 0;
        }

        $totalBytes = $state->fileBytesDownloaded + $state->dbBytesExported + $state->dbBytesDownloaded;
        return $totalBytes / $elapsed;
    }

    private function calculateETA(ProgressState $state, float $speed): ?string
    {
        if ($speed <= 0) {
            return null;
        }

        $totalExpected = $state->filesTotalBytes + ($state->dbEstimatedBytes ?: 0);
        $totalCompleted = $state->fileBytesDownloaded + $state->dbBytesExported + $state->dbBytesDownloaded;

        if ($totalCompleted >= $totalExpected) {
            return null;
        }

        $remaining = $totalExpected - $totalCompleted;
        $seconds = (int)($remaining / $speed);

        if ($seconds < 60) {
            return sprintf('%ds', $seconds);
        }
        if ($seconds < 3600) {
            return sprintf('%dm %ds', (int)($seconds / 60), $seconds % 60);
        }
        return sprintf('%dh %dm', (int)($seconds / 3600), (int)(($seconds % 3600) / 60));
    }

    private function getTerminalWidth(): int
    {
        static $width = null;

        if ($width === null) {
            $width = 80; // default
            if (function_exists('exec')) {
                exec('tput cols 2>/dev/null', $output, $return);
                if ($return === 0 && isset($output[0])) {
                    $width = (int)$output[0];
                }
            }
            $width = max(40, min(200, $width)); // Reasonable bounds
        }

        return $width;
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

    private function formatCount(int $value): string
    {
        if ($value >= 1000000) {
            return sprintf('%.1fm', $value / 1000000);
        }
        if ($value >= 1000) {
            return sprintf('%.1fk', $value / 1000);
        }
        return (string) $value;
    }

    private function formatDuration(float $seconds): string
    {
        if ($seconds < 60) {
            return sprintf('%.1fs', $seconds);
        }
        if ($seconds < 3600) {
            $mins = (int)($seconds / 60);
            $secs = (int)($seconds % 60);
            return sprintf('%dm %ds', $mins, $secs);
        }
        $hours = (int)($seconds / 3600);
        $mins = (int)(($seconds % 3600) / 60);
        return sprintf('%dh %dm', $hours, $mins);
    }
}
<?php
declare(strict_types=1);

namespace Localpoc\UI;

class TerminalRenderer
{
    private bool $plainMode;
    private bool $interactive;
    private float $lastRender = 0.0;
    private float $lastPlainRender = 0.0;
    private int $lastLineCount = 0;

    private array $state = [
        'site_url' => '',
        'output_dir' => '',
        'db_rows_total' => 0,
        'db_rows_current' => 0,
        'files_total' => 0,
        'files_current' => 0,
        'files_failed' => 0,
        'speed_total_bytes' => 0,
        'speed_last_bytes' => 0,
        'speed_last_time' => 0.0,
        'speed_display' => '0.00 MB',
    ];

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

    public function initialize(string $siteUrl, string $outputDir): void
    {
        $this->state['site_url'] = $siteUrl;
        $this->state['output_dir'] = $outputDir;
        $this->render(true);
    }

    public function setDatabaseRowsTotal(int $totalRows): void
    {
        $this->state['db_rows_total'] = max(0, $totalRows);
        $this->render();
    }

    public function updateDatabaseRows(int $processed): void
    {
        $this->state['db_rows_current'] = max(0, $processed);
        $this->render();
    }

    public function setFilesTotal(int $count, int $bytes): void
    {
        $this->state['files_total'] = max(0, $count);
        // bytes not used for progress but may be useful later
        $this->render();
    }

    public function updateFiles(int $completed, int $failed): void
    {
        $this->state['files_current'] = max(0, $completed);
        $this->state['files_failed'] = max(0, $failed);
        $this->render();
    }

    public function addTransferredBytes(int $bytes): void
    {
        if ($bytes <= 0) {
            return;
        }
        $this->state['speed_total_bytes'] += $bytes;
        $this->render();
    }

    public function log(string $message): void
    {
        if ($this->interactive && $this->lastLineCount > 0) {
            $this->clearLines();
            $this->lastLineCount = 0;
        }
        fwrite(STDOUT, "[lm] {$message}\n");
        $this->render(true);
    }

    public function error(string $message): void
    {
        if ($this->interactive && $this->lastLineCount > 0) {
            $this->clearLines();
            $this->lastLineCount = 0;
        }
        fwrite(STDERR, "[lm] ERROR: {$message}\n");
    }

    public function showSummary(string $archivePath, int $archiveSize): void
    {
        if ($this->interactive && $this->lastLineCount > 0) {
            $this->clearLines();
            $this->lastLineCount = 0;
        }

        $lines = [
            'Download complete',
            sprintf('  Database : %s rows', $this->formatCount($this->state['db_rows_total'])),
            sprintf('  Files    : %d files', $this->state['files_total']),
        ];

        if ($this->state['files_failed'] > 0) {
            $lines[] = sprintf('  Failed   : %d files', $this->state['files_failed']);
        }

        $lines[] = sprintf('  Archive  : %s', $archivePath);
        $lines[] = sprintf('  Size     : %s', $this->formatBytes($archiveSize));

        fwrite(STDOUT, "\n" . implode("\n", $lines) . "\n\n");
    }

    private function render(bool $force = false): void
    {
        if ($this->plainMode || !$this->interactive) {
            $this->renderPlain($force);
            return;
        }

        $now = microtime(true);
        if (!$force && ($now - $this->lastRender) < 0.2) {
            return;
        }
        $this->lastRender = $now;
        $this->updateSpeedDisplay($now);

        $lines = [];
        $lines[] = sprintf('Site: %s', $this->state['site_url']);
        $lines[] = sprintf('Output: %s', $this->state['output_dir']);
        $lines[] = '';
        $lines[] = $this->buildBar('DB', $this->state['db_rows_current'], $this->state['db_rows_total']);
        $lines[] = $this->buildBar('Files', $this->state['files_current'], $this->state['files_total'], $this->state['files_failed']);
        $lines[] = sprintf('Speed: %s/s', $this->state['speed_display']);

        $this->clearLines();
        fwrite(STDOUT, implode("\n", $lines) . "\n");
        $this->lastLineCount = count($lines);
    }

    private function renderPlain(bool $force): void
    {
        $now = microtime(true);
        if (!$force && ($now - $this->lastPlainRender) < 5.0) {
            return;
        }
        $this->lastPlainRender = $now;
        $this->updateSpeedDisplay($now);

        $line = sprintf(
            "[lm] DB rows: %s/%s | Files: %d/%d (failed %d) | Speed: %s/s",
            $this->formatCount($this->state['db_rows_current']),
            $this->formatCount($this->state['db_rows_total']),
            $this->state['files_current'],
            $this->state['files_total'],
            $this->state['files_failed'],
            $this->state['speed_display']
        );

        fwrite(STDOUT, $line . "\n");
    }

    private function buildBar(string $label, int $current, int $total, int $failed = 0): string
    {
        $width = 30;
        $percent = $total > 0 ? min(100, ($current / $total) * 100) : 0;
        $filled = (int) round($width * ($percent / 100));
        $bar = '[' . str_repeat('=', $filled) . str_repeat('.', $width - $filled) . ']';

        $meta = sprintf('%s / %s', $this->formatCount($current), $this->formatCount($total));
        if ($failed > 0 && $label === 'Files') {
            $meta .= sprintf(' (failed %d)', $failed);
        }

        return sprintf('%-5s %s %5.1f%%  %s', $label . ':', $bar, $percent, $meta);
    }

    private function clearLines(): void
    {
        if ($this->interactive && $this->lastLineCount > 0) {
            fwrite(STDOUT, "\033[{$this->lastLineCount}A");
            for ($i = 0; $i < $this->lastLineCount; $i++) {
                fwrite(STDOUT, "\033[2K\033[1B");
            }
            fwrite(STDOUT, "\033[{$this->lastLineCount}A");
        }
    }

    private function updateSpeedDisplay(float $now): void
    {
        if ($this->state['speed_last_time'] === 0.0) {
            $this->state['speed_last_time'] = $now;
            $this->state['speed_last_bytes'] = $this->state['speed_total_bytes'];
            $this->state['speed_display'] = '0.00 MB';
            return;
        }

        $deltaTime = $now - $this->state['speed_last_time'];
        if ($deltaTime < 0.5) {
            return;
        }

        $deltaBytes = $this->state['speed_total_bytes'] - $this->state['speed_last_bytes'];
        $speed = $deltaTime > 0 ? $deltaBytes / $deltaTime : 0;
        $this->state['speed_display'] = $this->formatBytesPerSecond($speed);
        $this->state['speed_last_time'] = $now;
        $this->state['speed_last_bytes'] = $this->state['speed_total_bytes'];
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int) floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);
        $value = $bytes / pow(1024, $i);
        return sprintf('%.2f %s', $value, $units[$i]);
    }

    private function formatBytesPerSecond(float $bytesPerSecond): string
    {
        if ($bytesPerSecond <= 0) {
            return '0.00 MB';
        }
        $mb = $bytesPerSecond / 1048576;
        return sprintf('%.2f MB', $mb);
    }

    private function formatCount(int $value): string
    {
        if ($value >= 1000000) {
            return sprintf('%.2fm', $value / 1000000);
        }
        if ($value >= 1000) {
            return sprintf('%.1fk', $value / 1000);
        }
        return (string) $value;
    }
}

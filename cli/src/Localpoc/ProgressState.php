<?php
declare(strict_types=1);

namespace Localpoc;

/**
 * Progress state data transfer object - simplified model
 */
class ProgressState
{
    // Database progress (row-based only)
    public int $dbRowsProcessed = 0;
    public int $dbTotalRows = 0;

    // File progress (count-based only)
    public int $filesCompleted = 0;
    public int $filesFailed = 0;
    public int $filesTotalCount = 0;

    // Actual network transfer in bytes (files + final DB download)
    public int $bytesTransferred = 0;

    // UI state
    public string $currentActivity = '';

    // Timing
    public float $startTime;

    public function __construct()
    {
        $this->startTime = microtime(true);
    }
}
<?php
declare(strict_types=1);

namespace Localpoc;

/**
 * Progress state data transfer object
 */
class ProgressState
{
    public int $dbBytesExported = 0;     // Bytes written during export
    public int $dbBytesDownloaded = 0;   // Bytes during SQL file download
    public int $dbRowsProcessed = 0;
    public int $dbTotalRows = 0;
    public int $dbEstimatedBytes = 0;    // Estimated total DB size

    public int $fileBytesDownloaded = 0;
    public int $filesCompleted = 0;
    public int $filesFailed = 0;
    public int $filesTotalCount = 0;
    public int $filesTotalBytes = 0;

    public int $archiveBytes = 0;
    public string $currentActivity = '';  // Current operation description

    public float $startTime;
    public float $lastUpdateTime;

    public function __construct()
    {
        $this->startTime = microtime(true);
        $this->lastUpdateTime = $this->startTime;
    }
}
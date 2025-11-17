<?php

namespace Localpoc;

use Exception;
use RuntimeException;

class StreamingDatabaseClient {

    private string $siteUrl; // base site URL (e.g. https://example.com), not the admin-ajax path
    private string $accessKey;

    public function __construct(string $siteUrl, string $accessKey) {
        $this->siteUrl = rtrim($siteUrl, '/');
        $this->accessKey = $accessKey;
    }

    /**
     * Initialize streaming session
     *
     * @param array $options ['chunk_size' => int, 'compression' => string]
     * @return array ['cursor' => string, 'sql_header' => string, 'metadata' => array]
     */
    public function initStream(array $options = []): array {
        $url = $this->siteUrl . '/wp-admin/admin-ajax.php?action=localpoc_db_stream_init';

        $params = [
            'chunk_size' => $options['chunk_size'] ?? 1000,
            'compression' => $options['compression'] ?? 'gzip'
        ];

        $response = Http::postJson($url, $params, $this->accessKey);

        if (empty($response['success']) || !isset($response['data'])) {
            $message = '';
            if (isset($response['data']['message'])) {
                $message = (string) $response['data']['message'];
            } elseif (isset($response['message'])) {
                $message = (string) $response['message'];
            }
            throw new RuntimeException('Init failed: ' . ($message ?: 'Unknown server error'));
        }

        return $response['data'];
    }

    /**
     * Fetch next chunk
     *
     * @param string $cursor Base64 encoded cursor
     * @param array $options ['time_budget' => int, 'compression' => string]
     * @return array ['sql' => string, 'cursor' => string, 'is_complete' => bool, 'progress' => array]
     */
    public function fetchChunk(string $cursor, array $options = []): array {
        $url = $this->siteUrl . '/wp-admin/admin-ajax.php?action=localpoc_db_stream_chunk';

        $params = [
            'cursor' => $cursor,
            'time_budget' => $options['time_budget'] ?? 5,
            'compression' => $options['compression'] ?? 'gzip'
        ];

        $response = Http::postJson($url, $params, $this->accessKey);

        if (empty($response['success']) || !isset($response['data'])) {
            $message = '';
            if (isset($response['data']['message'])) {
                $message = (string) $response['data']['message'];
            } elseif (isset($response['message'])) {
                $message = (string) $response['message'];
            }
            throw new RuntimeException('Chunk fetch failed: ' . ($message ?: 'Unknown server error'));
        }

        $data = $response['data'];

        // Decode SQL chunk
        $sql_decoded = base64_decode($data['sql_chunk']);
        if ($sql_decoded === false) {
            throw new RuntimeException('Failed to decode SQL chunk');
        }

        // Decompress if gzipped
        if (($options['compression'] ?? 'gzip') === 'gzip') {
            $sql_decompressed = @gzdecode($sql_decoded);
            if ($sql_decompressed === false) {
                // Fallback: maybe it wasn't compressed
                $sql_decompressed = $sql_decoded;
            }
            $data['sql'] = $sql_decompressed;
        } else {
            $data['sql'] = $sql_decoded;
        }

        unset($data['sql_chunk']); // Remove encoded version

        return $data;
    }

    /**
     * Stream entire database to file
     *
     * @param string $dest Destination file path
     * @param array $options ['chunk_size' => int, 'time_budget' => int]
     * @param callable|null $progressCallback Progress callback function
     */
    public function streamToFile(string $dest, array $options = [], ?callable $progressCallback = null): void {
        // Open output file
        $handle = @fopen($dest, 'w');
        if (!$handle) {
            throw new RuntimeException("Cannot open output file: $dest");
        }

        try {
            // Initialize stream
            echo "Initializing database stream...\n";
            $init = $this->initStream([
                'chunk_size' => $options['chunk_size'] ?? 1000,
                'compression' => 'gzip'
            ]);

            // Write SQL header
            $header = base64_decode($init['sql_header']);
            if ($header === false) {
                throw new RuntimeException('Failed to decode SQL header');
            }
            fwrite($handle, $header);

            $cursor = $init['cursor'];
            $metadata = $init['metadata'];
            $total_rows_sent = 0;
            $total_bytes_sent = strlen($header);
            $chunk_count = 0;

            echo "Streaming {$metadata['total_tables']} tables (~{$metadata['total_rows']} rows)...\n";

            // Stream chunks
            while (true) {
                $chunk = $this->fetchChunk($cursor, [
                    'time_budget' => $options['time_budget'] ?? 5,
                    'compression' => 'gzip'
                ]);

                // Write SQL to file
                fwrite($handle, $chunk['sql']);

                // Update counters
                $chunk_count++;
                $total_rows_sent += $chunk['progress']['rows_in_chunk'];
                $total_bytes_sent += strlen($chunk['sql']);

                // Progress callback (simple console output)
                if ($progressCallback) {
                    $progressCallback([
                        'current_table' => $chunk['progress']['current_table'],
                        'tables_completed' => $chunk['progress']['tables_completed'],
                        'total_tables' => $metadata['total_tables'],
                        'rows_sent' => $total_rows_sent,
                        'bytes_sent' => $total_bytes_sent,
                        'chunk_count' => $chunk_count
                    ]);
                } else {
                    // Default progress output
                    if ($chunk['progress']['rows_in_chunk'] > 0) {
                        echo sprintf(
                            "Table %d/%d: %s - %d rows (chunk #%d, %.2f MB total)\n",
                            $chunk['progress']['current_table_index'] + 1,
                            $metadata['total_tables'],
                            $chunk['progress']['current_table'],
                            $chunk['progress']['rows_in_chunk'],
                            $chunk_count,
                            $total_bytes_sent / 1048576
                        );
                    }
                }

                // Check completion
                if ($chunk['is_complete']) {
                    echo "\nStreaming complete: $chunk_count chunks, $total_rows_sent rows, " .
                         sprintf("%.2f MB\n", $total_bytes_sent / 1048576);
                    break;
                }

                // Update cursor for next iteration
                $cursor = $chunk['cursor'];
            }

        } catch (Exception $e) {
            fclose($handle);
            @unlink($dest); // Clean up partial file
            throw $e;
        }

        fclose($handle);
    }
}

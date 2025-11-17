<?php
/**
 * Database export job manager
 *
 * Handles chunked database exports with progress tracking
 *
 * @package LocalPOC
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Database job manager class
 */
class LocalPOC_Database_Job_Manager {

    /**
     * Job transient TTL (15 minutes)
     */
    const JOB_TTL = 900; // 15 * 60

    /**
     * Default batch size for row processing
     */
    const DEFAULT_BATCH_SIZE = 2000;

    /**
     * Get database metadata
     *
     * @return array Database metadata including table count, row count, and estimated size
     */
    public static function get_db_meta_data() {
        global $wpdb;

        try {
            // Try fast information_schema query first
            $tables_info = $wpdb->get_results("
                SELECT
                    TABLE_NAME,
                    IFNULL(TABLE_ROWS, 0) as rows,
                    IFNULL(DATA_LENGTH, 0) + IFNULL(INDEX_LENGTH, 0) as size
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
            ");

            // Check if information_schema query failed (permissions or errors)
            if ($wpdb->last_error || !$tables_info) {
                // Fallback to SHOW TABLES + SHOW TABLE STATUS (no COUNT queries!)
                $tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
                if (!$tables) {
                    return [
                        'total_tables' => 0,
                        'total_rows' => 0,
                        'total_approx_bytes' => 0,
                        'character_set' => $wpdb->charset,
                        'is_estimate' => true
                    ];
                }

                $total_tables = count($tables);
                $total_rows = 0;
                $estimated_bytes = 0;

                foreach ($tables as $table) {
                    $table_name = $table[0];
                    // Use SHOW TABLE STATUS for estimates (no COUNT(*) queries!)
                    $table_status = $wpdb->get_row("SHOW TABLE STATUS LIKE '{$table_name}'");
                    if ($table_status) {
                        $total_rows += intval($table_status->Rows);  // Approximate row count
                        $estimated_bytes += intval($table_status->Data_length) + intval($table_status->Index_length);
                    }
                }
            } else {
                // information_schema query succeeded
                $total_tables = count($tables_info);
                $total_rows = 0;
                $estimated_bytes = 0;

                foreach ($tables_info as $table) {
                    $total_rows += intval($table->rows);
                    $estimated_bytes += intval($table->size);
                }
            }

            // Add 30% overhead for SQL syntax
            $estimated_bytes = intval($estimated_bytes * 1.3);

            return [
                'total_tables' => $total_tables,
                'total_rows' => $total_rows,
                'total_approx_bytes' => $estimated_bytes,  // Use correct field name
                'character_set' => $wpdb->charset,
                'is_estimate' => true
            ];
        } catch (Exception $e) {
            return new WP_Error(
                'localpoc_db_meta_error',
                __('Failed to retrieve database metadata: ', 'localpoc') . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Initialize a new database export job
     *
     * @return array|WP_Error Job information or error
     */
    public static function init_job() {
        global $wpdb;

        try {
            // Generate unique job ID
            $job_id = wp_generate_password(20, false, false);

            // Create temporary SQL file
            $temp_file = wp_tempnam('localpoc_db_export');
            if (!$temp_file) {
                return new WP_Error(
                    'localpoc_temp_file_error',
                    __('Failed to create temporary file', 'localpoc'),
                    ['status' => 500]
                );
            }

            // Get database metadata
            $meta = self::get_db_meta_data();
            if (is_wp_error($meta)) {
                @unlink($temp_file);
                return $meta;
            }

            // Get list of all tables
            $tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
            $table_list = array_map(function($table) { return $table[0]; }, $tables);

            // Initialize SQL file with header
            $header = "-- LocalPOC Database Export\n";
            $header .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
            $header .= "-- WordPress Version: " . get_bloginfo('version') . "\n";
            $header .= "-- PHP Version: " . phpversion() . "\n";
            $header .= "-- MySQL Version: " . $wpdb->db_version() . "\n";
            $header .= "\n";
            $header .= "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n";
            $header .= "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n";
            $header .= "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n";
            $header .= "/*!40101 SET NAMES " . $wpdb->charset . " */;\n";
            $header .= "/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;\n";
            $header .= "/*!40103 SET TIME_ZONE='+00:00' */;\n";
            $header .= "/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;\n";
            $header .= "/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;\n";
            $header .= "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n";
            $header .= "/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;\n\n";

            file_put_contents($temp_file, $header);
            $bytes_written = strlen($header);

            // Store job metadata
            $job_meta = [
                'job_id' => $job_id,
                'created_at' => time(),
                'state' => 'running',
                'file_path' => $temp_file,
                'tables_list' => $table_list,
                'total_tables' => count($table_list),
                'completed_tables' => [],
                'current_table_index' => 0,
                'current_table_offset' => 0,
                'bytes_written' => $bytes_written,
                'estimated_bytes' => $meta['total_approx_bytes'],
                'total_rows' => $meta['total_rows'],
                'rows_processed' => 0,
                'warnings' => []
            ];

            // Save job metadata to transient
            set_transient('localpoc_db_job_' . $job_id, $job_meta, self::JOB_TTL);

            return [
                'job_id' => $job_id,
                'bytes_written' => $bytes_written,
                'estimated_bytes' => $meta['total_approx_bytes'],
                'total_tables' => $meta['total_tables'],
                'total_rows' => $meta['total_rows'],
                'rows_processed' => 0,
            ];

        } catch (Exception $e) {
            if (isset($temp_file) && file_exists($temp_file)) {
                @unlink($temp_file);
            }
            return new WP_Error(
                'localpoc_db_init_error',
                __('Failed to initialize database export: ', 'localpoc') . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Process database export job in chunks
     *
     * @param string $job_id Job identifier
     * @param int|null $time_budget Time budget in milliseconds (default 5000ms)
     * @return array|WP_Error Progress information or error
     */
    public static function process_job($job_id, $time_budget = null) {
        global $wpdb;

        if (!$job_id) {
            return new WP_Error(
                'localpoc_invalid_job',
                __('Invalid job ID', 'localpoc'),
                ['status' => 400]
            );
        }

        // Load job metadata
        $job_meta = get_transient('localpoc_db_job_' . $job_id);
        if (!$job_meta) {
            return new WP_Error(
                'localpoc_job_not_found',
                __('Job not found or expired', 'localpoc'),
                ['status' => 404]
            );
        }

        if (!isset($job_meta['rows_processed'])) {
            $job_meta['rows_processed'] = 0;
        }

        // Check if job is already completed
        if ($job_meta['state'] === 'completed') {
            return [
                'job_id' => $job_id,
                'bytes_written' => $job_meta['bytes_written'],
                'completed_tables' => count($job_meta['completed_tables']),
                'last_table' => end($job_meta['completed_tables']) ?: '',
                'last_batch_rows' => 0,
                'file_size' => filesize($job_meta['file_path']),
                'done' => true,
                'warnings' => $job_meta['warnings'],
                'rows_processed' => (int) ($job_meta['rows_processed'] ?? 0),
            ];
        }

        // Set time budget (default 5 seconds)
        $time_budget_seconds = $time_budget ? ($time_budget / 1000) : 5;
        $start_time = microtime(true);

        try {
            $file_handle = fopen($job_meta['file_path'], 'a');
            if (!$file_handle) {
                throw new Exception('Failed to open export file for writing');
            }

            $last_table_processed = '';
            $last_batch_rows = 0;
            $bytes_written_this_session = 0;

            // Process tables
            while ($job_meta['current_table_index'] < count($job_meta['tables_list'])) {
                // Check time budget
                if ((microtime(true) - $start_time) > $time_budget_seconds) {
                    break;
                }

                $table_name = $job_meta['tables_list'][$job_meta['current_table_index']];
                $last_table_processed = $table_name;

                // If starting a new table, write CREATE TABLE statement
                if ($job_meta['current_table_offset'] === 0) {
                    $create_table = self::get_create_table_statement($table_name);
                    if ($create_table) {
                        $output = "\n-- Table structure for table `{$table_name}`\n";
                        $output .= "DROP TABLE IF EXISTS `{$table_name}`;\n";
                        $output .= $create_table . ";\n\n";
                        fwrite($file_handle, $output);
                        $bytes_written_this_session += strlen($output);
                    }
                }

                // Export table data in batches
                $batch_size = self::DEFAULT_BATCH_SIZE;
                $offset = $job_meta['current_table_offset'];

                // Get batch of rows
                $rows = $wpdb->get_results(
                    "SELECT * FROM `{$table_name}` LIMIT {$offset}, {$batch_size}",
                    ARRAY_A
                );

                if ($rows) {
                    $last_batch_rows = count($rows);

                    // Write INSERT statements
                    $output = "-- Dumping data for table `{$table_name}`\n";
                    $output .= "LOCK TABLES `{$table_name}` WRITE;\n";
                    $output .= "/*!40000 ALTER TABLE `{$table_name}` DISABLE KEYS */;\n";

                    // Build INSERT statement
                    $insert_values = [];
                    foreach ($rows as $row) {
                        $values = [];
                        foreach ($row as $value) {
                            if (is_null($value)) {
                                $values[] = 'NULL';
                                continue;
                            }

                            $escaped = $wpdb->_real_escape($value);
                            if (method_exists($wpdb, 'remove_placeholder_escape')) {
                                $escaped = $wpdb->remove_placeholder_escape($escaped);
                            } elseif (method_exists($wpdb, 'placeholder_escape')) {
                                $escaped = str_replace($wpdb->placeholder_escape(), '%', $escaped);
                            }

                            $values[] = "'" . $escaped . "'";
                        }
                        $insert_values[] = '(' . implode(',', $values) . ')';
                    }

                    if ($insert_values) {
                        $columns = array_keys($rows[0]);
                        $columns_list = '`' . implode('`,`', $columns) . '`';
                        $output .= "INSERT INTO `{$table_name}` ({$columns_list}) VALUES\n";
                        $output .= implode(",\n", $insert_values) . ";\n";
                    }

                    $output .= "/*!40000 ALTER TABLE `{$table_name}` ENABLE KEYS */;\n";
                    $output .= "UNLOCK TABLES;\n";

                    fwrite($file_handle, $output);
                    $bytes_written_this_session += strlen($output);

                    // Update offset for next batch
                    $job_meta['current_table_offset'] += $batch_size;
                    $job_meta['rows_processed'] = ($job_meta['rows_processed'] ?? 0) + $last_batch_rows;
                } else {
                    // Table complete, move to next table
                    $job_meta['completed_tables'][] = $table_name;
                    $job_meta['current_table_index']++;
                    $job_meta['current_table_offset'] = 0;
                    $last_batch_rows = 0;
                }
            }

            fclose($file_handle);

            // Update job metadata
            $job_meta['bytes_written'] += $bytes_written_this_session;

            // Check if all tables are processed
            $done = ($job_meta['current_table_index'] >= count($job_meta['tables_list']));

            if ($done) {
                // Write footer
                $footer = "\n/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;\n";
                $footer .= "/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n";
                $footer .= "/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;\n";
                $footer .= "/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;\n";
                $footer .= "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n";
                $footer .= "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n";
                $footer .= "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n";
                $footer .= "/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;\n";
                $footer .= "-- Export completed: " . date('Y-m-d H:i:s') . "\n";

                file_put_contents($job_meta['file_path'], $footer, FILE_APPEND);
                $job_meta['bytes_written'] += strlen($footer);
                $job_meta['state'] = 'completed';  // Mark as completed ONCE here
            }

            // Update transient
            set_transient('localpoc_db_job_' . $job_id, $job_meta, self::JOB_TTL);

            return [
                'job_id' => $job_id,
                'bytes_written' => $job_meta['bytes_written'],
                'completed_tables' => count($job_meta['completed_tables']),
                'last_table' => $last_table_processed,
                'last_batch_rows' => $last_batch_rows,
                'file_size' => filesize($job_meta['file_path']),
                'done' => $done,
                'warnings' => $job_meta['warnings'],
                'rows_processed' => (int) ($job_meta['rows_processed'] ?? 0),
            ];

        } catch (Exception $e) {
            // Mark job as failed to prevent stuck 'running' state
            $job_meta['warnings'][] = $e->getMessage();
            $job_meta['state'] = 'failed';
            set_transient('localpoc_db_job_' . $job_id, $job_meta, self::JOB_TTL);

            return new WP_Error(
                'localpoc_db_process_error',
                __('Error processing database export: ', 'localpoc') . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Get CREATE TABLE statement for a table
     *
     * @param string $table_name Table name
     * @return string|null CREATE TABLE statement or null on error
     */
    private static function get_create_table_statement($table_name) {
        global $wpdb;

        try {
            $result = $wpdb->get_row("SHOW CREATE TABLE `{$table_name}`", ARRAY_A);
            if ($result && isset($result['Create Table'])) {
                return $result['Create Table'];
            }
        } catch (Exception $e) {
            // Silently fail, table might not have CREATE permission
        }

        return null;
    }

    /**
     * Get download path for completed job
     *
     * @param string $job_id Job identifier
     * @return string|WP_Error File path or error
     */
    public static function get_download_path($job_id) {
        if (!$job_id) {
            return new WP_Error(
                'localpoc_invalid_job',
                __('Invalid job ID', 'localpoc'),
                ['status' => 400]
            );
        }

        $job_meta = get_transient('localpoc_db_job_' . $job_id);
        if (!$job_meta) {
            return new WP_Error(
                'localpoc_job_not_found',
                __('Job not found or expired', 'localpoc'),
                ['status' => 404]
            );
        }

        // Check if job is completed
        if (!isset($job_meta['state']) || $job_meta['state'] !== 'completed') {
            return new WP_Error(
                'localpoc_job_not_completed',
                __('Database export job is not yet completed', 'localpoc'),
                ['status' => 400]
            );
        }

        if (!file_exists($job_meta['file_path'])) {
            return new WP_Error(
                'localpoc_file_not_found',
                __('Export file not found', 'localpoc'),
                ['status' => 404]
            );
        }

        return $job_meta['file_path'];
    }

    /**
     * Clean up and finish a job
     *
     * @param string $job_id Job identifier
     * @return bool|WP_Error Success or error
     */
    public static function finish_job($job_id) {
        if (!$job_id) {
            return new WP_Error(
                'localpoc_invalid_job',
                __('Invalid job ID', 'localpoc'),
                ['status' => 400]
            );
        }

        $job_meta = get_transient('localpoc_db_job_' . $job_id);
        if (!$job_meta) {
            // Already cleaned up or expired
            return true;
        }

        // Delete temporary file
        if (isset($job_meta['file_path']) && file_exists($job_meta['file_path'])) {
            @unlink($job_meta['file_path']);
        }

        // Delete transient
        delete_transient('localpoc_db_job_' . $job_id);

        return true;
    }
}

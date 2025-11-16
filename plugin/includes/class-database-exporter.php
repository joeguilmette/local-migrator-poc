<?php
/**
 * Database export functionality
 *
 * @package LocalPOC
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles streaming SQL export of WordPress database
 */
class LocalPOC_Database_Exporter {

    /**
     * Gets database metadata (tables, row counts, sizes)
     *
     * @return array Database metadata
     */
    public static function get_db_meta_data() {
        global $wpdb;

        $status = $wpdb->get_results('SHOW TABLE STATUS', ARRAY_A);
        $tables = [];
        $total_rows = 0;
        $total_bytes = 0;

        foreach ((array) $status as $row) {
            $name = $row['Name'];
            $rows = (int) $row['Rows'];
            $bytes = (int) $row['Data_length'] + (int) $row['Index_length'];
            $tables[] = [
                'name'         => $name,
                'rows'         => $rows,
                'approx_bytes' => $bytes,
            ];
            $total_rows += $rows;
            $total_bytes += $bytes;
        }

        return [
            'tables'             => $tables,
            'total_rows'         => $total_rows,
            'total_approx_bytes' => $total_bytes,
        ];
    }

    /**
     * Outputs CREATE TABLE statements for a given table
     *
     * @param string $table_name The table name
     * @param wpdb   $wpdb       WordPress database object
     */
    public static function stream_table_structure($table_name, wpdb $wpdb) {
        $safe_table = self::quote_identifier($table_name);
        $create = $wpdb->get_row("SHOW CREATE TABLE {$safe_table}", ARRAY_N);

        echo "\n-- Table: {$table_name}\n";
        echo "DROP TABLE IF EXISTS {$safe_table};\n";

        if (is_array($create) && isset($create[1])) {
            echo $create[1] . ";\n\n";
        } else {
            error_log('localpoc: Failed to fetch CREATE TABLE for ' . $table_name);
            echo "-- Unable to fetch CREATE TABLE for {$table_name}\n\n";
        }
    }

    /**
     * Streams INSERT statements for all rows in the given table
     *
     * Uses cursor-based pagination for better performance on large tables.
     *
     * @param string $table_name The table name
     * @param wpdb   $wpdb       WordPress database object
     */
    public static function stream_table_rows($table_name, wpdb $wpdb) {
        $safe_table = self::quote_identifier($table_name);
        $chunk_size = 200;
        $cursor = self::get_table_cursor_info($table_name, $wpdb);
        $lastValue = null;
        $use_cursor = $cursor !== null;
        $offset = 0;

        do {
            if ($use_cursor) {
                if ($lastValue === null) {
                    $sql = $wpdb->prepare("SELECT * FROM {$safe_table} ORDER BY {$cursor['quoted']} ASC LIMIT %d", $chunk_size);
                } else {
                    if ($cursor['numeric']) {
                        $sql = $wpdb->prepare("SELECT * FROM {$safe_table} WHERE {$cursor['quoted']} > %d ORDER BY {$cursor['quoted']} ASC LIMIT %d", $lastValue, $chunk_size);
                    } else {
                        $sql = $wpdb->prepare("SELECT * FROM {$safe_table} WHERE {$cursor['quoted']} > %s ORDER BY {$cursor['quoted']} ASC LIMIT %d", $lastValue, $chunk_size);
                    }
                }
            } else {
                $sql = $wpdb->prepare("SELECT * FROM {$safe_table} LIMIT %d OFFSET %d", $chunk_size, $offset);
            }

            $rows = $wpdb->get_results($sql, ARRAY_A);

            if ($rows === null) {
                error_log('localpoc: Failed to select rows for ' . $table_name . ' - ' . $wpdb->last_error);
                echo "-- Error exporting rows for {$table_name}\n";
                break;
            }

            if (empty($rows)) {
                break;
            }

            $batch = [];
            foreach ($rows as $row) {
                $values = [];
                foreach ($row as $value) {
                    $values[] = self::escape_sql_value($value);
                }

                $batch[] = '(' . implode(', ', $values) . ')';

                if (count($batch) >= 50) {
                    echo 'INSERT INTO ' . $safe_table . ' VALUES ' . implode(",\n", $batch) . ";\n";
                    $batch = [];
                }
            }

            if (!empty($batch)) {
                echo 'INSERT INTO ' . $safe_table . ' VALUES ' . implode(",\n", $batch) . ";\n";
            }

            if ($use_cursor) {
                $last = end($rows);
                $lastValue = $last[$cursor['column']];
            } else {
                $offset += count($rows);
            }

            if (function_exists('ob_get_level') && ob_get_level() > 0) {
                @ob_flush();
            }
            flush();
        } while (count($rows) === $chunk_size);
    }

    /**
     * Gets cursor information for efficient pagination
     *
     * Detects primary key or auto_increment column for indexed cursor pagination.
     *
     * @param string $table_name The table name
     * @param wpdb   $wpdb       WordPress database object
     * @return array|null Cursor info or null if not available
     */
    public static function get_table_cursor_info($table_name, wpdb $wpdb) {
        $safe_table = self::quote_identifier($table_name);
        $primary = $wpdb->get_results("SHOW KEYS FROM {$safe_table} WHERE Key_name = 'PRIMARY'", ARRAY_A);
        $column = '';
        if (!empty($primary)) {
            $unique_columns = array_unique(array_column($primary, 'Column_name'));
            if (count($unique_columns) === 1) {
                $column = $unique_columns[0];
            }
        }

        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$safe_table}", ARRAY_A);
        if (empty($column) && is_array($columns)) {
            foreach ($columns as $col_info) {
                if (isset($col_info['Extra']) && stripos($col_info['Extra'], 'auto_increment') !== false) {
                    $column = $col_info['Field'];
                    break;
                }
            }
        }

        if (empty($column)) {
            return null;
        }

        $type = '';
        if (is_array($columns)) {
            foreach ($columns as $col_info) {
                if ($col_info['Field'] === $column) {
                    $type = strtolower($col_info['Type']);
                    break;
                }
            }
        }

        return [
            'column' => $column,
            'quoted' => self::quote_identifier($column),
            'numeric' => (bool) preg_match('/int|decimal|float|double|bit/', $type),
        ];
    }

    /**
     * Escapes SQL values for direct output
     *
     * @param mixed $value The value to escape
     * @return string Escaped SQL value
     */
    public static function escape_sql_value($value) {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $escaped = addslashes((string) $value);
        $escaped = str_replace(["\r", "\n"], ['\\r', '\\n'], $escaped);

        return "'{$escaped}'";
    }

    /**
     * Quotes a table/column name for SQL output
     *
     * @param string $identifier The identifier to quote
     * @return string Quoted identifier
     */
    public static function quote_identifier($identifier) {
        $safe = str_replace('`', '``', $identifier);
        return "`{$safe}`";
    }
}

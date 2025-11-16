<?php
/**
 * Plugin Name: Local Site Downloader POC
 * Plugin URI: https://example.com/localpoc
 * Description: Exposes an API for downloading WordPress sites via a local CLI utility.
 * Version: 0.1.0
 * Author: Your Name
 * Author URI: https://example.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: localpoc
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main plugin class
 */
class LocalPOC_Plugin {

    /**
     * Option name for storing the access key
     */
    const OPTION_ACCESS_KEY = 'localpoc_access_key';

    /** Initializes plugin hooks. */
    public static function init() {
        // Generate access key on first run
        add_action('plugins_loaded', [__CLASS__, 'ensure_access_key']);

        // Register admin menu
        add_action('admin_menu', [__CLASS__, 'register_admin_menu']);

        // Register REST API routes
        add_action('rest_api_init', 'localpoc_register_rest_routes');
    }

    /** Ensures an access key exists for the site. */
    public static function ensure_access_key() {
        if (!get_option(self::OPTION_ACCESS_KEY)) {
            $access_key = wp_generate_password(32, false);
            update_option(self::OPTION_ACCESS_KEY, $access_key);
        }
    }

    /** Retrieves the stored access key. */
    public static function get_access_key() {
        return (string) get_option(self::OPTION_ACCESS_KEY, '');
    }

    /** Registers the admin settings page. */
    public static function register_admin_menu() {
        add_menu_page(
            'Local Site Downloader',           // Page title
            'Site Downloader',                 // Menu title
            'manage_options',                  // Capability
            'localpoc-downloader',             // Menu slug
            [__CLASS__, 'render_admin_page'],  // Callback
            'dashicons-download',              // Icon
            80                                 // Position
        );
    }

    /** Renders the admin settings page. */
    public static function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $access_key = self::get_access_key();
        $site_url = site_url();
        $cli_command = sprintf(
            'php localpoc.php download --url="%s" --key="%s" --output="./local-backup"',
            esc_attr($site_url),
            esc_attr($access_key)
        );

        ?>
        <div class="wrap">
            <h1>Local Site Downloader POC</h1>

            <div style="max-width: 800px;">
                <h2>Connection Details</h2>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="site-url">Site URL</label>
                        </th>
                        <td>
                            <input
                                type="text"
                                id="site-url"
                                class="regular-text"
                                value="<?php echo esc_attr($site_url); ?>"
                                readonly
                            />
                            <p class="description">The base URL of this WordPress site.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="access-key">Access Key</label>
                        </th>
                        <td>
                            <input
                                type="text"
                                id="access-key"
                                class="regular-text"
                                value="<?php echo esc_attr($access_key); ?>"
                                readonly
                            />
                            <p class="description">Your unique access key for CLI authentication.</p>
                        </td>
                    </tr>
                </table>

                <h2>CLI Command</h2>
                <p>Copy and run this command on your local machine (requires CLI tool installed):</p>

                <div style="background: #f5f5f5; padding: 15px; border-left: 4px solid #2271b1; margin: 20px 0;">
                    <code style="display: block; word-wrap: break-word; white-space: pre-wrap;"><?php echo esc_html($cli_command); ?></code>
                </div>

                <p>
                    <button
                        type="button"
                        class="button button-secondary"
                        onclick="navigator.clipboard.writeText('<?php echo esc_js($cli_command); ?>').then(() => alert('Command copied to clipboard!'))"
                    >
                        Copy Command
                    </button>
                </p>

                <hr style="margin: 30px 0;" />

                <h2>Next Steps</h2>
                <ol>
                    <li>Install the Local Site Downloader CLI tool on your local machine</li>
                    <li>Copy the command above</li>
                    <li>Run it in your terminal to download this WordPress site</li>
                </ol>
            </div>
        </div>
        <?php
    }
}

/** Returns the stored access key globally. */
function localpoc_get_access_key() {
    return LocalPOC_Plugin::get_access_key();
}

/** Validates the X-Localpoc-Key header for REST requests. */
function localpoc_require_valid_key(WP_REST_Request $request) {
    $provided_key = $request->get_header('x-localpoc-key');
    $expected_key = localpoc_get_access_key();

    if (empty($provided_key) || !hash_equals($expected_key, $provided_key)) {
        return new WP_Error(
            'localpoc_forbidden',
            __('Invalid access key.', 'localpoc'),
            ['status' => 403]
        );
    }

    return true;
}

/** Registers the REST API endpoints for the plugin. */
function localpoc_register_rest_routes() {
    register_rest_route(
        'localpoc/v1',
        '/files-manifest',
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'localpoc_rest_files_manifest',
            'permission_callback' => '__return_true',
        ]
    );

    register_rest_route(
        'localpoc/v1',
        '/file',
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'localpoc_rest_file',
            'permission_callback' => '__return_true',
        ]
    );

    register_rest_route(
        'localpoc/v1',
        '/db-stream',
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'localpoc_rest_db_stream',
            'permission_callback' => '__return_true',
        ]
    );
}

/** Returns a recursive file manifest for the WordPress root. */
function localpoc_rest_files_manifest(WP_REST_Request $request) {
    $auth_result = localpoc_require_valid_key($request);
    if ($auth_result instanceof WP_Error) {
        return $auth_result;
    }

    $root_path = function_exists('untrailingslashit') ? untrailingslashit(ABSPATH) : rtrim(ABSPATH, '/\\');
    $files = [];

    try {
        $directory_iterator = new RecursiveDirectoryIterator(
            $root_path,
            FilesystemIterator::SKIP_DOTS
        );

        $filtered_iterator = new RecursiveCallbackFilterIterator(
            $directory_iterator,
            function ($current) {
                if ($current->isDir()) {
                    return !in_array($current->getFilename(), ['.git', '.svn', '.hg'], true);
                }

                return true;
            }
        );

        $iterator = new RecursiveIteratorIterator(
            $filtered_iterator,
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file_info) {
            if (!$file_info->isFile() || !$file_info->isReadable()) {
                continue;
            }

            $full_path = $file_info->getPathname();
            $relative = substr($full_path, strlen($root_path));
            $relative = ltrim(str_replace('\\', '/', $relative), '/');

            if ($relative === '') {
                continue;
            }

            $files[] = [
                'path'  => $relative,
                'size'  => (int) $file_info->getSize(),
                'mtime' => (int) $file_info->getMTime(),
            ];
        }
    } catch (UnexpectedValueException $e) {
        return new WP_Error(
            'localpoc_manifest_error',
            __('Unable to build file manifest.', 'localpoc'),
            ['status' => 500]
        );
    }

    return rest_ensure_response([
        'root'  => ABSPATH,
        'files' => $files,
    ]);
}

/** Streams an individual file from the site filesystem. */
function localpoc_rest_file(WP_REST_Request $request) {
    $auth_result = localpoc_require_valid_key($request);
    if ($auth_result instanceof WP_Error) {
        return $auth_result;
    }

    $relative_path = $request->get_param('path');
    if (!is_string($relative_path) || $relative_path === '') {
        return new WP_Error(
            'localpoc_invalid_path',
            __('File path is required.', 'localpoc'),
            ['status' => 400]
        );
    }

    $relative_path = ltrim(str_replace('\\', '/', $relative_path), '/');
    if ($relative_path === '') {
        return new WP_Error(
            'localpoc_invalid_path',
            __('Invalid file path.', 'localpoc'),
            ['status' => 400]
        );
    }

    $root_realpath = realpath(ABSPATH);
    if ($root_realpath === false) {
        return new WP_Error(
            'localpoc_root_unavailable',
            __('Unable to determine site root.', 'localpoc'),
            ['status' => 500]
        );
    }

    $full_path = $root_realpath . DIRECTORY_SEPARATOR . $relative_path;
    $resolved_path = realpath($full_path);

    if ($resolved_path === false || !is_file($resolved_path)) {
        return new WP_Error(
            'localpoc_file_not_found',
            __('Requested file not found.', 'localpoc'),
            ['status' => 404]
        );
    }

    if (function_exists('wp_normalize_path')) {
        $normalized_root = wp_normalize_path($root_realpath);
        $normalized_resolved = wp_normalize_path($resolved_path);
    } else {
        $normalized_root = str_replace('\\', '/', $root_realpath);
        $normalized_resolved = str_replace('\\', '/', $resolved_path);
    }

    if (function_exists('trailingslashit')) {
        $normalized_root = trailingslashit($normalized_root);
    } else {
        $normalized_root = rtrim($normalized_root, '/') . '/';
    }

    if (strpos($normalized_resolved . '/', $normalized_root) !== 0) {
        return new WP_Error(
            'localpoc_invalid_path',
            __('Invalid file path.', 'localpoc'),
            ['status' => 400]
        );
    }

    if (!is_readable($resolved_path)) {
        return new WP_Error(
            'localpoc_unreadable_file',
            __('File is not readable.', 'localpoc'),
            ['status' => 403]
        );
    }

    $handle = fopen($resolved_path, 'rb');
    if (!$handle) {
        return new WP_Error(
            'localpoc_stream_error',
            __('Unable to open file for reading.', 'localpoc'),
            ['status' => 500]
        );
    }

    $filesize = filesize($resolved_path);
    $basename = basename($resolved_path);

    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }

    if (function_exists('nocache_headers')) {
        nocache_headers();
    }

    if (!headers_sent()) {
        header('Content-Type: application/octet-stream');
        if ($filesize !== false) {
            header('Content-Length: ' . $filesize);
        }
        header('Content-Disposition: attachment; filename="' . addslashes($basename) . '"');
    }

    while (!feof($handle)) {
        $buffer = fread($handle, 65536);
        if ($buffer === false) {
            break;
        }

        echo $buffer;

        if (function_exists('ob_get_level') && ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
    }

    fclose($handle);
    exit;

    // Fallback return for completeness; normally unreachable due to exit above.
    return rest_ensure_response([
        'message' => 'File stream completed.',
    ]);
}

/** Streams a lightweight SQL export of the WordPress database. */
function localpoc_rest_db_stream(WP_REST_Request $request) {
    $auth_result = localpoc_require_valid_key($request);
    if ($auth_result instanceof WP_Error) {
        return $auth_result;
    }

    global $wpdb;

    if (!$wpdb instanceof wpdb) {
        return new WP_Error(
            'localpoc_db_unavailable',
            __('Database connection unavailable.', 'localpoc'),
            ['status' => 500]
        );
    }

    $tables = $wpdb->get_col('SHOW TABLES');
    if (!is_array($tables)) {
        return new WP_Error(
            'localpoc_db_list_failed',
            __('Unable to list database tables.', 'localpoc'),
            ['status' => 500]
        );
    }

    if (function_exists('nocache_headers')) {
        nocache_headers();
    }

    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }

    if (!headers_sent()) {
        header('Content-Type: application/sql; charset=' . get_option('blog_charset'));
        header('Content-Disposition: attachment; filename="localpoc-db.sql"');
    }

    echo "-- LocalPOC database export generated at " . current_time('mysql') . "\n\n";

    foreach ($tables as $table_name) {
        localpoc_stream_table_structure($table_name, $wpdb);
        localpoc_stream_table_rows($table_name, $wpdb);
    }

    exit;
}

/** Outputs CREATE TABLE statements for a given table. */
function localpoc_stream_table_structure($table_name, wpdb $wpdb) {
    $safe_table = localpoc_quote_identifier($table_name);
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

/** Streams INSERT statements for all rows in the given table. */
function localpoc_stream_table_rows($table_name, wpdb $wpdb) {
    $safe_table = localpoc_quote_identifier($table_name);
    $chunk_size = 200;
    $offset = 0;

    do {
        $query = sprintf('SELECT * FROM %s LIMIT %d OFFSET %d', $safe_table, $chunk_size, $offset);
        $rows = $wpdb->get_results($query, ARRAY_A);

        if ($rows === null) {
            error_log('localpoc: Failed to select rows for ' . $table_name . ' - ' . $wpdb->last_error);
            echo "-- Error exporting rows for {$table_name}\n";
            break;
        }

        if (empty($rows)) {
            break;
        }

        foreach ($rows as $row) {
            $values = [];
            foreach ($row as $value) {
                $values[] = localpoc_escape_sql_value($value);
            }

            echo 'INSERT INTO ' . $safe_table . ' VALUES (' . implode(', ', $values) . ");\n";
        }

        $offset += count($rows);

        if (function_exists('ob_get_level') && ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
    } while (count($rows) === $chunk_size);
}

/** Escapes SQL values for direct output. */
function localpoc_escape_sql_value($value) {
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

/** Quotes a table name for SQL output. */
function localpoc_quote_identifier($identifier) {
    $safe = str_replace('`', '``', $identifier);
    return "`{$safe}`";
}

// Initialize the plugin
LocalPOC_Plugin::init();

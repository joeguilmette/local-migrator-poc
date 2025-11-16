<?php
/**
 * Main plugin initialization and admin UI
 *
 * @package LocalPOC
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main plugin class - handles initialization and admin UI
 */
class LocalPOC_Plugin {

    /**
     * Option name for storing the access key
     */
    const OPTION_ACCESS_KEY = 'localpoc_access_key';

    /**
     * Initializes plugin hooks
     */
    public static function init() {
        // Generate access key on first run
        add_action('plugins_loaded', [__CLASS__, 'ensure_access_key']);

        // Register admin menu
        add_action('admin_menu', [__CLASS__, 'register_admin_menu']);

        // Handle activation redirect
        add_action('admin_init', [__CLASS__, 'activation_redirect']);

        // Register AJAX endpoints (logged-in + unauthenticated)
        add_action('wp_ajax_localpoc_files_manifest', [LocalPOC_Ajax_Handlers::class, 'files_manifest']);
        add_action('wp_ajax_nopriv_localpoc_files_manifest', [LocalPOC_Ajax_Handlers::class, 'files_manifest']);

        add_action('wp_ajax_localpoc_file', [LocalPOC_Ajax_Handlers::class, 'file']);
        add_action('wp_ajax_nopriv_localpoc_file', [LocalPOC_Ajax_Handlers::class, 'file']);

        add_action('wp_ajax_localpoc_files_batch_zip', [LocalPOC_Ajax_Handlers::class, 'files_batch_zip']);
        add_action('wp_ajax_nopriv_localpoc_files_batch_zip', [LocalPOC_Ajax_Handlers::class, 'files_batch_zip']);

        add_action('wp_ajax_localpoc_db_meta', [LocalPOC_Ajax_Handlers::class, 'db_meta']);
        add_action('wp_ajax_nopriv_localpoc_db_meta', [LocalPOC_Ajax_Handlers::class, 'db_meta']);

        add_action('wp_ajax_localpoc_job_init', [LocalPOC_Ajax_Handlers::class, 'job_init']);
        add_action('wp_ajax_nopriv_localpoc_job_init', [LocalPOC_Ajax_Handlers::class, 'job_init']);

        add_action('wp_ajax_localpoc_job_finish', [LocalPOC_Ajax_Handlers::class, 'job_finish']);
        add_action('wp_ajax_nopriv_localpoc_job_finish', [LocalPOC_Ajax_Handlers::class, 'job_finish']);

        // Database job endpoints
        add_action('wp_ajax_localpoc_db_job_init', [LocalPOC_Ajax_Handlers::class, 'db_job_init']);
        add_action('wp_ajax_nopriv_localpoc_db_job_init', [LocalPOC_Ajax_Handlers::class, 'db_job_init']);

        add_action('wp_ajax_localpoc_db_job_process', [LocalPOC_Ajax_Handlers::class, 'db_job_process']);
        add_action('wp_ajax_nopriv_localpoc_db_job_process', [LocalPOC_Ajax_Handlers::class, 'db_job_process']);

        add_action('wp_ajax_localpoc_db_job_download', [LocalPOC_Ajax_Handlers::class, 'db_job_download']);
        add_action('wp_ajax_nopriv_localpoc_db_job_download', [LocalPOC_Ajax_Handlers::class, 'db_job_download']);

        add_action('wp_ajax_localpoc_db_job_finish', [LocalPOC_Ajax_Handlers::class, 'db_job_finish']);
        add_action('wp_ajax_nopriv_localpoc_db_job_finish', [LocalPOC_Ajax_Handlers::class, 'db_job_finish']);

    }

    /**
     * Ensures an access key exists for the site
     */
    public static function ensure_access_key() {
        if (!get_option(self::OPTION_ACCESS_KEY)) {
            $access_key = wp_generate_password(32, false);
            update_option(self::OPTION_ACCESS_KEY, $access_key);
        }
    }

    /**
     * Called on plugin activation
     */
    public static function on_activation() {
        // Set transient to trigger redirect
        set_transient('localpoc_activation_redirect', true, 30);
    }

    /**
     * Redirects to plugin admin page after activation
     */
    public static function activation_redirect() {
        // Check if we should redirect
        if (!get_transient('localpoc_activation_redirect')) {
            return;
        }

        // Delete the transient
        delete_transient('localpoc_activation_redirect');

        // Don't redirect if activating multiple plugins
        if (isset($_GET['activate-multi'])) {
            return;
        }

        // Redirect to plugin admin page
        wp_safe_redirect(admin_url('admin.php?page=localpoc-downloader'));
        exit;
    }

    /**
     * Retrieves the stored access key
     *
     * @return string The access key
     */
    public static function get_access_key() {
        return (string) get_option(self::OPTION_ACCESS_KEY, '');
    }

    /**
     * Registers the admin settings page
     */
    public static function register_admin_menu() {
        add_menu_page(
            'Local Migrator',                  // Page title
            'Local Migrator',                  // Menu title
            'manage_options',                  // Capability
            'localpoc-downloader',             // Menu slug
            [__CLASS__, 'render_admin_page'],  // Callback
            'dashicons-download',              // Icon
            80                                 // Position
        );
    }

    /**
     * Renders the admin settings page
     */
    public static function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $access_key = self::get_access_key();
        $site_url = site_url();
        $cli_command = sprintf(
            'localpoc download --url="%s" --key="%s" --output="./local-backup"',
            esc_attr($site_url),
            esc_attr($access_key)
        );

        ?>
        <div class="wrap">
            <h1>Local Migrator</h1>

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

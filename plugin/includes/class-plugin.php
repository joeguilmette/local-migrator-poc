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

        // Enqueue admin scripts
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets']);

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
     * Enqueues admin scripts and styles
     *
     * @param string $hook The current admin page hook
     */
    public static function enqueue_admin_assets($hook) {
        // Only load on our settings page
        if (!isset($_GET['page']) || $_GET['page'] !== 'localpoc-downloader') {
            return;
        }

        $plugin_main_file = LOCALPOC_PLUGIN_DIR . 'local-migrator.php';

        wp_enqueue_style(
            'localpoc-admin',
            plugins_url('assets/css/localpoc-admin.css', $plugin_main_file),
            [],
            LOCALPOC_VERSION
        );

        wp_enqueue_script(
            'localpoc-admin',
            plugins_url('assets/js/localpoc-admin.js', $plugin_main_file),
            [],
            LOCALPOC_VERSION,
            true
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
        ?>
        <div class="wrap localpoc-admin">
            <h1><?php echo esc_html__('Local Migrator', 'localpoc'); ?></h1>

            <div class="card">
                <h2 class="title"><?php echo esc_html__('Connection Details', 'localpoc'); ?></h2>
                <div class="inside">
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php echo esc_html__('Site URL', 'localpoc'); ?></th>
                            <td>
                                <code><?php echo esc_html($site_url); ?></code>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Access Key', 'localpoc'); ?></th>
                            <td>
                                <code><?php echo esc_html($access_key); ?></code>
                                <p class="description">
                                    <?php echo esc_html__('This key authorizes the CLI tool to download your site.', 'localpoc'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="card">
                <h2 class="title"><?php echo esc_html__('CLI Command', 'localpoc'); ?></h2>
                <div class="inside">
                    <p><?php echo esc_html__('Run this command to download your WordPress site:', 'localpoc'); ?></p>

                    <pre id="localpoc-cli-command">lm download --url="<?php echo esc_attr($site_url); ?>" --key="<?php echo esc_attr($access_key); ?>" --output="./local-backup"</pre>

                    <button type="button" id="localpoc-copy-command" class="button button-primary">
                        <?php echo esc_html__('Copy Download Command', 'localpoc'); ?>
                    </button>
                </div>
            </div>

            <div class="card">
                <h2 class="title"><?php echo esc_html__('Next Steps', 'localpoc'); ?></h2>
                <div class="inside">
                    <p><?php echo esc_html__('To use the download command above, first install the Local Migrator CLI:', 'localpoc'); ?></p>

                    <pre id="localpoc-install-cmd">curl -L https://github.com/joeguilmette/local-migrator-poc/releases/latest/download/local-migrator.phar -o /tmp/local-migrator && chmod +x /tmp/local-migrator && sudo mv /tmp/local-migrator /usr/local/bin/lm</pre>

                    <button type="button" id="localpoc-copy-install" class="button">
                        <?php echo esc_html__('Copy Install Command', 'localpoc'); ?>
                    </button>

                    <ol>
                        <li><?php echo esc_html__('Copy and run the install command above', 'localpoc'); ?></li>
                        <li><?php echo esc_html__('Verify with: lm --version', 'localpoc'); ?></li>
                        <li><?php echo esc_html__('Run the download command to get your site', 'localpoc'); ?></li>
                    </ol>
                </div>
            </div>
        </div>
        <?php
    }
}

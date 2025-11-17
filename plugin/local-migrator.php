<?php
/**
 * Plugin Name: Local Migrator
 * Plugin URI: https://example.com/local-migrator
 * Description: Exposes an API for downloading WordPress sites via a local CLI utility.
 * Version: 0.0.26
 * Author: Joe Guilmette
 * Author URI: https://example.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: localpoc
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('LOCALPOC_VERSION', '0.0.26');
define('LOCALPOC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LOCALPOC_PLUGIN_URL', plugin_dir_url(__FILE__));

// Load all includes
require_once LOCALPOC_PLUGIN_DIR . 'includes/class-auth.php';
require_once LOCALPOC_PLUGIN_DIR . 'includes/class-file-scanner.php';
require_once LOCALPOC_PLUGIN_DIR . 'includes/class-path-resolver.php';
require_once LOCALPOC_PLUGIN_DIR . 'includes/class-database-job-manager.php';
require_once LOCALPOC_PLUGIN_DIR . 'includes/class-request-handler.php';
require_once LOCALPOC_PLUGIN_DIR . 'includes/class-manifest-manager.php';
require_once LOCALPOC_PLUGIN_DIR . 'includes/class-batch-processor.php';
require_once LOCALPOC_PLUGIN_DIR . 'includes/class-ajax-handlers.php';
require_once LOCALPOC_PLUGIN_DIR . 'includes/class-plugin.php';

// Register activation hook
register_activation_hook(__FILE__, [LocalPOC_Plugin::class, 'on_activation']);

// Initialize the plugin
LocalPOC_Plugin::init();

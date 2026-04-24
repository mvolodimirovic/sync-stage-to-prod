<?php
/**
 * Plugin Name: Sync Stage To Prod
 * Plugin URI: https://medical.ensuria.ua/
 * Description: Synchronizing content from staging to production via REST API
 * Version: 0.2.3
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Ensuria
 * Author URI: https://medical.ensuria.ua/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: sync-stage-to-prod
 * Domain Path: /languages
 *
 * @package Sync_Stage_To_Prod
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin version
define('SYNC_STP_VERSION', '0.2.3');

// Plugin slug for menu and options
define('SYNC_STP_SLUG', 'sync-stage-to-prod');

// Options key in database
define('SYNC_STP_OPT_KEY', 'sync_stp_options');

// Required capability for plugin access
define('SYNC_STP_CAP', 'manage_options');

// Plugin directory path
define('SYNC_STP_PATH', plugin_dir_path(__FILE__));

// Plugin directory URL
define('SYNC_STP_URL', plugin_dir_url(__FILE__));

// Load required classes
require_once SYNC_STP_PATH . 'includes/class-sync-validator.php';
require_once SYNC_STP_PATH . 'includes/class-sync-media.php';
require_once SYNC_STP_PATH . 'includes/class-sync-service.php';
require_once SYNC_STP_PATH . 'includes/class-sync-admin.php';
require_once SYNC_STP_PATH . 'includes/class-sync-rest.php';
require_once SYNC_STP_PATH . 'includes/class-sync-cli.php';
require_once SYNC_STP_PATH . 'includes/class-sync-post-metabox.php';

/**
 * Initialize plugin functionality after all plugins are loaded
 */
add_action('plugins_loaded', function () {
    Sync_Admin::init();
    Sync_Rest::init();
    Sync_Post_Metabox::init();
});

/**
 * Register WP-CLI command if available
 */
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('sync-stage-to-prod', 'Sync_CLI');
}
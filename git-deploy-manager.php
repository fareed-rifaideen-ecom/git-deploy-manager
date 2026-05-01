<?php
/**
 * Plugin Name: Git Deploy Manager
 * Plugin URI: https://github.com/fareed-rifaideen-ecom/git-deploy-manager
 * Description: A robust deployment manager enabling automated, zero-downtime updates from GitHub repositories to WordPress plugins and themes.
 * Version: 1.9.0
 * Author: Fareed M. Rifaideen
 * License: MIT
 */

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

define('GDM_VERSION', '0.1.0');
define('GDM_PLUGIN_FILE', __FILE__);
define('GDM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GDM_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once GDM_PLUGIN_DIR . 'includes/class-gdm-activator.php';
require_once GDM_PLUGIN_DIR . 'includes/class-gdm-deactivator.php';
require_once GDM_PLUGIN_DIR . 'includes/class-gdm-loader.php';
require_once GDM_PLUGIN_DIR . 'includes/class-gdm-security.php';
require_once GDM_PLUGIN_DIR . 'includes/class-gdm-settings.php';
require_once GDM_PLUGIN_DIR . 'includes/class-gdm-package-repository.php';
require_once GDM_PLUGIN_DIR . 'includes/class-gdm-logger.php';
require_once GDM_PLUGIN_DIR . 'includes/class-gdm-github-provider.php';
require_once GDM_PLUGIN_DIR . 'includes/class-gdm-deployment-service.php';
require_once GDM_PLUGIN_DIR . 'includes/class-gdm-webhook-controller.php';
require_once GDM_PLUGIN_DIR . 'admin/class-gdm-admin.php';
require_once GDM_PLUGIN_DIR . 'includes/class-gdm-plugin.php';

register_activation_hook(__FILE__, ['GDM_Activator', 'activate']);
register_deactivation_hook(__FILE__, ['GDM_Deactivator', 'deactivate']);

function gdm_run_plugin() {
    $plugin = new GDM_Plugin();
    $plugin->run();
}

gdm_run_plugin();

<?php
if (!defined('ABSPATH')) {
    exit;
}

class GDM_Activator {
    public static function activate() {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        // Keep default settings
        if (!get_option('gdm_settings')) {
            add_option('gdm_settings', [
                'github_token'   => '',
                'webhook_secret' => wp_generate_password(32, false, false),
            ]);
        }

        self::create_custom_tables();
        self::migrate_existing_data();
    }

    private static function create_custom_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $table_packages = $wpdb->prefix . 'gdm_packages';
        // Added subdirectory column below
        $sql_packages = "CREATE TABLE $table_packages (
            id varchar(36) NOT NULL,
            name varchar(255) NOT NULL,
            package_type varchar(20) DEFAULT 'plugin' NOT NULL,
            repo_owner varchar(255) NOT NULL,
            repo_name varchar(255) NOT NULL,
            branch varchar(255) DEFAULT 'main' NOT NULL,
            subdirectory varchar(255) DEFAULT '' NOT NULL,
            plugin_slug varchar(255) NOT NULL,
            plugin_file varchar(255) NOT NULL,
            enabled tinyint(1) DEFAULT 0 NOT NULL,
            auto_deploy tinyint(1) DEFAULT 0 NOT NULL,
            last_commit varchar(40) DEFAULT '' NOT NULL,
            last_deployed_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        $table_logs = $wpdb->prefix . 'gdm_logs';
        $sql_logs = "CREATE TABLE $table_logs (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            level varchar(20) DEFAULT 'info' NOT NULL,
            message text NOT NULL,
            context longtext NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_packages);
        dbDelta($sql_logs);
    }

    private static function migrate_existing_data() {
        global $wpdb;

        // Migrate Packages
        $old_packages = get_option('gdm_packages');
        if (is_array($old_packages) && !empty($old_packages)) {
            $table_packages = $wpdb->prefix . 'gdm_packages';
            
            foreach ($old_packages as $package) {
                $wpdb->replace(
                    $table_packages,
                    [
                        'id'               => $package['id'],
                        'name'             => $package['name'] ?? '',
                        'package_type'     => $package['package_type'] ?? 'plugin', 
                        'repo_owner'       => $package['repo_owner'] ?? '',
                        'repo_name'        => $package['repo_name'] ?? '',
                        'branch'           => $package['branch'] ?? 'main',
                        'subdirectory'     => $package['subdirectory'] ?? '',
                        'plugin_slug'      => $package['plugin_slug'] ?? '',
                        'plugin_file'      => $package['plugin_file'] ?? '',
                        'enabled'          => !empty($package['enabled']) ? 1 : 0,
                        'auto_deploy'      => !empty($package['auto_deploy']) ? 1 : 0,
                        'last_commit'      => $package['last_commit'] ?? '',
                        'last_deployed_at' => !empty($package['last_deployed_at']) ? $package['last_deployed_at'] : '0000-00-00 00:00:00',
                    ],
                    ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s']
                );
            }
            
            // Delete the old option so we do not migrate twice
            delete_option('gdm_packages');
        }

        // Migrate Logs
        $old_logs = get_option('gdm_logs');
        if (is_array($old_logs) && !empty($old_logs)) {
            $table_logs = $wpdb->prefix . 'gdm_logs';
            
            // Reverse the array to insert oldest logs first
            $old_logs = array_reverse($old_logs);
            
            foreach ($old_logs as $log) {
                $wpdb->insert(
                    $table_logs,
                    [
                        'time'    => !empty($log['time']) ? $log['time'] : current_time('mysql'),
                        'level'   => $log['level'] ?? 'info',
                        'message' => $log['message'] ?? '',
                        'context' => is_array($log['context']) ? wp_json_encode($log['context']) : '{}',
                    ],
                    ['%s', '%s', '%s', '%s']
                );
            }
            
            delete_option('gdm_logs');
        }
    }
}

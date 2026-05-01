<?php
if (!defined('ABSPATH')) {
    exit;
}

class GDM_Package_Repository {
    
    private function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'gdm_packages';
    }

    public function all(): array {
        global $wpdb;
        $table = $this->get_table_name();
        
        // Suppress errors temporarily in case the table does not exist yet during activation
        $suppress = $wpdb->suppress_errors(true);
        $results = $wpdb->get_results("SELECT * FROM $table", ARRAY_A);
        $wpdb->suppress_errors($suppress);

        if (!$results || !is_array($results)) {
            return [];
        }

        $packages = [];
        foreach ($results as $row) {
            $packages[$row['id']] = $row;
        }

        return $packages;
    }

    public function find(string $id): ?array {
        $id = sanitize_key($id);

        if ($id === '') {
            return null;
        }

        global $wpdb;
        $table = $this->get_table_name();
        
        $query = $wpdb->prepare("SELECT * FROM $table WHERE id = %s", $id);
        
        $suppress = $wpdb->suppress_errors(true);
        $row = $wpdb->get_row($query, ARRAY_A);
        $wpdb->suppress_errors($suppress);

        return $row ?: null;
    }

    public function save(array $package): string {
        global $wpdb;
        $table = $this->get_table_name();

        $id = '';
        if (!empty($package['id']) && is_scalar($package['id'])) {
            $id = sanitize_key((string) $package['id']);
        }

        if ($id === '') {
            $id = wp_generate_uuid4();
        }

        $existing = $this->find($id) ?: [];

        $data = [
            'id'               => $id,
            'name'             => sanitize_text_field($package['name'] ?? ($existing['name'] ?? '')),
            'package_type'     => sanitize_text_field($package['package_type'] ?? ($existing['package_type'] ?? 'plugin')),
            'repo_owner'       => sanitize_text_field($package['repo_owner'] ?? ($existing['repo_owner'] ?? '')),
            'repo_name'        => sanitize_text_field($package['repo_name'] ?? ($existing['repo_name'] ?? '')),
            'branch'           => sanitize_text_field($package['branch'] ?? ($existing['branch'] ?? 'main')),
            'subdirectory'     => sanitize_text_field($package['subdirectory'] ?? ($existing['subdirectory'] ?? '')),
            'plugin_slug'      => sanitize_title($package['plugin_slug'] ?? ($existing['plugin_slug'] ?? '')),
            'plugin_file'      => sanitize_text_field($package['plugin_file'] ?? ($existing['plugin_file'] ?? '')),
            'enabled'          => !empty($package['enabled']) ? 1 : 0,
            'auto_deploy'      => !empty($package['auto_deploy']) ? 1 : 0,
            'last_commit'      => sanitize_text_field($package['last_commit'] ?? ($existing['last_commit'] ?? '')),
            'last_deployed_at' => sanitize_text_field($package['last_deployed_at'] ?? ($existing['last_deployed_at'] ?? '0000-00-00 00:00:00')),
        ];

        // Added an extra '%s' for subdirectory
        $format = ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s'];

        $wpdb->replace($table, $data, $format);

        return $id;
    }

    public function delete(string $id): void {
        $id = sanitize_key($id);

        if ($id === '') {
            return;
        }

        global $wpdb;
        $table = $this->get_table_name();
        
        $wpdb->delete($table, ['id' => $id], ['%s']);
    }
}

<?php
if (!defined('ABSPATH')) {
    exit;
}

class GDM_Logger {
    
    private function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'gdm_logs';
    }

    public function add(string $level, string $message, array $context = []): void {
        global $wpdb;
        $table = $this->get_table_name();

        $allowed_levels = ['info', 'warning', 'error', 'debug'];
        $level = sanitize_key($level);

        if (!in_array($level, $allowed_levels, true)) {
            $level = 'info';
        }

        $data = [
            'time'    => current_time('mysql'),
            'level'   => $level,
            'message' => sanitize_text_field($message),
            'context' => wp_json_encode($this->normalize_context($context)),
        ];

        $format = ['%s', '%s', '%s', '%s'];

        $wpdb->insert($table, $data, $format);
    }

    public function all(): array {
        global $wpdb;
        $table = $this->get_table_name();

        $suppress = $wpdb->suppress_errors(true);
        $results = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC LIMIT 100", ARRAY_A);
        $wpdb->suppress_errors($suppress);

        if (!$results || !is_array($results)) {
            return [];
        }

        $logs = [];
        foreach ($results as $row) {
            $row['context'] = json_decode($row['context'], true);
            if (!is_array($row['context'])) {
                $row['context'] = [];
            }
            $logs[] = $row;
        }

        return $logs;
    }

    /**
     * Determines the current health of a package based on its most recent log entry.
     */
    public function get_package_health(string $package_id): array {
        global $wpdb;
        $table = $this->get_table_name();
        
        // Use LIKE to search inside the JSON context for the package_id
        $like_string = '%"package_id":"' . $wpdb->esc_like($package_id) . '"%';
        
        $suppress = $wpdb->suppress_errors(true);
        $query = $wpdb->prepare("SELECT * FROM $table WHERE context LIKE %s ORDER BY id DESC LIMIT 1", $like_string);
        $row = $wpdb->get_row($query, ARRAY_A);
        $wpdb->suppress_errors($suppress);

        if (!$row) {
            return ['status' => 'unknown', 'message' => 'No activity recorded yet.'];
        }

        $context = json_decode($row['context'], true);
        $action = $context['action'] ?? '';
        $status = $context['status'] ?? '';
        
        if ($row['level'] === 'error') {
            return ['status' => 'error', 'message' => 'Failing: ' . $row['message']];
        }
        
        if ($status === 'success' && $action === 'deployment') {
            return ['status' => 'healthy', 'message' => 'Deployed successfully.'];
        }
        
        if ($status === 'success' && $action === 'webhook_registration') {
            return ['status' => 'healthy', 'message' => 'Webhook configured.'];
        }
        
        if ($status === 'success' && $action === 'validation') {
            return ['status' => 'healthy', 'message' => 'Validated successfully.'];
        }

        if ($row['level'] === 'warning') {
            return ['status' => 'warning', 'message' => 'Warning: Check logs.'];
        }

        return ['status' => 'info', 'message' => 'Pending/Idle.'];
    }

    private function normalize_context(array $context): array {
        $normalized = [];

        foreach ($context as $key => $value) {
            $normalized[sanitize_key((string) $key)] = $this->normalize_context_value($value);
        }

        return $normalized;
    }

    private function normalize_context_value($value): string {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if ($value === null) {
            return '';
        }

        if (is_scalar($value)) {
            return sanitize_text_field((string) $value);
        }

        return sanitize_text_field(wp_json_encode($value));
    }
}

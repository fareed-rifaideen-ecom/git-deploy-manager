<?php
if (!defined('ABSPATH')) {
    exit;
}

class GDM_Settings {
    private string $option_name = 'gdm_settings';

    public function get_all(): array {
        $defaults = [
            'github_token' => '',
            'webhook_secret' => '',
        ];

        return wp_parse_args(get_option($this->option_name, []), $defaults);
    }

    public function get(string $key, $default = '') {
        $settings = $this->get_all();
        return $settings[$key] ?? $default;
    }

    public function update(array $data): bool {
        $clean = [
            'github_token' => isset($data['github_token']) ? sanitize_text_field($data['github_token']) : '',
            'webhook_secret' => isset($data['webhook_secret']) ? sanitize_text_field($data['webhook_secret']) : wp_generate_password(32, false, false),
        ];

        return update_option($this->option_name, $clean, false);
    }
}

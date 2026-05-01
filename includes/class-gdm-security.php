<?php
if (!defined('ABSPATH')) {
    exit;
}

class GDM_Security {
    public static function can_manage(): bool {
        return current_user_can(is_multisite() ? 'manage_network_options' : 'manage_options');
    }

    public static function verify_admin_request(string $action, string $field = '_wpnonce'): void {
        if (!self::can_manage()) {
            wp_die(esc_html__('Insufficient permissions.', 'git-deploy-manager'));
        }

        check_admin_referer($action, $field);
    }

    public static function mask_secret(string $value): string {
        if (strlen($value) <= 8) {
            return str_repeat('*', max(4, strlen($value)));
        }

        return substr($value, 0, 4) . str_repeat('*', strlen($value) - 8) . substr($value, -4);
    }
}

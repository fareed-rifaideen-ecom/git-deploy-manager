<?php
if (!defined('ABSPATH')) {
    exit;
}

class GDM_Deployment_Service {
    private GDM_GitHub_Provider $provider;
    private GDM_Package_Repository $packages;
    private GDM_Logger $logger;

    public function __construct(
        GDM_GitHub_Provider $provider,
        GDM_Package_Repository $packages,
        GDM_Logger $logger
    ) {
        $this->provider = $provider;
        $this->packages = $packages;
        $this->logger = $logger;
    }

    public function deploy(string $package_id, string $source = 'webhook'): array {
        $lock_key = 'gdm_deploy_lock_' . $package_id;

        if (get_transient($lock_key)) {
            $this->logger->add('warning', 'Deployment already in progress.', [
                'action'     => 'deployment',
                'source'     => $source,
                'status'     => 'locked',
                'package_id' => $package_id,
            ]);

            return [
                'success' => false,
                'message' => 'Deployment already in progress.',
            ];
        }

        $package = $this->packages->find($package_id);

        if (!$package) {
            $this->logger->add('error', 'Package not found.', [
                'action'     => 'deployment',
                'source'     => $source,
                'status'     => 'failed',
                'package_id' => $package_id,
            ]);

            return [
                'success' => false,
                'message' => 'Package not found.',
            ];
        }

        set_transient($lock_key, 1, 60);

        try {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/misc.php';
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            require_once ABSPATH . 'wp-admin/includes/theme.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

            $archive_url = $this->provider->get_archive_url(
                (string) ($package['repo_owner'] ?? ''),
                (string) ($package['repo_name'] ?? ''),
                (string) ($package['branch'] ?? 'main')
            );

            $this->logger->add('info', 'Deployment started.', $this->build_log_context($package_id, $package, [
                'action'      => 'deployment',
                'source'      => $source,
                'status'      => 'started',
                'archive_url' => $archive_url,
            ]));

            $result = $this->deploy_archive_to_slug($archive_url, $package);

            if (empty($result['success'])) {
                $message = $result['message'] ?? 'Package deployment failed.';

                $this->logger->add('error', $message, $this->build_log_context($package_id, $package, [
                    'action'         => 'deployment',
                    'source'         => $source,
                    'status'         => 'failed',
                    'archive_url'    => $archive_url,
                    'result_message' => $message,
                ]));

                return [
                    'success' => false,
                    'message' => $message,
                ];
            }

            $commit = $this->provider->get_latest_commit(
                (string) ($package['repo_owner'] ?? ''),
                (string) ($package['repo_name'] ?? ''),
                (string) ($package['branch'] ?? 'main')
            );

            $commit_sha = !empty($commit['success']) ? (string) ($commit['sha'] ?? '') : '';

            $package['id'] = $package_id;
            $package['last_commit'] = $commit_sha;
            $package['last_deployed_at'] = current_time('mysql');
            $this->packages->save($package);

            $type_label = ($package['package_type'] ?? 'plugin') === 'theme' ? 'Theme' : 'Plugin';
            $status_message = !empty($result['mode']) && $result['mode'] === 'upgrade'
                ? $type_label . ' upgraded successfully.'
                : $type_label . ' installed successfully.';

            $this->logger->add('info', $status_message, $this->build_log_context($package_id, $package, [
                'action'         => 'deployment',
                'source'         => $source,
                'status'         => 'success',
                'mode'           => $result['mode'] ?? '',
                'commit_sha'     => $commit_sha,
                'plugin_file'    => $result['plugin_file'] ?? ($package['plugin_file'] ?? ''),
                'result_message' => $status_message,
            ]));

            return [
                'success'     => true,
                'message'     => $status_message,
                'commit_sha'  => $commit_sha,
                'plugin_file' => $result['plugin_file'] ?? '',
                'mode'        => $result['mode'] ?? '',
            ];
        } finally {
            delete_transient($lock_key);
        }
    }

    private function deploy_archive_to_slug(string $archive_url, array $package): array {
        $package_type = $package['package_type'] ?? 'plugin';
        $package_slug = sanitize_title((string) ($package['plugin_slug'] ?? ''));
        $package_file = trim((string) ($package['plugin_file'] ?? ''));
        $subdirectory = trim((string) ($package['subdirectory'] ?? ''), '/');

        if ($package_slug === '' || ($package_type === 'plugin' && $package_file === '')) {
            return [
                'success' => false,
                'message' => 'Directory slug or main file is missing.',
            ];
        }

        if ($package_type === 'theme') {
            $expected_main_filename = 'style.css';
            $target_dir = trailingslashit(get_theme_root()) . $package_slug;
        } else {
            $expected_main_filename = basename($package_file);
            $target_dir = trailingslashit(WP_PLUGIN_DIR) . $package_slug;
        }

        $target_main_file = trailingslashit($target_dir) . $expected_main_filename;
        $mode = file_exists($target_main_file) ? 'upgrade' : 'install';

        $tmp_zip = $this->download_archive($archive_url);

        if (is_wp_error($tmp_zip)) {
            return [
                'success' => false,
                'message' => $tmp_zip->get_error_message(),
            ];
        }

        $working_dir = trailingslashit(get_temp_dir()) . 'gdm-' . wp_generate_password(12, false, false);

        if (!wp_mkdir_p($working_dir)) {
            $this->cleanup_path($tmp_zip);

            return [
                'success' => false,
                'message' => 'Could not create temporary working directory.',
            ];
        }

        $unzipped = unzip_file($tmp_zip, $working_dir);
        $this->cleanup_path($tmp_zip);

        if (is_wp_error($unzipped)) {
            $this->cleanup_path($working_dir);

            return [
                'success' => false,
                'message' => $unzipped->get_error_message(),
            ];
        }

        // GitHub repos extract to a root folder like "owner-repo-sha"
        $extracted_root = $this->find_first_directory($working_dir);

        if (!$extracted_root) {
            $this->cleanup_path($working_dir);

            return [
                'success' => false,
                'message' => 'Could not locate extracted package.',
            ];
        }

        // Apply Subdirectory logic if specified
        $search_dir = $extracted_root;
        if ($subdirectory !== '') {
            $search_dir = trailingslashit($extracted_root) . $subdirectory;
            if (!is_dir($search_dir)) {
                $this->cleanup_path($working_dir);
                return [
                    'success' => false,
                    'message' => 'The specified subdirectory (' . esc_html($subdirectory) . ') was not found in the repository.',
                ];
            }
        }

        $package_main_source = $this->locate_package_main_file($search_dir, $expected_main_filename);

        if (!$package_main_source) {
            $this->cleanup_path($working_dir);

            return [
                'success' => false,
                'message' => 'Could not locate the main file (' . esc_html($expected_main_filename) . ') in the extracted package path.',
            ];
        }

        $source_plugin_dir = dirname($package_main_source);

        if (!$this->prepare_filesystem()) {
            $this->cleanup_path($working_dir);

            return [
                'success' => false,
                'message' => 'Could not initialize the WordPress filesystem API.',
            ];
        }

        global $wp_filesystem;

        // Zero-Downtime Swap Logic with Retained Backup
        $target_exists = $wp_filesystem->exists($target_dir);
        $backup_dir = ($package_type === 'theme' ? trailingslashit(get_theme_root()) : trailingslashit(WP_PLUGIN_DIR)) . $package_slug . '-gdm-bak';

        if ($target_exists) {
            // Delete previous backup if it exists
            if ($wp_filesystem->exists($backup_dir)) {
                $wp_filesystem->delete($backup_dir, true);
            }
            // Move live directory to backup instantly
            $wp_filesystem->move($target_dir, $backup_dir, true);
        }

        // Move the newly extracted directory into the live position
        $moved = $wp_filesystem->move($source_plugin_dir, $target_dir, true);

        // Fallback for cross-partition issues where move() might fail
        if (!$moved) {
            $copied = copy_dir($source_plugin_dir, $target_dir);
            
            if (is_wp_error($copied)) {
                // AUTOMATIC ROLLBACK: If moving/copying fails, restore the backup instantly
                if ($target_exists) {
                    $wp_filesystem->delete($target_dir, true); // Remove any partial copy
                    $wp_filesystem->move($backup_dir, $target_dir, true);
                }
                $this->cleanup_path($working_dir);

                return [
                    'success' => false,
                    'message' => 'Could not move the new files into place. The previous version was restored safely. Error: ' . $copied->get_error_message(),
                ];
            }
        }

        // Clean up temporary extraction folder. We NO LONGER delete the $backup_dir here so users can roll back.
        $this->cleanup_path($working_dir);
        
        if ($package_type === 'theme') {
            wp_clean_themes_cache();
        } else {
            wp_clean_plugins_cache(true);
        }

        if (!file_exists($target_main_file)) {
            return [
                'success' => false,
                'message' => 'Deployment copied files, but the expected main file was not found at the target path.',
            ];
        }

        return [
            'success'     => true,
            'mode'        => $mode,
            'plugin_file' => $package_type === 'theme' ? $package_slug : plugin_basename($target_main_file),
        ];
    }

    /**
     * Checks if a backup folder exists for manual rollback.
     */
    public function has_backup(string $package_id): bool {
        $package = $this->packages->find($package_id);
        if (!$package) {
            return false;
        }

        $package_type = $package['package_type'] ?? 'plugin';
        $package_slug = sanitize_title((string) ($package['plugin_slug'] ?? ''));

        if ($package_slug === '') {
            return false;
        }

        $backup_dir = ($package_type === 'theme' ? trailingslashit(get_theme_root()) : trailingslashit(WP_PLUGIN_DIR)) . $package_slug . '-gdm-bak';
        return file_exists($backup_dir);
    }

    /**
     * Instantly restores the previously deployed version.
     */
    public function rollback(string $package_id, string $source = 'admin'): array {
        $package = $this->packages->find($package_id);

        if (!$package) {
            return ['success' => false, 'message' => 'Package not found.'];
        }

        $package_type = $package['package_type'] ?? 'plugin';
        $package_slug = sanitize_title((string) ($package['plugin_slug'] ?? ''));
        $base_dir = $package_type === 'theme' ? trailingslashit(get_theme_root()) : trailingslashit(WP_PLUGIN_DIR);
        
        $target_dir = $base_dir . $package_slug;
        $backup_dir = $base_dir . $package_slug . '-gdm-bak';

        if (!file_exists($backup_dir)) {
            return ['success' => false, 'message' => 'No backup available for rollback.'];
        }

        if (!$this->prepare_filesystem()) {
            return ['success' => false, 'message' => 'Could not initialize the WordPress filesystem API.'];
        }

        global $wp_filesystem;

        // Remove broken live folder
        if ($wp_filesystem->exists($target_dir)) {
            $wp_filesystem->delete($target_dir, true);
        }

        // Restore backup into live position
        $moved = $wp_filesystem->move($backup_dir, $target_dir, true);

        if (!$moved) {
            return ['success' => false, 'message' => 'Failed to restore backup directory. Check file permissions.'];
        }

        if ($package_type === 'theme') {
            wp_clean_themes_cache();
        } else {
            wp_clean_plugins_cache(true);
        }

        $this->logger->add('info', 'Package rolled back successfully.', $this->build_log_context($package_id, $package, [
            'action' => 'rollback',
            'source' => $source,
            'status' => 'success',
        ]));

        return ['success' => true, 'message' => 'Successfully restored the previous version.'];
    }

    private function download_archive(string $archive_url) {
        $tmp_file = wp_tempnam($archive_url);

        if (!$tmp_file) {
            return new WP_Error('gdm_temp_file_failed', 'Could not create a temporary archive file.');
        }

        $response = wp_remote_get($archive_url, [
            'timeout'  => 60,
            'headers'  => $this->get_request_headers(),
            'stream'   => true,
            'filename' => $tmp_file,
        ]);

        if (is_wp_error($response)) {
            $this->cleanup_path($tmp_file);

            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code !== 200) {
            $this->cleanup_path($tmp_file);

            return new WP_Error('gdm_download_failed', 'Archive download failed. HTTP ' . $code);
        }

        return $tmp_file;
    }

    private function get_request_headers(): array {
        $settings = get_option('gdm_settings', []);
        $token = is_array($settings) ? ($settings['github_token'] ?? '') : '';

        $headers = [
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'Git-Deploy-Manager/' . GDM_VERSION,
        ];

        if (!empty($token)) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $headers;
    }

    private function prepare_filesystem(): bool {
        global $wp_filesystem;

        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        WP_Filesystem();

        return !empty($wp_filesystem);
    }

    private function find_first_directory(string $path): ?string {
        $items = @scandir($path);

        if (!$items || !is_array($items)) {
            return null;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $full_path = trailingslashit($path) . $item;

            if (is_dir($full_path)) {
                return $full_path;
            }
        }

        return null;
    }

    private function locate_package_main_file(string $root_dir, string $expected_filename): ?string {
        if (!is_dir($root_dir)) {
            return null;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root_dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file_info) {
            if ($file_info->isFile() && $file_info->getFilename() === $expected_filename) {
                return $file_info->getPathname();
            }
        }

        return null;
    }

    private function cleanup_path(string $path): void {
        if (!file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }

        $items = @scandir($path);

        if (is_array($items)) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                $this->cleanup_path($path . DIRECTORY_SEPARATOR . $item);
            }
        }

        @rmdir($path);
    }

    private function build_log_context(string $package_id, array $package, array $extra = []): array {
        return array_merge([
            'package_id'   => $package_id,
            'package_name' => $package['name'] ?? '',
            'package_type' => $package['package_type'] ?? 'plugin',
            'repo_owner'   => $package['repo_owner'] ?? '',
            'repo_name'    => $package['repo_name'] ?? '',
            'branch'       => $package['branch'] ?? '',
            'subdirectory' => $package['subdirectory'] ?? '',
            'plugin_slug'  => $package['plugin_slug'] ?? '',
            'plugin_file'  => $package['plugin_file'] ?? '',
        ], $extra);
    }
}

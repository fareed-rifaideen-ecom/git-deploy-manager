<?php
if (!defined('ABSPATH')) {
    exit;
}

class GDM_GitHub_Provider {
    private GDM_Settings $settings;

    public function __construct(GDM_Settings $settings) {
        $this->settings = $settings;
    }

    private function headers(): array {
        $headers = [
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'Git-Deploy-Manager/' . GDM_VERSION,
        ];

        $token = $this->settings->get('github_token');
        if (!empty($token)) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $headers;
    }

    public function verify_connection(): array {
        $response = wp_remote_get('https://api.github.com/user', [
            'headers' => $this->headers(),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return ['success' => false, 'message' => 'GitHub connection failed. HTTP ' . $code];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        return [
            'success' => true,
            'message' => 'Connected to GitHub as ' . ($body['login'] ?? 'unknown'),
        ];
    }

    /**
     * Fetches a list of repositories accessible to the user.
     */
    public function get_repositories(): array {
        $token = $this->settings->get('github_token');
        if (empty($token)) {
            return ['success' => false, 'message' => 'A GitHub token is required to fetch repositories.'];
        }

        $url = 'https://api.github.com/user/repos?per_page=100&sort=updated';

        $response = wp_remote_get($url, [
            'headers' => $this->headers(),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return ['success' => false, 'message' => 'Failed to fetch repositories. HTTP ' . $code];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!is_array($body)) {
             return ['success' => false, 'message' => 'Invalid response from GitHub.'];
        }

        $repos = [];
        foreach ($body as $repo) {
            $repos[] = [
                'name'      => $repo['name'],
                'full_name' => $repo['full_name'],
                'owner'     => $repo['owner']['login'],
                'private'   => $repo['private']
            ];
        }

        return ['success' => true, 'repositories' => $repos];
    }

    /**
     * Fetches branches for a given repository.
     */
    public function get_branches(string $owner, string $repo): array {
        $url = sprintf(
            'https://api.github.com/repos/%s/%s/branches?per_page=100',
            rawurlencode($owner),
            rawurlencode($repo)
        );

        $response = wp_remote_get($url, [
            'headers' => $this->headers(),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return ['success' => false, 'message' => 'Failed to fetch branches. HTTP ' . $code];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($body)) {
             return ['success' => false, 'message' => 'Invalid response from GitHub.'];
        }
        
        $branches = [];
        foreach ($body as $branch) {
            $branches[] = $branch['name'];
        }

        return ['success' => true, 'branches' => $branches];
    }

    public function get_latest_commit(string $owner, string $repo, string $branch = 'main'): array {
        $url = sprintf(
            'https://api.github.com/repos/%s/%s/commits/%s',
            rawurlencode($owner),
            rawurlencode($repo),
            rawurlencode($branch)
        );

        $response = wp_remote_get($url, [
            'headers' => $this->headers(),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($body['sha'])) {
            return ['success' => false, 'message' => 'Could not fetch latest commit.'];
        }

        return [
            'success' => true,
            'sha'     => sanitize_text_field($body['sha']),
            'message' => 'Latest commit fetched.',
        ];
    }

    public function get_archive_url(string $owner, string $repo, string $branch = 'main'): string {
        return sprintf(
            'https://api.github.com/repos/%s/%s/zipball/%s',
            rawurlencode($owner),
            rawurlencode($repo),
            rawurlencode($branch)
        );
    }

    public function validate_package(array $package): array {
        $owner = sanitize_text_field((string) ($package['repo_owner'] ?? ''));
        $repo = sanitize_text_field((string) ($package['repo_name'] ?? ''));
        $branch = sanitize_text_field((string) ($package['branch'] ?? 'main'));
        $plugin_slug = sanitize_title((string) ($package['plugin_slug'] ?? ''));
        $plugin_file = trim((string) ($package['plugin_file'] ?? ''));
        $token = (string) $this->settings->get('github_token');

        if ($owner === '' || $repo === '') {
            return [
                'success' => false,
                'message' => 'Repository owner and repository name are required.',
            ];
        }

        if ($branch === '') {
            return [
                'success' => false,
                'message' => 'Branch is required.',
            ];
        }

        if ($plugin_slug === '' || $plugin_file === '') {
            return [
                'success' => false,
                'message' => 'Plugin slug and plugin main file are required.',
            ];
        }

        $repository_result = $this->get_repository($owner, $repo);
        if (empty($repository_result['success'])) {
            return $repository_result;
        }

        $repository = $repository_result['repository'] ?? [];
        $is_private = !empty($repository['private']);

        if ($is_private && $token === '') {
            return [
                'success' => false,
                'message' => 'This repository is private. Save a GitHub token in Settings before validating or deploying it.',
                'visibility' => 'private',
            ];
        }

        $commit_result = $this->get_latest_commit($owner, $repo, $branch);
        if (empty($commit_result['success'])) {
            return [
                'success' => false,
                'message' => 'Repository found, but the branch could not be validated. Check the branch name and access permissions.',
                'visibility' => $is_private ? 'private' : 'public',
            ];
        }

        $archive_result = $this->validate_archive_contains_plugin($owner, $repo, $branch, $plugin_file);
        if (empty($archive_result['success'])) {
            return [
                'success' => false,
                'message' => $archive_result['message'] ?? 'Archive validation failed.',
                'visibility' => $is_private ? 'private' : 'public',
            ];
        }

        return [
            'success' => true,
            'message' => sprintf(
                '%s repository validated successfully. Branch "%s" is accessible and plugin file "%s" was found.',
                $is_private ? 'Private' : 'Public',
                $branch,
                $plugin_file
            ),
            'visibility' => $is_private ? 'private' : 'public',
            'sha' => $commit_result['sha'] ?? '',
        ];
    }

    private function get_repository(string $owner, string $repo): array {
        $url = sprintf(
            'https://api.github.com/repos/%s/%s',
            rawurlencode($owner),
            rawurlencode($repo)
        );

        $response = wp_remote_get($url, [
            'headers' => $this->headers(),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $token = (string) $this->settings->get('github_token');

        if ($code === 200 && is_array($body)) {
            return [
                'success'    => true,
                'repository' => $body,
            ];
        }

        if ($code === 404) {
            if ($token === '') {
                return [
                    'success' => false,
                    'message' => 'Repository not found. If this is a private repository, save a GitHub token first.',
                ];
            }

            return [
                'success' => false,
                'message' => 'Repository not found or your GitHub token does not have access to it.',
            ];
        }

        if ($code === 401 || $code === 403) {
            return [
                'success' => false,
                'message' => 'GitHub access failed. Check your token permissions or repository access. HTTP ' . $code,
            ];
        }

        return [
            'success' => false,
            'message' => 'Could not validate the repository. HTTP ' . $code,
        ];
    }

    private function validate_archive_contains_plugin(string $owner, string $repo, string $branch, string $plugin_file): array {
        $archive_url = $this->get_archive_url($owner, $repo, $branch);
        $tmp_zip = $this->download_archive($archive_url);

        if (is_wp_error($tmp_zip)) {
            return [
                'success' => false,
                'message' => $tmp_zip->get_error_message(),
            ];
        }

        $working_dir = trailingslashit(get_temp_dir()) . 'gdm-validate-' . wp_generate_password(12, false, false);

        if (!wp_mkdir_p($working_dir)) {
            $this->cleanup_path($tmp_zip);

            return [
                'success' => false,
                'message' => 'Could not create a temporary validation directory.',
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

        $extracted_root = $this->find_first_directory($working_dir);
        if (!$extracted_root) {
            $this->cleanup_path($working_dir);

            return [
                'success' => false,
                'message' => 'Could not locate the extracted plugin package.',
            ];
        }

        $expected_main_filename = basename($plugin_file);
        $plugin_main_source = $this->locate_plugin_main_file($extracted_root, $expected_main_filename);

        $this->cleanup_path($working_dir);

        if (!$plugin_main_source) {
            return [
                'success' => false,
                'message' => 'Repository archive downloaded successfully, but the expected plugin main file was not found.',
            ];
        }

        return [
            'success' => true,
            'message' => 'Archive validation passed.',
        ];
    }

    private function download_archive(string $archive_url) {
        $tmp_file = wp_tempnam($archive_url);

        if (!$tmp_file) {
            return new WP_Error('gdm_temp_file_failed', 'Could not create a temporary archive file.');
        }

        $response = wp_remote_get($archive_url, [
            'timeout'             => 60,
            'headers'             => $this->headers(),
            'stream'              => true,
            'filename'            => $tmp_file,
            'redirection'         => 5,
            'limit_response_size' => 1024 * 1024 * 20,
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

    private function locate_plugin_main_file(string $root_dir, string $expected_filename): ?string {
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

    public function register_webhook(array $package, string $webhook_url, string $webhook_secret): array {
        $owner = sanitize_text_field((string) ($package['repo_owner'] ?? ''));
        $repo  = sanitize_text_field((string) ($package['repo_name'] ?? ''));
        $token = (string) $this->settings->get('github_token');

        if ($owner === '' || $repo === '') {
            return ['success' => false, 'message' => 'Repository owner and name are required.'];
        }

        if ($token === '') {
            return ['success' => false, 'message' => 'GitHub token is missing. A token with webhook permissions is required.'];
        }

        $api_url = sprintf(
            'https://api.github.com/repos/%s/%s/hooks',
            rawurlencode($owner),
            rawurlencode($repo)
        );

        $existing_response = wp_remote_get($api_url, [
            'headers' => $this->headers(),
            'timeout' => 15,
        ]);

        if (!is_wp_error($existing_response) && wp_remote_retrieve_response_code($existing_response) === 200) {
            $hooks = json_decode(wp_remote_retrieve_body($existing_response), true);
            if (is_array($hooks)) {
                foreach ($hooks as $hook) {
                    if (isset($hook['config']['url']) && $hook['config']['url'] === $webhook_url) {
                        return ['success' => true, 'message' => 'Webhook already exists.'];
                    }
                }
            }
        }

        $payload = [
            'name'   => 'web',
            'active' => true,
            'events' => ['push'],
            'config' => [
                'url'          => $webhook_url,
                'content_type' => 'json',
                'insecure_ssl' => '0',
                'secret'       => $webhook_secret,
            ],
        ];

        $response = wp_remote_post($api_url, [
            'headers' => $this->headers(),
            'body'    => wp_json_encode($payload),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code === 201) {
            return ['success' => true, 'message' => 'Webhook auto-registered successfully.'];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $error_message = $body['message'] ?? 'Unknown API error.';

        return ['success' => false, 'message' => 'GitHub API error (' . $code . '): ' . $error_message];
    }
}

<?php
if (!defined('ABSPATH')) {
    exit;
}

class GDM_Admin {
    private GDM_Settings $settings;
    private GDM_Package_Repository $packages;
    private GDM_Deployment_Service $deployment_service;
    private GDM_GitHub_Provider $provider;
    private GDM_Logger $logger;

    public function __construct(
        GDM_Settings $settings,
        GDM_Package_Repository $packages,
        GDM_Deployment_Service $deployment_service,
        GDM_GitHub_Provider $provider,
        GDM_Logger $logger
    ) {
        $this->settings = $settings;
        $this->packages = $packages;
        $this->deployment_service = $deployment_service;
        $this->provider = $provider;
        $this->logger = $logger;

        add_action('wp_ajax_gdm_ajax_deploy', [$this, 'handle_ajax_deploy']);
        add_action('wp_ajax_gdm_ajax_get_repos', [$this, 'handle_ajax_get_repos']);
        add_action('wp_ajax_gdm_ajax_get_branches', [$this, 'handle_ajax_get_branches']);
    }

    public function enqueue_assets(): void {
        wp_enqueue_style('gdm-admin', GDM_PLUGIN_URL . 'assets/admin.css', [], GDM_VERSION);

        wp_enqueue_script('gdm-admin-js', GDM_PLUGIN_URL . 'assets/admin.js', [], GDM_VERSION, true);

        wp_localize_script('gdm-admin-js', 'gdmAjax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('gdm_ajax_deploy_nonce'),
            'i18n'     => [
                'confirmDeploy' => __('Are you sure you want to deploy this package now? This will overwrite the live files.', 'git-deploy-manager'),
                'deploying'     => __('Deploying...', 'git-deploy-manager'),
                'success'       => __('Deployed!', 'git-deploy-manager'),
                'error'         => __('Deployment failed. Please check the logs.', 'git-deploy-manager'),
            ]
        ]);
    }

    public function register_menu(): void {
        add_menu_page(
            __('Git Deploy Manager', 'git-deploy-manager'),
            __('Git Deploy Manager', 'git-deploy-manager'),
            is_multisite() ? 'manage_network_options' : 'manage_options',
            'git-deploy-manager',
            [$this, 'render_packages_page'],
            'dashicons-update'
        );

        add_submenu_page(
            'git-deploy-manager',
            __('Packages', 'git-deploy-manager'),
            __('Packages', 'git-deploy-manager'),
            is_multisite() ? 'manage_network_options' : 'manage_options',
            'git-deploy-manager',
            [$this, 'render_packages_page']
        );

        add_submenu_page(
            'git-deploy-manager',
            __('Settings', 'git-deploy-manager'),
            __('Settings', 'git-deploy-manager'),
            is_multisite() ? 'manage_network_options' : 'manage_options',
            'git-deploy-manager-settings',
            [$this, 'render_settings_page']
        );

        add_submenu_page(
            'git-deploy-manager',
            __('Logs', 'git-deploy-manager'),
            __('Logs', 'git-deploy-manager'),
            is_multisite() ? 'manage_network_options' : 'manage_options',
            'git-deploy-manager-logs',
            [$this, 'render_logs_page']
        );
    }

    public function handle_ajax_deploy(): void {
        check_ajax_referer('gdm_ajax_deploy_nonce');

        if (!GDM_Security::can_manage()) {
            wp_send_json_error(__('Insufficient permissions.', 'git-deploy-manager'));
        }

        $package_id = sanitize_text_field(wp_unslash($_POST['package_id'] ?? ''));

        if (!$package_id) {
            wp_send_json_error(__('Missing package ID.', 'git-deploy-manager'));
        }

        $result = $this->deployment_service->deploy($package_id, 'admin-ajax');

        if (!empty($result['success'])) {
            wp_send_json_success($result['message'] ?? __('Deployed successfully.', 'git-deploy-manager'));
        } else {
            wp_send_json_error($result['message'] ?? __('Deployment failed.', 'git-deploy-manager'));
        }
    }

    public function handle_ajax_get_repos(): void {
        check_ajax_referer('gdm_ajax_deploy_nonce');

        if (!GDM_Security::can_manage()) {
            wp_send_json_error(__('Insufficient permissions.', 'git-deploy-manager'));
        }

        $result = $this->provider->get_repositories();

        if (!empty($result['success'])) {
            wp_send_json_success($result['repositories']);
        } else {
            wp_send_json_error($result['message'] ?? __('Failed to fetch repositories.', 'git-deploy-manager'));
        }
    }

    public function handle_ajax_get_branches(): void {
        check_ajax_referer('gdm_ajax_deploy_nonce');

        if (!GDM_Security::can_manage()) {
            wp_send_json_error(__('Insufficient permissions.', 'git-deploy-manager'));
        }

        $owner = sanitize_text_field(wp_unslash($_POST['owner'] ?? ''));
        $repo  = sanitize_text_field(wp_unslash($_POST['repo'] ?? ''));

        if (!$owner || !$repo) {
            wp_send_json_error(__('Missing repository details.', 'git-deploy-manager'));
        }

        $result = $this->provider->get_branches($owner, $repo);

        if (!empty($result['success'])) {
            wp_send_json_success($result['branches']);
        } else {
            wp_send_json_error($result['message'] ?? __('Failed to fetch branches.', 'git-deploy-manager'));
        }
    }

    public function handle_admin_post(): void {
        if (!isset($_POST['gdm_action'])) {
            return;
        }

        $action = sanitize_text_field(wp_unslash($_POST['gdm_action']));

        if ($action === 'save_settings') {
            GDM_Security::verify_admin_request('gdm_save_settings');

            $this->settings->update([
                'github_token'   => wp_unslash($_POST['github_token'] ?? ''),
                'webhook_secret' => wp_unslash($_POST['webhook_secret'] ?? ''),
            ]);

            wp_safe_redirect(
                add_query_arg(
                    [
                        'page'    => 'git-deploy-manager-settings',
                        'updated' => 1,
                    ],
                    admin_url('admin.php')
                )
            );
            exit;
        }

        if ($action === 'save_package') {
            GDM_Security::verify_admin_request('gdm_save_package');

            $id = $this->packages->save([
                'id'           => wp_unslash($_POST['id'] ?? ''),
                'name'         => wp_unslash($_POST['name'] ?? ''),
                'package_type' => wp_unslash($_POST['package_type'] ?? 'plugin'),
                'repo_owner'   => wp_unslash($_POST['repo_owner'] ?? ''),
                'repo_name'    => wp_unslash($_POST['repo_name'] ?? ''),
                'branch'       => wp_unslash($_POST['branch'] ?? 'main'),
                'subdirectory' => wp_unslash($_POST['subdirectory'] ?? ''),
                'plugin_slug'  => wp_unslash($_POST['plugin_slug'] ?? ''),
                'plugin_file'  => wp_unslash($_POST['plugin_file'] ?? ''),
                'enabled'      => !empty($_POST['enabled']),
                'auto_deploy'  => !empty($_POST['auto_deploy']),
            ]);

            $redirect_args = [
                'page'       => 'git-deploy-manager',
                'saved'      => 1,
                'package_id' => $id,
            ];

            if (!empty($_POST['auto_deploy'])) {
                $package        = $this->packages->find($id);
                $webhook_url    = rest_url('git-deploy-manager/v1/deploy/' . $id);
                $webhook_secret = $this->settings->get('webhook_secret');

                // FIX: Warn the user clearly if no webhook secret has been configured,
                // instead of silently skipping registration with no feedback.
                if (empty($webhook_secret)) {
                    $redirect_args['webhook_no_secret'] = 1;
                } elseif ($package) {
                    $hook_result = $this->provider->register_webhook($package, $webhook_url, $webhook_secret);

                    if (!empty($hook_result['success'])) {
                        $redirect_args['webhook_created'] = 1;
                    } else {
                        $redirect_args['webhook_failed'] = 1;
                        $this->logger->add('error', 'Failed to auto-register GitHub webhook.', [
                            'action'         => 'webhook_registration',
                            'package_id'     => $id,
                            'result_message' => $hook_result['message'] ?? 'Unknown error',
                        ]);
                    }
                }
            }

            wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            exit;
        }

        if ($action === 'link_package') {
            GDM_Security::verify_admin_request('gdm_link_package');

            $installed_package = sanitize_text_field(wp_unslash($_POST['installed_package'] ?? ''));
            $parts             = explode(':', $installed_package, 2);
            $package_type      = ($parts[0] === 'theme') ? 'theme' : 'plugin';
            $path              = $parts[1] ?? '';

            if ($package_type === 'plugin') {
                $plugin_file = $path;
                $plugin_slug = dirname($plugin_file);
                if ($plugin_slug === '.') {
                    $plugin_slug = basename($plugin_file, '.php');
                }
            } else {
                $plugin_slug = $path;
                $plugin_file = 'style.css';
            }

            $id = $this->packages->save([
                'id'           => '',
                'name'         => wp_unslash($_POST['name'] ?? ''),
                'package_type' => $package_type,
                'repo_owner'   => wp_unslash($_POST['repo_owner'] ?? ''),
                'repo_name'    => wp_unslash($_POST['repo_name'] ?? ''),
                'branch'       => wp_unslash($_POST['branch'] ?? 'main'),
                'subdirectory' => wp_unslash($_POST['subdirectory'] ?? ''),
                'plugin_slug'  => $plugin_slug,
                'plugin_file'  => $plugin_file,
                'enabled'      => 1,
                'auto_deploy'  => !empty($_POST['auto_deploy']),
            ]);

            $redirect_args = [
                'page'       => 'git-deploy-manager',
                'saved'      => 1,
                'package_id' => $id,
            ];

            if (!empty($_POST['auto_deploy'])) {
                $package        = $this->packages->find($id);
                $webhook_url    = rest_url('git-deploy-manager/v1/deploy/' . $id);
                $webhook_secret = $this->settings->get('webhook_secret');

                // FIX: Same no-secret guard as save_package above.
                if (empty($webhook_secret)) {
                    $redirect_args['webhook_no_secret'] = 1;
                } elseif ($package) {
                    $hook_result = $this->provider->register_webhook($package, $webhook_url, $webhook_secret);

                    if (!empty($hook_result['success'])) {
                        $redirect_args['webhook_created'] = 1;
                    } else {
                        $redirect_args['webhook_failed'] = 1;
                        $this->logger->add('error', 'Failed to auto-register GitHub webhook during package linking.', [
                            'action'         => 'webhook_registration',
                            'package_id'     => $id,
                            'result_message' => $hook_result['message'] ?? 'Unknown error',
                        ]);
                    }
                }
            }

            wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            exit;
        }

        if ($action === 'rollback_package') {
            GDM_Security::verify_admin_request('gdm_rollback_package');

            $package_id = sanitize_text_field(wp_unslash($_POST['package_id'] ?? ''));
            $result     = $this->deployment_service->rollback($package_id, 'admin');

            if (!empty($result['success'])) {
                $this->set_validation_notice('success', $result['message']);
            } else {
                $this->set_validation_notice('error', $result['message']);
            }

            wp_safe_redirect(
                add_query_arg(
                    ['page' => 'git-deploy-manager'],
                    admin_url('admin.php')
                )
            );
            exit;
        }

        if ($action === 'validate_package') {
            GDM_Security::verify_admin_request('gdm_validate_package');

            $package_id = sanitize_text_field(wp_unslash($_POST['package_id'] ?? ''));
            $package    = $this->packages->find($package_id);

            if (!$package) {
                $this->logger->add('error', 'Package validation failed because the package was not found.', [
                    'action'     => 'validation',
                    'source'     => 'admin',
                    'status'     => 'failed',
                    'package_id' => $package_id,
                ]);

                $this->set_validation_notice('error', __('Package not found.', 'git-deploy-manager'));

                wp_safe_redirect(
                    add_query_arg(
                        ['page' => 'git-deploy-manager'],
                        admin_url('admin.php')
                    )
                );
                exit;
            }

            $result  = $this->provider->validate_package($package);
            $message = $result['message'] ?? __('Validation completed.', 'git-deploy-manager');

            $this->logger->add(
                !empty($result['success']) ? 'info' : 'error',
                !empty($result['success'])
                    ? 'Package validation completed successfully.'
                    : 'Package validation failed.',
                [
                    'action'         => 'validation',
                    'source'         => 'admin',
                    'status'         => !empty($result['success']) ? 'success' : 'failed',
                    'package_id'     => $package_id,
                    'package_name'   => $package['name'] ?? '',
                    'repo_owner'     => $package['repo_owner'] ?? '',
                    'repo_name'      => $package['repo_name'] ?? '',
                    'branch'         => $package['branch'] ?? '',
                    'subdirectory'   => $package['subdirectory'] ?? '',
                    'plugin_slug'    => $package['plugin_slug'] ?? '',
                    'plugin_file'    => $package['plugin_file'] ?? '',
                    'visibility'     => $result['visibility'] ?? '',
                    'commit_sha'     => $result['sha'] ?? '',
                    'result_message' => $message,
                ]
            );

            $this->set_validation_notice(
                !empty($result['success']) ? 'success' : 'error',
                $message
            );

            wp_safe_redirect(
                add_query_arg(
                    ['page' => 'git-deploy-manager'],
                    admin_url('admin.php')
                )
            );
            exit;
        }

        if ($action === 'delete_package') {
            GDM_Security::verify_admin_request('gdm_delete_package');

            $package_id = sanitize_text_field(wp_unslash($_POST['package_id'] ?? ''));
            $this->packages->delete($package_id);

            wp_safe_redirect(
                add_query_arg(
                    [
                        'page'    => 'git-deploy-manager',
                        'deleted' => 1,
                    ],
                    admin_url('admin.php')
                )
            );
            exit;
        }

        if ($action === 'deploy_package') {
            GDM_Security::verify_admin_request('gdm_deploy_package');

            $package_id = sanitize_text_field(wp_unslash($_POST['package_id'] ?? ''));
            $result     = $this->deployment_service->deploy($package_id, 'admin');
            $flag       = !empty($result['success']) ? 'deployed' : 'failed';

            wp_safe_redirect(
                add_query_arg(
                    [
                        'page' => 'git-deploy-manager',
                        $flag  => 1,
                    ],
                    admin_url('admin.php')
                )
            );
            exit;
        }

        if ($action === 'test_github') {
            GDM_Security::verify_admin_request('gdm_test_github');

            $result = $this->provider->verify_connection();

            $this->logger->add(
                !empty($result['success']) ? 'info' : 'error',
                $result['message'],
                [
                    'action'         => 'github_access_test',
                    'source'         => 'settings',
                    'status'         => !empty($result['success']) ? 'success' : 'failed',
                    'result_message' => $result['message'] ?? '',
                ]
            );

            wp_safe_redirect(
                add_query_arg(
                    [
                        'page'          => 'git-deploy-manager-settings',
                        'github_tested' => 1,
                    ],
                    admin_url('admin.php')
                )
            );
            exit;
        }
    }

    private function validation_notice_key(): string {
        return 'gdm_validation_notice_' . get_current_user_id();
    }

    private function set_validation_notice(string $type, string $message): void {
        set_transient(
            $this->validation_notice_key(),
            [
                'type'    => $type,
                'message' => $message,
            ],
            60
        );
    }

    private function render_validation_notice(): void {
        $notice = get_transient($this->validation_notice_key());

        if (!is_array($notice) || empty($notice['message'])) {
            return;
        }

        delete_transient($this->validation_notice_key());

        $type = sanitize_key((string) ($notice['type'] ?? 'info'));

        if (!in_array($type, ['success', 'error', 'warning', 'info'], true)) {
            $type = 'info';
        }

        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html((string) $notice['message']) . '</p></div>';
    }

    private function render_admin_notices(): void {
        $this->render_validation_notice();

        if (!empty($_GET['saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Package saved successfully.', 'git-deploy-manager') . '</p></div>';
        }

        if (!empty($_GET['deleted'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Package deleted successfully.', 'git-deploy-manager') . '</p></div>';
        }

        if (!empty($_GET['deployed'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Package deployed successfully.', 'git-deploy-manager') . '</p></div>';
        }

        if (!empty($_GET['failed'])) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Package deployment failed. Check the Logs screen for details.', 'git-deploy-manager') . '</p></div>';
        }

        if (!empty($_GET['updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved successfully.', 'git-deploy-manager') . '</p></div>';
        }

        if (!empty($_GET['github_tested'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('GitHub authenticated access test completed. Check Logs for the exact result. Public repositories can still work without a token.', 'git-deploy-manager') . '</p></div>';
        }

        if (!empty($_GET['webhook_created'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('GitHub Webhook auto-registered successfully!', 'git-deploy-manager') . '</p></div>';
        }

        if (!empty($_GET['webhook_failed'])) {
            echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('Package saved, but GitHub Webhook auto-registration failed. Please check the logs or ensure your GitHub token has "repo" webhook permissions.', 'git-deploy-manager') . '</p></div>';
        }

        // FIX: New notice — shown when auto_deploy is checked but no webhook secret is configured.
        if (!empty($_GET['webhook_no_secret'])) {
            $settings_url = add_query_arg(['page' => 'git-deploy-manager-settings'], admin_url('admin.php'));
            echo '<div class="notice notice-error is-dismissible"><p>'
                . sprintf(
                    /* translators: %s Settings page link */
                    esc_html__('Auto Deploy is enabled, but no Webhook Secret is set. The webhook was NOT registered with GitHub. Please add a Webhook Secret in %s, then re-save this package.', 'git-deploy-manager'),
                    '<a href="' . esc_url($settings_url) . '">' . esc_html__('Settings', 'git-deploy-manager') . '</a>'
                )
                . '</p></div>';
        }
    }

    public function render_packages_page(): void {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        $all_themes  = wp_get_themes();

        $packages = $this->packages->all();
        $edit_id  = sanitize_text_field(wp_unslash($_GET['edit_package'] ?? ''));

        $current_package = [
            'id'               => '',
            'name'             => '',
            'package_type'     => 'plugin',
            'repo_owner'       => '',
            'repo_name'        => '',
            'branch'           => 'main',
            'subdirectory'     => '',
            'plugin_slug'      => '',
            'plugin_file'      => '',
            'enabled'          => 1,
            'auto_deploy'      => 0,
            'last_commit'      => '',
            'last_deployed_at' => '',
        ];

        if ($edit_id && isset($packages[$edit_id]) && is_array($packages[$edit_id])) {
            $current_package = array_merge($current_package, $packages[$edit_id]);
        }

        $is_editing = !empty($current_package['id']);
        $cancel_url = add_query_arg(
            ['page' => 'git-deploy-manager'],
            admin_url('admin.php')
        );
        $has_token = !empty($this->settings->get('github_token'));

        ?>
        <div class="wrap gdm-wrap">
            <?php $this->render_admin_notices(); ?>

            <h1><?php esc_html_e('Git Deploy Manager', 'git-deploy-manager'); ?></h1>
            <p><?php esc_html_e('Register a GitHub-backed package and deploy it manually or automatically.', 'git-deploy-manager'); ?></p>

            <div class="gdm-card">
                <h2>
                    <?php
                    echo esc_html(
                        $is_editing
                            ? __('Edit package', 'git-deploy-manager')
                            : __('Add New Package', 'git-deploy-manager')
                    );
                    ?>
                </h2>

                <?php if ($has_token && !$is_editing) : ?>
                    <button type="button" class="button button-secondary" id="gdm-fetch-repos-btn" style="margin-bottom: 15px;">
                        <span class="dashicons dashicons-download" style="vertical-align: middle; margin-top: -2px;"></span>
                        <?php esc_html_e('Auto-fill from GitHub', 'git-deploy-manager'); ?>
                    </button>
                <?php elseif (!$has_token && !$is_editing): ?>
                    <p class="description"><?php esc_html_e('Save a GitHub token in Settings to enable the auto-fill wizard.', 'git-deploy-manager'); ?></p>
                <?php endif; ?>

                <form method="post">
                    <?php wp_nonce_field('gdm_save_package'); ?>
                    <input type="hidden" name="gdm_action" value="save_package">
                    <input type="hidden" name="id" value="<?php echo esc_attr($current_package['id']); ?>">

                    <table class="form-table" role="presentation">
                        <tr>
                            <th><label for="package_type"><?php esc_html_e('Package Type', 'git-deploy-manager'); ?></label></th>
                            <td>
                                <select name="package_type" id="package_type">
                                    <option value="plugin" <?php selected($current_package['package_type'] ?? 'plugin', 'plugin'); ?>><?php esc_html_e('Plugin', 'git-deploy-manager'); ?></option>
                                    <option value="theme" <?php selected($current_package['package_type'] ?? '', 'theme'); ?>><?php esc_html_e('Theme', 'git-deploy-manager'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="name"><?php esc_html_e('Name', 'git-deploy-manager'); ?></label></th>
                            <td>
                                <input
                                    name="name"
                                    id="name"
                                    type="text"
                                    class="regular-text"
                                    value="<?php echo esc_attr($current_package['name']); ?>"
                                    required
                                >
                                <p class="description"><?php esc_html_e('Internal label used in the package list.', 'git-deploy-manager'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="repo_owner"><?php esc_html_e('Repo owner', 'git-deploy-manager'); ?></label></th>
                            <td>
                                <input
                                    name="repo_owner"
                                    id="repo_owner"
                                    type="text"
                                    class="regular-text"
                                    value="<?php echo esc_attr($current_package['repo_owner']); ?>"
                                    required
                                >
                                <p class="description"><?php esc_html_e('Example: your GitHub username or organization name.', 'git-deploy-manager'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="repo_name"><?php esc_html_e('Repo name', 'git-deploy-manager'); ?></label></th>
                            <td>
                                <input
                                    name="repo_name"
                                    id="repo_name"
                                    type="text"
                                    class="regular-text"
                                    value="<?php echo esc_attr($current_package['repo_name']); ?>"
                                    required
                                >
                                <p class="description"><?php esc_html_e('Example: my-private-plugin or my-custom-theme.', 'git-deploy-manager'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="branch"><?php esc_html_e('Branch', 'git-deploy-manager'); ?></label></th>
                            <td>
                                <div style="display:flex; align-items:center; gap: 8px;">
                                    <input
                                        name="branch"
                                        id="branch"
                                        type="text"
                                        class="regular-text"
                                        value="<?php echo esc_attr($current_package['branch']); ?>"
                                        required
                                    >
                                    <select id="gdm-branch-select" style="display:none; max-width:150px;"></select>
                                </div>
                                <p class="description"><?php esc_html_e('Usually main or master.', 'git-deploy-manager'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="subdirectory"><?php esc_html_e('Subdirectory', 'git-deploy-manager'); ?></label></th>
                            <td>
                                <input
                                    name="subdirectory"
                                    id="subdirectory"
                                    type="text"
                                    class="regular-text"
                                    value="<?php echo esc_attr($current_package['subdirectory']); ?>"
                                >
                                <p class="description"><?php esc_html_e('Optional. If your plugin/theme files are not in the root of the repo, enter the folder path (e.g., src or my-plugin).', 'git-deploy-manager'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="plugin_slug"><?php esc_html_e('Directory slug', 'git-deploy-manager'); ?></label></th>
                            <td>
                                <input
                                    name="plugin_slug"
                                    id="plugin_slug"
                                    type="text"
                                    class="regular-text"
                                    value="<?php echo esc_attr($current_package['plugin_slug']); ?>"
                                    required
                                >
                                <p class="description"><?php esc_html_e('Target directory inside wp-content/plugins or wp-content/themes. Must exactly match the folder name (e.g. my-plugin).', 'git-deploy-manager'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="plugin_file"><?php esc_html_e('Main file', 'git-deploy-manager'); ?></label></th>
                            <td>
                                <input
                                    name="plugin_file"
                                    id="plugin_file"
                                    type="text"
                                    class="regular-text"
                                    placeholder="my-plugin/my-plugin.php or style.css"
                                    value="<?php echo esc_attr($current_package['plugin_file']); ?>"
                                    required
                                >
                                <p class="description"><?php esc_html_e('Plugins: my-plugin/my-plugin.php. Themes: style.css. This must match the main file inside the repository package.', 'git-deploy-manager'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="enabled"><?php esc_html_e('Enabled', 'git-deploy-manager'); ?></label></th>
                            <td>
                                <label>
                                    <input
                                        name="enabled"
                                        id="enabled"
                                        type="checkbox"
                                        value="1"
                                        <?php checked(!empty($current_package['enabled'])); ?>
                                    >
                                    <?php esc_html_e('Active package', 'git-deploy-manager'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="auto_deploy"><?php esc_html_e('Auto Deploy', 'git-deploy-manager'); ?></label></th>
                            <td>
                                <label>
                                    <input
                                        name="auto_deploy"
                                        id="auto_deploy"
                                        type="checkbox"
                                        value="1"
                                        <?php checked(!empty($current_package['auto_deploy'])); ?>
                                    >
                                    <?php esc_html_e('Enable Push to Deploy (Auto-registers Webhook)', 'git-deploy-manager'); ?>
                                </label>
                                <p class="description"><?php esc_html_e('Requires a Webhook Secret to be set in Settings. When checked and saved, the plugin will automatically register the webhook URL with GitHub.', 'git-deploy-manager'); ?></p>
                            </td>
                        </tr>
                    </table>

                    <div class="gdm-inline-actions">
                        <?php
                        submit_button(
                            $is_editing
                                ? __('Update package', 'git-deploy-manager')
                                : __('Save package', 'git-deploy-manager'),
                            'primary',
                            'submit',
                            false
                        );
                        ?>

                        <?php if ($is_editing) : ?>
                            <a href="<?php echo esc_url($cancel_url); ?>" class="button">
                                <?php esc_html_e('Cancel edit', 'git-deploy-manager'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </form>

                <?php if ($is_editing) : ?>
                    <div style="margin-top: 12px;">
                        <form method="post">
                            <?php wp_nonce_field('gdm_validate_package'); ?>
                            <input type="hidden" name="gdm_action" value="validate_package">
                            <input type="hidden" name="package_id" value="<?php echo esc_attr($current_package['id']); ?>">
                            <?php submit_button(__('Validate package', 'git-deploy-manager'), 'secondary', 'submit', false); ?>
                        </form>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Repo Wizard Modal -->
            <div id="gdm-repo-modal-overlay" class="gdm-modal-overlay">
                <div class="gdm-card" style="width:100%; max-width:600px; margin:0; max-height:80vh; display:flex; flex-direction:column;">
                    <h2><?php esc_html_e('Select Repository', 'git-deploy-manager'); ?></h2>
                    <p id="gdm-repo-modal-msg"><?php esc_html_e('Fetching your repositories from GitHub...', 'git-deploy-manager'); ?></p>
                    <input type="text" id="gdm-repo-search" class="regular-text" placeholder="<?php esc_html_e('Search repositories...', 'git-deploy-manager'); ?>" style="display:none; width:100%; margin-bottom:10px;">
                    <div id="gdm-repo-list" class="gdm-repo-list" style="display:none; flex-grow:1;"></div>
                    <div class="gdm-inline-actions" style="margin-top: 16px;">
                        <button type="button" class="button" id="gdm-close-repo-modal"><?php esc_html_e('Cancel', 'git-deploy-manager'); ?></button>
                    </div>
                </div>
            </div>

            <?php if (!$is_editing) : ?>
            <div class="gdm-card">
                <h2><?php esc_html_e('Link Existing Plugin or Theme', 'git-deploy-manager'); ?></h2>
                <p><?php esc_html_e('Adopt an already installed plugin or theme and manage its future updates via Git Deploy Manager. This will not deploy files immediately; it simply links the local folder to a repository.', 'git-deploy-manager'); ?></p>

                <form method="post">
                    <?php wp_nonce_field('gdm_link_package'); ?>
                    <input type="hidden" name="gdm_action" value="link_package">

                    <table class="form-table" role="presentation">
                        <tr>
                            <th><label for="installed_package"><?php esc_html_e('Installed Package', 'git-deploy-manager'); ?></label></th>
                            <td>
                                <select name="installed_package" id="installed_package" required>
                                    <option value=""><?php esc_html_e('-- Select an installed package --', 'git-deploy-manager'); ?></option>

                                    <optgroup label="<?php esc_attr_e('Plugins', 'git-deploy-manager'); ?>">
                                        <?php foreach ($all_plugins as $path => $plugin_data) : ?>
                                            <option value="plugin:<?php echo esc_attr($path); ?>" data-name="<?php echo esc_attr($plugin_data['Name']); ?>">
                                                <?php echo esc_html($plugin_data['Name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>

                                    <optgroup label="<?php esc_attr_e('Themes', 'git-deploy-manager'); ?>">
                                        <?php foreach ($all_themes as $stylesheet => $theme_data) : ?>
                                            <option value="theme:<?php echo esc_attr($stylesheet); ?>" data-name="<?php echo esc_attr($theme_data->get('Name')); ?>">
                                                <?php echo esc_html($theme_data->get('Name')); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="link_name"><?php esc_html_e('Name', 'git-deploy-manager'); ?></label></th>
                            <td>
                                <input name="name" id="link_name" type="text" class="regular-text" required>
                                <p class="description"><?php esc_html_e('Auto-filled when you select a package above.', 'git-deploy-manager'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="link_repo_owner"><?php esc_html_e('Repo owner', 'git-deploy-manager'); ?></label></th>
                            <td>
                                <input name="repo_owner" id="link_repo_owner" type="text" class="regular-text" required>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="link_repo_name"><?php esc_html_e('Repo name', 'git-deploy-manager'); ?></label></th>
                            <td>
                                <input name="repo_name" id="link_repo_name" type="text" class="regular-text" required>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="link_branch"><?php esc_html_e('Branch', 'git-deploy-manager'); ?></label></th>
                            <td>
                                <input name="branch" id="link_branch" type="text" class="regular-text" value="main" required>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="link_subdirectory"><?php esc_html_e('Subdirectory', 'git-deploy-manager'); ?></label></th>
                            <td>
                                <input name="subdirectory" id="link_subdirectory" type="text" class="regular-text">
                                <p class="description"><?php esc_html_e('Optional. If the files are inside a specific folder like "src".', 'git-deploy-manager'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="link_auto_deploy"><?php esc_html_e('Auto Deploy', 'git-deploy-manager'); ?></label></th>
                            <td>
                                <label>
                                    <input name="auto_deploy" id="link_auto_deploy" type="checkbox" value="1">
                                    <?php esc_html_e('Enable Push to Deploy (Auto-registers Webhook)', 'git-deploy-manager'); ?>
                                </label>
                                <p class="description"><?php esc_html_e('Requires a Webhook Secret to be set in Settings.', 'git-deploy-manager'); ?></p>
                            </td>
                        </tr>
                    </table>

                    <div class="gdm-inline-actions">
                        <?php submit_button(__('Link Package', 'git-deploy-manager'), 'primary', 'submit', false); ?>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <div class="gdm-card">
                <h2><?php esc_html_e('Packages', 'git-deploy-manager'); ?></h2>

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Package', 'git-deploy-manager'); ?></th>
                            <th><?php esc_html_e('Repository', 'git-deploy-manager'); ?></th>
                            <th><?php esc_html_e('Branch', 'git-deploy-manager'); ?></th>
                            <th><?php esc_html_e('Auto Deploy', 'git-deploy-manager'); ?></th>
                            <th><?php esc_html_e('Health', 'git-deploy-manager'); ?></th>
                            <th><?php esc_html_e('Last commit', 'git-deploy-manager'); ?></th>
                            <th><?php esc_html_e('Last deployed', 'git-deploy-manager'); ?></th>
                            <th><?php esc_html_e('Actions', 'git-deploy-manager'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($packages)) : ?>
                        <tr>
                            <td colspan="8"><?php esc_html_e('No packages added yet.', 'git-deploy-manager'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($packages as $package) : ?>
                            <?php
                            $package = array_merge(
                                [
                                    'id'               => '',
                                    'name'             => '',
                                    'package_type'     => 'plugin',
                                    'repo_owner'       => '',
                                    'repo_name'        => '',
                                    'branch'           => 'main',
                                    'subdirectory'     => '',
                                    'plugin_slug'      => '',
                                    'plugin_file'      => '',
                                    'enabled'          => 0,
                                    'auto_deploy'      => 0,
                                    'last_commit'      => '',
                                    'last_deployed_at' => '',
                                ],
                                is_array($package) ? $package : []
                            );

                            $edit_url = add_query_arg(
                                [
                                    'page'         => 'git-deploy-manager',
                                    'edit_package' => $package['id'],
                                ],
                                admin_url('admin.php')
                            );

                            $deploy_url = rest_url('git-deploy-manager/v1/deploy/' . $package['id']);
                            $health     = $this->logger->get_package_health($package['id']);
                            $has_backup = $this->deployment_service->has_backup($package['id']);
                            ?>
                            <tr>
                                <td>
                                    <strong class="gdm-text-strong"><?php echo esc_html($package['name']); ?></strong>
                                    <span style="font-size: 11px; padding: 2px 6px; border-radius: 4px; background: #e0e0e0; color: #333; margin-left: 6px;">
                                        <?php echo esc_html(ucfirst($package['package_type'])); ?>
                                    </span><br>
                                    <span class="description"><?php echo esc_html($package['plugin_slug']); ?></span><br>
                                    <?php
                                    $this->render_badge(
                                        !empty($package['enabled']) ? __('Enabled', 'git-deploy-manager') : __('Disabled', 'git-deploy-manager'),
                                        !empty($package['enabled']) ? 'success' : 'neutral'
                                    );
                                    ?>
                                </td>
                                <td>
                                    <strong class="gdm-text-strong">
                                        <?php echo esc_html($package['repo_owner'] . '/' . $package['repo_name']); ?>
                                    </strong><br>
                                    <span class="description"><?php echo esc_html($package['plugin_file']); ?></span>
                                </td>
                                <td>
                                    <code><?php echo esc_html($package['branch']); ?></code>
                                </td>
                                <td>
                                    <div class="gdm-inline-actions">
                                        <?php if (!empty($package['auto_deploy'])) : ?>
                                            <?php $this->render_badge(__('Yes', 'git-deploy-manager'), 'success'); ?>
                                            <button
                                                type="button"
                                                class="button button-small gdm-view-url"
                                                data-url="<?php echo esc_attr($deploy_url); ?>"
                                            >
                                                <?php esc_html_e('View URL', 'git-deploy-manager'); ?>
                                            </button>
                                        <?php else : ?>
                                            <?php $this->render_badge(__('No', 'git-deploy-manager'), 'neutral'); ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php
                                    if ($health['status'] === 'healthy') {
                                        $this->render_badge(__('Healthy', 'git-deploy-manager'), 'success');
                                    } elseif ($health['status'] === 'error') {
                                        $this->render_badge(__('Failing', 'git-deploy-manager'), 'error');
                                    } elseif ($health['status'] === 'warning') {
                                        $this->render_badge(__('Warning', 'git-deploy-manager'), 'warning');
                                    } else {
                                        $this->render_badge(__('Unknown', 'git-deploy-manager'), 'neutral');
                                    }
                                    ?>
                                    <div class="description" style="margin-top: 4px; font-size: 11px; max-width: 150px;"><?php echo esc_html($health['message']); ?></div>
                                </td>
                                <td>
                                    <?php if (!empty($package['last_commit'])) : ?>
                                        <code><?php echo esc_html(substr($package['last_commit'], 0, 10)); ?></code>
                                    <?php else : ?>
                                        <?php $this->render_badge(__('Not available', 'git-deploy-manager'), 'neutral'); ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($package['last_deployed_at']) && $package['last_deployed_at'] !== '0000-00-00 00:00:00') : ?>
                                        <strong class="gdm-text-strong"><?php echo esc_html($package['last_deployed_at']); ?></strong>
                                    <?php else : ?>
                                        <?php $this->render_badge(__('Never deployed', 'git-deploy-manager'), 'warning'); ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="gdm-inline-actions">
                                        <a href="<?php echo esc_url($edit_url); ?>" class="button button-small">
                                            <?php esc_html_e('Edit', 'git-deploy-manager'); ?>
                                        </a>

                                        <button
                                            type="button"
                                            class="button button-secondary gdm-deploy-btn"
                                            data-package="<?php echo esc_attr($package['id']); ?>"
                                        >
                                            <span class="dashicons dashicons-update" style="display: none; vertical-align: middle; margin-top: -2px;"></span>
                                            <?php esc_html_e('Deploy now', 'git-deploy-manager'); ?>
                                        </button>

                                        <?php if ($has_backup) : ?>
                                            <form method="post" onsubmit="return confirm('<?php echo esc_js(__('Are you sure you want to rollback? The current live version will be replaced by the previous backup.', 'git-deploy-manager')); ?>');" class="gdm-rollback-form">
                                                <?php wp_nonce_field('gdm_rollback_package'); ?>
                                                <input type="hidden" name="gdm_action" value="rollback_package">
                                                <input type="hidden" name="package_id" value="<?php echo esc_attr($package['id']); ?>">
                                                <?php submit_button(__('Rollback', 'git-deploy-manager'), 'secondary button-small', 'submit', false); ?>
                                            </form>
                                        <?php endif; ?>

                                        <form method="post" onsubmit="return confirm('<?php echo esc_js(__('Are you sure you want to delete this package?', 'git-deploy-manager')); ?>");">
                                            <?php wp_nonce_field('gdm_delete_package'); ?>
                                            <input type="hidden" name="gdm_action" value="delete_package">
                                            <input type="hidden" name="package_id" value="<?php echo esc_attr($package['id']); ?>">
                                            <?php submit_button(__('Delete', 'git-deploy-manager'), 'delete', 'submit', false); ?>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div id="gdm-url-modal" class="gdm-modal-overlay">
                <div class="gdm-card" role="dialog" aria-modal="true" aria-labelledby="gdm-url-modal-title" style="width:100%; max-width:720px; margin:0;">
                    <h2 id="gdm-url-modal-title"><?php esc_html_e('Push-to-Deploy URL', 'git-deploy-manager'); ?></h2>
                    <p><?php esc_html_e('Use this URL when creating the repository webhook in GitHub.', 'git-deploy-manager'); ?></p>

                    <input type="text" id="gdm-url-field" class="regular-text" readonly value="">

                    <div class="gdm-inline-actions" style="margin-top: 16px;">
                        <button type="button" class="button button-primary" id="gdm-copy-url">
                            <?php esc_html_e('Copy URL', 'git-deploy-manager'); ?>
                        </button>
                        <button type="button" class="button" id="gdm-close-url-modal">
                            <?php esc_html_e('Close', 'git-deploy-manager'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_settings_page(): void {
        $settings = $this->settings->get_all();
        $settings = is_array($settings) ? $settings : [];

        ?>
        <div class="wrap gdm-wrap">
            <?php $this->render_admin_notices(); ?>

            <h1><?php esc_html_e('Git Deploy Settings', 'git-deploy-manager'); ?></h1>

            <div class="notice notice-info">
                <p><?php esc_html_e('GitHub token is optional for public repositories and required for private repositories.', 'git-deploy-manager'); ?></p>
                <p><?php esc_html_e('Public GitHub packages can still be deployed without a token. The token is only needed when GitHub authentication is required, such as private repository downloads or authenticated API access.', 'git-deploy-manager'); ?></p>
            </div>

            <div class="gdm-card">
                <h2><?php esc_html_e('GitHub access', 'git-deploy-manager'); ?></h2>

                <form method="post">
                    <?php wp_nonce_field('gdm_save_settings'); ?>
                    <input type="hidden" name="gdm_action" value="save_settings">

                    <table class="form-table" role="presentation">
                        <tr>
                            <th><label for="github_token"><?php esc_html_e('GitHub token', 'git-deploy-manager'); ?></label></th>
                            <td>
                                <input
                                    name="github_token"
                                    id="github_token"
                                    type="password"
                                    class="regular-text"
                                    value="<?php echo esc_attr($settings['github_token'] ?? ''); ?>"
                                >
                                <p class="description"><?php esc_html_e('Optional for public repositories. Required for private repositories. Must have "repo" or "admin:repo_hook" scope for Auto Deploy.', 'git-deploy-manager'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="webhook_secret"><?php esc_html_e('Webhook secret', 'git-deploy-manager'); ?></label></th>
                            <td>
                                <input
                                    name="webhook_secret"
                                    id="webhook_secret"
                                    type="text"
                                    class="regular-text"
                                    value="<?php echo esc_attr($settings['webhook_secret'] ?? ''); ?>"
                                >
                                <!-- FIX: Clearer description — required for Auto Deploy to work. -->
                                <p class="description">
                                    <?php esc_html_e('Required for Auto Deploy (Push to Deploy). Set a strong random string here, and use the exact same value in your GitHub repository webhook configuration. Without this, webhooks will not be registered or accepted.', 'git-deploy-manager'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <div class="gdm-inline-actions">
                        <?php submit_button(__('Save settings', 'git-deploy-manager'), 'primary', 'submit', false); ?>
                    </div>
                </form>
            </div>

            <div class="gdm-card">
                <h2><?php esc_html_e('Test authenticated GitHub access', 'git-deploy-manager'); ?></h2>
                <p><?php esc_html_e('This test checks token-based GitHub access only. It is useful for private repositories. Public repositories can still work even if this test fails when no token is saved.', 'git-deploy-manager'); ?></p>

                <form method="post">
                    <?php wp_nonce_field('gdm_test_github'); ?>
                    <input type="hidden" name="gdm_action" value="test_github">
                    <div class="gdm-inline-actions">
                        <?php submit_button(__('Test authenticated access', 'git-deploy-manager'), 'secondary', 'submit', false); ?>
                    </div>
                </form>
            </div>

            <div class="gdm-card">
                <h2><?php esc_html_e('Webhook endpoint', 'git-deploy-manager'); ?></h2>
                <p><code><?php echo esc_html(rest_url('git-deploy-manager/v1/deploy/{package_id}')); ?></code></p>
                <p><?php esc_html_e('Send the secret in the x-gdm-secret header.', 'git-deploy-manager'); ?></p>
            </div>
        </div>
        <?php
    }

    public function render_logs_page(): void {
        $logs = $this->logger->all();
        ?>
        <div class="wrap gdm-wrap">
            <?php $this->render_admin_notices(); ?>

            <h1><?php esc_html_e('Deployment Logs', 'git-deploy-manager'); ?></h1>

            <div class="gdm-card">
                <h2><?php esc_html_e('Recent events', 'git-deploy-manager'); ?></h2>

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Time', 'git-deploy-manager'); ?></th>
                            <th><?php esc_html_e('Level', 'git-deploy-manager'); ?></th>
                            <th><?php esc_html_e('Event', 'git-deploy-manager'); ?></th>
                            <th><?php esc_html_e('Package', 'git-deploy-manager'); ?></th>
                            <th><?php esc_html_e('Repository', 'git-deploy-manager'); ?></th>
                            <th><?php esc_html_e('Branch', 'git-deploy-manager'); ?></th>
                            <th><?php esc_html_e('Source', 'git-deploy-manager'); ?></th>
                            <th><?php esc_html_e('Status', 'git-deploy-manager'); ?></th>
                            <th><?php esc_html_e('Details', 'git-deploy-manager'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($logs)) : ?>
                        <tr>
                            <td colspan="9"><?php esc_html_e('No logs yet.', 'git-deploy-manager'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($logs as $log) : ?>
                            <?php
                            $context = $this->get_log_context($log);
                            $package_label = $this->get_log_context_value(
                                $context,
                                'package_name',
                                $this->get_log_context_value($context, 'package_id', '—')
                            );

                            $repo_owner = $this->get_log_context_value($context, 'repo_owner', '');
                            $repo_name  = $this->get_log_context_value($context, 'repo_name', '');
                            $repository = ($repo_owner !== '' && $repo_name !== '')
                                ? $repo_owner . '/' . $repo_name
                                : '—';

                            $branch = $this->get_log_context_value($context, 'branch', '—');
                            $event  = $this->format_log_label(
                                $this->get_log_context_value($context, 'action', (string) ($log['message'] ?? 'log'))
                            );
                            $source = $this->format_log_label($this->get_log_context_value($context, 'source', '—'));
                            $status = $this->format_log_label($this->get_log_context_value($context, 'status', '—'));
                            $level  = strtolower((string) ($log['level'] ?? 'info'));
                            ?>
                            <tr>
                                <td><strong class="gdm-text-strong"><?php echo esc_html((string) ($log['time'] ?? '')); ?></strong></td>
                                <td><?php $this->render_badge(strtoupper($level), $this->badge_variant_for_level($level)); ?></td>
                                <td><strong class="gdm-text-strong"><?php echo esc_html($event); ?></strong></td>
                                <td><?php echo esc_html($package_label); ?></td>
                                <td><?php echo esc_html($repository); ?></td>
                                <td>
                                    <?php if ($branch !== '—') : ?>
                                        <code><?php echo esc_html($branch); ?></code>
                                    <?php else : ?>
                                        <?php $this->render_badge('—', 'neutral'); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php $this->render_badge($source, 'neutral'); ?></td>
                                <td><?php $this->render_badge($status, $this->badge_variant_for_status(strtolower($status))); ?></td>
                                <td><?php $this->render_log_details($log); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    private function render_badge(string $label, string $variant = 'neutral'): void {
        $allowed = ['success', 'warning', 'error', 'neutral'];

        if (!in_array($variant, $allowed, true)) {
            $variant = 'neutral';
        }

        echo '<span class="gdm-badge gdm-badge--' . esc_attr($variant) . '">' . esc_html($label) . '</span>';
    }

    private function badge_variant_for_level(string $level): string {
        switch ($level) {
            case 'error':
                return 'error';
            case 'warning':
                return 'warning';
            case 'info':
                return 'success';
            default:
                return 'neutral';
        }
    }

    private function badge_variant_for_status(string $status): string {
        switch ($status) {
            case 'success':
                return 'success';
            case 'failed':
            case 'error':
                return 'error';
            case 'started':
            case 'locked':
                return 'warning';
            default:
                return 'neutral';
        }
    }

    private function get_log_context(array $log): array {
        $context = $log['context'] ?? [];

        return is_array($context) ? $context : [];
    }

    private function get_log_context_value(array $context, string $key, string $fallback = '—'): string {
        if (!array_key_exists($key, $context)) {
            return $fallback;
        }

        $value = $context[$key];

        if (is_array($value) || is_object($value)) {
            $value = wp_json_encode($value);
        } elseif (is_bool($value)) {
            $value = $value ? 'yes' : 'no';
        } elseif ($value === null) {
            $value = '';
        }

        $value = trim((string) $value);

        return $value === '' ? $fallback : $value;
    }

    private function format_log_label(string $value): string {
        if ($value === '' || $value === '—') {
            return '—';
        }

        return ucwords(str_replace(['_', '-'], ' ', $value));
    }

    private function format_log_key_label(string $key): string {
        $labels = [
            'package_id'      => 'Package ID',
            'package_name'    => 'Package Name',
            'package_type'    => 'Package Type',
            'repo_owner'      => 'Repo Owner',
            'repo_name'       => 'Repo Name',
            'branch'          => 'Branch',
            'subdirectory'    => 'Subdirectory',
            'plugin_slug'     => 'Directory Slug',
            'plugin_file'     => 'Main File',
            'commit_sha'      => 'Commit SHA',
            'archive_url'     => 'Archive URL',
            'result_message'  => 'Result Message',
            'delivery_id'     => 'Delivery ID',
            'hook_id'         => 'Hook ID',
            'repo'            => 'Repository',
            'visibility'      => 'Visibility',
            'mode'            => 'Mode',
            'source'          => 'Source',
            'status'          => 'Status',
            'action'          => 'Action',
        ];

        if (isset($labels[$key])) {
            return $labels[$key];
        }

        return ucwords(str_replace(['_', '-'], ' ', $key));
    }

    private function render_log_details(array $log): void {
        $context         = $this->get_log_context($log);
        $preferred_order = [
            'result_message',
            'package_id',
            'package_name',
            'package_type',
            'repo_owner',
            'repo_name',
            'branch',
            'subdirectory',
            'plugin_slug',
            'plugin_file',
            'commit_sha',
            'mode',
            'visibility',
            'source',
            'status',
            'archive_url',
            'delivery_id',
            'hook_id',
        ];

        echo '<details>';
        echo '<summary>' . esc_html__('View details', 'git-deploy-manager') . '</summary>';
        echo '<div>';
        echo '<p><strong>' . esc_html__('Message:', 'git-deploy-manager') . '</strong> ' . esc_html((string) ($log['message'] ?? '')) . '</p>';

        if (!empty($context)) {
            echo '<table class="widefat striped" style="width:auto; min-width:420px;">';

            foreach ($preferred_order as $key) {
                if (!array_key_exists($key, $context)) {
                    continue;
                }

                $value = $this->get_log_context_value($context, $key, '');

                if ($value === '') {
                    continue;
                }

                echo '<tr>';
                echo '<th>' . esc_html($this->format_log_key_label($key)) . '</th>';
                echo '<td><code>' . esc_html($value) . '</code></td>';
                echo '</tr>';
            }

            foreach ($context as $key => $value) {
                if (in_array($key, $preferred_order, true)) {
                    continue;
                }

                $display_value = $this->get_log_context_value($context, (string) $key, '');

                if ($display_value === '') {
                    continue;
                }

                echo '<tr>';
                echo '<th>' . esc_html($this->format_log_key_label((string) $key)) . '</th>';
                echo '<td><code>' . esc_html($display_value) . '</code></td>';
                echo '</tr>';
            }

            echo '</table>';
        }

        echo '</div>';
        echo '</details>';
    }
}

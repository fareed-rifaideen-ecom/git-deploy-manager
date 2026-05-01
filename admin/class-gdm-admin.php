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

            // FIX: Always attempt webhook (re-)registration whenever auto_deploy is enabled on
            // save — not only on first save. This ensures the webhook is correctly registered
            // even if the Webhook Secret was set after the package was first created, or if
            // the webhook was deleted from GitHub and needs to be recreated.
            if (!empty($_POST['auto_deploy'])) {
                $package        = $this->packages->find($id);
                $webhook_url    = rest_url('git-deploy-manager/v1/deploy/' . $id);
                $webhook_secret = $this->settings->get('webhook_secret');

                if ($package && $webhook_secret !== '') {
                    $hook_result = $this->provider->register_webhook($package, $webhook_url, $webhook_secret);

                    if (!empty($hook_result['success'])) {
                        $redirect_args['webhook_created'] = 1;
                    } else {
                        $redirect_args['webhook_failed'] = 1;
                        $this->logger->add('error', 'Failed to auto-register GitHub webhook.', [
                            'action'         => 'webhook_registration',
                            'package_id'     => $id,
                            'result_message' => $hook_result['message'] ?? 'Unknown error'
                        ]);
                    }
                } elseif ($webhook_secret === '') {
                    // Webhook secret is missing — flag this clearly so the admin notice can warn the user.
                    $redirect_args['webhook_failed']         = 1;
                    $redirect_args['webhook_no_secret']      = 1;
                }
            }

            wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            exit;
        }

        if ($action === 'link_package') {
            GDM_Security::verify_admin_request('gdm_link_package');

            $installed_package = sanitize_text_field(wp_unslash($_POST['installed_package'] ?? ''));
            $parts = explode(':', $installed_package, 2);
            $package_type = ($parts[0] === 'theme') ? 'theme' : 'plugin';
            $path = $parts[1] ?? '';

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

            // FIX: Same fix applied to link_package — always re-register webhook on save.
            if (!empty($_POST['auto_deploy'])) {
                $package        = $this->packages->find($id);
                $webhook_url    = rest_url('git-deploy-manager/v1/deploy/' . $id);
                $webhook_secret = $this->settings->get('webhook_secret');

                if ($package && $webhook_secret !== '') {
                    $hook_result = $this->provider->register_webhook($package, $webhook_url, $webhook_secret);

                    if (!empty($hook_result['success'])) {
                        $redirect_args['webhook_created'] = 1;
                    } else {
                        $redirect_args['webhook_failed'] = 1;
                        $this->logger->add('error', 'Failed to auto-register GitHub webhook during package linking.', [
                            'action'         => 'webhook_registration',
                            'package_id'     => $id,
                            'result_message' => $hook_result['message'] ?? 'Unknown error'
                        ]);
                    }
                } elseif ($webhook_secret === '') {
                    $redirect_args['webhook_failed']    = 1;
                    $redirect_args['webhook_no_secret'] = 1;
                }
            }

            wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            exit;
        }

        if ($action === 'rollback_package') {
            GDM_Security::verify_admin_request('gdm_rollback_package');

            $package_id = sanitize_text_field(wp_unslash($_POST['package_id'] ?? ''));
            $result = $this->deployment_service->rollback($package_id, 'admin');

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
            $package = $this->packages->find($package_id);

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

            $result = $this->provider->validate_package($package);
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
                    'result_message' => $message,
                ]
            );

            $this->set_validation_notice(!empty($result['success']) ? 'success' : 'error', $message);

            wp_safe_redirect(
                add_query_arg(
                    ['page' => 'git-deploy-manager'],
                    admin_url('admin.php')
                )
            );
            exit;
        }
    }

    private function set_validation_notice(string $type, string $message): void {
        set_transient('gdm_validation_notice', ['type' => $type, 'message' => $message], 60);
    }

    public function render_packages_page(): void {
        $packages  = $this->packages->all();
        $notice    = get_transient('gdm_validation_notice');
        if ($notice) {
            delete_transient('gdm_validation_notice');
        }
        include GDM_PLUGIN_DIR . 'admin/views/packages.php';
    }

    public function render_settings_page(): void {
        $settings = $this->settings->all();
        include GDM_PLUGIN_DIR . 'admin/views/settings.php';
    }

    public function render_logs_page(): void {
        include GDM_PLUGIN_DIR . 'admin/views/logs.php';
    }
}

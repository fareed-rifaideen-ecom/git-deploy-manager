<?php
if (!defined('ABSPATH')) {
    exit;
}

class GDM_Plugin {
    private GDM_Loader $loader;

    public function __construct() {
        $this->loader = new GDM_Loader();

        $settings = new GDM_Settings();
        $packages = new GDM_Package_Repository();
        $logger = new GDM_Logger();
        $provider = new GDM_GitHub_Provider($settings);
        $deployment_service = new GDM_Deployment_Service($provider, $packages, $logger);
        $admin = new GDM_Admin($settings, $packages, $deployment_service, $provider, $logger);
        $webhooks = new GDM_Webhook_Controller($settings, $deployment_service, $logger);

        $this->loader->add_action('admin_menu', $admin, 'register_menu');
        $this->loader->add_action('admin_enqueue_scripts', $admin, 'enqueue_assets');
        $this->loader->add_action('admin_init', $admin, 'handle_admin_post');
        $this->loader->add_action('rest_api_init', $webhooks, 'register_routes');
    }

    public function run(): void {
        $this->loader->run();
    }
}

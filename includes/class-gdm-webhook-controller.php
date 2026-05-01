<?php
if (!defined('ABSPATH')) {
    exit;
}

class GDM_Webhook_Controller {
    private GDM_Settings $settings;
    private GDM_Deployment_Service $deployment_service;
    private GDM_Logger $logger;
    private GDM_Package_Repository $packages;

    public function __construct(
        GDM_Settings $settings,
        GDM_Deployment_Service $deployment_service,
        GDM_Logger $logger
    ) {
        $this->settings = $settings;
        $this->deployment_service = $deployment_service;
        $this->logger = $logger;
        
        // We instantiate the package repository here so we can look up the branch rules
        // without needing to alter the main plugin loader file.
        $this->packages = new GDM_Package_Repository();
    }

    public function register_routes(): void {
        register_rest_route('git-deploy-manager/v1', '/deploy/(?P<package_id>[a-zA-Z0-9\-]+)', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_deploy'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle_deploy(WP_REST_Request $request): WP_REST_Response {
        $package_id  = sanitize_text_field((string) $request['package_id']);
        $secret      = (string) $this->settings->get('webhook_secret');
        $signature   = (string) $request->get_header('x-hub-signature-256');
        $event       = sanitize_text_field((string) $request->get_header('x-github-event'));
        $delivery_id = sanitize_text_field((string) $request->get_header('x-github-delivery'));
        $raw_body    = (string) $request->get_body();

        if ($secret === '') {
            $this->logger->add('error', 'Webhook rejected because no webhook secret is configured.', [
                'package_id' => $package_id,
            ]);

            return new WP_REST_Response([
                'success' => false,
                'message' => 'Webhook secret is not configured.',
            ], 500);
        }

        if ($signature === '') {
            $this->logger->add('warning', 'Webhook rejected because signature header is missing.', [
                'package_id'  => $package_id,
                'delivery_id' => $delivery_id,
                'event'       => $event,
            ]);

            return new WP_REST_Response([
                'success' => false,
                'message' => 'Missing signature.',
            ], 401);
        }

        $expected_signature = 'sha256=' . hash_hmac('sha256', $raw_body, $secret);

        if (!hash_equals($expected_signature, $signature)) {
            $this->logger->add('warning', 'Webhook rejected because signature validation failed.', [
                'package_id'  => $package_id,
                'delivery_id' => $delivery_id,
                'event'       => $event,
            ]);

            return new WP_REST_Response([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 401);
        }

        if ($event === '') {
            $this->logger->add('warning', 'Webhook rejected because GitHub event header is missing.', [
                'package_id'  => $package_id,
                'delivery_id' => $delivery_id,
            ]);

            return new WP_REST_Response([
                'success' => false,
                'message' => 'Missing GitHub event header.',
            ], 400);
        }

        $payload = json_decode($raw_body, true);

        if (!is_array($payload)) {
            $this->logger->add('warning', 'Webhook rejected because JSON payload is invalid.', [
                'package_id'  => $package_id,
                'delivery_id' => $delivery_id,
                'event'       => $event,
            ]);

            return new WP_REST_Response([
                'success' => false,
                'message' => 'Invalid JSON payload.',
            ], 400);
        }

        if ($event === 'ping') {
            $this->logger->add('info', 'GitHub webhook ping received successfully.', [
                'package_id'  => $package_id,
                'delivery_id' => $delivery_id,
                'hook_id'     => $payload['hook_id'] ?? '',
                'zen'         => $payload['zen'] ?? '',
            ]);

            return new WP_REST_Response([
                'success' => true,
                'message' => 'Ping received successfully.',
            ], 200);
        }

        if ($event !== 'push') {
            $this->logger->add('info', 'GitHub webhook ignored because event is not a push.', [
                'package_id'  => $package_id,
                'delivery_id' => $delivery_id,
                'event'       => $event,
            ]);

            return new WP_REST_Response([
                'success' => true,
                'message' => 'Event ignored.',
            ], 200);
        }

        $ref = isset($payload['ref']) ? sanitize_text_field((string) $payload['ref']) : '';
        $repository = '';

        if (!empty($payload['repository']['full_name'])) {
            $repository = sanitize_text_field((string) $payload['repository']['full_name']);
        }

        // --- Smart Branch Filtering Logic ---
        $package = $this->packages->find($package_id);
        
        if (!$package) {
            $this->logger->add('error', 'GitHub push webhook rejected (package not found).', [
                'package_id'  => $package_id,
                'delivery_id' => $delivery_id,
                'event'       => $event,
            ]);
            
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Package not found.',
            ], 404);
        }

        $target_branch = $package['branch'] ?? 'main';
        $expected_ref = 'refs/heads/' . $target_branch;

        // If the push event was for a different branch, safely ignore it
        if ($ref !== $expected_ref) {
            $this->logger->add('info', 'GitHub push webhook ignored (branch mismatch).', [
                'package_id'      => $package_id,
                'delivery_id'     => $delivery_id,
                'event'           => $event,
                'ref_pushed'      => $ref,
                'expected_branch' => $target_branch,
                'repository'      => $repository,
            ]);
            
            // We return 200 OK so GitHub logs it as a successful delivery (not an error), 
            // even though we chose to ignore it.
            return new WP_REST_Response([
                'success' => true,
                'message' => 'Event ignored (branch mismatch).',
            ], 200); 
        }
        // ------------------------------------

        $this->logger->add('info', 'GitHub push webhook accepted. Starting deployment.', [
            'package_id'  => $package_id,
            'delivery_id' => $delivery_id,
            'event'       => $event,
            'ref'         => $ref,
            'repository'  => $repository,
        ]);

        $result = $this->deployment_service->deploy($package_id);
        $status = !empty($result['success']) ? 200 : 500;

        $this->logger->add(
            !empty($result['success']) ? 'info' : 'error',
            !empty($result['success'])
                ? 'Webhook-triggered deployment completed successfully.'
                : 'Webhook-triggered deployment failed.',
            [
                'package_id'  => $package_id,
                'delivery_id' => $delivery_id,
                'event'       => $event,
                'ref'         => $ref,
                'repository'  => $repository,
            ]
        );

        return new WP_REST_Response($result, $status);
    }
}

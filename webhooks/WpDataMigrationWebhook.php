<?php
if ( ! defined('ABSPATH') ) { exit; }

class WpDataMigrationWebhook {

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register REST route for data migration export.
     */
    public function register_routes(): void {

        register_rest_route('wp-data-migration/v1', '/export', [
            'methods'  => 'GET, POST',
            'callback' => function(WP_REST_Request $request) {
                $helper = new WpDataMigrationHelper();
                return $helper->handleExport($request);
            },
            'permission_callback' => '__return_true',
        ]);

    }

}

new WpDataMigrationWebhook();

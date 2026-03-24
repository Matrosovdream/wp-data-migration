<?php
if ( ! defined('ABSPATH') ) { exit; }

class WpDataMigrationInit {

    public function __construct() {

        // Helpers
        $this->include_helpers();

        // Webhooks
        $this->include_webhooks();

    }

    private function include_helpers() {

        require_once WP_DM_BASE_URL . '/classes/helpers/WpDataMigrationHelper.php';

    }

    private function include_webhooks() {

        require_once WP_DM_BASE_URL . '/webhooks/WpDataMigrationWebhook.php';

    }

}

new WpDataMigrationInit();

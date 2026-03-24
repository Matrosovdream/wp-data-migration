<?php
if ( ! defined('ABSPATH') ) { exit; }

class WpDataMigrationHelper {

    /**
     * Handle export request.
     */
    public function handleExport(WP_REST_Request $request): WP_REST_Response {

        $auth = $this->validatePassword($request);
        if ( $auth !== true ) {
            return $auth;
        }

        $table = $this->resolveTable($request);
        if ( $table instanceof WP_REST_Response ) {
            return $table;
        }

        $exists = $this->tableExists($table);
        if ( $exists instanceof WP_REST_Response ) {
            return $exists;
        }

        return $this->queryTable($table, $request);

    }

    private function validatePassword(WP_REST_Request $request) {

        $password = $request->get_param('password');
        if ( empty($password) || $password !== WP_MIGRATION_WEBHOOK_PASSWORD ) {
            return new WP_REST_Response([
                'ok'    => false,
                'error' => 'Unauthorized',
            ], 401);
        }

        return true;

    }

    private function resolveTable(WP_REST_Request $request) {

        $table_param = $request->get_param('table');
        if ( empty($table_param) ) {
            return new WP_REST_Response([
                'ok'    => false,
                'error' => 'Missing required parameter: table',
            ], 400);
        }

        $full_table = 'wp_' . sanitize_text_field($table_param);

        $allowed = defined('WP_MIGRATION_TABLES') ? WP_MIGRATION_TABLES : [];
        if ( ! in_array($full_table, $allowed, true) ) {
            return new WP_REST_Response([
                'ok'    => false,
                'error' => 'Table not allowed: ' . $full_table,
            ], 403);
        }

        return $full_table;

    }

    private function tableExists(string $table) {

        global $wpdb;

        $table_exists = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $table)
        );

        if ( ! $table_exists ) {
            return new WP_REST_Response([
                'ok'    => false,
                'error' => 'Table not found in database: ' . $table,
            ], 404);
        }

        return true;

    }

    private function queryTable(string $table, WP_REST_Request $request): WP_REST_Response {

        global $wpdb;

        $page     = max(1, (int) $request->get_param('page') ?: 1);
        $per_page = (int) $request->get_param('per_page') ?: WP_MIGRATION_PER_PAGE;
        $per_page = max(1, min($per_page, 5000));
        $offset   = ($page - 1) * $per_page;

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");

        $data = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        );

        $total_pages = (int) ceil($total / $per_page);

        return new WP_REST_Response([
            'data' => $data,
            'pagination' => [
                'page'        => $page,
                'per_page'    => $per_page,
                'total'       => $total,
                'total_pages' => $total_pages,
            ],
        ], 200);

    }

}

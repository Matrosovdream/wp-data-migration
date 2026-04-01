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

    /**
     * Handle single entry request.
     */
    public function handleEntry(WP_REST_Request $request): WP_REST_Response {

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

        $entry_id = (int) $request->get_param('entry_id');
        if ( empty($entry_id) ) {
            return new WP_REST_Response([
                'ok'    => false,
                'error' => 'Missing required parameter: entry_id',
            ], 400);
        }

        global $wpdb;

        $data = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE `id` = %d",
                $entry_id
            ),
            ARRAY_A
        );

        if ( empty($data) ) {
            return new WP_REST_Response([
                'ok'    => false,
                'error' => 'Entry not found',
            ], 404);
        }

        $data = $this->applyTableHook($table, $data);

        return new WP_REST_Response([
            'data' => $data[0],
        ], 200);

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

        $order = strtoupper($request->get_param('order') ?: 'DESC');
        if ( ! in_array($order, ['ASC', 'DESC'], true) ) {
            $order = 'DESC';
        }

        $data = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` ORDER BY 1 {$order} LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        );

        $data = $this->applyTableHook($table, $data);

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

    private function applyTableHook(string $table, array $data): array {

        $hooks = [
            'wp_frm_items'              => 'hookFrmItems',
            'wp_frm_easypost_shipments' => 'hookEasypostShipments',
            'wp_frm_midigator_preventions' => 'hookMidigatorPreventions',
        ];

        if ( isset($hooks[$table]) ) {
            $data = $this->{$hooks[$table]}($data);
        }

        return $data;

    }

    private function hookFrmItems(array $entries): array {

        if ( empty($entries) ) {
            return $entries;
        }

        global $wpdb;

        $ids = array_column($entries, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $metas = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `wp_frm_item_metas` WHERE `item_id` IN ({$placeholders})",
                ...$ids
            ),
            ARRAY_A
        );

        $metas_by_item = [];
        foreach ( $metas as $meta ) {
            $metas_by_item[ $meta['item_id'] ][] = $meta;
        }

        // Find file fields from wp_frm_fields
        $all_field_ids = array_unique(array_column($metas, 'field_id'));
        $files_by_item = [];

        if ( ! empty($all_field_ids) ) {
            $field_placeholders = implode(',', array_fill(0, count($all_field_ids), '%d'));
            $file_fields = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT id FROM `wp_frm_fields` WHERE `id` IN ({$field_placeholders}) AND `type` IN ('file', 'multi_file')",
                    ...$all_field_ids
                )
            );

            if ( ! empty($file_fields) ) {
                $file_field_map = array_flip($file_fields);

                // Collect all attachment IDs from file metas
                $attachment_ids = [];
                foreach ( $metas as $meta ) {
                    if ( ! isset($file_field_map[ $meta['field_id'] ]) ) {
                        continue;
                    }
                    $value = $meta['meta_value'];
                    $parsed_ids = $this->parseFileMetaValue($value);
                    foreach ( $parsed_ids as $aid ) {
                        $attachment_ids[ $aid ] = true;
                    }
                }

                // Query wp_posts for attachments
                $attachment_map = [];
                if ( ! empty($attachment_ids) ) {
                    $att_ids = array_keys($attachment_ids);
                    $att_placeholders = implode(',', array_fill(0, count($att_ids), '%d'));
                    $attachments = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT ID, guid FROM `wp_posts` WHERE `ID` IN ({$att_placeholders}) AND `post_type` = 'attachment'",
                            ...$att_ids
                        ),
                        ARRAY_A
                    );
                    foreach ( $attachments as $att ) {
                        $attachment_map[ $att['ID'] ] = $att['guid'];
                    }
                }

                // Build files list per item
                foreach ( $metas as $meta ) {
                    if ( ! isset($file_field_map[ $meta['field_id'] ]) ) {
                        continue;
                    }
                    $parsed_ids = $this->parseFileMetaValue($meta['meta_value']);
                    foreach ( $parsed_ids as $aid ) {
                        if ( isset($attachment_map[ $aid ]) ) {
                            $files_by_item[ $meta['item_id'] ][] = [
                                'file_id' => (int) $aid,
                                'url'     => $attachment_map[ $aid ],
                            ];
                        }
                    }
                }
            }
        }

        foreach ( $entries as &$entry ) {
            $entry['metas'] = $metas_by_item[ $entry['id'] ] ?? [];
            $entry['files'] = $files_by_item[ $entry['id'] ] ?? [];
        }

        return $entries;

    }

    /**
     * Parse a file meta value into an array of attachment IDs.
     * Handles single IDs, comma-separated, JSON arrays, and serialized arrays.
     */
    private function parseFileMetaValue($value): array {

        if ( empty($value) ) {
            return [];
        }

        // Single numeric ID
        if ( is_numeric($value) ) {
            return [ (int) $value ];
        }

        // Serialized array
        $unserialized = @unserialize($value);
        if ( is_array($unserialized) ) {
            return array_filter(array_map('intval', $unserialized));
        }

        // JSON array
        $decoded = json_decode($value, true);
        if ( is_array($decoded) ) {
            return array_filter(array_map('intval', $decoded));
        }

        // Comma-separated
        if ( strpos($value, ',') !== false ) {
            return array_filter(array_map('intval', explode(',', $value)));
        }

        return [];

    }

    /**
     * Handle file download request.
     */
    public function handleFileDownload(WP_REST_Request $request): WP_REST_Response {

        $auth = $this->validatePassword($request);
        if ( $auth !== true ) {
            return $auth;
        }

        $file_id = (int) $request->get_param('file_id');
        if ( empty($file_id) ) {
            return new WP_REST_Response([
                'ok'    => false,
                'error' => 'Missing required parameter: file_id',
            ], 400);
        }

        global $wpdb;

        $attachment = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT ID, guid, post_mime_type FROM `wp_posts` WHERE `ID` = %d AND `post_type` = 'attachment'",
                $file_id
            ),
            ARRAY_A
        );

        if ( ! $attachment ) {
            return new WP_REST_Response([
                'ok'    => false,
                'error' => 'Attachment not found',
            ], 404);
        }

        // Resolve file path from attachment metadata
        $upload_dir = wp_upload_dir();
        $attached_file = get_post_meta($file_id, '_wp_attached_file', true);

        if ( empty($attached_file) ) {
            return new WP_REST_Response([
                'ok'    => false,
                'error' => 'File metadata not found',
            ], 404);
        }

        $file_path = $upload_dir['basedir'] . '/' . $attached_file;

        if ( ! file_exists($file_path) || ! is_readable($file_path) ) {
            return new WP_REST_Response([
                'ok'    => false,
                'error' => 'File not found on server',
            ], 404);
        }

        $mime_type = $attachment['post_mime_type'] ?: 'application/octet-stream';
        $filename  = basename($file_path);

        header('Content-Type: ' . $mime_type);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($file_path));

        readfile($file_path);
        exit;

    }

    private function hookEasypostShipments(array $shipments): array {

        if ( empty($shipments) ) {
            return $shipments;
        }

        global $wpdb;

        $easypost_ids = array_column($shipments, 'easypost_id');
        $placeholders = implode(',', array_fill(0, count($easypost_ids), '%s'));

        $sub_tables = [
            'addresses' => 'wp_frm_easypost_shipment_addresses',
            'parcel'    => 'wp_frm_easypost_shipment_parcel',
            'label'     => 'wp_frm_easypost_shipment_label',
            'rate'      => 'wp_frm_easypost_shipment_rate',
            'history'   => 'wp_frm_easypost_shipment_history',
        ];

        $related = [];
        foreach ( $sub_tables as $key => $sub_table ) {
            $fk_column = ( $key === 'history' ) ? 'easypost_shipment_id' : 'easypost_shipment_id';
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `{$sub_table}` WHERE `{$fk_column}` IN ({$placeholders})",
                    ...$easypost_ids
                ),
                ARRAY_A
            );
            foreach ( $rows as $row ) {
                $related[ $key ][ $row[ $fk_column ] ][] = $row;
            }
        }

        foreach ( $shipments as &$shipment ) {
            $eid = $shipment['easypost_id'];
            foreach ( $sub_tables as $key => $sub_table ) {
                $shipment[ $key ] = $related[ $key ][ $eid ] ?? [];
            }
        }

        return $shipments;

    }

    private function hookMidigatorPreventions(array $preventions): array {

        if ( empty($preventions) ) {
            return $preventions;
        }

        global $wpdb;

        $ids = array_column($preventions, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        // Fetch resolves by prevention_id
        $resolves = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `wp_frm_midigator_resolves` WHERE `prevention_id` IN ({$placeholders})",
                ...$ids
            ),
            ARRAY_A
        );
        $resolves_by_prevention = [];
        $resolve_ids = [];
        foreach ( $resolves as $resolve ) {
            $resolves_by_prevention[ $resolve['prevention_id'] ][] = $resolve;
            $resolve_ids[] = $resolve['id'];
        }

        // Fetch resolve history by resolve_id
        $history_by_resolve = [];
        if ( ! empty($resolve_ids) ) {
            $placeholders_r = implode(',', array_fill(0, count($resolve_ids), '%d'));
            $history_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `wp_frm_midigator_resolve_history` WHERE `resolve_id` IN ({$placeholders_r})",
                    ...$resolve_ids
                ),
                ARRAY_A
            );
            foreach ( $history_rows as $row ) {
                $history_by_resolve[ $row['resolve_id'] ][] = $row;
            }
        }

        foreach ( $preventions as &$prevention ) {
            $pid = $prevention['id'];
            $prevention['resolves'] = $resolves_by_prevention[ $pid ] ?? [];
            foreach ( $prevention['resolves'] as &$resolve ) {
                $resolve['resolve_history'] = $history_by_resolve[ $resolve['id'] ] ?? [];
            }
        }

        return $preventions;

    }

}

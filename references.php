<?php
if ( ! defined('ABSPATH') ) { exit; }

/**
 * Webhook password (auto-generated on plugin activation, stored in wp_options)
 */
define('WP_MIGRATION_WEBHOOK_PASSWORD', 'pass123');

/**
 * Default records per page
 */
define('WP_MIGRATION_PER_PAGE', 500);

/**
 * Allowed tables for migration export
 */
define('WP_MIGRATION_TABLES', [
    'wp_frm_easypost_shipment_addresses',
    'wp_frm_easypost_shipment_history',
    'wp_frm_easypost_shipment_label',
    'wp_frm_easypost_shipment_parcel',
    'wp_frm_easypost_shipment_rate',
    'wp_frm_easypost_shipments',
    'wp_frm_fields',
    'wp_frm_forms',
    'wp_frm_item_metas',
    'wp_frm_items',
    'wp_frm_midigator_preventions',
    'wp_frm_midigator_rdr',
    'wp_frm_midigator_resolve_history',
    'wp_frm_midigator_resolves',
    'wp_frm_payments',
    'wp_frm_payments_archive',
    'wp_frm_payments_authnet',
    'wp_frm_payments_failed',
    'wp_frm_refunds_authnet',
    'wp_users',
]);

<?php
/*
Plugin Name: WP Data Migration
Description: Data migration webhook for exporting table data
Version: 1.0.0
Author: Stanislav Matrosov
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Variables
define('WP_DM_BASE_URL', __DIR__);
define('WP_DM_BASE_PATH', plugin_dir_url(__FILE__));

// References
require_once 'references.php';

// Initialize core
require_once 'classes/WpDataMigrationInit.php';

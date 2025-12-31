<?php
/*
Plugin Name: Woo Step-By-Step Finder
Plugin URI: https://github.com/YourUsername/woo-step-finder
Description: A professional, dependent dropdown search engine for WooCommerce.
Version: 1.1.0
Author: Mohamad Sultan
Author URI: https://mohamadsultan.com
Text Domain: woo-step-finder
Domain Path: /languages
License: GPLv2 or later
*/

if ( ! defined( 'ABSPATH' ) ) exit;

function wsf_load_textdomain() {
    load_plugin_textdomain( 'woo-step-finder', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'wsf_load_textdomain' );

// تعريف الثوابت
define( 'WSF_VERSION', '1.1.0' );
define( 'WSF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WSF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// استدعاء الكلاس الأساسي (لاحظ الاسم الجديد للملف)
require_once WSF_PLUGIN_DIR . 'includes/class-wsf-search-engine.php';

// تشغيل الإضافة
function run_wsf_search_engine() {
    new WSF_Search_Engine();
}
run_wsf_search_engine();

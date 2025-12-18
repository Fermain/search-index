<?php
/**
 * Plugin Name: Search Index
 * Description: Generates a minimal JSON search index for posts under uploads/search/index.json.
 * Version: 0.2.0
 * Author: Your Team
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SEARCH_INDEX_PLUGIN_PATH', \plugin_dir_path( __FILE__ ) );
define( 'SEARCH_INDEX_PLUGIN_VERSION', '0.1.3' );

require_once SEARCH_INDEX_PLUGIN_PATH . 'src/Plugin.php';
require_once SEARCH_INDEX_PLUGIN_PATH . 'src/Generator.php';

\add_action( 'plugins_loaded', function() {
    \SearchIndex\Plugin::init();
} );

\register_activation_hook( __FILE__, function() {
    \SearchIndex\Plugin::activate();
} );



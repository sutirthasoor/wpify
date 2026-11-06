<?php
/**
 * Plugin Name: WPIFY
 * Description: Import a published Canva website into WordPress as fully editable Gutenberg, Elementor or Bricks pages.
 * Version: 1.2.0
 * Author: Systems Solution
 * Requires at least: 5.9
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: wpify
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WPIFY_VERSION', '1.2.0' );
define( 'WPIFY_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPIFY_URL', plugin_dir_url( __FILE__ ) );

foreach ( [
	'class-fetcher', 'class-renderer', 'class-parser', 'class-snapshot', 'class-exact', 'class-fonts', 'class-media', 'class-importer', 'class-admin',
	'builders/class-builder-base', 'builders/class-builder-gutenberg',
	'builders/class-builder-elementor', 'builders/class-builder-bricks',
] as $f ) {
	require_once WPIFY_DIR . 'includes/' . $f . '.php';
}

add_action( 'plugins_loaded', [ 'WPIFY_Admin', 'init' ] );
add_action( 'plugins_loaded', [ 'WPIFY_Fonts', 'init' ] );

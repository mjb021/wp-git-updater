<?php
/* 
 * Plugin Name: Wordpress Github updater
 * Plugin URI:        https://github.com/mjb021/wp-git-updater
 * GitHub Plugin URI: https://github.com/mjb021/wp-git-updater
 * Release Asset: true
 * Primary Branch: main
 * Description: A plugin to fill dog breed options in WPForms.
 * Version: 0.1.0
 * Author: Mark Blom
 * License: GPL-3.0+
 * License URI: http://www.gnu.org/licenses/gpl-3.0.txt
 */

// Exit if accessed directly to prevent unauthorized file scanning.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define core plugin path constants for clean inclusion maps.
define( 'GU_CORE_UPDATER_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Load global core functions.
 * Contains the file system scanners, updater transients, and zip extraction overrides.
 */
require_once GU_CORE_UPDATER_PATH . 'includes/functions.php';
require_once GU_CORE_UPDATER_PATH . 'includes/class-df-transient-handler.php';
require_once GU_CORE_UPDATER_PATH . 'includes/class-df-zip-handler.php';

/**
 * Conditional Loading: Only load administrative backend assets if inside wp-admin
 * and the currently logged-in user possesses the required capability.
 */
if ( is_admin() ) {
    add_action( 'init', function() {
        if ( current_user_can( 'manage_options' ) ) {
            require_once GU_CORE_UPDATER_PATH . 'includes/admin-menu.php';
        }
    } );
}
/**
 * GLOBAL HEADERS REGISTRATION: Ensure custom GitHub headers are registered 
 * at the absolute earliest lifecycle point to guarantee detection by get_plugins().
 */
add_filter( 'extra_plugin_headers', function( $extra_headers ) {
    $extra_headers[] = 'GitHub Plugin URI';
    $extra_headers[] = 'GitHub Updater Source';
    $extra_headers[] = 'GitHub Plugin Subdirectory';
    return $extra_headers;
} );

?>
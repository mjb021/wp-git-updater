<?php
/**
 * Global core functions and parser settings for Git Updater Core.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * CORE SCANNER: Retrieves all installed plugins containing a 'GitHub Plugin URI' header entry.
 * Indexes standard WordPress environment requirements dynamically.
 */
function gu_get_github_supported_plugins() {
    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    // Register our custom headers alongside native core requirements
    $header_callback = function( $extra_headers ) {
        $extra_headers[] = 'GitHub Plugin URI';
        $extra_headers[] = 'GitHub Updater Source';
        $extra_headers[] = 'GitHub Plugin Subdirectory';
        return $extra_headers;
    };
    add_filter( 'extra_plugin_headers', $header_callback );

    $all_plugins = get_plugins();
    remove_filter( 'extra_plugin_headers', $header_callback );

    $github_plugins = array();

    foreach ( $all_plugins as $plugin_file => $plugin_data ) {
        if ( ! empty( $plugin_data['GitHub Plugin URI'] ) ) {
            $github_plugins[ $plugin_file ] = array(
                'name'         => isset( $plugin_data['Name'] ) ? $plugin_data['Name'] : '',
                'version'      => isset( $plugin_data['Version'] ) ? $plugin_data['Version'] : '',
                'uri'          => esc_url_raw( $plugin_data['GitHub Plugin URI'] ),
                'source_type'  => ! empty( $plugin_data['GitHub Updater Source'] ) ? sanitize_text_field( $plugin_data['GitHub Updater Source'] ) : 'tags',
                'subdirectory' => ! empty( $plugin_data['GitHub Plugin Subdirectory'] ) ? trim( sanitize_text_field( $plugin_data['GitHub Plugin Subdirectory'] ), '/' ) : '',
                'requires'     => isset( $plugin_data['RequiresWP'] ) ? $plugin_data['RequiresWP'] : '',
                'requires_php' => isset( $plugin_data['RequiresPHP'] ) ? $plugin_data['RequiresPHP'] : '',
                // Dynamically extract the standard 'Tested up to' capability header from the plugin file
                'tested'       => isset( $plugin_data['TestedWP'] ) ? $plugin_data['TestedWP'] : '',
            );
        }
    }

    return $github_plugins;
}

/**
 * HELPER: Extract organization/owner and repository name from a GitHub URL.
 */
function gu_parse_github_url( $url ) {
    $url = rtrim( $url, '/' );
    if ( str_ends_with( $url, '.git' ) ) {
        $url = substr( $url, 0, -4 );
    }

    $path = parse_url( $url, PHP_URL_PATH );
    if ( ! $path ) {
        error_log( 'Git Updater Debug: Failed to parse URL path for ' . $url );
        return false;
    }

    $parts = explode( '/', trim( $path, '/' ) );
    
    // CRITICAL FIX: Extract explicit string array indexes first, then sanitize individually
    if ( count( $parts ) >= 2 ) {
        return [
            'org_name'  => sanitize_text_field( (string) $parts[0] ),
            'repo_name' => sanitize_text_field( (string) $parts[1] )
        ];
    }
    
    error_log( 'Git Updater Debug: URL structure invalid for ' . $url );
    return false;
}

/**
 * HELPER: Construct GitHub API request parameters (Works natively without a token).
 */
function gu_get_github_api_args() {
    $args = [
        'headers' => [ 'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) ],
        'timeout' => 10
    ];

    $token = get_option( 'gu_github_api_token' );
    if ( ! empty( $token ) ) {
        $args['headers']['Authorization'] = 'token ' . trim( $token );
        error_log( 'Git Updater Debug: Using stored GitHub API token for authentication.' );
    } else {
        error_log( 'Git Updater Debug: No API token found. Executing anonymous GitHub API request.' );
    }

    return $args;
}

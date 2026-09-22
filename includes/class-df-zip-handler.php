<?php
/**
 * Class to handle ZIP extraction and subdirectory shifting during updates.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_filter( 'upgrader_source_selection', 'gu_upgrader_source_selection', 10, 4 );

/**
 * ZIP UNPACKER CONTROLLER: Handles directory selection and shifting for custom release structures.
 */
function gu_upgrader_source_selection( $source, $remote_source, $upgrader, $hook_extra ) {
    global $wp_filesystem;

    if ( empty( $hook_extra['plugin'] ) ) {
        return $source;
    }

    $plugin_slug = $hook_extra['plugin'];
    $github_supported_plugins = gu_get_github_supported_plugins();

    if ( ! isset( $github_supported_plugins[ $plugin_slug ] ) ) {
        return $source;
    }

    error_log( "Git Updater Debug: Installation/Upgrade folder manipulation started for [{$plugin_slug}]." );
    error_log( "Git Updater Debug: Current temporary source path: {$source}" );

    $plugin_info = $github_supported_plugins[ $plugin_slug ];
    $wp_plugin_dirname = dirname( $plugin_slug );

    if ( empty( $plugin_info['subdirectory'] ) ) {
        $current_folder_name = basename( rtrim( $source, '/' ) );
        error_log( "Git Updater Debug: No subdirectory defined. Current archive directory root name is: {$current_folder_name}" );
        
        if ( $current_folder_name !== $wp_plugin_dirname ) {
            $new_destination = trailingslashit( $remote_source ) . $wp_plugin_dirname;
            error_log( "Git Updater Debug: Renaming extraction directory path to: {$new_destination}" );
            
            if ( $wp_filesystem->exists( $new_destination ) ) {
                $wp_filesystem->delete( $new_destination, true );
            }
            
            if ( $wp_filesystem->move( $source, $new_destination ) ) {
                return trailingslashit( $new_destination );
            }
        }
        return $source;
    }

    $corrected_source = trailingslashit( $source ) . $plugin_info['subdirectory'] . '/';
    error_log( "Git Updater Debug: Subdirectory detected [{$plugin_info['subdirectory']}]. Target pointer path: {$corrected_source}" );

    if ( $wp_filesystem->is_dir( $corrected_source ) ) {
        $new_destination = trailingslashit( $remote_source ) . $wp_plugin_dirname;
        error_log( "Git Updater Debug: Inner directory verified. Shifting files out to target location: {$new_destination}" );
        
        if ( $wp_filesystem->exists( $new_destination ) ) {
            $wp_filesystem->delete( $new_destination, true );
        }

        if ( $wp_filesystem->move( $corrected_source, $new_destination ) ) {
            error_log( "Git Updater Debug: Folder restructuring completed successfully." );
            return trailingslashit( $new_destination );
        }
    } else {
        error_log( "Git Updater Debug: Target subdirectory [{$plugin_info['subdirectory']}] not found inside archive. Attempting flat folder recovery fallback." );
        $current_folder_name = basename( rtrim( $source, '/' ) );
        
        if ( $current_folder_name !== $wp_plugin_dirname ) {
            $new_destination = trailingslashit( $remote_source ) . $wp_plugin_dirname;
            if ( $wp_filesystem->move( $source, $new_destination ) ) {
                error_log( "Git Updater Debug: Flat recovery fallback renaming successful: {$new_destination}" );
                return trailingslashit( $new_destination );
            }
        }
    }

    return $source;
}

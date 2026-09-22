<?php
/**
 * Transient modifier and plugin details injector hook bindings.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_filter( 'site_transient_update_plugins', 'gu_github_release_updater' );
add_filter( 'plugins_api', 'gu_inject_plugin_details', 20, 3 );

/**
 * UPDATER: Compares versions against GitHub releases and injects them into the update transient.
 */
function gu_github_release_updater( $transient ) {
    if ( ! is_object( $transient ) || ! isset( $transient->checked ) || ! is_array( $transient->checked ) ) {
        return $transient;
    }

    $github_supported_plugins = gu_get_github_supported_plugins();
    if ( empty( $github_supported_plugins ) ) {
        return $transient;
    }

    // Fetch dynamic allowed owners array from settings database
    $allowed_owners = get_option( 'gu_allowed_orgs', [] );
    if ( ! is_array( $allowed_owners ) ) {
        $allowed_owners = [];
    }
    $allowed_owners = array_map( 'strtolower', $allowed_owners );

    if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
        $transient->response = [];
    }
    if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
        $transient->no_update = [];
    }

    foreach ( $transient->checked as $plugin_slug => $current_version ) {
        if ( ! isset( $github_supported_plugins[ $plugin_slug ] ) ) {
            continue; 
        }

        $plugin_info = $github_supported_plugins[ $plugin_slug ];
        $repo_meta   = gu_parse_github_url( $plugin_info['uri'] );

        if ( ! $repo_meta ) {
            continue; 
        }

        // WHITELIST CHECK: Skip plugin if its owner is not checked in admin settings
        $current_owner = strtolower( $repo_meta['org_name'] );
        if ( ! in_array( $current_owner, $allowed_owners, true ) ) {
            continue; 
        }

        $org  = $repo_meta['org_name'];
        $repo = $repo_meta['repo_name'];

        $transient_key = 'gu_gh_release_' . md5( $plugin_slug );
        $release_data  = get_transient( $transient_key );

        if ( false === $release_data ) {
            $api_url = 'https://api.github.com/repos/' . $org . '/' . $repo . '/releases/latest';
            error_log( 'Git Updater Debug: [API CALL] Fetching data from GitHub: ' . $api_url );
            
            $response = wp_remote_get( $api_url, gu_get_github_api_args() );
            
            if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
                set_transient( $transient_key, 'failed', 1 * HOUR_IN_SECONDS );
                continue; 
            }

            $release_data = json_decode( wp_remote_retrieve_body( $response ) );
            if ( ! empty( $release_data ) && isset( $release_data->tag_name ) ) {
                set_transient( $transient_key, $release_data, 12 * HOUR_IN_SECONDS );
            }
        }

        if ( empty( $release_data ) || 'failed' === $release_data || ! isset( $release_data->tag_name ) ) {
            continue;
        }

        $new_version = ltrim( $release_data->tag_name, 'v' );
        $wp_plugin_dirname = dirname( $plugin_slug );
        
        $obj              = new stdClass();
        $obj->id          = '://github.com';
        $obj->slug        = $wp_plugin_dirname; 
        $obj->plugin      = $plugin_slug;
        $obj->new_version = $new_version;
        $obj->url         = 'https://github.com/' . $org . '/' . $repo;
        $obj->package     = '';

        if ( ! empty( $plugin_info['requires'] ) ) {
            $obj->requires = $plugin_info['requires']; // Maps to "Requires at least" header
        }
        if ( ! empty( $plugin_info['requires_php'] ) ) {
            $obj->requires_php = $plugin_info['requires_php']; // Maps to "Requires PHP" header
        }
        // Dynamically assign the "Tested up to" value if defined in headers, fallback to active WP version
        $obj->tested = ! empty( $plugin_info['tested'] ) ? $plugin_info['tested'] : get_bloginfo( 'version' ); 

        // Always supply the tested mark to clear the "Unknown" notice banner
        $obj->tested       = get_bloginfo( 'version' ); 

        if ( 'release' === $plugin_info['source_type'] && ! empty( $release_data->assets ) && is_array( $release_data->assets ) ) {
            foreach ( $release_data->assets as $asset ) {
                if ( str_ends_with( $asset->name, '.zip' ) ) {
                    $obj->package = $asset->browser_download_url;
                    break;
                }
            }
        }

        if ( empty( $obj->package ) && ! empty( $release_data->zipball_url ) ) {
            $token = get_option( 'gu_github_api_token' );
            $obj->package = ! empty( $token ) ? add_query_arg( 'access_token', trim( $token ), $release_data->zipball_url ) : $release_data->zipball_url;
        }

        if ( version_compare( $current_version, $new_version, '<' ) && ! empty( $obj->package ) ) {
            $transient->response[ $plugin_slug ] = $obj;
            unset( $transient->no_update[ $plugin_slug ] );
            
            error_log( "Git Updater Debug: Comparing versions for [" . $plugin_slug . "] -> Local: " . $current_version . " vs Remote: " . $new_version );
            error_log( "Git Updater Debug: UPDATE AVAILABLE. Injected [" . $plugin_slug . "] into transient response array." );
        } else {
            $transient->no_update[ $plugin_slug ] = $obj;
            unset( $transient->response[ $plugin_slug ] );
        }
    }

    return $transient;
}

/**
 * MODAL: Supplies documentation details and changelogs into the WordPress update view.
 */
function gu_inject_plugin_details( $result, $action, $args ) {
    if ( 'plugin_information' !== $action ) {
        return $result;
    }

    $github_supported_plugins = gu_get_github_supported_plugins();
    if ( empty( $github_supported_plugins ) ) {
        return $result;
    }

    $matched_plugin = null;
    $matched_slug   = null;

    foreach ( $github_supported_plugins as $plugin_slug => $plugin_info ) {
        if ( dirname( $plugin_slug ) === $args->slug ) {
            $matched_plugin = $plugin_info;
            $matched_slug   = $plugin_slug;
            break;
        }
    }

    if ( ! $matched_plugin ) {
        return $result;
    }

    $repo_meta = gu_parse_github_url( $matched_plugin['uri'] );
    if ( ! $repo_meta ) {
        return $result;
    }

    // WHITELIST CHECK FOR MODAL DETAILS: Skip if not allowed
    $allowed_owners = get_option( 'gu_allowed_orgs', [] );
    if ( ! is_array( $allowed_owners ) ) {
        $allowed_owners = [];
    }
    $allowed_owners = array_map( 'strtolower', $allowed_owners );
    $current_owner = strtolower( $repo_meta['org_name'] );

    if ( ! in_array( $current_owner, $allowed_owners, true ) ) {
        return $result;
    }

    $org  = $repo_meta['org_name'];
    $repo = $repo_meta['repo_name'];

    $transient_key = 'gu_gh_release_' . md5( $matched_slug );
    $release_data  = get_transient( $transient_key );

    if ( false === $release_data ) {
        $api_url = 'https://github.com' . $org . '/' . $repo . '/releases/latest';
        $response = wp_remote_get( $api_url, gu_get_github_api_args() );

        if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
            $release_data = json_decode( wp_remote_retrieve_body( $response ) );
            set_transient( $transient_key, $release_data, 12 * HOUR_IN_SECONDS );
        }
    }

    $changelog      = 'A new version is available on GitHub.';
    $version_number = $matched_plugin['version']; 

    if ( ! empty( $release_data ) && 'failed' !== $release_data && isset( $release_data->tag_name ) ) {
        $version_number = ltrim( $release_data->tag_name, 'v' );
        if ( ! empty( $release_data->body ) ) {
            $changelog = function_exists( 'wp_markdown_parse' ) ? wp_markdown_parse( $release_data->body ) : wp_kses_post( nl2br( $release_data->body ) ); 
        }
    }

    $res                 = new stdClass();
    $res->name           = $matched_plugin['name'];
    $res->slug           = $args->slug;
    $res->version        = $version_number;
    $res->author         = 'GitHub Repository'; 
    $res->homepage       = $matched_plugin['uri'];
    $res->requires       = '6.0';        
    $res->tested         = get_bloginfo( 'version' );        
    $res->requires_php   = '7.4';        
    $res->sections       = [
        'description' => sprintf( 'Managed via GitHub: %s', esc_url( $matched_plugin['uri'] ) ),
        'changelog'   => $changelog
    ];

    return $res;
}

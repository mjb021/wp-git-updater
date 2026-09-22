<?php
/**
 * Admin Settings Menu and UI Configuration for Git Updater Core.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_menu', 'gu_git_updater_create_menu' );
add_action( 'admin_init', 'gu_git_updater_register_settings' );
add_action( 'admin_init', 'gu_git_updater_handle_cache_flush' );

/**
 * ADMIN SETTINGS: Injects custom settings menu entry directly under Settings.
 */
function gu_git_updater_create_menu() {
    add_options_page(
        'GitHub Updater Settings',     
        'GitHub Updater',              
        'manage_options',              
        'git-updater-settings',     
        'gu_git_updater_settings_page' 
    );
}

/**
 * ADMIN SETTINGS: Registers fields inside the option database.
 */
function gu_git_updater_register_settings() {
    register_setting( 'gu-git-updater-group', 'gu_github_api_token', [
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '',
    ] );

    register_setting( 'gu-git-updater-group', 'gu_allowed_orgs', [
        'type'              => 'array',
        'sanitize_callback' => 'gu_sanitize_orgs_array',
        'default'           => [],
    ] );
}

/**
 * SANITIZER: Ensures the saved organizations array contains only clean text.
 */
function gu_sanitize_orgs_array( $input ) {
    if ( ! is_array( $input ) ) {
        return [];
    }
    return array_map( 'sanitize_text_field', $input );
}

/**
 * CACHE MECHANISM: Listens for manual request inputs to flush transients.
 */
function gu_git_updater_handle_cache_flush() {
    if ( ! isset( $_GET['page'] ) || 'git-updater-settings' !== $_GET['page'] ) {
        return;
    }

    if ( isset( $_GET['action'] ) && 'flush_cache' === $_GET['action'] ) {
        check_admin_referer( 'gu_flush_cache_action', 'gu_nonce' );

        $github_plugins = gu_get_github_supported_plugins();

        if ( ! empty( $github_plugins ) ) {
            foreach ( $github_plugins as $slug => $info ) {
                $transient_key = 'gu_gh_release_' . md5( $slug );
                delete_transient( $transient_key );
            }
        }

        delete_site_transient( 'update_plugins' );
        error_log( 'Git Updater Debug: Manual cache flush requested.' );

        wp_safe_redirect( add_query_arg( array( 'page' => 'git-updater-settings', 'cache_flushed' => '1' ), admin_url( 'options-general.php' ) ) );
        exit;
    }
}
/**
 * ADMIN SETTINGS: Handles UI presentation layout templates maps.
 */
function gu_git_updater_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $monitored = gu_get_github_supported_plugins();
    $available_orgs = [];

    if ( ! empty( $monitored ) ) {
        foreach ( $monitored as $slug => $data ) {
            $repo_meta = gu_parse_github_url( $data['uri'] );
            if ( $repo_meta && ! empty( $repo_meta['org_name'] ) ) {
                $lower_org = strtolower( $repo_meta['org_name'] );
                $available_orgs[ $lower_org ] = $repo_meta['org_name'];
            }
        }
    }

    $saved_orgs = get_option( 'gu_allowed_orgs', [] );
    if ( ! is_array( $saved_orgs ) ) {
        $saved_orgs = [];
    }
    ?>
    <div class="wrap">
        <h1>GitHub Updater Settings</h1>

        <?php if ( isset( $_GET['cache_flushed'] ) && '1' === $_GET['cache_flushed'] ) : ?>
            <div class="notice notice-success is-dismissible">
                <p>GitHub update cache cleared and refreshed successfully!</p>
            </div>
        <?php endif; ?>

        <form method="post" action="options.php" style="margin-bottom: 20px;">
            <?php settings_fields( 'gu-git-updater-group' ); ?>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="gu_github_api_token">GitHub Token (PAT)</label></th>
                        <td>
                            <input type="password" id="gu_github_api_token" name="gu_github_api_token" value="<?php echo esc_attr( get_option( 'gu_github_api_token' ) ); ?>" class="regular-text" autocomplete="off" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Allowed GitHub Owners</th>
                        <td>
                            <?php if ( ! empty( $available_orgs ) ) : ?>
                                <fieldset>
                                    <?php foreach ( $available_orgs as $lower_org => $original_org ) : 
                                        $is_checked = in_array( $lower_org, array_map( 'strtolower', $saved_orgs ), true );
                                        ?>
                                        <label style="display: block; margin-bottom: 6px;">
                                            <input type="checkbox" name="gu_allowed_orgs[]" value="<?php echo esc_attr( $original_org ); ?>" <?php checked( $is_checked ); ?> />
                                            <?php echo esc_html( $original_org ); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </fieldset>
                            <?php else : ?>
                                <p class="description" style="color: #dc3232;">No plugins with a GitHub Plugin URI header detected.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>
            <?php submit_button( 'Save Settings', 'primary', 'submit', false ); ?>
            <?php 
            $refresh_url = wp_nonce_url( add_query_arg( array( 'page' => 'git-updater-settings', 'action' => 'flush_cache' ), admin_url( 'options-general.php' ) ), 'gu_flush_cache_action', 'gu_nonce' );
            ?>
            <a href="<?php echo esc_url( $refresh_url ); ?>" class="button button-secondary" style="margin-left: 10px; vertical-align: middle;">Refresh Updates</a>
        </form>

        <h2>Monitored Git Plugins</h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Current Version</th>
                    <th>Strategy</th>
                    <th>Subdirectory</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( ! empty( $monitored ) ) : 
                    foreach ( $monitored as $slug => $data ) : 
                        $repo_meta = gu_parse_github_url( $data['uri'] );
                        $owner = $repo_meta ? strtolower( $repo_meta['org_name'] ) : '';
                        $is_allowed = in_array( $owner, array_map( 'strtolower', $saved_orgs ), true );
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html( $data['name'] ); ?></strong></td>
                            <td><?php echo esc_html( $data['version'] ); ?></td>
                            <td><mark style="background:#e4e4e4; padding:2px 6px; border-radius:3px; font-size:11px;"><?php echo esc_html( strtoupper( $data['source_type'] ) ); ?></mark></td>
                            <td><?php echo ! empty( $data['subdirectory'] ) ? '<code>' . esc_html( $data['subdirectory'] ) . '</code>' : '<em>(Root)</em>'; ?></td>
                            <td>
                                <?php if ( $is_allowed ) : ?>
                                    <span style="color: #46b450; font-weight: bold;">Active</span>
                                <?php else : ?>
                                    <span style="color: #999;">Ignored</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach;
                else : ?>
                    <tr><td colspan="5">No active plugins found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

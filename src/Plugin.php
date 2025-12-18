<?php

namespace SearchIndex;

class Plugin {

    public const RESOURCE_OPTION = 'search_index_enable_resource_tags';

    public static function init() : void {
        if ( \get_option( self::RESOURCE_OPTION, null ) === null ) {
            \add_option( self::RESOURCE_OPTION, '1' );
        }
        \add_action( 'save_post', [ self::class, 'maybeRebuild' ], 20, 3 );
        \add_action( 'trashed_post', [ self::class, 'rebuild' ], 20, 1 );
        \add_action( 'deleted_post', [ self::class, 'rebuild' ], 20, 1 );
        \add_action( 'transition_post_status', [ self::class, 'onStatusChange' ], 20, 3 );

        \add_action( 'admin_post_search_index_rebuild', [ self::class, 'handleManualRebuild' ] );
        \add_action( 'admin_menu', [ self::class, 'registerMenu' ] );
        \add_action( 'admin_post_search_index_save', [ self::class, 'handleSaveSettings' ] );
        \add_action( 'update_option_yoti-blog-resource-tag-settings', [ self::class, 'rebuildIfResourceExportEnabled' ], 10, 3 );
        \add_action( 'update_option_yoti-blog-categories-settings', [ self::class, 'rebuildIfResourceExportEnabled' ], 10, 3 );
        \add_action( 'update_option_user-profile-details', [ self::class, 'rebuildIfResourceExportEnabled' ], 10, 3 );
    }

    public static function activate() : void {
        if ( \get_option( self::RESOURCE_OPTION, null ) === null ) {
            \add_option( self::RESOURCE_OPTION, '1' );
        }
        self::rebuild();
    }

    public static function handleManualRebuild() : void {
        if ( ! \current_user_can( 'manage_options' ) ) {
            \wp_die( 'Unauthorized' );
        }
        \check_admin_referer( 'search-index-rebuild' );
        self::rebuild();
        \wp_safe_redirect( \wp_get_referer() ? \wp_get_referer() : \admin_url() );
        exit;
    }

    public static function onStatusChange( $new_status, $old_status, $post ) : void {
        if ( $post && $post->post_type === 'post' ) {
            if ( $new_status === 'publish' || $old_status === 'publish' ) {
                self::rebuild();
            }
        }
    }

    public static function maybeRebuild( $post_id, $post, $update ) : void {
        if ( \wp_is_post_revision( $post_id ) || \wp_is_post_autosave( $post_id ) ) { return; }
        if ( $post && $post->post_type === 'post' ) {
            if ( $post->post_status === 'publish' || $post->post_status === 'trash' ) {
                self::rebuild();
            }
        }
    }

    public static function rebuild() : void {
        $gen = new Generator();
        $gen->build();
    }

    public static function rebuildIfResourceExportEnabled( $old_value = null, $value = null, $option = null ) : void {
        if ( self::isResourceExportEnabled() ) {
            self::rebuild();
        }
    }

    private static function isResourceExportEnabled() : bool {
        return (bool) \get_option( self::RESOURCE_OPTION, false );
    }

    public static function registerMenu() : void {
        \add_submenu_page(
            'tools.php',
            'Search Index',
            'Search Index',
            'manage_options',
            'search-index',
            [ self::class, 'renderPage' ]
        );
    }

    public static function renderPage() : void {
        if ( ! \current_user_can( 'manage_options' ) ) { return; }
        $u = \wp_upload_dir();
        $base_dir = isset( $u['basedir'] ) ? $u['basedir'] : '';
        $base_url = isset( $u['baseurl'] ) ? $u['baseurl'] : '';
        $file = \trailingslashit( $base_dir ) . 'search/index.json';
        $url = \trailingslashit( $base_url ) . 'search/index.json';
        $exists = is_string( $file ) && \file_exists( $file );
        $size = $exists ? (int) \filesize( $file ) : 0;
        $mtime = $exists ? (int) \filemtime( $file ) : 0;
        $count = null;
        $version = '';
        $generated = '';
        if ( $exists ) {
            $raw = @\file_get_contents( $file );
            $data = is_string( $raw ) ? \json_decode( $raw, true ) : null;
            if ( is_array( $data ) ) {
                if ( isset( $data['items'] ) && is_array( $data['items'] ) ) { $count = count( $data['items'] ); }
                if ( isset( $data['version'] ) && is_string( $data['version'] ) ) { $version = $data['version']; }
                if ( isset( $data['generatedAt'] ) && is_string( $data['generatedAt'] ) ) { $generated = $data['generatedAt']; }
            }
        }
        $resource_enabled = self::isResourceExportEnabled();
        $resource_file = \trailingslashit( $base_dir ) . 'search/resource-tags.json';
        $resource_url = \trailingslashit( $base_url ) . 'search/resource-tags.json';
        $resource_exists = $resource_enabled && \file_exists( $resource_file );
        $resource_size = $resource_exists ? (int) \filesize( $resource_file ) : 0;
        $resource_mtime = $resource_exists ? (int) \filemtime( $resource_file ) : 0;
        $resource_generated = '';
        $resource_count = null;
        if ( $resource_exists ) {
            $resource_raw = @\file_get_contents( $resource_file );
            $resource_data = is_string( $resource_raw ) ? \json_decode( $resource_raw, true ) : null;
            if ( is_array( $resource_data ) ) {
                if ( isset( $resource_data['posts'] ) && is_array( $resource_data['posts'] ) ) { $resource_count = count( $resource_data['posts'] ); }
                if ( isset( $resource_data['generatedAt'] ) && is_string( $resource_data['generatedAt'] ) ) { $resource_generated = $resource_data['generatedAt']; }
            }
        }
        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">Search Index</h1>';
        echo '<hr class="wp-header-end" />';
        echo '<h2>Index status</h2>';
        echo '<table class="widefat striped fixed" role="presentation">';
        echo '<tbody>';
        echo '<tr><th scope="row" style="width:220px">Path</th><td>' . esc_html( $file ) . '</td></tr>';
        echo '<tr><th scope="row">URL</th><td>' . ( $exists ? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noreferrer">' . esc_html( $url ) . '</a>' : esc_html( $url ) ) . '</td></tr>';
        echo '<tr><th scope="row">Status</th><td>' . ( $exists ? '<span class="dashicons dashicons-yes" style="color:#46b450"></span> Exists' : '<span class="dashicons dashicons-dismiss" style="color:#dc3232"></span> Missing' ) . '</td></tr>';
        echo '<tr><th scope="row">Size</th><td>' . ( $exists ? number_format_i18n( $size ) . ' bytes' : '-' ) . '</td></tr>';
        echo '<tr><th scope="row">Last modified</th><td>' . ( $exists ? esc_html( \date_i18n( 'Y-m-d H:i:s', $mtime ) ) : '-' ) . '</td></tr>';
        echo '<tr><th scope="row">Version</th><td>' . ( $version !== '' ? esc_html( $version ) : '-' ) . '</td></tr>';
        echo '<tr><th scope="row">Items</th><td>' . ( is_int( $count ) ? number_format_i18n( $count ) : '-' ) . '</td></tr>';
        echo '<tr><th scope="row">Resource tag export</th><td>' . ( $resource_enabled ? '<span class="dashicons dashicons-yes" style="color:#46b450"></span> Enabled' : '<span class="dashicons dashicons-minus" style="color:#82878c"></span> Disabled' ) . '</td></tr>';
        if ( $resource_enabled ) {
            echo '<tr><th scope="row">Resource dataset path</th><td>' . esc_html( $resource_file ) . '</td></tr>';
            echo '<tr><th scope="row">Resource dataset URL</th><td>' . ( $resource_exists ? '<a href="' . esc_url( $resource_url ) . '" target="_blank" rel="noreferrer">' . esc_html( $resource_url ) . '</a>' : esc_html( $resource_url ) ) . '</td></tr>';
            echo '<tr><th scope="row">Resource dataset status</th><td>' . ( $resource_exists ? '<span class="dashicons dashicons-yes" style="color:#46b450"></span> Exists' : '<span class="dashicons dashicons-dismiss" style="color:#dc3232"></span> Missing' ) . '</td></tr>';
            echo '<tr><th scope="row">Resource dataset size</th><td>' . ( $resource_exists ? number_format_i18n( $resource_size ) . ' bytes' : '-' ) . '</td></tr>';
            echo '<tr><th scope="row">Resource dataset updated</th><td>' . ( $resource_exists ? esc_html( \date_i18n( 'Y-m-d H:i:s', $resource_mtime ) ) : '-' ) . '</td></tr>';
            echo '<tr><th scope="row">Resource dataset generatedAt</th><td>' . ( $resource_generated !== '' ? esc_html( $resource_generated ) : '-' ) . '</td></tr>';
            echo '<tr><th scope="row">Resource dataset posts</th><td>' . ( is_int( $resource_count ) ? number_format_i18n( $resource_count ) : '-' ) . '</td></tr>';
        }
        echo '</tbody>';
        echo '</table>';
        echo '<hr />';
        echo '<h2>Settings</h2>';
        echo '<form method="post" action="' . esc_url( \admin_url( 'admin-post.php' ) ) . '" class="card">';
        echo '<input type="hidden" name="action" value="search_index_save" />';
        \wp_nonce_field( 'search-index-save' );
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">Resource tag export</th><td>';
        echo '<label><input type="checkbox" name="search_index_enable_resource_tags" value="1" ' . checked( $resource_enabled, true, false ) . ' /> <span>Generate JSON for blog resource tags</span></label>';
        echo '<p class="description">Outputs uploads/search/resource-tags.json for front-end filtering.</p>';
        echo '</td></tr>';
        echo '</tbody></table>';
        submit_button( 'Save settings' );
        echo '</form>';

        echo '<hr />';
        echo '<form method="post" action="' . esc_url( \admin_url( 'admin-post.php' ) ) . '" class="card">';
        echo '<input type="hidden" name="action" value="search_index_rebuild" />';
        \wp_nonce_field( 'search-index-rebuild' );
        submit_button( 'Rebuild index', 'primary' );
        echo '</form>';
        echo '</div>';
    }

    public static function handleSaveSettings() : void {
        if ( ! \current_user_can( 'manage_options' ) ) {
            \wp_die( 'Unauthorized' );
        }
        \check_admin_referer( 'search-index-save' );
        $resource_enabled = isset( $_POST['search_index_enable_resource_tags'] ) && (int) $_POST['search_index_enable_resource_tags'] === 1;
        \update_option( self::RESOURCE_OPTION, $resource_enabled ? '1' : '0' );
        self::rebuild();
        \wp_safe_redirect( \admin_url( 'tools.php?page=search-index' ) );
        exit;
    }
}



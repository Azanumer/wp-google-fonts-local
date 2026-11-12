<?php
/**
 * Plugin Name: WP Google Fonts Local
 * Plugin URI: https://github.com/Azanumer/wp-google-fonts-local
 * Description: Downloads Google Fonts used by your theme to your own uploads folder and serves them locally — faster first paint, no third-party request, GDPR-friendly.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Azan Umer
 * Author URI: https://azanumer.cv
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: wp-google-fonts-local
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WGFL_VERSION', '1.0.0' );
define( 'WGFL_OPTION', 'wgfl_map' ); // md5(source URL) => array( local_url, source_url, downloaded_at ).

/**
 * Where local font files live.
 */
function wgfl_upload_dir() {
    $upload = wp_upload_dir();
    return trailingslashit( $upload['basedir'] ) . 'google-fonts';
}

function wgfl_upload_url() {
    $upload = wp_upload_dir();
    return trailingslashit( $upload['baseurl'] ) . 'google-fonts';
}

/**
 * Rewrite any enqueued Google Fonts stylesheet to a locally hosted copy.
 */
function wgfl_style_loader_src( $src, $handle ) {
    if ( is_admin() || false === strpos( $src, 'fonts.googleapis.com/css' ) ) {
        return $src;
    }

    $local = wgfl_localize_stylesheet( $src );
    return $local ? $local : $src;
}
add_filter( 'style_loader_src', 'wgfl_style_loader_src', 10, 2 );

/**
 * Download a Google Fonts CSS file, fetch its font files, rewrite the URLs
 * to local paths, and return the local stylesheet URL. Results are cached
 * in an option so this runs once per unique Google Fonts URL.
 */
function wgfl_localize_stylesheet( $source_url ) {
    $key = md5( $source_url );
    $map = get_option( WGFL_OPTION, array() );

    if ( isset( $map[ $key ] ) && file_exists( wgfl_upload_dir() . '/' . $key . '.css' ) ) {
        return $map[ $key ]['local_url'];
    }

    $response = wp_remote_get(
        $source_url,
        array(
            'timeout'    => 20,
            // A modern browser UA makes Google return woff2 (smallest) files.
            'user-agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
        )
    );
    if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
        return false;
    }

    $css = wp_remote_retrieve_body( $response );
    if ( '' === $css ) {
        return false;
    }

    $dir = wgfl_upload_dir();
    if ( ! wp_mkdir_p( $dir ) ) {
        return false;
    }

    // Download every font file referenced by the CSS and rewrite its URL.
    if ( preg_match_all( '/url\((https:\/\/fonts\.gstatic\.com[^)]+)\)/', $css, $matches ) ) {
        $seen = array();
        foreach ( array_unique( $matches[1] ) as $font_url ) {
            if ( isset( $seen[ $font_url ] ) ) {
                continue;
            }
            $seen[ $font_url ] = true;

            $file_name = md5( $font_url ) . '.' . wgfl_font_extension( $font_url );
            $file_path = $dir . '/' . $file_name;
            if ( ! file_exists( $file_path ) ) {
                $font = wp_remote_get( $font_url, array( 'timeout' => 30 ) );
                if ( is_wp_error( $font ) || 200 !== (int) wp_remote_retrieve_response_code( $font ) ) {
                    continue;
                }
                file_put_contents( $file_path, wp_remote_retrieve_body( $font ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            }
            $css = str_replace( $font_url, wgfl_upload_url() . '/' . $file_name, $css );
        }
    }

    // Strip any URL we could not localize, so no request leaks to Google.
    $css = preg_replace( '/url\(https:\/\/fonts\.gstatic\.com[^)]+\)/', 'url()', $css );

    $css_file = $dir . '/' . $key . '.css';
    file_put_contents( $css_file, $css ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

    $map[ $key ] = array(
        'source_url'    => $source_url,
        'local_url'     => wgfl_upload_url() . '/' . $key . '.css',
        'downloaded_at' => time(),
    );
    update_option( WGFL_OPTION, $map );

    return $map[ $key ]['local_url'];
}

function wgfl_font_extension( $url ) {
    $path = wp_parse_url( $url, PHP_URL_PATH );
    $ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
    return in_array( $ext, array( 'woff2', 'woff', 'ttf' ), true ) ? $ext : 'woff2';
}

/**
 * Weekly refresh: re-download every cached stylesheet so font updates
 * (new subsets, new versions) are picked up.
 */
function wgfl_schedule_cron() {
    if ( ! wp_next_scheduled( 'wgfl_weekly_refresh' ) ) {
        wp_schedule_event( time(), 'weekly', 'wgfl_weekly_refresh' );
    }
}
register_activation_hook( __FILE__, 'wgfl_schedule_cron' );

function wgfl_refresh_all() {
    $map = get_option( WGFL_OPTION, array() );
    foreach ( array_keys( $map ) as $key ) {
        $file = wgfl_upload_dir() . '/' . $key . '.css';
        if ( file_exists( $file ) ) {
            unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        }
        unset( $map[ $key ] );
    }
    update_option( WGFL_OPTION, $map );
}
add_action( 'wgfl_weekly_refresh', 'wgfl_refresh_all' );

/**
 * Tools page: status + manual refresh / clear.
 */
function wgfl_admin_menu() {
    add_management_page(
        __( 'Google Fonts Local', 'wp-google-fonts-local' ),
        __( 'Google Fonts Local', 'wp-google-fonts-local' ),
        'manage_options',
        'wgfl-status',
        'wgfl_tools_page'
    );
}
add_action( 'admin_menu', 'wgfl_admin_menu' );

function wgfl_tools_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    if ( isset( $_POST['wgfl_action'] ) && check_admin_referer( 'wgfl_tools' ) ) {
        $action = sanitize_key( wp_unslash( $_POST['wgfl_action'] ) );
        if ( 'refresh' === $action ) {
            wgfl_refresh_all();
            echo '<div class="notice notice-success"><p>' . esc_html__( 'Cache cleared. Fonts will be re-downloaded on the next front-end visit.', 'wp-google-fonts-local' ) . '</p></div>';
        } elseif ( 'purge' === $action ) {
            wgfl_refresh_all();
            $files = glob( wgfl_upload_dir() . '/*' );
            if ( is_array( $files ) ) {
                foreach ( $files as $file ) {
                    if ( is_file( $file ) ) {
                        unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                    }
                }
            }
            echo '<div class="notice notice-success"><p>' . esc_html__( 'All local font files deleted.', 'wp-google-fonts-local' ) . '</p></div>';
        }
    }

    $map = get_option( WGFL_OPTION, array() );

    echo '<div class="wrap"><h1>' . esc_html__( 'Google Fonts Local', 'wp-google-fonts-local' ) . '</h1>';
    echo '<p>' . esc_html__( 'Google Fonts stylesheets enqueued by your theme are downloaded once and served from your own uploads folder. The next front-end visit re-downloads anything missing.', 'wp-google-fonts-local' ) . '</p>';

    if ( $map ) {
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Source URL', 'wp-google-fonts-local' ) . '</th><th>' . esc_html__( 'Local file', 'wp-google-fonts-local' ) . '</th><th>' . esc_html__( 'Downloaded', 'wp-google-fonts-local' ) . '</th></tr></thead><tbody>';
        foreach ( $map as $entry ) {
            echo '<tr><td>' . esc_html( $entry['source_url'] ) . '</td><td>' . esc_html( basename( $entry['local_url'] ) ) . '</td><td>' . esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $entry['downloaded_at'] ) ) . '</td></tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p>' . esc_html__( 'No fonts localized yet. Visit the front end of your site and they will appear here.', 'wp-google-fonts-local' ) . '</p>';
    }

    echo '<form method="post" style="margin-top:15px;">';
    wp_nonce_field( 'wgfl_tools' );
    echo '<button type="submit" name="wgfl_action" value="refresh" class="button">' . esc_html__( 'Refresh (re-download on next visit)', 'wp-google-fonts-local' ) . '</button> ';
    echo '<button type="submit" name="wgfl_action" value="purge" class="button" onclick="return confirm(\'' . esc_js( __( 'Delete all local font files?', 'wp-google-fonts-local' ) ) . '\')">' . esc_html__( 'Delete all local files', 'wp-google-fonts-local' ) . '</button>';
    echo '</form></div>';
}

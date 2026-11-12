<?php
/**
 * Uninstall: remove the map option, clear the cron, delete local font files.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'wgfl_map' );
wp_clear_scheduled_hook( 'wgfl_weekly_refresh' );

$upload = wp_upload_dir();
$dir    = trailingslashit( $upload['basedir'] ) . 'google-fonts';
if ( is_dir( $dir ) ) {
    $files = glob( $dir . '/*' );
    if ( is_array( $files ) ) {
        foreach ( $files as $file ) {
            if ( is_file( $file ) ) {
                unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            }
        }
    }
    rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

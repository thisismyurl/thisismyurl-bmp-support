<?php
/**
 * Uninstaller for BMP Support by thisismyurl.com.
 *
 * @package TIMU_BMP_Support
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wp_filesystem;
if ( empty( $wp_filesystem ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem();
}

$upload_dir = wp_upload_dir();
$backup_dir = $upload_dir['basedir'] . '/bmp-backups/';
$options    = get_option( 'timu_bmp_support_options', array() );
if ( ! empty( $options['delete_backups_uninstall'] ) && $wp_filesystem && $wp_filesystem->exists( $backup_dir ) ) {
	$wp_filesystem->delete( $backup_dir, true );
}

delete_metadata( 'post', 0, '_timu_bmp_original_path', '', true );
delete_metadata( 'post', 0, '_timu_bmp_savings', '', true );
delete_metadata( 'post', 0, '_timu_bmp_converted_at', '', true );
delete_option( 'timu_bmp_support_options' );
delete_option( 'timu_bmp_environment_status' );

$timestamp = wp_next_scheduled( 'timu_bmp_auto_optimize_event' );
while ( false !== $timestamp ) {
	wp_unschedule_event( (int) $timestamp, 'timu_bmp_auto_optimize_event' );
	$timestamp = wp_next_scheduled( 'timu_bmp_auto_optimize_event' );
}

wp_cache_flush();

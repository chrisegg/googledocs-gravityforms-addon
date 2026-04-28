<?php
/**
 * Fired when the plugin is uninstalled from WordPress (Plugins screen).
 *
 * @package Gravity_Forms\Gravity_Forms_GoogleDocs
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$feeds_table = $wpdb->prefix . 'gf_addon_feed';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $feeds_table ) ) === $feeds_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->delete( $feeds_table, array( 'addon_slug' => 'gravityformsgoogledocs' ), array( '%s' ) );
}

delete_option( 'gf_googledocs_access_token' );
delete_option( 'gravityformsaddon_gravityformsgoogledocs_settings' );

delete_transient( 'gf_googledocs_account_info' );
delete_transient( 'gf_googledocs_api_rate' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_gf_googledocs' ) . '%',
		$wpdb->esc_like( '_transient_timeout_gf_googledocs' ) . '%'
	)
);

$entry_meta = $wpdb->prefix . 'gf_entry_meta';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $entry_meta ) ) === $entry_meta ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->delete( $entry_meta, array( 'meta_key' => 'gfgoogledocs_doc_id' ), array( '%s' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->delete( $entry_meta, array( 'meta_key' => 'gfgoogledocs_doc_url' ), array( '%s' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->delete( $entry_meta, array( 'meta_key' => 'gfgoogledocs_error' ), array( '%s' ) );
}

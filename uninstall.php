<?php
/**
 * Uninstall cleanup: job transients and the temporary export directory.
 *
 * @package WP_to_Markdown
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove all data created by the plugin.
 */
function seoryco_wpmd_uninstall() {
	global $wpdb;

	// Remove leftover job transients (random suffixes, so match by prefix).
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_seoryco_wpmd_job_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_seoryco_wpmd_job_' ) . '%'
		)
	);

	// Remove the temporary export directory.
	$uploads = wp_upload_dir();
	if ( ! empty( $uploads['error'] ) ) {
		return;
	}

	$dir = trailingslashit( $uploads['basedir'] ) . 'seoryco-wpmd-tmp';
	if ( ! is_dir( $dir ) ) {
		return;
	}

	$entries = glob( $dir . '/*' );
	if ( is_array( $entries ) ) {
		foreach ( $entries as $entry ) {
			if ( is_file( $entry ) ) {
				wp_delete_file( $entry );
			}
		}
	}

	$hidden = $dir . '/.htaccess';
	if ( file_exists( $hidden ) ) {
		wp_delete_file( $hidden );
	}

	rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

seoryco_wpmd_uninstall();

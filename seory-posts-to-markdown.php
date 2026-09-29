<?php
/**
 * Plugin Name: Seory Posts to Markdown
 * Plugin URI: https://tools.seory.co.jp/wp-to-markdown
 * Description: Export posts, pages, and custom post types as Markdown files with YAML front matter, bundled into a ZIP archive. AI-ready output; no external service or account required.
 * Version: 0.2
 * Author: seoryco
 * Author URI: https://seory.co.jp
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: seory-posts-to-markdown
 * Requires at least: 6.9
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SEORYCO_WPMD_VERSION', '0.2' );
define( 'SEORYCO_WPMD_PLUGIN_FILE', __FILE__ );
define( 'SEORYCO_WPMD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SEORYCO_WPMD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SEORYCO_WPMD_BATCH_SIZE', 30 );
define( 'SEORYCO_WPMD_JOB_TTL', HOUR_IN_SECONDS );

if ( file_exists( SEORYCO_WPMD_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once SEORYCO_WPMD_PLUGIN_DIR . 'vendor/autoload.php';
}

require_once SEORYCO_WPMD_PLUGIN_DIR . 'includes/class-seoryco-wpmd-markdown.php';
require_once SEORYCO_WPMD_PLUGIN_DIR . 'includes/class-seoryco-wpmd-exporter.php';
require_once SEORYCO_WPMD_PLUGIN_DIR . 'includes/class-seoryco-wpmd-zip.php';
require_once SEORYCO_WPMD_PLUGIN_DIR . 'includes/admin-page.php';
require_once SEORYCO_WPMD_PLUGIN_DIR . 'includes/ajax.php';
require_once SEORYCO_WPMD_PLUGIN_DIR . 'includes/abilities.php';

/**
 * Return the temp directory used for ZIP generation, creating it when needed.
 *
 * @return string|WP_Error Absolute path without trailing slash.
 */
function seoryco_wpmd_get_temp_dir() {
	$uploads = wp_upload_dir();
	if ( ! empty( $uploads['error'] ) ) {
		return new WP_Error( 'seoryco_wpmd_uploads', $uploads['error'] );
	}

	$dir = trailingslashit( $uploads['basedir'] ) . 'seoryco-wpmd-tmp';
	if ( ! wp_mkdir_p( $dir ) ) {
		return new WP_Error( 'seoryco_wpmd_mkdir', __( 'Could not create the temporary export directory.', 'seory-posts-to-markdown' ) );
	}

	// Prevent directory listing and direct access to generated archives.
	if ( ! file_exists( $dir . '/index.php' ) ) {
		file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
	if ( ! file_exists( $dir . '/.htaccess' ) ) {
		file_put_contents( $dir . '/.htaccess', "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	return $dir;
}

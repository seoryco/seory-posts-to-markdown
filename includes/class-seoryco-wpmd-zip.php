<?php
/**
 * Incremental ZIP writer backed by ZipArchive.
 *
 * @package WP_to_Markdown
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seoryco_Wpmd_Zip {

	/**
	 * @var string
	 */
	private $path;

	/**
	 * @param string $path Absolute path of the .zip file (created on first write).
	 */
	public function __construct( $path ) {
		$this->path = $path;
	}

	/**
	 * Append files to the archive.
	 *
	 * @param array $files Map of relative path => content.
	 * @return true|WP_Error
	 */
	public function add_files( array $files ) {
		if ( empty( $files ) ) {
			return true;
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'seoryco_wpmd_zip', 'The PHP zip extension (ZipArchive) is required but not available on this server.' );
		}

		return $this->add_with_ziparchive( $files );
	}

	/**
	 * @param array $files Map of relative path => content.
	 * @return true|WP_Error
	 */
	private function add_with_ziparchive( array $files ) {
		$zip    = new ZipArchive();
		$opened = $zip->open( $this->path, ZipArchive::CREATE );
		if ( true !== $opened ) {
			return new WP_Error( 'seoryco_wpmd_zip', 'ZipArchive open failed with code ' . $opened );
		}

		foreach ( $files as $name => $content ) {
			if ( ! $zip->addFromString( $name, $content ) ) {
				$zip->close();

				return new WP_Error( 'seoryco_wpmd_zip', 'Could not add file to archive: ' . $name );
			}
		}

		if ( ! $zip->close() ) {
			return new WP_Error( 'seoryco_wpmd_zip', 'ZipArchive close failed.' );
		}

		return true;
	}
}

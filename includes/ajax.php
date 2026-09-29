<?php
/**
 * AJAX batch export handlers and ZIP download endpoint.
 *
 * @package WP_to_Markdown
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sanitize and validate export options from the request.
 *
 * @param array $request Raw request array (typically $_POST).
 * @return array
 */
function seoryco_wpmd_parse_options( array $request ) {
	$allowed_types = array_keys( seoryco_wpmd_get_post_types() );

	$post_types = array();
	if ( isset( $request['post_types'] ) && is_array( $request['post_types'] ) ) {
		foreach ( $request['post_types'] as $type ) {
			$type = sanitize_key( wp_unslash( $type ) );
			if ( in_array( $type, $allowed_types, true ) ) {
				$post_types[] = $type;
			}
		}
	}

	$content_mode = isset( $request['content_mode'] ) ? sanitize_key( wp_unslash( $request['content_mode'] ) ) : 'raw';
	$date_folders = isset( $request['date_folders'] ) ? sanitize_key( wp_unslash( $request['date_folders'] ) ) : 'none';

	$date_from = isset( $request['date_from'] ) ? sanitize_text_field( wp_unslash( $request['date_from'] ) ) : '';
	$date_to   = isset( $request['date_to'] ) ? sanitize_text_field( wp_unslash( $request['date_to'] ) ) : '';

	// Per-post-type taxonomy filters: tax_filter[post_type][taxonomy] = term_id.
	$tax_filters = array();
	if ( isset( $request['tax_filter'] ) && is_array( $request['tax_filter'] ) ) {
		foreach ( wp_unslash( $request['tax_filter'] ) as $type => $taxonomies ) {
			$type = sanitize_key( $type );
			if ( ! in_array( $type, $allowed_types, true ) || ! is_array( $taxonomies ) ) {
				continue;
			}

			foreach ( $taxonomies as $taxonomy => $term_id ) {
				$taxonomy = sanitize_key( $taxonomy );
				$term_id  = absint( $term_id );
				$object   = get_taxonomy( $taxonomy );

				if ( $term_id > 0 && $object && $object->public && is_object_in_taxonomy( $type, $taxonomy ) ) {
					$tax_filters[ $type ][ $taxonomy ] = $term_id;
				}
			}
		}
	}

	return array(
		'post_types'     => array_values( array_unique( $post_types ) ),
		'include_drafts' => ! empty( $request['include_drafts'] ),
		'content_mode'   => in_array( $content_mode, array( 'raw', 'rendered' ), true ) ? $content_mode : 'raw',
		'tax_filters'    => $tax_filters,
		'date_from'      => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ? $date_from : '',
		'date_to'        => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ? $date_to : '',
		'post_folders'   => ! empty( $request['post_folders'] ),
		'prefix_date'    => ! empty( $request['prefix_date'] ),
		'date_folders'   => in_array( $date_folders, array( 'none', 'year', 'year-month' ), true ) ? $date_folders : 'none',
		'include_index'  => ! empty( $request['include_index'] ),
	);
}

/**
 * Collect the IDs of all posts matching the options.
 *
 * Queries one post type at a time so taxonomy filters apply per type.
 * Result order: post types in selection order, chronological within a type.
 *
 * @param array $options Parsed options.
 * @return int[]
 */
function seoryco_wpmd_collect_post_ids( array $options ) {
	$ids = array();

	foreach ( $options['post_types'] as $post_type ) {
		$args = array(
			'post_type'           => $post_type,
			'post_status'         => $options['include_drafts'] ? array( 'publish', 'draft' ) : array( 'publish' ),
			'posts_per_page'      => -1,
			'fields'              => 'ids',
			'orderby'             => 'date',
			'order'               => 'ASC',
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		);

		if ( ! empty( $options['tax_filters'][ $post_type ] ) ) {
			$tax_query = array();
			foreach ( $options['tax_filters'][ $post_type ] as $taxonomy => $term_id ) {
				$tax_query[] = array(
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $term_id,
				);
			}
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		if ( '' !== $options['date_from'] || '' !== $options['date_to'] ) {
			$range = array( 'inclusive' => true );
			if ( '' !== $options['date_from'] ) {
				$range['after'] = $options['date_from'] . ' 00:00:00';
			}
			if ( '' !== $options['date_to'] ) {
				$range['before'] = $options['date_to'] . ' 23:59:59';
			}
			$args['date_query'] = array( $range );
		}

		$query = new WP_Query( $args );
		$ids   = array_merge( $ids, array_map( 'intval', $query->posts ) );
	}

	return array_values( array_unique( $ids ) );
}

/**
 * Permission and nonce gate shared by the AJAX handlers.
 */
function seoryco_wpmd_ajax_guard() {
	check_ajax_referer( 'seoryco_wpmd_export', 'nonce' );

	if ( ! current_user_can( 'export' ) ) {
		wp_send_json_error(
			array( 'message' => __( 'You do not have permission to run this export.', 'seory-posts-to-markdown' ) ),
			403
		);
	}
}

/**
 * Start an export job.
 */
function seoryco_wpmd_ajax_start() {
	seoryco_wpmd_ajax_guard();

	$options = seoryco_wpmd_parse_options( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

	if ( empty( $options['post_types'] ) ) {
		wp_send_json_error( array( 'message' => __( 'Select at least one post type.', 'seory-posts-to-markdown' ) ) );
	}

	$exporter = new Seoryco_Wpmd_Exporter( $options );
	if ( ! $exporter->is_available() ) {
		wp_send_json_error( array( 'message' => __( 'The Markdown conversion library is missing. Please reinstall the plugin.', 'seory-posts-to-markdown' ) ) );
	}

	if ( ! class_exists( 'ZipArchive' ) ) {
		wp_send_json_error( array( 'message' => __( 'The PHP zip extension (ZipArchive) is required but not available on this server.', 'seory-posts-to-markdown' ) ) );
	}

	$ids = seoryco_wpmd_collect_post_ids( $options );
	if ( empty( $ids ) ) {
		wp_send_json_error( array( 'message' => __( 'No posts were found to convert.', 'seory-posts-to-markdown' ) ) );
	}

	$temp_dir = seoryco_wpmd_get_temp_dir();
	if ( is_wp_error( $temp_dir ) ) {
		wp_send_json_error( array( 'message' => $temp_dir->get_error_message() ) );
	}

	$job_id     = strtolower( wp_generate_password( 16, false, false ) );
	$used_paths = array( 'README.md' => true );
	if ( $options['include_index'] ) {
		$used_paths['index.md'] = true;
	}

	$job = array(
		'id'         => $job_id,
		'user'       => get_current_user_id(),
		'options'    => $options,
		'ids'        => $ids,
		'position'   => 0,
		'zip_path'   => trailingslashit( $temp_dir ) . 'export-' . $job_id . '.zip',
		'used_paths' => $used_paths,
		'paths'      => array(),
		'index'      => array(),
		'converted'  => 0,
		'skipped'    => 0,
		'drafts'     => 0,
		'types'      => array(),
		'done'       => false,
	);

	set_transient( 'seoryco_wpmd_job_' . $job_id, $job, SEORYCO_WPMD_JOB_TTL );

	wp_send_json_success(
		array(
			'job'   => $job_id,
			'total' => count( $ids ),
		)
	);
}
add_action( 'wp_ajax_seoryco_wpmd_start', 'seoryco_wpmd_ajax_start' );

/**
 * Process the next batch of a job.
 */
function seoryco_wpmd_ajax_step() {
	seoryco_wpmd_ajax_guard();

	$job_id = isset( $_POST['job'] ) ? sanitize_key( wp_unslash( $_POST['job'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$job    = get_transient( 'seoryco_wpmd_job_' . $job_id );

	if ( ! is_array( $job ) || (int) $job['user'] !== get_current_user_id() ) {
		wp_send_json_error( array( 'message' => __( 'Export job not found or expired. Please start again.', 'seory-posts-to-markdown' ) ) );
	}

	$exporter = new Seoryco_Wpmd_Exporter( $job['options'] );
	$zip      = new Seoryco_Wpmd_Zip( $job['zip_path'] );

	$total = count( $job['ids'] );
	$batch = array_slice( $job['ids'], $job['position'], SEORYCO_WPMD_BATCH_SIZE );
	$files = array();

	foreach ( $batch as $post_id ) {
		$file = $exporter->convert_post( $post_id, $job['used_paths'] );

		if ( is_wp_error( $file ) ) {
			$job['skipped']++;
			continue;
		}

		$files[ $file['path'] ] = $file['content'];

		$job['converted']++;
		if ( $file['is_draft'] ) {
			$job['drafts']++;
		}
		$job['types'][ $file['type'] ] = isset( $job['types'][ $file['type'] ] ) ? $job['types'][ $file['type'] ] + 1 : 1;

		if ( count( $job['paths'] ) < 200 ) {
			$job['paths'][] = $file['path'];
		}
		$job['index'][] = array(
			'title' => '' !== $file['title'] ? $file['title'] : $file['path'],
			'path'  => $file['path'],
		);
	}

	$job['position'] += count( $batch );
	$done             = $job['position'] >= $total;

	if ( $done ) {
		if ( $job['options']['include_index'] ) {
			$files['index.md'] = seoryco_wpmd_build_index( $job['index'] );
		}
		$files['README.md'] = seoryco_wpmd_build_readme( $job );
		$job['done']        = true;
	}

	$result = $zip->add_files( $files );
	if ( is_wp_error( $result ) ) {
		delete_transient( 'seoryco_wpmd_job_' . $job_id );
		if ( file_exists( $job['zip_path'] ) ) {
			wp_delete_file( $job['zip_path'] );
		}
		wp_send_json_error( array( 'message' => __( 'Could not write the ZIP archive.', 'seory-posts-to-markdown' ) ) );
	}

	set_transient( 'seoryco_wpmd_job_' . $job_id, $job, SEORYCO_WPMD_JOB_TTL );

	$response = array(
		'processed' => $job['position'],
		'total'     => $total,
		'done'      => $done,
	);

	if ( $done ) {
		$response['summary'] = array(
			'converted' => $job['converted'],
			'skipped'   => $job['skipped'],
			'drafts'    => $job['drafts'],
			'types'     => $job['types'],
			'paths'     => array_slice( $job['paths'], 0, 80 ),
			'pathTotal' => count( $job['index'] ),
		);

		// Note: wp_nonce_url() runs esc_html() on the URL, which breaks it when
		// used via JSON + location.assign, so build the query manually.
		$response['downloadUrl'] = add_query_arg(
			array(
				'action'   => 'seoryco_wpmd_download',
				'job'      => $job_id,
				'_wpnonce' => wp_create_nonce( 'seoryco_wpmd_download_' . $job_id ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	wp_send_json_success( $response );
}
add_action( 'wp_ajax_seoryco_wpmd_step', 'seoryco_wpmd_ajax_step' );

/**
 * Build index.md content.
 *
 * @param array $entries List of { title, path }.
 * @return string
 */
function seoryco_wpmd_build_index( array $entries ) {
	$lines = array( '# Index', '' );
	foreach ( $entries as $entry ) {
		$title   = str_replace( array( '[', ']' ), array( '\[', '\]' ), $entry['title'] );
		$lines[] = '- [' . $title . '](' . $entry['path'] . ')';
	}
	$lines[] = '';

	return implode( "\n", $lines );
}

/**
 * Build README.md content for the archive root.
 *
 * @param array $job Job state.
 * @return string
 */
function seoryco_wpmd_build_readme( array $job ) {
	return implode(
		"\n",
		array(
			'# Seory Posts to Markdown',
			'',
			'- Source: ' . get_bloginfo( 'name' ) . ' (' . home_url( '/' ) . ')',
			'- Exported: ' . wp_date( 'Y-m-d H:i:s' ),
			'- Converted items: ' . $job['converted'],
			'- Content mode: ' . $job['options']['content_mode'],
			'- Images downloaded: no',
			'- Generated by: Seory Posts to Markdown plugin (https://wordpress.org/plugins/seory-posts-to-markdown/)',
			'',
		)
	);
}

/**
 * Stream the finished ZIP and clean up.
 */
function seoryco_wpmd_download() {
	if ( ! current_user_can( 'export' ) ) {
		wp_die( esc_html__( 'You do not have permission to run this export.', 'seory-posts-to-markdown' ) );
	}

	$job_id = isset( $_GET['job'] ) ? sanitize_key( wp_unslash( $_GET['job'] ) ) : '';
	$nonce  = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

	if ( ! wp_verify_nonce( $nonce, 'seoryco_wpmd_download_' . $job_id ) ) {
		wp_die( esc_html__( 'The download link has expired. Please run the export again.', 'seory-posts-to-markdown' ) );
	}

	$job = get_transient( 'seoryco_wpmd_job_' . $job_id );
	if ( ! is_array( $job ) || (int) $job['user'] !== get_current_user_id() || empty( $job['done'] ) || ! file_exists( $job['zip_path'] ) ) {
		wp_die( esc_html__( 'Export job not found or expired. Please start again.', 'seory-posts-to-markdown' ) );
	}

	$site = sanitize_title( get_bloginfo( 'name' ) );
	if ( '' === $site ) {
		$site = 'wordpress-export';
	}

	$filename = $site . '-markdown-' . wp_date( 'Ymd-His' ) . '.zip';

	nocache_headers();
	header( 'Content-Type: application/zip' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	header( 'Content-Length: ' . (string) filesize( $job['zip_path'] ) );

	readfile( $job['zip_path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile

	wp_delete_file( $job['zip_path'] );
	delete_transient( 'seoryco_wpmd_job_' . $job_id );

	exit;
}
add_action( 'admin_post_seoryco_wpmd_download', 'seoryco_wpmd_download' );

<?php
/**
 * Read-only WordPress Abilities (WordPress 6.9+).
 *
 * Registers the `content-export` ability category and four read-only abilities
 * under the `seoryco-wpmd` namespace. Nothing is stored: every call runs a query
 * on the spot and returns the result.
 *
 * The hooks used here only fire on WordPress 6.9 and later, so on older versions
 * nothing in this file runs.
 *
 * Compatibility notes (see docs/spec-v0.2-abilities.md §2):
 * - `show_in_rest` is set explicitly (6.9 / 7.0 do not derive it from `public`).
 * - Before 7.1, REST GET input values arrive as strings, so every callback
 *   normalizes types and applies defaults itself.
 * - 6.9 does not catch exceptions thrown by callbacks, so execute callbacks
 *   catch them and return a WP_Error.
 *
 * Read-only in practice, too: while an ability renders content (list-external-links,
 * get-post-markdown), oEmbed is suspended so no embed provider is contacted and the
 * oEmbed cache is not written (see seoryco_wpmd_ability_without_oembed()). The
 * ZIP export in the admin screen is not affected.
 *
 * Access control (see docs/spec-v0.2-abilities.md §4.5):
 * - A request-level gate (`read` for published-only requests, `edit_others_posts`
 *   otherwise, filterable with `seoryco_wpmd_ability_permission`).
 * - Only publicly viewable post types (is_post_type_viewable()) are served.
 * - Per post type, each status is only queried when the user holds that post
 *   type's own capability (cap->read, cap->read_private_posts, or
 *   cap->edit_others_posts).
 * - Per post, `read_post` (public and private statuses) or `edit_post` (all
 *   other statuses) is checked before anything about the post is returned.
 *
 * @package WP_to_Markdown
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SEORYCO_WPMD_ABILITY_CATEGORY', 'content-export' );
define( 'SEORYCO_WPMD_LINKS_PER_POST', 200 );
define( 'SEORYCO_WPMD_CONTEXT_LENGTH', 200 );
define( 'SEORYCO_WPMD_MAX_HTML_BYTES', 1048576 );

/**
 * Register the ability category.
 */
function seoryco_wpmd_register_ability_category() {
	if ( wp_has_ability_category( SEORYCO_WPMD_ABILITY_CATEGORY ) ) {
		return;
	}

	wp_register_ability_category(
		SEORYCO_WPMD_ABILITY_CATEGORY,
		array(
			'label'       => __( 'Content export', 'seory-posts-to-markdown' ),
			'description' => __( 'Read-only access to site content for export and monitoring: post listings, Markdown conversion, and external links.', 'seory-posts-to-markdown' ),
		)
	);
}
add_action( 'wp_abilities_api_categories_init', 'seoryco_wpmd_register_ability_category' );

/**
 * Meta shared by every ability of this plugin.
 *
 * @return array
 */
function seoryco_wpmd_ability_meta() {
	return array(
		'annotations'  => array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		),
		// Required on 6.9 / 7.0, where show_in_rest is not derived from `public`.
		'show_in_rest' => true,
		// 7.1+ unified exposure flag; also read by MCP Adapter 0.6+.
		'public'       => true,
		// MCP Adapter 0.5 and earlier read only this key.
		'mcp'          => array( 'public' => true ),
	);
}

/**
 * Register the abilities.
 */
function seoryco_wpmd_register_abilities() {
	$statuses = array( 'publish', 'draft', 'pending', 'private', 'future' );

	$status_property = array(
		'type'        => 'array',
		'items'       => array(
			'type' => 'string',
			'enum' => $statuses,
		),
		'default'     => array( 'publish' ),
		'description' => __( 'Post statuses to include. Anything other than publish requires the edit_others_posts capability. Results are further limited to posts the user can read or edit.', 'seory-posts-to-markdown' ),
	);

	$post_types_property = array(
		'type'        => 'array',
		'items'       => array( 'type' => 'string' ),
		'description' => __( 'Post type slugs. Omit for all publicly viewable post types.', 'seory-posts-to-markdown' ),
	);

	$modified_after_property = array(
		'type'        => 'string',
		'format'      => 'date-time',
		'description' => __( 'Datetime in GMT, e.g. 2026-09-01T00:00:00Z (a MySQL-style "YYYY-MM-DD HH:MM:SS" is also accepted). Only posts whose effective modified time (post_modified_gmt, or post_modified converted to GMT when post_modified_gmt is 0000-00-00 00:00:00) is later than this value are returned.', 'seory-posts-to-markdown' ),
	);

	$cursor_property = array(
		'type'        => 'string',
		'pattern'     => '^[0-9]{14}-[0-9]{1,20}$',
		'description' => __( 'Opaque cursor: pass the next_cursor value returned by the previous call to get the next page. Omit it for the first page, and keep the other input values the same on every page.', 'seory-posts-to-markdown' ),
	);

	$next_cursor_property = array(
		'type'        => array( 'string', 'null' ),
		'description' => __( 'Cursor for the next page, or null when there are no more posts. A page can hold fewer items than per_page even when more follow.', 'seory-posts-to-markdown' ),
	);

	$post_item_properties = array(
		'id'           => array( 'type' => 'integer' ),
		'title'        => array( 'type' => 'string' ),
		'slug'         => array( 'type' => 'string' ),
		'type'         => array( 'type' => 'string' ),
		'status'       => array( 'type' => 'string' ),
		'modified_gmt' => array( 'type' => 'string' ),
		'link'         => array(
			'type'   => 'string',
			'format' => 'uri',
		),
	);

	wp_register_ability(
		'seoryco-wpmd/list-posts',
		array(
			'label'               => __( 'List posts modified after a given time', 'seory-posts-to-markdown' ),
			'description'         => __( 'Returns a paginated list of posts/pages/custom post types filtered by modification time, post type, and status. Use to discover what changed since the last sync.', 'seory-posts-to-markdown' ),
			'category'            => SEORYCO_WPMD_ABILITY_CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'default'              => array(),
				'additionalProperties' => false,
				'properties'           => array(
					'modified_after' => $modified_after_property,
					'post_types'     => $post_types_property,
					'status'         => $status_property,
					'cursor'         => $cursor_property,
					'per_page'       => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 50,
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'items'       => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => $post_item_properties,
							'required'   => array( 'id', 'type', 'status', 'modified_gmt' ),
						),
					),
					'per_page'    => array( 'type' => 'integer' ),
					'next_cursor' => $next_cursor_property,
				),
			),
			'execute_callback'    => 'seoryco_wpmd_ability_list_posts',
			'permission_callback' => 'seoryco_wpmd_ability_permission_list_posts',
			'meta'                => seoryco_wpmd_ability_meta(),
		)
	);

	wp_register_ability(
		'seoryco-wpmd/list-post-ids',
		array(
			'label'               => __( 'List all post IDs with status and modified time', 'seory-posts-to-markdown' ),
			'description'         => __( 'Lightweight listing of ID, status, and effective modified time (post_modified_gmt, or post_modified converted to GMT when post_modified_gmt is 0000-00-00 00:00:00) for every post of the given types that the current user can read or edit, including trashed items, in ascending ID order. Intended for detecting deletions, trashing, and unpublishing by diffing against a previously saved list — not for content sync (use list-posts for that).', 'seory-posts-to-markdown' ),
			'category'            => SEORYCO_WPMD_ABILITY_CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'default'              => array(),
				'additionalProperties' => false,
				'properties'           => array(
					'post_types'    => $post_types_property,
					'include_trash' => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'after_id'      => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'default'     => 0,
						'description' => __( 'Return posts with an ID greater than this value. Omit it (or pass 0) for the first page, then pass next_after_id from the previous call.', 'seory-posts-to-markdown' ),
					),
					'per_page'      => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 500,
						'default' => 500,
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'items'         => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'           => array( 'type' => 'integer' ),
								'type'         => array( 'type' => 'string' ),
								'status'       => array( 'type' => 'string' ),
								'modified_gmt' => array( 'type' => 'string' ),
							),
							'required'   => array( 'id', 'type', 'status', 'modified_gmt' ),
						),
					),
					'per_page'      => array( 'type' => 'integer' ),
					'next_after_id' => array(
						'type'        => array( 'integer', 'null' ),
						'description' => __( 'Value to pass as after_id for the next page, or null when there are no more posts.', 'seory-posts-to-markdown' ),
					),
				),
			),
			'execute_callback'    => 'seoryco_wpmd_ability_list_post_ids',
			'permission_callback' => 'seoryco_wpmd_ability_permission_list_post_ids',
			'meta'                => seoryco_wpmd_ability_meta(),
		)
	);

	wp_register_ability(
		'seoryco-wpmd/get-post-markdown',
		array(
			'label'               => __( 'Get one post as Markdown with front matter', 'seory-posts-to-markdown' ),
			'description'         => __( 'Converts a single post to Markdown with YAML front matter, reusing the same conversion used by the ZIP export. Choose raw (stored content) or rendered (the_content filters applied, shortcodes/blocks expanded). In rendered mode, embeds (oEmbed) are not fetched, so each embed stays as its plain URL.', 'seory-posts-to-markdown' ),
			'category'            => SEORYCO_WPMD_ABILITY_CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => array(
					'id'   => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'mode' => array(
						'type'    => 'string',
						'enum'    => array( 'raw', 'rendered' ),
						'default' => 'raw',
					),
				),
				'required'             => array( 'id' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'id'           => array( 'type' => 'integer' ),
					'markdown'     => array(
						'type'        => 'string',
						'description' => __( 'Front matter + body, identical format to one file inside the ZIP export.', 'seory-posts-to-markdown' ),
					),
					'modified_gmt' => array( 'type' => 'string' ),
				),
				'required'   => array( 'id', 'markdown' ),
			),
			'execute_callback'    => 'seoryco_wpmd_ability_get_post_markdown',
			'permission_callback' => 'seoryco_wpmd_ability_permission_get_post_markdown',
			'meta'                => seoryco_wpmd_ability_meta(),
		)
	);

	wp_register_ability(
		'seoryco-wpmd/list-external-links',
		array(
			'label'               => __( 'List external links found in posts', 'seory-posts-to-markdown' ),
			'description'         => __( 'Extracts links to other domains from post content, with anchor text, rel attributes, target, and short surrounding context. Used by clients to monitor for broken links and domain-reuse (expired-domain) hijacking.', 'seory-posts-to-markdown' ),
			'category'            => SEORYCO_WPMD_ABILITY_CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'default'              => array(),
				'additionalProperties' => false,
				'properties'           => array(
					'modified_after' => $modified_after_property,
					'post_types'     => $post_types_property,
					'status'         => $status_property,
					'cursor'         => $cursor_property,
					'per_page'       => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 50,
						'default'     => 20,
						'description' => __( 'Number of posts (not links) per page.', 'seory-posts-to-markdown' ),
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'items'                      => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'url'              => array(
									'type'   => 'string',
									'format' => 'uri',
								),
								'source_post_id'   => array( 'type' => 'integer' ),
								'source_post_link' => array(
									'type'   => 'string',
									'format' => 'uri',
								),
								'anchor_text'      => array( 'type' => 'string' ),
								'rel'              => array(
									'type'        => 'array',
									'items'       => array( 'type' => 'string' ),
									'description' => __( 'Lowercased rel tokens as found in the HTML (e.g. nofollow, sponsored, ugc, noopener, noreferrer, external).', 'seory-posts-to-markdown' ),
								),
								'target'           => array( 'type' => 'string' ),
								'context'          => array(
									'type'        => 'string',
									'description' => __( 'One sentence of surrounding text, truncated to 200 characters.', 'seory-posts-to-markdown' ),
								),
							),
							'required'   => array( 'url', 'source_post_id' ),
						),
					),
					'per_page'                   => array( 'type' => 'integer' ),
					'next_cursor'                => $next_cursor_property,
					'truncated'                  => array(
						'type'        => 'boolean',
						'description' => __( 'true if any post\'s link count was cut off by the per-post link cap.', 'seory-posts-to-markdown' ),
					),
					'content_truncated'          => array(
						'type'        => 'boolean',
						'description' => __( 'true if the rendered HTML of any post was larger than the size limit, so only its first part was scanned. Links after that point are not listed.', 'seory-posts-to-markdown' ),
					),
					'content_truncated_post_ids' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'description' => __( 'IDs of the posts whose rendered HTML was only partly scanned because of the size limit.', 'seory-posts-to-markdown' ),
					),
				),
			),
			'execute_callback'    => 'seoryco_wpmd_ability_list_external_links',
			'permission_callback' => 'seoryco_wpmd_ability_permission_list_external_links',
			'meta'                => seoryco_wpmd_ability_meta(),
		)
	);
}
add_action( 'wp_abilities_api_init', 'seoryco_wpmd_register_abilities' );

/*
 * -------------------------------------------------------------------------
 * Input normalization
 * -------------------------------------------------------------------------
 */

/**
 * Normalize ability input: cast types and apply per-property defaults.
 *
 * Before WordPress 7.1, REST GET input values arrive as strings and property
 * defaults in the input schema are never applied, so this is done here.
 *
 * @param mixed $input    Raw input.
 * @param int   $max_page Maximum allowed per_page value.
 * @param int   $per_page Default per_page value.
 * @return array
 */
function seoryco_wpmd_ability_normalize_list_input( $input, $max_page, $per_page ) {
	$input = is_array( $input ) ? $input : array();

	$statuses = array( 'publish' );
	if ( isset( $input['status'] ) ) {
		$requested = array_values(
			array_intersect(
				array_map( 'sanitize_key', wp_parse_list( $input['status'] ) ),
				array( 'publish', 'draft', 'pending', 'private', 'future' )
			)
		);
		if ( ! empty( $requested ) ) {
			$statuses = array_values( array_unique( $requested ) );
		}
	}

	$post_types = array();
	if ( isset( $input['post_types'] ) ) {
		$post_types = array_values( array_unique( array_filter( array_map( 'sanitize_key', wp_parse_list( $input['post_types'] ) ) ) ) );
	}

	$modified_after = '';
	if ( isset( $input['modified_after'] ) && is_scalar( $input['modified_after'] ) ) {
		$modified_after = trim( (string) $input['modified_after'] );
	}

	$cursor = '';
	if ( isset( $input['cursor'] ) && is_scalar( $input['cursor'] ) ) {
		$cursor = trim( (string) $input['cursor'] );
	}

	$after_id = isset( $input['after_id'] ) && is_scalar( $input['after_id'] ) ? (int) $input['after_id'] : 0;
	$size     = isset( $input['per_page'] ) && is_scalar( $input['per_page'] ) ? (int) $input['per_page'] : $per_page;

	return array(
		'modified_after' => $modified_after,
		'post_types'     => $post_types,
		'status'         => $statuses,
		'include_trash'  => isset( $input['include_trash'] ) ? wp_validate_boolean( $input['include_trash'] ) : true,
		'cursor'         => $cursor,
		'after_id'       => max( 0, $after_id ),
		'per_page'       => min( $max_page, max( 1, $size ) ),
	);
}

/**
 * Post types the abilities may serve: the exportable post types that are also
 * publicly viewable (is_post_type_viewable(), which honors publicly_queryable).
 *
 * @return string[]
 */
function seoryco_wpmd_ability_post_types() {
	$types = array();
	foreach ( seoryco_wpmd_get_post_types() as $name => $object ) {
		if ( is_post_type_viewable( $object ) ) {
			$types[] = $name;
		}
	}

	return $types;
}

/**
 * Resolve requested post types against the post types the abilities may serve.
 *
 * @param string[] $requested Requested slugs (empty for all).
 * @return string[]|WP_Error
 */
function seoryco_wpmd_ability_resolve_post_types( array $requested ) {
	$allowed = seoryco_wpmd_ability_post_types();

	if ( empty( $requested ) ) {
		return $allowed;
	}

	$unknown = array_diff( $requested, $allowed );
	if ( ! empty( $unknown ) ) {
		return new WP_Error(
			'seoryco_wpmd_invalid_post_type',
			sprintf(
				/* translators: %s: comma-separated list of post type slugs. */
				__( 'Unknown or non-public post type: %s', 'seory-posts-to-markdown' ),
				implode( ', ', $unknown )
			),
			array( 'status' => 400 )
		);
	}

	return $requested;
}

/**
 * Convert the modified_after input into a GMT MySQL datetime.
 *
 * @param string $value Input value ('' when not given).
 * @return string|WP_Error '' when not given.
 */
function seoryco_wpmd_ability_parse_modified_after( $value ) {
	if ( '' === $value ) {
		return '';
	}

	$timestamp = strtotime( $value );
	if ( false === $timestamp ) {
		return new WP_Error(
			'seoryco_wpmd_invalid_date',
			__( 'modified_after must be a date-time such as 2026-09-01T00:00:00Z.', 'seory-posts-to-markdown' ),
			array( 'status' => 400 )
		);
	}

	return gmdate( 'Y-m-d H:i:s', $timestamp );
}

/**
 * Parse a list cursor ("YYYYMMDDHHMMSS-ID").
 *
 * @param string $value Input value ('' when not given).
 * @return array|null|WP_Error { modified: 'Y-m-d H:i:s', id: int }, or null when not given.
 */
function seoryco_wpmd_ability_parse_cursor( $value ) {
	if ( '' === $value ) {
		return null;
	}

	if ( ! preg_match( '/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})-(\d{1,20})$/', $value, $m ) ) {
		return new WP_Error(
			'seoryco_wpmd_invalid_cursor',
			__( 'cursor is not valid. Pass the next_cursor value returned by the previous call.', 'seory-posts-to-markdown' ),
			array( 'status' => 400 )
		);
	}

	return array(
		'modified' => sprintf( '%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6] ),
		'id'       => (int) $m[7],
	);
}

/*
 * -------------------------------------------------------------------------
 * Modified time
 * -------------------------------------------------------------------------
 */

/**
 * The site's current UTC offset in seconds.
 *
 * Used to derive a GMT time for posts whose post_modified_gmt is zero. The same
 * value is used in SQL and in PHP so that ordering, filtering, cursors, and the
 * returned modified_gmt all agree within a request.
 *
 * @return int
 */
function seoryco_wpmd_ability_gmt_offset() {
	return (int) round( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
}

/**
 * The effective modified time of a post in GMT ("Y-m-d H:i:s").
 *
 * post_modified_gmt, or, when it is zero (posts inserted directly as drafts),
 * post_modified shifted by the site's current UTC offset. Mirrors
 * seoryco_wpmd_ability_modified_sql() exactly.
 *
 * @param WP_Post $post Post.
 * @return string '0000-00-00 00:00:00' when no usable date exists.
 */
function seoryco_wpmd_ability_effective_modified( $post ) {
	$zero = '0000-00-00 00:00:00';
	$gmt  = (string) $post->post_modified_gmt;
	if ( '' !== $gmt && $zero !== $gmt ) {
		return $gmt;
	}

	$local = (string) $post->post_modified;
	if ( '' === $local || $zero === $local ) {
		return $zero;
	}

	$timestamp = strtotime( $local . ' UTC' );
	if ( false === $timestamp ) {
		return $zero;
	}

	return gmdate( 'Y-m-d H:i:s', $timestamp - seoryco_wpmd_ability_gmt_offset() );
}

/**
 * SQL expression for the effective modified time (see seoryco_wpmd_ability_effective_modified()).
 *
 * @return string
 */
function seoryco_wpmd_ability_modified_sql() {
	global $wpdb;

	return $wpdb->prepare(
		"IF( {$wpdb->posts}.post_modified_gmt = '0000-00-00 00:00:00' AND {$wpdb->posts}.post_modified <> '0000-00-00 00:00:00', DATE_SUB( {$wpdb->posts}.post_modified, INTERVAL %d SECOND ), {$wpdb->posts}.post_modified_gmt )",
		seoryco_wpmd_ability_gmt_offset()
	);
}

/**
 * The post's modified time in GMT as ISO 8601 with a Z suffix.
 *
 * @param WP_Post $post Post.
 * @return string '' when no usable date exists.
 */
function seoryco_wpmd_ability_modified_gmt( $post ) {
	$modified = seoryco_wpmd_ability_effective_modified( $post );
	if ( 0 === strpos( $modified, '0000-00-00' ) ) {
		return '';
	}

	return str_replace( ' ', 'T', $modified ) . 'Z';
}

/**
 * Cursor pointing just after the given post in (effective modified, ID) order.
 *
 * @param WP_Post $post Post.
 * @return string
 */
function seoryco_wpmd_ability_make_cursor( $post ) {
	return str_replace( array( '-', ' ', ':' ), '', seoryco_wpmd_ability_effective_modified( $post ) ) . '-' . (int) $post->ID;
}

/*
 * -------------------------------------------------------------------------
 * Helpers
 * -------------------------------------------------------------------------
 */

/**
 * Plain-text post title without the "Protected:" / "Private:" prefixes.
 *
 * @param WP_Post $post Post.
 * @return string
 */
function seoryco_wpmd_ability_post_title( $post ) {
	add_filter( 'protected_title_format', 'seoryco_wpmd_ability_title_format' );
	add_filter( 'private_title_format', 'seoryco_wpmd_ability_title_format' );
	$title = get_the_title( $post );
	remove_filter( 'protected_title_format', 'seoryco_wpmd_ability_title_format' );
	remove_filter( 'private_title_format', 'seoryco_wpmd_ability_title_format' );

	return html_entity_decode( (string) $title, ENT_QUOTES, 'UTF-8' );
}

/**
 * Title format without a prefix.
 *
 * @return string
 */
function seoryco_wpmd_ability_title_format() {
	return '%s';
}

/**
 * Convert an unexpected exception into a WP_Error.
 *
 * WordPress 6.9 does not catch exceptions thrown by ability callbacks.
 *
 * @param Throwable $e Exception.
 * @return WP_Error
 */
function seoryco_wpmd_ability_exception_error( $e ) {
	return new WP_Error(
		'seoryco_wpmd_ability_exception',
		sprintf(
			/* translators: %s: error message. */
			__( 'The request could not be completed: %s', 'seory-posts-to-markdown' ),
			$e->getMessage()
		),
		array( 'status' => 500 )
	);
}

/*
 * -------------------------------------------------------------------------
 * Permissions
 * -------------------------------------------------------------------------
 */

/**
 * Check the request-level capability after letting the site override it.
 *
 * This is only the first gate. Post type and per-post checks
 * (seoryco_wpmd_ability_allowed_statuses(), seoryco_wpmd_ability_can_access_post())
 * always apply on top of it, so the filter cannot expose posts the user could
 * not otherwise read or edit.
 *
 * @param string $capability   Capability required by default.
 * @param string $ability_name Ability name.
 * @param array  $args         Normalized input.
 * @return bool
 */
function seoryco_wpmd_ability_check_capability( $capability, $ability_name, array $args ) {
	/**
	 * Filters the capability required to run one of this plugin's abilities.
	 *
	 * Return a capability name to require a different capability, or a boolean
	 * to allow or deny the call outright. This controls the request-level gate
	 * only: results are always limited to publicly viewable post types and to
	 * posts the user can read (`read_post`) or edit (`edit_post`), using each
	 * post type's own capabilities.
	 *
	 * @since 0.2
	 *
	 * @param string|bool $capability   Capability name (default: `read` for published-only
	 *                                  requests, `edit_others_posts` otherwise).
	 * @param string      $ability_name Ability name, e.g. `seoryco-wpmd/list-posts`.
	 * @param array       $args         Normalized ability input.
	 */
	$capability = apply_filters( 'seoryco_wpmd_ability_permission', $capability, $ability_name, $args );

	if ( is_bool( $capability ) ) {
		return $capability;
	}

	if ( ! is_string( $capability ) || '' === $capability ) {
		return false;
	}

	return current_user_can( $capability );
}

/**
 * Permission callback for list-posts.
 *
 * @param mixed $input Ability input.
 * @return bool
 */
function seoryco_wpmd_ability_permission_list_posts( $input = null ) {
	$args = seoryco_wpmd_ability_normalize_list_input( $input, 100, 50 );

	return seoryco_wpmd_ability_check_capability( seoryco_wpmd_ability_status_capability( $args['status'] ), 'seoryco-wpmd/list-posts', $args );
}

/**
 * Permission callback for list-external-links.
 *
 * @param mixed $input Ability input.
 * @return bool
 */
function seoryco_wpmd_ability_permission_list_external_links( $input = null ) {
	$args = seoryco_wpmd_ability_normalize_list_input( $input, 50, 20 );

	return seoryco_wpmd_ability_check_capability( seoryco_wpmd_ability_status_capability( $args['status'] ), 'seoryco-wpmd/list-external-links', $args );
}

/**
 * Request-level capability for a set of statuses: `read` for published only, else `edit_others_posts`.
 *
 * @param string[] $statuses Normalized statuses.
 * @return string
 */
function seoryco_wpmd_ability_status_capability( array $statuses ) {
	return ( array( 'publish' ) === $statuses ) ? 'read' : 'edit_others_posts';
}

/**
 * Permission callback for list-post-ids (trash and private items are listed).
 *
 * @param mixed $input Ability input.
 * @return bool
 */
function seoryco_wpmd_ability_permission_list_post_ids( $input = null ) {
	$args = seoryco_wpmd_ability_normalize_list_input( $input, 500, 500 );

	return seoryco_wpmd_ability_check_capability( 'edit_others_posts', 'seoryco-wpmd/list-post-ids', $args );
}

/**
 * The statuses of one post type that the current user may see, using the post
 * type's own capabilities:
 * - public statuses (publish): cap->read
 * - private statuses: cap->read_private_posts
 * - everything else (draft, pending, future, trash, custom): cap->edit_others_posts
 *
 * @param string   $post_type Post type slug.
 * @param string[] $statuses  Candidate statuses.
 * @return string[]
 */
function seoryco_wpmd_ability_allowed_statuses( $post_type, array $statuses ) {
	$object = get_post_type_object( $post_type );
	if ( ! $object instanceof WP_Post_Type ) {
		return array();
	}

	$allowed = array();
	foreach ( $statuses as $status ) {
		$status_object = get_post_status_object( $status );
		if ( ! $status_object ) {
			continue;
		}

		if ( $status_object->public ) {
			$capability = $object->cap->read;
		} elseif ( $status_object->private ) {
			$capability = $object->cap->read_private_posts;
		} else {
			$capability = $object->cap->edit_others_posts;
		}

		if ( is_string( $capability ) && '' !== $capability && current_user_can( $capability ) ) {
			$allowed[] = $status;
		}
	}

	return $allowed;
}

/**
 * Map each post type to the statuses the current user may see (types with none are dropped).
 *
 * @param string[] $post_types Post type slugs.
 * @param string[] $statuses   Candidate statuses.
 * @return array<string, string[]>
 */
function seoryco_wpmd_ability_type_statuses( array $post_types, array $statuses ) {
	$map = array();
	foreach ( $post_types as $post_type ) {
		$allowed = seoryco_wpmd_ability_allowed_statuses( $post_type, $statuses );
		if ( ! empty( $allowed ) ) {
			$map[ $post_type ] = $allowed;
		}
	}

	return $map;
}

/**
 * Whether the current user may receive data about this post.
 *
 * The post type must be served by the abilities (publicly viewable), the status
 * must be allowed by the post type's capabilities, and the user must pass the
 * per-post meta capability: `read_post` for public and private statuses,
 * `edit_post` for everything else (drafts, pending, scheduled, trashed).
 *
 * @param WP_Post $post Post.
 * @return bool
 */
function seoryco_wpmd_ability_can_access_post( $post ) {
	if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, seoryco_wpmd_ability_post_types(), true ) ) {
		return false;
	}

	if ( array( $post->post_status ) !== seoryco_wpmd_ability_allowed_statuses( $post->post_type, array( $post->post_status ) ) ) {
		return false;
	}

	$status_object = get_post_status_object( $post->post_status );
	if ( $status_object && ( $status_object->public || $status_object->private ) ) {
		return current_user_can( 'read_post', $post->ID );
	}

	return current_user_can( 'edit_post', $post->ID );
}

/**
 * Permission callback for get-post-markdown.
 *
 * Returns false for missing posts so that existence is not revealed.
 *
 * @param mixed $input Ability input.
 * @return bool
 */
function seoryco_wpmd_ability_permission_get_post_markdown( $input = null ) {
	$args = seoryco_wpmd_ability_normalize_post_input( $input );
	$post = $args['id'] > 0 ? get_post( $args['id'] ) : null;

	if ( ! $post instanceof WP_Post || post_password_required( $post ) ) {
		return false;
	}

	if ( ! seoryco_wpmd_ability_check_capability( seoryco_wpmd_ability_status_capability( array( $post->post_status ) ), 'seoryco-wpmd/get-post-markdown', $args ) ) {
		return false;
	}

	return seoryco_wpmd_ability_can_access_post( $post );
}

/**
 * Normalize get-post-markdown input.
 *
 * @param mixed $input Raw input.
 * @return array
 */
function seoryco_wpmd_ability_normalize_post_input( $input ) {
	$input = is_array( $input ) ? $input : array();
	$mode  = isset( $input['mode'] ) && is_scalar( $input['mode'] ) ? (string) $input['mode'] : 'raw';

	return array(
		'id'   => isset( $input['id'] ) && is_scalar( $input['id'] ) ? max( 0, (int) $input['id'] ) : 0,
		'mode' => in_array( $mode, array( 'raw', 'rendered' ), true ) ? $mode : 'raw',
	);
}

/*
 * -------------------------------------------------------------------------
 * Queries
 * -------------------------------------------------------------------------
 */

/**
 * Add this plugin's post type/status matrix, filters, cursor, and ordering to
 * its own queries (marked with the `seoryco_wpmd_spec` query variable).
 *
 * @param array    $clauses Query clauses.
 * @param WP_Query $query   Query.
 * @return array
 */
function seoryco_wpmd_ability_posts_clauses( $clauses, $query ) {
	if ( ! $query instanceof WP_Query ) {
		return $clauses;
	}

	$spec = $query->get( 'seoryco_wpmd_spec' );
	if ( ! is_array( $spec ) || empty( $spec['type_statuses'] ) ) {
		return $clauses;
	}

	global $wpdb;

	// Each post type only with the statuses the user may see for that type.
	$pairs = array();
	foreach ( $spec['type_statuses'] as $post_type => $statuses ) {
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name from $wpdb; placeholders are built to match the values.
		$pairs[] = $wpdb->prepare( "( {$wpdb->posts}.post_type = %s AND {$wpdb->posts}.post_status IN ( {$placeholders} ) )", array_merge( array( $post_type ), array_values( $statuses ) ) );
	}
	$where = ' AND ( ' . implode( ' OR ', $pairs ) . ' )';

	if ( 'id' === $spec['order'] ) {
		if ( $spec['after_id'] > 0 ) {
			$where .= $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $spec['after_id'] );
		}
		$clauses['orderby'] = "{$wpdb->posts}.ID ASC";
	} else {
		$modified = seoryco_wpmd_ability_modified_sql();

		if ( '' !== $spec['modified_after'] ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $modified is built with $wpdb->prepare().
			$where .= $wpdb->prepare( " AND {$modified} > %s", $spec['modified_after'] );
		}

		if ( is_array( $spec['cursor'] ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $modified is built with $wpdb->prepare().
			$where .= $wpdb->prepare( " AND ( {$modified} > %s OR ( {$modified} = %s AND {$wpdb->posts}.ID > %d ) )", $spec['cursor']['modified'], $spec['cursor']['modified'], $spec['cursor']['id'] );
		}

		$clauses['orderby'] = "{$modified} ASC, {$wpdb->posts}.ID ASC";
	}

	$clauses['where'] .= $where;

	return $clauses;
}
add_filter( 'posts_clauses', 'seoryco_wpmd_ability_posts_clauses', 10, 2 );

/**
 * Fetch up to $limit + 1 posts (the extra row tells whether another page exists).
 *
 * @param array<string, string[]> $type_statuses Post type => allowed statuses.
 * @param array                   $spec          order (modified|id), modified_after, cursor, after_id.
 * @param int                     $limit         Page size.
 * @return WP_Post[]
 */
function seoryco_wpmd_ability_fetch_posts( array $type_statuses, array $spec, $limit ) {
	if ( empty( $type_statuses ) ) {
		return array();
	}

	$statuses = array();
	foreach ( $type_statuses as $allowed ) {
		$statuses = array_merge( $statuses, $allowed );
	}

	$query = new WP_Query(
		array(
			'post_type'              => array_keys( $type_statuses ),
			'post_status'            => array_values( array_unique( $statuses ) ),
			'posts_per_page'         => $limit + 1,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'suppress_filters'       => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'seoryco_wpmd_spec'      => array_merge(
				array(
					'order'          => 'modified',
					'modified_after' => '',
					'cursor'         => null,
					'after_id'       => 0,
				),
				$spec,
				array( 'type_statuses' => $type_statuses )
			),
		)
	);

	return is_array( $query->posts ) ? $query->posts : array();
}

/**
 * Run the modified-order query shared by list-posts and list-external-links.
 *
 * @param array $args Normalized input.
 * @return array|WP_Error { posts: WP_Post[] (at most per_page), has_more: bool }
 */
function seoryco_wpmd_ability_query_modified( array $args ) {
	$post_types = seoryco_wpmd_ability_resolve_post_types( $args['post_types'] );
	if ( is_wp_error( $post_types ) ) {
		return $post_types;
	}

	$modified_after = seoryco_wpmd_ability_parse_modified_after( $args['modified_after'] );
	if ( is_wp_error( $modified_after ) ) {
		return $modified_after;
	}

	$cursor = seoryco_wpmd_ability_parse_cursor( $args['cursor'] );
	if ( is_wp_error( $cursor ) ) {
		return $cursor;
	}

	$posts = seoryco_wpmd_ability_fetch_posts(
		seoryco_wpmd_ability_type_statuses( $post_types, $args['status'] ),
		array(
			'order'          => 'modified',
			'modified_after' => $modified_after,
			'cursor'         => $cursor,
		),
		$args['per_page']
	);

	return array(
		'posts'    => array_slice( $posts, 0, $args['per_page'] ),
		'has_more' => count( $posts ) > $args['per_page'],
	);
}

/*
 * -------------------------------------------------------------------------
 * Execute callbacks
 * -------------------------------------------------------------------------
 */

/**
 * Execute callback: seoryco-wpmd/list-posts.
 *
 * @param mixed $input Ability input.
 * @return array|WP_Error
 */
function seoryco_wpmd_ability_list_posts( $input = null ) {
	try {
		$args   = seoryco_wpmd_ability_normalize_list_input( $input, 100, 50 );
		$result = seoryco_wpmd_ability_query_modified( $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$items = array();
		foreach ( $result['posts'] as $post ) {
			if ( ! seoryco_wpmd_ability_can_access_post( $post ) ) {
				continue;
			}

			$link    = get_permalink( $post );
			$items[] = array(
				'id'           => (int) $post->ID,
				'title'        => seoryco_wpmd_ability_post_title( $post ),
				'slug'         => rawurldecode( (string) $post->post_name ),
				'type'         => (string) $post->post_type,
				'status'       => (string) $post->post_status,
				'modified_gmt' => seoryco_wpmd_ability_modified_gmt( $post ),
				'link'         => $link ? (string) $link : '',
			);
		}

		$last = end( $result['posts'] );

		return array(
			'items'       => $items,
			'per_page'    => (int) $args['per_page'],
			'next_cursor' => ( $result['has_more'] && $last ) ? seoryco_wpmd_ability_make_cursor( $last ) : null,
		);
	} catch ( Throwable $e ) {
		return seoryco_wpmd_ability_exception_error( $e );
	}
}

/**
 * Execute callback: seoryco-wpmd/list-post-ids.
 *
 * @param mixed $input Ability input.
 * @return array|WP_Error
 */
function seoryco_wpmd_ability_list_post_ids( $input = null ) {
	try {
		$args       = seoryco_wpmd_ability_normalize_list_input( $input, 500, 500 );
		$post_types = seoryco_wpmd_ability_resolve_post_types( $args['post_types'] );
		if ( is_wp_error( $post_types ) ) {
			return $post_types;
		}

		// Every non-internal status (publish, future, draft, pending, private, plus custom ones).
		$statuses = array_values( get_post_stati( array( 'internal' => false ) ) );
		if ( $args['include_trash'] ) {
			$statuses[] = 'trash';
		}

		$posts    = seoryco_wpmd_ability_fetch_posts(
			seoryco_wpmd_ability_type_statuses( $post_types, $statuses ),
			array(
				'order'    => 'id',
				'after_id' => $args['after_id'],
			),
			$args['per_page']
		);
		$has_more = count( $posts ) > $args['per_page'];
		$posts    = array_slice( $posts, 0, $args['per_page'] );

		$items = array();
		foreach ( $posts as $post ) {
			if ( ! seoryco_wpmd_ability_can_access_post( $post ) ) {
				continue;
			}

			$items[] = array(
				'id'           => (int) $post->ID,
				'type'         => (string) $post->post_type,
				'status'       => (string) $post->post_status,
				'modified_gmt' => seoryco_wpmd_ability_modified_gmt( $post ),
			);
		}

		$last = end( $posts );

		return array(
			'items'         => $items,
			'per_page'      => (int) $args['per_page'],
			'next_after_id' => ( $has_more && $last ) ? (int) $last->ID : null,
		);
	} catch ( Throwable $e ) {
		return seoryco_wpmd_ability_exception_error( $e );
	}
}

/**
 * Execute callback: seoryco-wpmd/get-post-markdown.
 *
 * @param mixed $input Ability input.
 * @return array|WP_Error
 */
function seoryco_wpmd_ability_get_post_markdown( $input = null ) {
	try {
		$args = seoryco_wpmd_ability_normalize_post_input( $input );

		// Re-check right before converting, in case the post changed after the permission check.
		if ( ! seoryco_wpmd_ability_permission_get_post_markdown( $args ) ) {
			return new WP_Error(
				'seoryco_wpmd_not_found',
				__( 'The post could not be found.', 'seory-posts-to-markdown' ),
				array( 'status' => 404 )
			);
		}

		$exporter = new Seoryco_Wpmd_Exporter( seoryco_wpmd_ability_exporter_options( $args['mode'] ) );
		if ( ! $exporter->is_available() ) {
			return new WP_Error(
				'seoryco_wpmd_converter_missing',
				__( 'The Markdown conversion library is missing. Please reinstall the plugin.', 'seory-posts-to-markdown' ),
				array( 'status' => 500 )
			);
		}

		// A read-only GET must not fetch embeds or write the oEmbed cache (the ZIP export is not affected).
		$file = seoryco_wpmd_ability_without_oembed(
			static function () use ( $exporter, $args ) {
				$used_paths = array();

				return $exporter->convert_post( $args['id'], $used_paths );
			}
		);
		if ( is_wp_error( $file ) ) {
			return new WP_Error(
				'seoryco_wpmd_not_found',
				__( 'The post could not be found.', 'seory-posts-to-markdown' ),
				array( 'status' => 404 )
			);
		}

		$post = get_post( $args['id'] );

		return array(
			'id'           => (int) $args['id'],
			'markdown'     => (string) $file['content'],
			'modified_gmt' => $post ? seoryco_wpmd_ability_modified_gmt( $post ) : '',
		);
	} catch ( Throwable $e ) {
		return seoryco_wpmd_ability_exception_error( $e );
	}
}

/**
 * Exporter options for single-post conversion (paths are not used).
 *
 * @param string $mode raw|rendered.
 * @return array
 */
function seoryco_wpmd_ability_exporter_options( $mode ) {
	return array(
		'content_mode' => $mode,
		'post_folders' => false,
		'prefix_date'  => false,
		'date_folders' => 'none',
	);
}

/**
 * Execute callback: seoryco-wpmd/list-external-links.
 *
 * @param mixed $input Ability input.
 * @return array|WP_Error
 */
function seoryco_wpmd_ability_list_external_links( $input = null ) {
	try {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return new WP_Error(
				'seoryco_wpmd_dom_missing',
				__( 'The PHP DOM extension is required but not available on this server.', 'seory-posts-to-markdown' ),
				array( 'status' => 500 )
			);
		}

		$args   = seoryco_wpmd_ability_normalize_list_input( $input, 50, 20 );
		$result = seoryco_wpmd_ability_query_modified( $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$exporter  = new Seoryco_Wpmd_Exporter( seoryco_wpmd_ability_exporter_options( 'rendered' ) );
		$site_host = seoryco_wpmd_ability_normalize_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$max_bytes = seoryco_wpmd_ability_max_html_bytes();

		// A GET that lists links must not fetch embeds or write the oEmbed cache.
		$scan = seoryco_wpmd_ability_without_oembed(
			static function () use ( $result, $exporter, $site_host, $max_bytes ) {
				$scan = array(
					'items'                      => array(),
					'truncated'                  => false,
					'content_truncated_post_ids' => array(),
				);

				foreach ( $result['posts'] as $post ) {
					if ( ! seoryco_wpmd_ability_can_access_post( $post ) ) {
						continue;
					}

					// Password-protected content is never exposed (same rule as get-post-markdown).
					if ( post_password_required( $post ) ) {
						continue;
					}

					$found = seoryco_wpmd_ability_extract_links( $exporter->get_content_html( $post ), $site_host, SEORYCO_WPMD_LINKS_PER_POST, $max_bytes );
					if ( $found['truncated'] ) {
						$scan['truncated'] = true;
					}
					if ( $found['content_truncated'] ) {
						$scan['content_truncated_post_ids'][] = (int) $post->ID;
					}

					$post_link = get_permalink( $post );
					foreach ( $found['links'] as $link ) {
						$scan['items'][] = array(
							'url'              => $link['url'],
							'source_post_id'   => (int) $post->ID,
							'source_post_link' => $post_link ? (string) $post_link : '',
							'anchor_text'      => $link['anchor_text'],
							'rel'              => $link['rel'],
							'target'           => $link['target'],
							'context'          => $link['context'],
						);
					}
				}

				return $scan;
			}
		);

		$last = end( $result['posts'] );

		return array(
			'items'                      => $scan['items'],
			'per_page'                   => (int) $args['per_page'],
			'next_cursor'                => ( $result['has_more'] && $last ) ? seoryco_wpmd_ability_make_cursor( $last ) : null,
			'truncated'                  => $scan['truncated'],
			'content_truncated'          => ! empty( $scan['content_truncated_post_ids'] ),
			'content_truncated_post_ids' => $scan['content_truncated_post_ids'],
		);
	} catch ( Throwable $e ) {
		return seoryco_wpmd_ability_exception_error( $e );
	}
}

/*
 * -------------------------------------------------------------------------
 * oEmbed suspension (list-external-links and get-post-markdown)
 * -------------------------------------------------------------------------
 */

/**
 * Shared state of the oEmbed suspension: nesting depth and the WP_Embed
 * filters removed by the outermost call.
 *
 * @return array { depth: int, removed: array, embed_shortcode: callable|null }
 */
function &seoryco_wpmd_ability_oembed_state() {
	static $state = array(
		'depth'           => 0,
		'removed'         => array(),
		'embed_shortcode' => null,
	);

	return $state;
}

/**
 * Run a callback with oEmbed turned off, and turn it back on afterwards.
 *
 * Used by every ability that renders content (list-external-links, and
 * get-post-markdown), so that a read-only GET neither contacts oEmbed providers
 * nor writes the oEmbed cache. Calls may nest (for example when another plugin's
 * `the_content` filter runs an ability while one is already rendering): only the
 * outermost call turns oEmbed back on, also when the callback throws.
 *
 * @param callable $callback Callback.
 * @return mixed The callback's return value.
 */
function seoryco_wpmd_ability_without_oembed( callable $callback ) {
	try {
		seoryco_wpmd_ability_suspend_oembed();

		return $callback();
	} finally {
		seoryco_wpmd_ability_restore_oembed();
	}
}

/**
 * Turn off oEmbed (nestable; see seoryco_wpmd_ability_without_oembed()).
 *
 * On the outermost call:
 * - Removes WP_Embed's `the_content` filters (run_shortcode, autoembed), which
 *   are the normal path for embeds in post content.
 * - Short-circuits any remaining oEmbed request (e.g. core blocks that call
 *   WP_Embed::autoembed() or wp_oembed_get() directly), so no HTTP request is made.
 * - Blocks writes of `_oembed_*` post meta, so the oEmbed cache is not changed.
 * - Points the `[embed]` shortcode (normally only a placeholder that outputs
 *   nothing outside WP_Embed::run_shortcode()) at a handler that outputs the URL
 *   as text, so every embed stays as its plain URL instead of disappearing.
 *
 * Inner calls only increase the depth. Every call must be paired with
 * seoryco_wpmd_ability_restore_oembed() in a finally block.
 */
function seoryco_wpmd_ability_suspend_oembed() {
	global $wp_embed;

	$state = &seoryco_wpmd_ability_oembed_state();
	++$state['depth'];
	if ( $state['depth'] > 1 ) {
		return;
	}

	$state['removed'] = array();
	if ( $wp_embed instanceof WP_Embed ) {
		foreach ( array( 'run_shortcode', 'autoembed' ) as $method ) {
			$priority = has_filter( 'the_content', array( $wp_embed, $method ) );
			if ( false !== $priority ) {
				remove_filter( 'the_content', array( $wp_embed, $method ), $priority );
				$state['removed'][] = array( $method, $priority );
			}
		}
	}

	$state['embed_shortcode'] = isset( $GLOBALS['shortcode_tags']['embed'] ) ? $GLOBALS['shortcode_tags']['embed'] : null;
	add_shortcode( 'embed', 'seoryco_wpmd_ability_embed_as_url' );

	add_filter( 'pre_oembed_result', 'seoryco_wpmd_ability_block_oembed', PHP_INT_MAX );
	add_filter( 'update_post_metadata', 'seoryco_wpmd_ability_block_oembed_cache', PHP_INT_MAX, 3 );
	add_filter( 'add_post_metadata', 'seoryco_wpmd_ability_block_oembed_cache', PHP_INT_MAX, 3 );
}

/**
 * Undo seoryco_wpmd_ability_suspend_oembed(). Only the outermost call restores.
 */
function seoryco_wpmd_ability_restore_oembed() {
	global $wp_embed;

	$state = &seoryco_wpmd_ability_oembed_state();
	if ( $state['depth'] < 1 ) {
		return;
	}

	--$state['depth'];
	if ( $state['depth'] > 0 ) {
		return;
	}

	remove_filter( 'pre_oembed_result', 'seoryco_wpmd_ability_block_oembed', PHP_INT_MAX );
	remove_filter( 'update_post_metadata', 'seoryco_wpmd_ability_block_oembed_cache', PHP_INT_MAX );
	remove_filter( 'add_post_metadata', 'seoryco_wpmd_ability_block_oembed_cache', PHP_INT_MAX );

	if ( null !== $state['embed_shortcode'] ) {
		add_shortcode( 'embed', $state['embed_shortcode'] );
	} else {
		remove_shortcode( 'embed' );
	}
	$state['embed_shortcode'] = null;

	if ( $wp_embed instanceof WP_Embed ) {
		foreach ( $state['removed'] as $filter ) {
			add_filter( 'the_content', array( $wp_embed, $filter[0] ), $filter[1] );
		}
	}
	$state['removed'] = array();
}

/**
 * `[embed]` shortcode while oEmbed is suspended: the URL as plain text.
 *
 * @param array|string $attr    Shortcode attributes.
 * @param string       $content URL between the tags.
 * @return string
 */
function seoryco_wpmd_ability_embed_as_url( $attr, $content = '' ) {
	$url = trim( (string) $content );
	if ( '' === $url && is_array( $attr ) && ! empty( $attr['src'] ) ) {
		$url = trim( (string) $attr['src'] );
	}

	return esc_html( $url );
}

/**
 * Short-circuit remote oEmbed lookups (local results already produced by core are kept).
 *
 * @param string|false|null $result oEmbed HTML, or null to continue.
 * @return string|false
 */
function seoryco_wpmd_ability_block_oembed( $result ) {
	return null === $result ? false : $result;
}

/**
 * Skip writes of oEmbed cache post meta.
 *
 * @param null|bool $check    Short-circuit value.
 * @param int       $post_id  Post ID.
 * @param string    $meta_key Meta key.
 * @return null|bool
 */
function seoryco_wpmd_ability_block_oembed_cache( $check, $post_id, $meta_key ) {
	if ( is_string( $meta_key ) && 0 === strpos( $meta_key, '_oembed_' ) ) {
		return false;
	}

	return $check;
}

/*
 * -------------------------------------------------------------------------
 * Link extraction
 * -------------------------------------------------------------------------
 */

/**
 * Normalize a host for same-site comparison.
 *
 * Percent-decodes and lowercases the host, converts internationalized names to
 * their ASCII (punycode) form, and drops a leading "www." so www/non-www and
 * Unicode/ASCII spellings of the same host compare equal.
 *
 * @param string $host Host.
 * @return string
 */
function seoryco_wpmd_ability_normalize_host( $host ) {
	$host = trim( rawurldecode( (string) $host ), " \t\n\r\0\x0B." );
	$host = function_exists( 'mb_strtolower' ) ? mb_strtolower( $host, 'UTF-8' ) : strtolower( $host );

	if ( preg_match( '/[^\x21-\x7e]/', $host ) ) {
		$host = seoryco_wpmd_ability_idn_to_ascii( $host );
	}

	return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
}

/**
 * Convert an internationalized host name to ASCII.
 *
 * Uses the intl extension when available, otherwise the IDNA encoder bundled
 * with WordPress (Requests). Returns the input unchanged if neither can encode it.
 *
 * @param string $host Lowercased host.
 * @return string
 */
function seoryco_wpmd_ability_idn_to_ascii( $host ) {
	if ( function_exists( 'idn_to_ascii' ) && defined( 'INTL_IDNA_VARIANT_UTS46' ) ) {
		$ascii = idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
		if ( is_string( $ascii ) && '' !== $ascii ) {
			return strtolower( $ascii );
		}
	}

	if ( class_exists( '\WpOrg\Requests\IdnaEncoder' ) ) {
		try {
			return strtolower( \WpOrg\Requests\IdnaEncoder::encode( $host ) );
		} catch ( Exception $e ) {
			return $host;
		}
	}

	return $host;
}

/**
 * Collapse whitespace in a text fragment.
 *
 * @param string $text Text.
 * @return string
 */
function seoryco_wpmd_ability_clean_text( $text ) {
	$text = preg_replace( '/[\s\x{00A0}\x{3000}]+/u', ' ', (string) $text );

	return trim( (string) $text );
}

/**
 * Maximum size in bytes of the rendered HTML that list-external-links parses per post.
 *
 * libxml's memory is not counted against PHP's memory_limit, and a node-dense
 * document takes roughly 20-25 times its size in memory, so an unbounded post
 * could exhaust the server's memory during a GET request. The default of 1 MB
 * (SEORYCO_WPMD_MAX_HTML_BYTES) is several times larger than a very long article
 * (a 30,000-character Japanese article renders to roughly 100-300 KB) while
 * keeping the worst case to about 25 MB and a few tens of milliseconds of parsing.
 *
 * @return int
 */
function seoryco_wpmd_ability_max_html_bytes() {
	/**
	 * Filters the maximum size (bytes) of rendered HTML that list-external-links parses per post.
	 *
	 * Only the first part of a larger post is scanned, and the post is reported
	 * in the `content_truncated_post_ids` output. Only an integer (or a string of
	 * digits) of 1 or more is used; any other value falls back to the default.
	 *
	 * @since 0.2
	 *
	 * @param int $bytes Maximum size in bytes (default 1048576).
	 */
	$bytes = apply_filters( 'seoryco_wpmd_links_max_html_bytes', SEORYCO_WPMD_MAX_HTML_BYTES );

	if ( is_string( $bytes ) && 1 === preg_match( '/^\s*\+?[0-9]+\s*$/', $bytes ) ) {
		$bytes = (int) $bytes;
	}

	return ( is_int( $bytes ) && $bytes > 0 ) ? $bytes : SEORYCO_WPMD_MAX_HTML_BYTES;
}

/**
 * Cut HTML to at most $max_bytes, never inside a tag, so no partial tag (and no
 * partial href) is parsed.
 *
 * The first $max_bytes are scanned from the start: tags are skipped as a whole
 * (quoted attribute values may contain `>` or `<`), as are comments and the
 * contents of raw-text elements (script, style, and the like). If the cut falls
 * inside a tag or comment, the HTML is cut just before its `<`; if it falls in
 * text, it is cut there at a UTF-8 character boundary. So a link whose start tag
 * fits in the limit is kept even when its anchor text runs past it.
 *
 * @param string $html      HTML.
 * @param int    $max_bytes Maximum size in bytes.
 * @return string
 */
function seoryco_wpmd_ability_cut_html( $html, $max_bytes ) {
	$html = (string) $html;
	if ( strlen( $html ) <= $max_bytes ) {
		return $html;
	}

	$head   = substr( $html, 0, $max_bytes );
	$length = strlen( $head );
	$raw    = array( 'script', 'style', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes', 'noscript', 'plaintext' );
	$pos    = 0;

	while ( true ) {
		$open = strpos( $head, '<', $pos );
		if ( false === $open ) {
			break; // The cut is in text.
		}

		$next = substr( $head, $open + 1, 1 );
		if ( '!' === $next && '<!--' === substr( $head, $open, 4 ) ) {
			$end = strpos( $head, '-->', $open + 4 );
			if ( false === $end ) {
				return substr( $head, 0, $open ); // The cut is inside a comment.
			}
			$pos = $end + 3;
			continue;
		}

		if ( '' === $next || ! preg_match( '/[A-Za-z\/!?]/', $next ) ) {
			$pos = $open + 1; // A "<" in text, not a tag.
			continue;
		}

		// Find the ">" that ends this tag, skipping quoted attribute values.
		$scan = $open + 1;
		while ( true ) {
			$scan += strcspn( $head, '"\'>', $scan );
			if ( $scan >= $length ) {
				return substr( $head, 0, $open ); // The cut is inside this tag.
			}
			if ( '>' === $head[ $scan ] ) {
				break;
			}
			$quote = strpos( $head, $head[ $scan ], $scan + 1 );
			if ( false === $quote ) {
				return substr( $head, 0, $open ); // The cut is inside a quoted value.
			}
			$scan = $quote + 1;
		}
		$pos = $scan + 1;

		// Raw-text elements: skip to their end tag (their content is not markup).
		if ( preg_match( '/^<([A-Za-z]+)/', substr( $head, $open, 12 ), $name ) && in_array( strtolower( $name[1] ), $raw, true ) && '/' !== substr( $head, $scan - 1, 1 ) ) {
			$close = stripos( $head, '</' . $name[1], $pos );
			if ( false === $close ) {
				return substr( $head, 0, $open ); // The cut is inside this element.
			}
			$pos = $close;
		}
	}

	// The cut is in text: keep whole UTF-8 characters.
	return function_exists( 'mb_strcut' ) ? mb_strcut( $html, 0, $max_bytes, 'UTF-8' ) : $head;
}

/**
 * Extract external links (http/https to another host) from rendered HTML.
 *
 * Only the first $max_bytes of the HTML are parsed. Stops as soon as one more
 * external link than $limit is found, so link-heavy pages do not build context
 * strings for links that would be discarded.
 *
 * @param string   $html      Rendered post HTML.
 * @param string   $site_host Normalized host of the site.
 * @param int      $limit     Maximum number of links to return.
 * @param int|null $max_bytes Maximum HTML size to parse (default: seoryco_wpmd_ability_max_html_bytes()).
 * @return array { links: array[] (each: url, anchor_text, rel, target, context), truncated: bool, content_truncated: bool }
 */
function seoryco_wpmd_ability_extract_links( $html, $site_host, $limit, $max_bytes = null ) {
	$result = array(
		'links'             => array(),
		'truncated'         => false,
		'content_truncated' => false,
	);

	$html      = (string) $html;
	$max_bytes = null === $max_bytes ? seoryco_wpmd_ability_max_html_bytes() : max( 1, (int) $max_bytes );
	if ( strlen( $html ) > $max_bytes ) {
		$html                        = seoryco_wpmd_ability_cut_html( $html, $max_bytes );
		$result['content_truncated'] = true;
	}

	if ( '' === trim( $html ) || false === stripos( $html, '<a' ) ) {
		return $result;
	}

	$previous = libxml_use_internal_errors( true );
	$document = new DOMDocument();
	$loaded   = $document->loadHTML( '<?xml encoding="utf-8" ?><div>' . $html . '</div>', LIBXML_NONET );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );

	if ( ! $loaded ) {
		return $result;
	}

	$scheme = (string) wp_parse_url( home_url(), PHP_URL_SCHEME );

	foreach ( $document->getElementsByTagName( 'a' ) as $anchor ) {
		$href = trim( (string) $anchor->getAttribute( 'href' ) );
		if ( '' === $href ) {
			continue;
		}

		// Protocol-relative URL: assume the site's scheme.
		if ( 0 === strpos( $href, '//' ) ) {
			$href = ( '' !== $scheme ? $scheme : 'https' ) . ':' . $href;
		}

		$parts = wp_parse_url( $href );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			continue; // Relative, fragment, or malformed: internal or not a web link.
		}

		if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			continue; // mailto:, tel:, javascript: and the like.
		}

		if ( seoryco_wpmd_ability_normalize_host( $parts['host'] ) === $site_host ) {
			continue; // Internal link (any port / www / IDN spelling).
		}

		if ( count( $result['links'] ) >= $limit ) {
			$result['truncated'] = true;
			break;
		}

		$anchor_text = seoryco_wpmd_ability_clean_text( $anchor->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( '' === $anchor_text ) {
			$images = $anchor->getElementsByTagName( 'img' );
			if ( $images->length > 0 ) {
				$anchor_text = seoryco_wpmd_ability_clean_text( $images->item( 0 )->getAttribute( 'alt' ) );
			}
		}

		$rel = preg_split( '/\s+/', strtolower( trim( (string) $anchor->getAttribute( 'rel' ) ) ), -1, PREG_SPLIT_NO_EMPTY );

		$result['links'][] = array(
			'url'         => $href,
			'anchor_text' => $anchor_text,
			'rel'         => array_values( array_unique( is_array( $rel ) ? $rel : array() ) ),
			'target'      => trim( (string) $anchor->getAttribute( 'target' ) ),
			'context'     => seoryco_wpmd_ability_link_context( $anchor, $anchor_text ),
		);
	}

	return $result;
}

/**
 * The sentence around a link, truncated to SEORYCO_WPMD_CONTEXT_LENGTH characters.
 *
 * Only the text near the link is read (about SEORYCO_WPMD_CONTEXT_LENGTH characters
 * on each side, within the enclosing block element), so the cost per link does not
 * grow with the size of the paragraph. The link's own position is tracked, so a
 * sentence that repeats the anchor text elsewhere is not picked by mistake.
 *
 * @param DOMElement $anchor      Link element.
 * @param string     $anchor_text Cleaned anchor text (unused; kept for compatibility).
 * @return string
 */
function seoryco_wpmd_ability_link_context( $anchor, $anchor_text = '' ) {
	unset( $anchor_text );

	$block_tags = array( 'p', 'li', 'td', 'th', 'dd', 'dt', 'blockquote', 'figcaption', 'caption', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'section', 'article' );

	$block = $anchor->parentNode; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	while ( $block instanceof DOMElement && ! in_array( strtolower( $block->nodeName ), $block_tags, true ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$block = $block->parentNode; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	if ( ! $block instanceof DOMNode ) {
		return '';
	}

	// The text before, inside, and after the link, joined with collapsed
	// whitespace. The link's position is kept as byte offsets ($from, $to), not
	// as marker characters, so no character of the post text is lost.
	$inside   = (string) $anchor->textContent; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	$segments = array(
		seoryco_wpmd_ability_context_side( $anchor, $block, true ),
		$inside,
		seoryco_wpmd_ability_context_side( $anchor, $block, false ),
	);
	$text     = '';
	$from     = 0;
	$to       = 0;
	foreach ( $segments as $index => $segment ) {
		$segment = (string) preg_replace( '/[\s\x{00A0}\x{3000}]+/u', ' ', (string) $segment );
		if ( ( '' === $text || ' ' === substr( $text, -1 ) ) && ' ' === substr( $segment, 0, 1 ) ) {
			$segment = substr( $segment, 1 );
		}
		if ( 1 === $index ) {
			$from = strlen( $text );
		}
		$text .= $segment;
		if ( 1 === $index ) {
			$to = strlen( $text );
		}
	}
	$text = rtrim( $text, ' ' );
	$to   = min( $to, strlen( $text ) );
	$from = min( $from, $to );
	if ( '' === $text ) {
		return '';
	}

	// Pick the sentence(s) that contain the link.
	$start = 0;
	$parts = preg_split( '/(?<=[。！？!?])\s*|(?<=\.)\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_OFFSET_CAPTURE );
	if ( is_array( $parts ) && ! empty( $parts ) ) {
		$end = strlen( $text );
		foreach ( $parts as $part ) {
			if ( $part[1] <= $from ) {
				$start = $part[1];
			}
			if ( $part[1] < $to ) {
				$end = $part[1] + strlen( $part[0] );
			}
		}
		$end  = max( $end, $to );
		$text = substr( $text, $start, $end - $start );
	}

	// Position of the link in the sentence, in characters.
	$lead     = strlen( $text ) - strlen( ltrim( $text ) );
	$text     = trim( $text );
	$position = mb_strlen( substr( $text, 0, max( 0, $from - $start - $lead ) ), 'UTF-8' );

	if ( mb_strlen( $text ) <= SEORYCO_WPMD_CONTEXT_LENGTH ) {
		return $text;
	}

	// Keep the link inside the window when it is far into a long sentence.
	$center = $position + (int) floor( mb_strlen( seoryco_wpmd_ability_clean_text( $inside ) ) / 2 );
	$start  = max( 0, min( $center - (int) floor( SEORYCO_WPMD_CONTEXT_LENGTH / 2 ), mb_strlen( $text ) - SEORYCO_WPMD_CONTEXT_LENGTH ) );

	return mb_substr( $text, $start, SEORYCO_WPMD_CONTEXT_LENGTH );
}

/**
 * Raw text on one side of a link, within its block element, up to about
 * SEORYCO_WPMD_CONTEXT_LENGTH characters (plus slack for whitespace that is
 * collapsed later).
 *
 * @param DOMNode $anchor Link element.
 * @param DOMNode $block  Enclosing block element.
 * @param bool    $before true for the text before the link, false for after.
 * @return string
 */
function seoryco_wpmd_ability_context_side( $anchor, $block, $before ) {
	$want   = SEORYCO_WPMD_CONTEXT_LENGTH;
	$pieces = array();
	$length = 0;
	$node   = $anchor;

	while ( $node instanceof DOMNode && ! $node->isSameNode( $block ) ) {
		$sibling = $before ? $node->previousSibling : $node->nextSibling; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		while ( $sibling instanceof DOMNode ) {
			$text     = (string) $sibling->textContent; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$pieces[] = $text;
			$length  += mb_strlen( seoryco_wpmd_ability_clean_text( $text ) );
			if ( $length >= $want ) {
				break 2;
			}
			$sibling = $before ? $sibling->previousSibling : $sibling->nextSibling; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}
		$node = $node->parentNode; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	if ( $before ) {
		$pieces = array_reverse( $pieces );
	}
	$text = implode( '', $pieces );

	// A single huge text node: keep only the part next to the link.
	$slack = $want * 8;
	if ( mb_strlen( $text ) > $slack ) {
		$text = $before ? mb_substr( $text, -$slack ) : mb_substr( $text, 0, $slack );
	}

	return $text;
}

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
 * @package WP_to_Markdown
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SEORYCO_WPMD_ABILITY_CATEGORY', 'content-export' );
define( 'SEORYCO_WPMD_LINKS_PER_POST', 200 );
define( 'SEORYCO_WPMD_CONTEXT_LENGTH', 200 );

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
 * JSON Schema for a paginated list output.
 *
 * @param array  $item_properties Properties of one item.
 * @param array  $item_required   Required item properties.
 * @param string $total_key       Name of the total-count property.
 * @return array
 */
function seoryco_wpmd_list_output_schema( array $item_properties, array $item_required, $total_key = 'total' ) {
	return array(
		'type'       => 'object',
		'properties' => array(
			'items'       => array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => $item_properties,
					'required'   => $item_required,
				),
			),
			'page'        => array( 'type' => 'integer' ),
			'per_page'    => array( 'type' => 'integer' ),
			$total_key    => array( 'type' => 'integer' ),
			'total_pages' => array( 'type' => 'integer' ),
		),
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
		'description' => __( 'Post statuses to include. Anything other than publish requires the edit_others_posts capability.', 'seory-posts-to-markdown' ),
	);

	$post_types_property = array(
		'type'        => 'array',
		'items'       => array( 'type' => 'string' ),
		'description' => __( 'Post type slugs. Omit for all public post types.', 'seory-posts-to-markdown' ),
	);

	$modified_after_property = array(
		'type'        => 'string',
		'format'      => 'date-time',
		'description' => __( 'Datetime in GMT, e.g. 2026-09-01T00:00:00Z (a MySQL-style "YYYY-MM-DD HH:MM:SS" is also accepted). Only posts with post_modified_gmt greater than this value are returned.', 'seory-posts-to-markdown' ),
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
					'page'           => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
					'per_page'       => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 50,
					),
				),
			),
			'output_schema'       => seoryco_wpmd_list_output_schema( $post_item_properties, array( 'id', 'type', 'status', 'modified_gmt' ) ),
			'execute_callback'    => 'seoryco_wpmd_ability_list_posts',
			'permission_callback' => 'seoryco_wpmd_ability_permission_list_posts',
			'meta'                => seoryco_wpmd_ability_meta(),
		)
	);

	wp_register_ability(
		'seoryco-wpmd/list-post-ids',
		array(
			'label'               => __( 'List all post IDs with status and modified time', 'seory-posts-to-markdown' ),
			'description'         => __( 'Lightweight full listing of ID, status, and post_modified_gmt for every post of the given types, including trashed items. Intended for detecting deletions, trashing, and unpublishing by diffing against a previously saved list — not for content sync (use list-posts for that).', 'seory-posts-to-markdown' ),
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
					'page'          => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
					'per_page'      => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 500,
						'default' => 500,
					),
				),
			),
			'output_schema'       => seoryco_wpmd_list_output_schema(
				array(
					'id'           => array( 'type' => 'integer' ),
					'type'         => array( 'type' => 'string' ),
					'status'       => array( 'type' => 'string' ),
					'modified_gmt' => array( 'type' => 'string' ),
				),
				array( 'id', 'type', 'status', 'modified_gmt' )
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
			'description'         => __( 'Converts a single post to Markdown with YAML front matter, reusing the same conversion used by the ZIP export. Choose raw (stored content) or rendered (the_content filters applied, shortcodes/blocks expanded).', 'seory-posts-to-markdown' ),
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
					'page'           => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
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
					'items'       => array(
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
					'page'        => array( 'type' => 'integer' ),
					'per_page'    => array( 'type' => 'integer' ),
					'total_posts' => array( 'type' => 'integer' ),
					'total_pages' => array( 'type' => 'integer' ),
					'truncated'   => array(
						'type'        => 'boolean',
						'description' => __( 'true if any post\'s link count was cut off by the per-post link cap.', 'seory-posts-to-markdown' ),
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

	$page = isset( $input['page'] ) && is_scalar( $input['page'] ) ? (int) $input['page'] : 1;
	$size = isset( $input['per_page'] ) && is_scalar( $input['per_page'] ) ? (int) $input['per_page'] : $per_page;

	return array(
		'modified_after' => $modified_after,
		'post_types'     => $post_types,
		'status'         => $statuses,
		'include_trash'  => isset( $input['include_trash'] ) ? wp_validate_boolean( $input['include_trash'] ) : true,
		'page'           => max( 1, $page ),
		'per_page'       => min( $max_page, max( 1, $size ) ),
	);
}

/**
 * Resolve requested post types against the public post types.
 *
 * @param string[] $requested Requested slugs (empty for all).
 * @return string[]|WP_Error
 */
function seoryco_wpmd_ability_resolve_post_types( array $requested ) {
	$allowed = array_keys( seoryco_wpmd_get_post_types() );

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
 * The post's modified time in GMT as ISO 8601 with a Z suffix.
 *
 * Posts inserted directly as drafts can have a zero post_modified_gmt; the
 * local post_modified is converted instead in that case.
 *
 * @param WP_Post $post Post.
 * @return string '' when no usable date exists.
 */
function seoryco_wpmd_ability_modified_gmt( $post ) {
	$gmt = (string) $post->post_modified_gmt;
	if ( '' === $gmt || 0 === strpos( $gmt, '0000-00-00' ) ) {
		$local = (string) $post->post_modified;
		if ( '' === $local || 0 === strpos( $local, '0000-00-00' ) ) {
			return '';
		}
		$gmt = get_gmt_from_date( $local );
	}

	return (string) mysql2date( 'Y-m-d\TH:i:s\Z', $gmt, false );
}

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
 * Check a capability after letting the site override it.
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
	 * to allow or deny the call outright.
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
 * Permission callback for list-posts and list-external-links.
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
 * Capability needed for a set of statuses: `read` for published only, else `edit_others_posts`.
 *
 * @param string[] $statuses Normalized statuses.
 * @return string
 */
function seoryco_wpmd_ability_status_capability( array $statuses ) {
	return ( array( 'publish' ) === $statuses ) ? 'read' : 'edit_others_posts';
}

/**
 * Permission callback for list-post-ids (trash and private items are always exposed).
 *
 * @param mixed $input Ability input.
 * @return bool
 */
function seoryco_wpmd_ability_permission_list_post_ids( $input = null ) {
	$args = seoryco_wpmd_ability_normalize_list_input( $input, 500, 500 );

	return seoryco_wpmd_ability_check_capability( 'edit_others_posts', 'seoryco-wpmd/list-post-ids', $args );
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

	if ( ! $post instanceof WP_Post || ! array_key_exists( $post->post_type, seoryco_wpmd_get_post_types() ) ) {
		return false;
	}

	if ( post_password_required( $post ) ) {
		return false;
	}

	if ( 'publish' === $post->post_status ) {
		return seoryco_wpmd_ability_check_capability( 'read', 'seoryco-wpmd/get-post-markdown', $args );
	}

	return seoryco_wpmd_ability_check_capability( 'edit_others_posts', 'seoryco-wpmd/get-post-markdown', $args )
		&& current_user_can( 'read_post', $post->ID );
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
 * Order the plugin's ability queries by post_modified_gmt (then ID).
 *
 * @param string   $orderby ORDER BY clause.
 * @param WP_Query $query   Query.
 * @return string
 */
function seoryco_wpmd_ability_orderby_gmt( $orderby, $query ) {
	if ( $query instanceof WP_Query && $query->get( 'seoryco_wpmd_order_gmt' ) ) {
		global $wpdb;

		return "{$wpdb->posts}.post_modified_gmt ASC, {$wpdb->posts}.ID ASC";
	}

	return $orderby;
}
add_filter( 'posts_orderby', 'seoryco_wpmd_ability_orderby_gmt', 10, 2 );

/**
 * Run the paginated post query shared by list-posts and list-external-links.
 *
 * @param array $args Normalized input.
 * @return WP_Query|WP_Error
 */
function seoryco_wpmd_ability_query_posts( array $args ) {
	$post_types = seoryco_wpmd_ability_resolve_post_types( $args['post_types'] );
	if ( is_wp_error( $post_types ) ) {
		return $post_types;
	}

	$modified_after = seoryco_wpmd_ability_parse_modified_after( $args['modified_after'] );
	if ( is_wp_error( $modified_after ) ) {
		return $modified_after;
	}

	$query_args = array(
		'post_type'              => $post_types,
		'post_status'            => $args['status'],
		'posts_per_page'         => $args['per_page'],
		'paged'                  => $args['page'],
		'orderby'                => 'modified',
		'order'                  => 'ASC',
		'ignore_sticky_posts'    => true,
		'suppress_filters'       => false,
		'update_post_meta_cache' => false,
		'seoryco_wpmd_order_gmt' => true,
	);

	if ( '' !== $modified_after ) {
		$query_args['date_query'] = array(
			array(
				'column'    => 'post_modified_gmt',
				'after'     => $modified_after,
				'inclusive' => false,
			),
		);
	}

	return new WP_Query( $query_args );
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
		$args  = seoryco_wpmd_ability_normalize_list_input( $input, 100, 50 );
		$query = seoryco_wpmd_ability_query_posts( $args );
		if ( is_wp_error( $query ) ) {
			return $query;
		}

		$items = array();
		foreach ( $query->posts as $post ) {
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

		return array(
			'items'       => $items,
			'page'        => (int) $args['page'],
			'per_page'    => (int) $args['per_page'],
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
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

		$query = new WP_Query(
			array(
				'post_type'              => $post_types,
				'post_status'            => $statuses,
				'posts_per_page'         => $args['per_page'],
				'paged'                  => $args['page'],
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'ignore_sticky_posts'    => true,
				'suppress_filters'       => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = array(
				'id'           => (int) $post->ID,
				'type'         => (string) $post->post_type,
				'status'       => (string) $post->post_status,
				'modified_gmt' => seoryco_wpmd_ability_modified_gmt( $post ),
			);
		}

		return array(
			'items'       => $items,
			'page'        => (int) $args['page'],
			'per_page'    => (int) $args['per_page'],
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
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
		$args     = seoryco_wpmd_ability_normalize_post_input( $input );
		$exporter = new Seoryco_Wpmd_Exporter( seoryco_wpmd_ability_exporter_options( $args['mode'] ) );
		if ( ! $exporter->is_available() ) {
			return new WP_Error(
				'seoryco_wpmd_converter_missing',
				__( 'The Markdown conversion library is missing. Please reinstall the plugin.', 'seory-posts-to-markdown' ),
				array( 'status' => 500 )
			);
		}

		$used_paths = array();
		$file       = $exporter->convert_post( $args['id'], $used_paths );
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

		$args  = seoryco_wpmd_ability_normalize_list_input( $input, 50, 20 );
		$query = seoryco_wpmd_ability_query_posts( $args );
		if ( is_wp_error( $query ) ) {
			return $query;
		}

		$exporter  = new Seoryco_Wpmd_Exporter( seoryco_wpmd_ability_exporter_options( 'rendered' ) );
		$site_host = seoryco_wpmd_ability_normalize_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$items     = array();
		$truncated = false;

		foreach ( $query->posts as $post ) {
			// Password-protected content is never exposed (same rule as get-post-markdown).
			if ( post_password_required( $post ) ) {
				continue;
			}

			$links = seoryco_wpmd_ability_extract_links( $exporter->get_content_html( $post ), $site_host );
			if ( count( $links ) > SEORYCO_WPMD_LINKS_PER_POST ) {
				$links     = array_slice( $links, 0, SEORYCO_WPMD_LINKS_PER_POST );
				$truncated = true;
			}

			$post_link = get_permalink( $post );
			foreach ( $links as $link ) {
				$items[] = array(
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

		return array(
			'items'       => $items,
			'page'        => (int) $args['page'],
			'per_page'    => (int) $args['per_page'],
			'total_posts' => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'truncated'   => $truncated,
		);
	} catch ( Throwable $e ) {
		return seoryco_wpmd_ability_exception_error( $e );
	}
}

/*
 * -------------------------------------------------------------------------
 * Link extraction
 * -------------------------------------------------------------------------
 */

/**
 * Lowercase a host and drop a leading "www." so www/non-www count as the same site.
 *
 * @param string $host Host.
 * @return string
 */
function seoryco_wpmd_ability_normalize_host( $host ) {
	$host = strtolower( trim( $host, " \t\n\r\0\x0B." ) );

	return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
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
 * Extract external links (http/https to another host) from rendered HTML.
 *
 * @param string $html      Rendered post HTML.
 * @param string $site_host Normalized host of the site.
 * @return array[] Each: url, anchor_text, rel, target, context.
 */
function seoryco_wpmd_ability_extract_links( $html, $site_host ) {
	$html = (string) $html;
	if ( '' === trim( $html ) || false === stripos( $html, '<a' ) ) {
		return array();
	}

	$previous = libxml_use_internal_errors( true );
	$document = new DOMDocument();
	$loaded   = $document->loadHTML( '<?xml encoding="utf-8" ?><div>' . $html . '</div>', LIBXML_NONET );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );

	if ( ! $loaded ) {
		return array();
	}

	$scheme = (string) wp_parse_url( home_url(), PHP_URL_SCHEME );
	$links  = array();

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
			continue; // Internal link (any port / www variant).
		}

		$anchor_text = seoryco_wpmd_ability_clean_text( $anchor->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( '' === $anchor_text ) {
			$images = $anchor->getElementsByTagName( 'img' );
			if ( $images->length > 0 ) {
				$anchor_text = seoryco_wpmd_ability_clean_text( $images->item( 0 )->getAttribute( 'alt' ) );
			}
		}

		$rel = preg_split( '/\s+/', strtolower( trim( (string) $anchor->getAttribute( 'rel' ) ) ), -1, PREG_SPLIT_NO_EMPTY );

		$links[] = array(
			'url'         => $href,
			'anchor_text' => $anchor_text,
			'rel'         => array_values( array_unique( is_array( $rel ) ? $rel : array() ) ),
			'target'      => trim( (string) $anchor->getAttribute( 'target' ) ),
			'context'     => seoryco_wpmd_ability_link_context( $anchor, $anchor_text ),
		);
	}

	return $links;
}

/**
 * The sentence around a link, truncated to SEORYCO_WPMD_CONTEXT_LENGTH characters.
 *
 * @param DOMElement $anchor      Link element.
 * @param string     $anchor_text Cleaned anchor text.
 * @return string
 */
function seoryco_wpmd_ability_link_context( $anchor, $anchor_text ) {
	$block_tags = array( 'p', 'li', 'td', 'th', 'dd', 'dt', 'blockquote', 'figcaption', 'caption', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'section', 'article' );

	$node = $anchor->parentNode; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	while ( $node instanceof DOMElement && ! in_array( strtolower( $node->nodeName ), $block_tags, true ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$node = $node->parentNode; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	if ( ! $node instanceof DOMNode ) {
		return '';
	}

	$text = seoryco_wpmd_ability_clean_text( $node->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	if ( '' === $text ) {
		return '';
	}

	// Pick the sentence that contains the anchor text.
	if ( '' !== $anchor_text ) {
		$sentences = preg_split( '/(?<=[。！？!?])\s*|(?<=\.)\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( is_array( $sentences ) ) {
			foreach ( $sentences as $sentence ) {
				if ( false !== mb_strpos( $sentence, $anchor_text ) ) {
					$text = trim( $sentence );
					break;
				}
			}
		}
	}

	if ( mb_strlen( $text ) <= SEORYCO_WPMD_CONTEXT_LENGTH ) {
		return $text;
	}

	// Keep the anchor text inside the window when it is far into a long sentence.
	$start    = 0;
	$position = '' !== $anchor_text ? mb_strpos( $text, $anchor_text ) : false;
	if ( false !== $position ) {
		$center = $position + (int) floor( mb_strlen( $anchor_text ) / 2 );
		$start  = max( 0, min( $center - (int) floor( SEORYCO_WPMD_CONTEXT_LENGTH / 2 ), mb_strlen( $text ) - SEORYCO_WPMD_CONTEXT_LENGTH ) );
	}

	return mb_substr( $text, $start, SEORYCO_WPMD_CONTEXT_LENGTH );
}

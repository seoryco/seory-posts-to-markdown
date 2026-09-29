<?php
/**
 * Converts WP_Post objects into Markdown files (front matter + body + output path).
 *
 * @package WP_to_Markdown
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seoryco_Wpmd_Exporter {

	/**
	 * Export options: content_mode, post_folders, prefix_date, date_folders.
	 *
	 * @var array
	 */
	private $options;

	/**
	 * @var Seoryco_Wpmd_Markdown
	 */
	private $markdown;

	/**
	 * @param array $options Export options.
	 */
	public function __construct( array $options ) {
		$this->options  = $options;
		$this->markdown = new Seoryco_Wpmd_Markdown();
	}

	/**
	 * Whether the Markdown converter is usable.
	 *
	 * @return bool
	 */
	public function is_available() {
		return $this->markdown->is_available();
	}

	/**
	 * Convert one post. Returns file data or WP_Error.
	 *
	 * @param int   $post_id    Post ID.
	 * @param array $used_paths Reference map of already used ZIP paths (path => true).
	 * @return array|WP_Error { path, content, title, type, is_draft }
	 */
	public function convert_post( $post_id, array &$used_paths ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'seoryco_wpmd_missing', 'Post not found: ' . $post_id );
		}

		$date = $this->normalize_date( $post );
		$slug = $this->decode( $post->post_name );
		if ( '' === $slug ) {
			$slug = $post->ID ? 'id-' . $post->ID : ( $post->post_title ? $post->post_title : 'untitled' );
		}

		$item = array(
			'id'             => (string) $post->ID,
			'title'          => get_the_title( $post ),
			'slug'           => $slug,
			'type'           => $post->post_type,
			'status'         => $post->post_status,
			'author'         => get_the_author_meta( 'display_name', (int) $post->post_author ),
			'link'           => get_permalink( $post ),
			'date'           => $date,
			'featured_image' => get_the_post_thumbnail_url( $post, 'full' ),
			'categories'     => $this->term_slugs( $post, 'category' ),
			'tags'           => $this->term_slugs( $post, 'post_tag' ),
			'taxonomies'     => $this->custom_taxonomy_slugs( $post ),
			'is_draft'       => ( 'draft' === $post->post_status ),
		);

		$content = $this->content_html( $post );
		$body    = $this->markdown->convert( $content );

		$front = $this->build_frontmatter( $item, $post );
		$title = '' !== $item['title'] ? '# ' . $item['title'] . "\n\n" : '';

		$path = $this->make_unique_path( $this->build_path( $item ), $used_paths, $item );

		return array(
			'path'     => $path,
			'content'  => $front . "\n\n" . $title . $body . "\n",
			'title'    => $item['title'],
			'type'     => $item['type'],
			'is_draft' => $item['is_draft'],
		);
	}

	/**
	 * Public access to the HTML that convert_post() would convert (used by the abilities).
	 *
	 * @since 0.2
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public function get_content_html( $post ) {
		return $this->content_html( $post );
	}

	/**
	 * Get the HTML to convert, honoring the content rendering mode.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private function content_html( $post ) {
		if ( 'rendered' === $this->options['content_mode'] ) {
			$previous = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;

			$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			setup_postdata( $post );

			/** This filter is documented in wp-includes/post-template.php */
			$html = apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Intentionally applying the core filter.

			wp_reset_postdata();
			$GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

			return $html;
		}

		// Raw mode: paragraph structure only, no shortcode/block rendering.
		return wpautop( $post->post_content );
	}

	/**
	 * Term slugs for every public custom taxonomy of the post, keyed by taxonomy name.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string, string[]>
	 */
	private function custom_taxonomy_slugs( $post ) {
		$result = array();

		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
			if ( ! $taxonomy->public || in_array( $taxonomy->name, array( 'category', 'post_tag', 'post_format' ), true ) ) {
				continue;
			}

			$slugs = $this->term_slugs( $post, $taxonomy->name );
			if ( ! empty( $slugs ) ) {
				$result[ $taxonomy->name ] = $slugs;
			}
		}

		return $result;
	}

	/**
	 * Term slugs for a taxonomy.
	 *
	 * @param WP_Post $post     Post.
	 * @param string  $taxonomy Taxonomy name.
	 * @return string[]
	 */
	private function term_slugs( $post, $taxonomy ) {
		if ( ! is_object_in_taxonomy( $post->post_type, $taxonomy ) ) {
			return array();
		}

		$terms = get_the_terms( $post, $taxonomy );
		if ( ! is_array( $terms ) ) {
			return array();
		}

		$slugs = array();
		foreach ( $terms as $term ) {
			$slug = $this->decode( $term->slug );
			if ( '' !== $slug ) {
				$slugs[] = $slug;
			}
		}

		return array_values( array_unique( $slugs ) );
	}

	/**
	 * YYYY-MM-DD from post_date, or empty string when unavailable.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private function normalize_date( $post ) {
		$date = (string) $post->post_date;
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}/', $date ) && 0 !== strpos( $date, '0000-00-00' ) ) {
			return substr( $date, 0, 10 );
		}

		return '';
	}

	/**
	 * URL-decode a value, returning the input when decoding is not applicable.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function decode( $value ) {
		$decoded = rawurldecode( (string) $value );

		return is_string( $decoded ) ? $decoded : (string) $value;
	}

	/**
	 * Quote a scalar for YAML front matter.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function yaml_string( $value ) {
		$value = str_replace( '\\', '\\\\', (string) $value );
		$value = str_replace( '"', '\\"', $value );
		$value = preg_replace( '/\r?\n/', ' ', $value );

		return '"' . $value . '"';
	}

	/**
	 * YAML block-style array, or empty string when there are no values.
	 *
	 * @param string[] $values Values.
	 * @return string
	 */
	private function yaml_array( array $values ) {
		$unique = array_values( array_unique( array_filter( $values ) ) );
		if ( empty( $unique ) ) {
			return '';
		}

		$lines = '';
		foreach ( $unique as $value ) {
			$lines .= "\n  - " . $this->yaml_string( $value );
		}

		return $lines;
	}

	/**
	 * Build the YAML front matter block.
	 *
	 * @param array   $item Converted post data.
	 * @param WP_Post $post The post being exported.
	 * @return string
	 */
	private function build_frontmatter( array $item, $post ) {
		$pairs = array(
			'title'          => '' !== $item['title'] ? $this->yaml_string( $item['title'] ) : '',
			'date'           => $item['date'],
			'type'           => '' !== $item['type'] ? $this->yaml_string( $item['type'] ) : '',
			'status'         => '' !== $item['status'] ? $this->yaml_string( $item['status'] ) : '',
			'slug'           => '' !== $item['slug'] ? $this->yaml_string( $item['slug'] ) : '',
			'link'           => $item['link'] ? $this->yaml_string( $item['link'] ) : '',
			'author'         => $item['author'] ? $this->yaml_string( $item['author'] ) : '',
			'featured_image' => $item['featured_image'] ? $this->yaml_string( $item['featured_image'] ) : '',
			'categories'     => $this->yaml_array( $item['categories'] ),
			'tags'           => $this->yaml_array( $item['tags'] ),
		);

		$reserved = array( 'title', 'date', 'type', 'status', 'slug', 'link', 'author', 'featured_image', 'categories', 'tags', 'draft' );
		foreach ( $item['taxonomies'] as $taxonomy => $slugs ) {
			$key           = in_array( $taxonomy, $reserved, true ) ? 'tax_' . $taxonomy : $taxonomy;
			$pairs[ $key ] = $this->yaml_array( $slugs );
		}

		$pairs['draft'] = $item['is_draft'] ? 'true' : '';

		/**
		 * Filters the front matter key/value pairs before output.
		 *
		 * Keys are output in array order. Values must already be YAML-formatted
		 * (quoted strings, block-style lists, or plain scalars); keys with an
		 * empty-string value are omitted from the output.
		 *
		 * @since 0.1
		 *
		 * @param array   $pairs Ordered map of front matter key => YAML value.
		 * @param WP_Post $post  The post being exported.
		 */
		$pairs = apply_filters( 'seoryco_wpmd_frontmatter', $pairs, $post );

		$lines = array( '---' );
		foreach ( $pairs as $key => $value ) {
			if ( '' !== $value ) {
				$lines[] = $key . ': ' . $value;
			}
		}

		$lines[] = '---';

		return implode( "\n", $lines );
	}

	/**
	 * Sanitize one path segment (mirrors the web tool's sanitizeSegment).
	 *
	 * @param string $value    Raw segment.
	 * @param string $fallback Fallback when the segment collapses to nothing.
	 * @return string
	 */
	private function sanitize_segment( $value, $fallback ) {
		$value = $this->decode( $value );

		if ( class_exists( 'Normalizer' ) ) {
			$normalized = Normalizer::normalize( $value, Normalizer::FORM_KC );
			if ( false !== $normalized && null !== $normalized ) {
				$value = $normalized;
			}
		}

		$value = preg_replace( '/[\x00-\x1f<>:"\/\\\\|?*]+/u', '-', $value );
		$value = preg_replace( '/\s+/u', '-', (string) $value );
		$value = preg_replace( '/^-+|-+$/u', '', (string) $value );
		$value = preg_replace( '/\.+/u', '.', (string) $value );

		if ( '' === $value || '.' === $value || '..' === $value ) {
			return $fallback;
		}

		return mb_substr( $value, 0, 120 );
	}

	/**
	 * Build the relative output path for a post.
	 *
	 * @param array $item Converted post data.
	 * @return string
	 */
	private function build_path( array $item ) {
		$segments = array();

		if ( '' !== $item['date'] && 'none' !== $this->options['date_folders'] ) {
			$segments[] = substr( $item['date'], 0, 4 );
			if ( 'year-month' === $this->options['date_folders'] ) {
				$segments[] = substr( $item['date'], 5, 2 );
			}
		}

		$fallback = $item['id'] ? 'id-' . $item['id'] : 'untitled';
		$slug     = $this->sanitize_segment( $item['slug'], $fallback );
		$filename = ( $this->options['prefix_date'] && '' !== $item['date'] ) ? $item['date'] . '-' . $slug : $slug;

		if ( $this->options['post_folders'] ) {
			$segments[] = $filename;
			$segments[] = 'index.md';
		} else {
			$segments[] = $filename . '.md';
		}

		return implode( '/', $segments );
	}

	/**
	 * Ensure the path is unique within the archive.
	 *
	 * @param string $path       Candidate path.
	 * @param array  $used_paths Reference map (path => true).
	 * @param array  $item       Converted post data.
	 * @return string
	 */
	private function make_unique_path( $path, array &$used_paths, array $item ) {
		if ( ! isset( $used_paths[ $path ] ) ) {
			$used_paths[ $path ] = true;

			return $path;
		}

		$suffix  = $this->sanitize_segment( $item['id'] ? $item['id'] : 'duplicate', 'duplicate' );
		$with_id = $this->suffixed_path( $path, $suffix );

		if ( ! isset( $used_paths[ $with_id ] ) ) {
			$used_paths[ $with_id ] = true;

			return $with_id;
		}

		$counter   = 2;
		$candidate = $with_id;
		while ( isset( $used_paths[ $candidate ] ) ) {
			$candidate = $this->suffixed_path( $path, (string) $counter );
			$counter++;
		}
		$used_paths[ $candidate ] = true;

		return $candidate;
	}

	/**
	 * Append a suffix before the file extension (or folder name for index.md layouts).
	 *
	 * @param string $path   Original path.
	 * @param string $suffix Suffix without leading dash.
	 * @return string
	 */
	private function suffixed_path( $path, $suffix ) {
		if ( '/index.md' === substr( $path, -9 ) ) {
			return substr( $path, 0, -9 ) . '-' . $suffix . '/index.md';
		}

		return preg_replace( '/\.md$/', '-' . $suffix . '.md', $path );
	}
}

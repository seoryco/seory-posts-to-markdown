<?php
/**
 * Tools > Seory Posts to Markdown admin page.
 *
 * @package WP_to_Markdown
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the Tools submenu page.
 */
function seoryco_wpmd_admin_menu() {
	add_management_page(
		__( 'Seory Posts to Markdown', 'seory-posts-to-markdown' ),
		__( 'Seory Posts to Markdown', 'seory-posts-to-markdown' ),
		'export',
		'seory-posts-to-markdown',
		'seoryco_wpmd_render_admin_page'
	);
}
add_action( 'admin_menu', 'seoryco_wpmd_admin_menu' );

/**
 * Enqueue assets on our page only.
 *
 * @param string $hook_suffix Current admin page hook.
 */
function seoryco_wpmd_admin_enqueue( $hook_suffix ) {
	if ( 'tools_page_seory-posts-to-markdown' !== $hook_suffix ) {
		return;
	}

	wp_enqueue_style(
		'seoryco-wpmd-admin',
		SEORYCO_WPMD_PLUGIN_URL . 'css/seory-posts-to-markdown-admin.css',
		array(),
		SEORYCO_WPMD_VERSION
	);

	wp_enqueue_script(
		'seoryco-wpmd-admin',
		SEORYCO_WPMD_PLUGIN_URL . 'js/seory-posts-to-markdown-admin.js',
		array(),
		SEORYCO_WPMD_VERSION,
		true
	);

	wp_localize_script(
		'seoryco-wpmd-admin',
		'seorycoWpmd',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'seoryco_wpmd_export' ),
			'i18n'    => array(
				'converting'  => __( 'Converting…', 'seory-posts-to-markdown' ),
				'convert'     => __( 'Convert and download ZIP', 'seory-posts-to-markdown' ),
				/* translators: 1: number of processed items, 2: total number of items. */
				'progress'    => __( '%1$s / %2$s converted', 'seory-posts-to-markdown' ),
				'downloaded'  => __( 'ZIP download started.', 'seory-posts-to-markdown' ),
				'failed'      => __( 'Export failed. Please try again.', 'seory-posts-to-markdown' ),
				'noPostTypes' => __( 'Select at least one post type.', 'seory-posts-to-markdown' ),
				/* translators: %s: number of additional files. */
				'andMore'     => __( '…and %s more', 'seory-posts-to-markdown' ),
			),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'seoryco_wpmd_admin_enqueue' );

/**
 * Exportable post types (public, minus attachments).
 *
 * @return WP_Post_Type[]
 */
function seoryco_wpmd_get_post_types() {
	$types = get_post_types( array( 'public' => true ), 'objects' );
	unset( $types['attachment'] );

	return $types;
}

/**
 * Render the admin page.
 */
function seoryco_wpmd_render_admin_page() {
	if ( ! current_user_can( 'export' ) ) {
		wp_die( esc_html__( 'You do not have permission to run this export.', 'seory-posts-to-markdown' ) );
	}

	$post_types = seoryco_wpmd_get_post_types();
	?>
	<div class="wrap seoryco-wpmd">
		<h1><?php esc_html_e( 'Seory Posts to Markdown', 'seory-posts-to-markdown' ); ?></h1>
		<p class="description seoryco-wpmd-intro">
			<?php esc_html_e( 'Export posts, pages, and custom post types as Markdown files with YAML front matter, bundled into a ZIP archive. Everything runs on this server — no data is sent anywhere.', 'seory-posts-to-markdown' ); ?>
		</p>

		<form id="seoryco-wpmd-form" onsubmit="return false;">
			<div class="seoryco-wpmd-card">
				<h2><?php esc_html_e( 'Content to export', 'seory-posts-to-markdown' ); ?></h2>
				<?php foreach ( $post_types as $type ) : ?>
					<?php
					$type_taxonomies = array_filter(
						get_object_taxonomies( $type->name, 'objects' ),
						static function ( $taxonomy ) {
							return $taxonomy->public && $taxonomy->hierarchical;
						}
					);
					?>
					<div class="seoryco-wpmd-type">
						<label class="seoryco-wpmd-inline">
							<input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $type->name ); ?>" checked />
							<span class="seoryco-wpmd-type-name"><?php echo esc_html( $type->labels->name ); ?></span>
							<code><?php echo esc_html( $type->name ); ?></code>
						</label>
						<?php if ( ! empty( $type_taxonomies ) ) : ?>
							<div class="seoryco-wpmd-type-filters">
								<?php foreach ( $type_taxonomies as $taxonomy ) : ?>
									<?php
									$terms = get_terms(
										array(
											'taxonomy'   => $taxonomy->name,
											'hide_empty' => false,
										)
									);
									if ( ! is_array( $terms ) || empty( $terms ) ) {
										continue;
									}
									?>
									<label class="seoryco-wpmd-field">
										<span class="seoryco-wpmd-label"><?php echo esc_html( $taxonomy->labels->name ); ?></span>
										<select class="seoryco-wpmd-tax-select" name="tax_filter[<?php echo esc_attr( $type->name ); ?>][<?php echo esc_attr( $taxonomy->name ); ?>]">
											<option value="0">
												<?php
												/* translators: %s: taxonomy name (e.g. Categories). */
												echo esc_html( sprintf( __( 'All %s', 'seory-posts-to-markdown' ), $taxonomy->labels->name ) );
												?>
											</option>
											<?php foreach ( $terms as $term ) : ?>
												<option value="<?php echo esc_attr( $term->term_id ); ?>"><?php echo esc_html( $term->name ); ?></option>
											<?php endforeach; ?>
										</select>
									</label>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>

				<label class="seoryco-wpmd-inline">
					<input type="checkbox" name="include_drafts" value="1" />
					<?php esc_html_e( 'Include drafts', 'seory-posts-to-markdown' ); ?>
				</label>
			</div>

			<div class="seoryco-wpmd-card">
				<h2><?php esc_html_e( 'Content rendering', 'seory-posts-to-markdown' ); ?></h2>
				<label class="seoryco-wpmd-option">
					<input type="radio" name="content_mode" value="raw" checked />
					<span>
						<span class="seoryco-wpmd-option-title"><?php esc_html_e( 'Raw editor content', 'seory-posts-to-markdown' ); ?></span>
						<span class="seoryco-wpmd-help"><?php esc_html_e( 'Convert the stored content as-is. Shortcodes stay unexpanded; block markup comments are removed.', 'seory-posts-to-markdown' ); ?></span>
					</span>
				</label>
				<label class="seoryco-wpmd-option">
					<input type="radio" name="content_mode" value="rendered" />
					<span>
						<span class="seoryco-wpmd-option-title"><?php esc_html_e( 'Rendered content', 'seory-posts-to-markdown' ); ?></span>
						<span class="seoryco-wpmd-help"><?php esc_html_e( 'Apply content filters before conversion so shortcodes and blocks are fully expanded.', 'seory-posts-to-markdown' ); ?></span>
					</span>
				</label>
			</div>

			<div class="seoryco-wpmd-card">
				<h2><?php esc_html_e( 'Date range (all post types)', 'seory-posts-to-markdown' ); ?></h2>
				<div class="seoryco-wpmd-dates">
					<label class="seoryco-wpmd-field">
						<span class="seoryco-wpmd-label"><?php esc_html_e( 'Date from', 'seory-posts-to-markdown' ); ?></span>
						<input type="date" name="date_from" />
					</label>
					<label class="seoryco-wpmd-field">
						<span class="seoryco-wpmd-label"><?php esc_html_e( 'Date to', 'seory-posts-to-markdown' ); ?></span>
						<input type="date" name="date_to" />
					</label>
				</div>
			</div>

			<div class="seoryco-wpmd-card">
				<h2><?php esc_html_e( 'Output options', 'seory-posts-to-markdown' ); ?></h2>
				<label class="seoryco-wpmd-option">
					<input type="checkbox" name="post_folders" value="1" />
					<span>
						<span class="seoryco-wpmd-option-title"><?php esc_html_e( 'Put each post in its own folder', 'seory-posts-to-markdown' ); ?></span>
						<span class="seoryco-wpmd-help"><?php esc_html_e( 'On: slug/index.md. Off: slug.md (flat output for AI tools).', 'seory-posts-to-markdown' ); ?></span>
					</span>
				</label>
				<label class="seoryco-wpmd-option">
					<input type="checkbox" name="prefix_date" value="1" />
					<span>
						<span class="seoryco-wpmd-option-title"><?php esc_html_e( 'Prefix filenames with dates', 'seory-posts-to-markdown' ); ?></span>
						<span class="seoryco-wpmd-help"><?php esc_html_e( 'On: 2024-01-15-slug.md. Posts without dates are left unchanged.', 'seory-posts-to-markdown' ); ?></span>
					</span>
				</label>
				<label class="seoryco-wpmd-field">
					<span class="seoryco-wpmd-label"><?php esc_html_e( 'Date folders', 'seory-posts-to-markdown' ); ?></span>
					<select name="date_folders">
						<option value="none"><?php esc_html_e( 'None', 'seory-posts-to-markdown' ); ?></option>
						<option value="year"><?php esc_html_e( 'By year (2024/)', 'seory-posts-to-markdown' ); ?></option>
						<option value="year-month"><?php esc_html_e( 'By year/month (2024/01/)', 'seory-posts-to-markdown' ); ?></option>
					</select>
				</label>
				<label class="seoryco-wpmd-option">
					<input type="checkbox" name="include_index" value="1" checked />
					<span>
						<span class="seoryco-wpmd-option-title"><?php esc_html_e( 'Generate index.md (table of contents)', 'seory-posts-to-markdown' ); ?></span>
						<span class="seoryco-wpmd-help"><?php esc_html_e( 'A list of all exported files — useful as an entry point when handing the archive to AI tools.', 'seory-posts-to-markdown' ); ?></span>
					</span>
				</label>
			</div>

			<p>
				<button type="button" class="button button-primary button-hero" id="seoryco-wpmd-convert">
					<?php esc_html_e( 'Convert and download ZIP', 'seory-posts-to-markdown' ); ?>
				</button>
			</p>
		</form>

		<div id="seoryco-wpmd-progress" class="seoryco-wpmd-card" hidden>
			<div class="seoryco-wpmd-progress-track"><div class="seoryco-wpmd-progress-bar" style="width:0%"></div></div>
			<p class="seoryco-wpmd-progress-text"></p>
		</div>

		<div id="seoryco-wpmd-error" class="notice notice-error inline" hidden><p></p></div>
		<div id="seoryco-wpmd-notice" class="notice notice-success inline" hidden><p></p></div>

		<div id="seoryco-wpmd-result" class="seoryco-wpmd-card" hidden>
			<h2><?php esc_html_e( 'Result', 'seory-posts-to-markdown' ); ?></h2>
			<div class="seoryco-wpmd-stats">
				<div class="seoryco-wpmd-stat">
					<span class="seoryco-wpmd-stat-label"><?php esc_html_e( 'Converted items', 'seory-posts-to-markdown' ); ?></span>
					<span class="seoryco-wpmd-stat-value" id="seoryco-wpmd-stat-converted">0</span>
				</div>
				<div class="seoryco-wpmd-stat">
					<span class="seoryco-wpmd-stat-label"><?php esc_html_e( 'Skipped items', 'seory-posts-to-markdown' ); ?></span>
					<span class="seoryco-wpmd-stat-value" id="seoryco-wpmd-stat-skipped">0</span>
				</div>
				<div class="seoryco-wpmd-stat">
					<span class="seoryco-wpmd-stat-label"><?php esc_html_e( 'Drafts', 'seory-posts-to-markdown' ); ?></span>
					<span class="seoryco-wpmd-stat-value" id="seoryco-wpmd-stat-drafts">0</span>
				</div>
			</div>
			<p class="seoryco-wpmd-types" id="seoryco-wpmd-types"></p>
			<details class="seoryco-wpmd-paths">
				<summary><?php esc_html_e( 'Output file examples', 'seory-posts-to-markdown' ); ?></summary>
				<ul id="seoryco-wpmd-paths"></ul>
			</details>
		</div>

		<div class="seoryco-wpmd-card seoryco-wpmd-note">
			<h2><?php esc_html_e( 'Security and behavior', 'seory-posts-to-markdown' ); ?></h2>
			<p>
				<?php esc_html_e( 'All processing happens on this server; nothing is sent to external services. Images are not downloaded — image URLs remain as Markdown references. script, style, and noscript elements are removed from the output.', 'seory-posts-to-markdown' ); ?>
			</p>
		</div>
	</div>
	<?php
}

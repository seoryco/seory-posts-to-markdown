=== Seory Posts to Markdown ===
Contributors: seoryco
Tags: markdown, export, ai, llm, converter
Requires at least: 6.7
Tested up to: 7.0
Stable tag: 0.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export posts, pages, and custom post types as Markdown files with YAML front matter, bundled into a ZIP. AI-ready, no external services.

== Description ==

Seory Posts to Markdown converts your site content into clean Markdown files and bundles them into a single ZIP archive — ready to hand to AI tools, static site generators, or any Markdown-based workflow.

**Everything runs on your server.** No data is sent to external services, no API keys, no accounts.

= Features =

* Export posts, pages, and public custom post types in one click
* YAML front matter: title, date, type, status, slug, link, author, featured image, categories, tags, custom taxonomy terms, draft flag
* **Two content modes**: raw editor content, or fully rendered content with shortcodes and blocks expanded (`the_content` filters applied)
* Per-post-type taxonomy filters (categories, custom taxonomies) plus a global date range; optionally include drafts
* Flexible output layout: flat files (`slug.md`), per-post folders (`slug/index.md`), date prefixes (`2024-01-15-slug.md`), and year / year-month folders
* Optional `index.md` table of contents — a convenient entry point when handing the archive to AI tools
* GFM tables, fenced code blocks, ATX headings
* Batch processing keeps large sites within PHP time limits

= What it does not do =

* Images are not downloaded; image URLs remain as Markdown references
* `script`, `style`, and `noscript` elements are removed from the output

= Related =

A browser-based version (convert a WordPress export XML without installing anything) is available at [tools.seory.co.jp/wp-to-markdown](https://tools.seory.co.jp/wp-to-markdown).

== Installation ==

1. Install and activate the plugin.
2. Go to **Tools → Seory Posts to Markdown**.
3. Choose post types and output options, then click **Convert and download ZIP**.

== Frequently Asked Questions ==

= Is my content sent anywhere? =

No. Conversion runs entirely on your own server, and the generated ZIP is deleted right after download.

= What is the difference between the two content modes? =

*Raw editor content* converts the stored post content as-is: shortcodes stay unexpanded and block markup comments are removed. *Rendered content* applies the `the_content` filters first, so shortcodes and blocks are expanded to their final HTML before conversion.

= Which user roles can use it? =

Users with the `export` capability (administrators by default).

= Are custom post types supported? =

Yes — all public custom post types appear in the post type list.

= Can I customize the front matter? =

Yes. Developers can add, remove, or reorder keys with the `seoryco_wpmd_frontmatter` filter:

`add_filter( 'seoryco_wpmd_frontmatter', function ( $pairs, $post ) {
    $pairs['subtitle'] = '"' . get_post_meta( $post->ID, 'subtitle', true ) . '"';
    return $pairs;
}, 10, 2 );`

Values must be YAML-formatted; keys with an empty-string value are omitted.

== Screenshots ==

1. Export settings under Tools → Seory Posts to Markdown: per-post-type selection with taxonomy filters, content rendering modes, date range, and output options

== Changelog ==

= 0.1 =
* Initial release.

# Seory Posts to Markdown

A WordPress plugin that exports posts, pages, and custom post types as Markdown files with YAML front matter, bundled into a ZIP archive. On WordPress 6.9+, it also registers read-only [WordPress Abilities](https://developer.wordpress.org/apis/abilities-api/) so AI tools can list changed posts, fetch a post as Markdown, and list external links through your site's own REST API.

**Install it from WordPress.org:** https://wordpress.org/plugins/seory-posts-to-markdown/

- Requires WordPress 6.9 or later and PHP 7.4 or later (with the `zip` extension)
- Full documentation: [`readme.txt`](readme.txt)

## About this repository

This is the source of the plugin as published on WordPress.org. Releases are deployed to the WordPress.org plugin SVN; the tags here (`v0.2`, `v0.2.1`, …) match the released versions.

The Markdown conversion library ([league/html-to-markdown](https://github.com/thephpleague/html-to-markdown)) is committed under `vendor/` so the plugin works without running Composer.

## Support

Please use the [WordPress.org support forum](https://wordpress.org/support/plugin/seory-posts-to-markdown/). GitHub issues are disabled.

## Reporting a security issue

Please do not report security issues in the public forum. Use GitHub's private vulnerability reporting instead: **Security** tab → **Report a vulnerability**.

## License

GPLv2 or later. See https://www.gnu.org/licenses/gpl-2.0.html.

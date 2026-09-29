<?php
/**
 * HTML to Markdown conversion (league/html-to-markdown wrapper).
 *
 * @package WP_to_Markdown
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use League\HTMLToMarkdown\Converter\CommentConverter;
use League\HTMLToMarkdown\Converter\ConverterInterface;
use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\ElementInterface;
use League\HTMLToMarkdown\HtmlConverter;

if ( class_exists( 'League\\HTMLToMarkdown\\HtmlConverter' ) ) {

	/**
	 * Converts <iframe> elements into a plain Markdown link to their src.
	 */
	class Seoryco_Wpmd_Iframe_Converter implements ConverterInterface {

		/**
		 * @param ElementInterface $element Element being converted.
		 * @return string
		 */
		public function convert( ElementInterface $element ): string {
			$src = $element->getAttribute( 'src' );
			if ( '' === $src ) {
				return '';
			}

			return "\n\n[Embedded content](" . $src . ")\n\n";
		}

		/**
		 * @return string[]
		 */
		public function getSupportedTags(): array {
			return array( 'iframe' );
		}
	}

	/**
	 * Preserves WordPress `<!--more-->` / `<!--more Custom text-->` comments.
	 */
	class Seoryco_Wpmd_More_Comment_Converter extends CommentConverter {

		/**
		 * @param ElementInterface $element Element being converted.
		 * @return string
		 */
		public function convert( ElementInterface $element ): string {
			$value = trim( $element->getValue() );
			if ( 0 === strpos( $value, 'more' ) ) {
				return "\n\n<!--" . $element->getValue() . "-->\n\n";
			}

			return parent::convert( $element );
		}
	}

	/**
	 * Keeps block-level wrappers (figure etc.) as blocks so their content does
	 * not run into the next element when tags are stripped.
	 */
	class Seoryco_Wpmd_Block_Converter implements ConverterInterface {

		/**
		 * @param ElementInterface $element Element being converted.
		 * @return string
		 */
		public function convert( ElementInterface $element ): string {
			$content = trim( $element->getValue() );
			if ( '' === $content ) {
				return '';
			}

			return "\n\n" . $content . "\n\n";
		}

		/**
		 * @return string[]
		 */
		public function getSupportedTags(): array {
			return array( 'figure', 'figcaption' );
		}
	}
}

/**
 * Factory / helper around the League converter, mirroring the web tool's Turndown setup.
 */
class Seoryco_Wpmd_Markdown {

	/**
	 * @var HtmlConverter|null
	 */
	private $converter = null;

	public function __construct() {
		if ( ! class_exists( 'League\\HTMLToMarkdown\\HtmlConverter' ) ) {
			return;
		}

		$converter = new HtmlConverter(
			array(
				'header_style'    => 'atx',
				'list_item_style' => '-',
				'remove_nodes'    => 'script style noscript',
				'use_autolinks'   => false,
				'hard_break'      => false,
				'strip_tags'      => true,
			)
		);

		$environment = $converter->getEnvironment();
		$environment->addConverter( new TableConverter() );
		$environment->addConverter( new Seoryco_Wpmd_Iframe_Converter() );
		$environment->addConverter( new Seoryco_Wpmd_More_Comment_Converter() );
		$environment->addConverter( new Seoryco_Wpmd_Block_Converter() );

		$this->converter = $converter;
	}

	/**
	 * Whether the underlying library is available.
	 *
	 * @return bool
	 */
	public function is_available() {
		return null !== $this->converter;
	}

	/**
	 * Convert an HTML fragment to Markdown with the web tool's post-processing.
	 *
	 * @param string $html HTML fragment.
	 * @return string Markdown.
	 */
	public function convert( $html ) {
		if ( null === $this->converter || '' === trim( (string) $html ) ) {
			return '';
		}

		$markdown = $this->converter->convert( (string) $html );

		// Normalize line endings, list marker spacing, and collapse extra blank lines.
		$markdown = preg_replace( '/\r\n|\r/', "\n", $markdown );
		// ParagraphConverter escapes "<!--" inside <p>; restore preserved more comments.
		$markdown = preg_replace( '/\\\\(<!--more.*?-->)/', '$1', $markdown );
		$markdown = preg_replace( '/^[ \t]+$/m', '', $markdown );
		$markdown = preg_replace( '/(^|\n)(-|\d+\.) +/', '$1$2 ', $markdown );
		$markdown = preg_replace( '/\n{3,}/', "\n\n", $markdown );

		return trim( (string) $markdown );
	}
}

<?php
/**
 * Dev.to Adapter Class
 *
 * @package AtomicJamstack
 */

declare(strict_types=1);

namespace AjcBridge\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not permitted.' );
}

/**
 * Dev.to (Forem) platform adapter
 *
 * Converts WordPress posts to Dev.to-compatible Markdown format
 * with front matter following Dev.to conventions.
 *
 * Unlike static site generators, Dev.to is an API-based platform,
 * so this adapter doesn't generate files but prepares content for API submission.
 */
class DevTo_Adapter implements Adapter_Interface {

	/**
	 * Convert WordPress post to Dev.to Markdown format
	 *
	 * @param \WP_Post    $post          WordPress post object.
	 * @param string|null $canonical_url Optional canonical URL for syndication.
	 *
	 * @return string Complete Markdown content with front matter.
	 */
	public function convert( \WP_Post $post, ?string $canonical_url = null ): string {
		$front_matter = $this->get_front_matter( $post, $canonical_url );
		$content      = $this->convert_content( $post->post_content );

		return $this->build_markdown( $front_matter, $content );
	}

	/**
	 * Get file path for post
	 *
	 * Dev.to is API-based, so no file storage is needed.
	 *
	 * @param \WP_Post $post WordPress post object.
	 *
	 * @return string Empty string (no file path for API-based publishing).
	 */
	public function get_file_path( \WP_Post $post ): string {
		return '';
	}

	/**
	 * Get front matter metadata for post
	 *
	 * Extracts and formats post metadata according to Dev.to requirements.
	 *
	 * @param \WP_Post    $post          WordPress post object.
	 * @param string|null $canonical_url Optional canonical URL for syndication.
	 *
	 * @return array Associative array of front matter fields.
	 */
	public function get_front_matter( \WP_Post $post, ?string $canonical_url = null ): array {
		$front_matter = array(
			'title'       => $post->post_title,
			'published'   => false, // Always sync as draft for manual review on Dev.to
			'description' => $this->get_description( $post ),
			'tags'        => $this->get_tags( $post->ID ),
		);

		// Add cover image if available (must be absolute URL)
		$cover_image = $this->get_cover_image( $post->ID );
		if ( $cover_image ) {
			$front_matter['cover_image'] = $cover_image;
		}

		// Add canonical URL if provided
		if ( $canonical_url ) {
			$front_matter['canonical_url'] = $canonical_url;
		}

		// Add series (category) if available
		$series = $this->get_series( $post->ID );
		if ( $series ) {
			$front_matter['series'] = $series;
		}

		return $front_matter;
	}

	/**
	 * Get post description for SEO meta
	 *
	 * Uses excerpt if available, otherwise truncates content.
	 * Max 160 characters for Dev.to.
	 *
	 * @param \WP_Post $post WordPress post object.
	 *
	 * @return string Description (max 160 chars).
	 */
	private function get_description( \WP_Post $post ): string {
		$description = '';

		// Try excerpt first
		if ( ! empty( $post->post_excerpt ) ) {
			$description = $post->post_excerpt;
		} else {
			// Fallback to truncated content
			$content     = wp_strip_all_tags( $post->post_content );
			$description = wp_trim_words( $content, 25, '' );
		}

		// Truncate to 160 characters
		if ( strlen( $description ) > 160 ) {
			$description = substr( $description, 0, 157 ) . '...';
		}

		return $description;
	}

	/**
	 * Get post tags formatted for Dev.to
	 *
	 * Dev.to requirements:
	 * - Max 4 tags
	 * - Lowercase
	 * - No spaces (use hyphens)
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return array Array of formatted tag names.
	 */
	private function get_tags( int $post_id ): array {
		$tags = wp_get_post_tags( $post_id, array( 'fields' => 'names' ) );

		if ( empty( $tags ) || is_wp_error( $tags ) ) {
			return array();
		}

		// Format tags: lowercase, replace spaces with hyphens
		$formatted = array_map(
			function ( $tag ) {
				$tag = strtolower( $tag );
				$tag = str_replace( ' ', '-', $tag );
				// Remove special characters except hyphens
				$tag = preg_replace( '/[^a-z0-9\-]/', '', $tag );
				return $tag;
			},
			$tags
		);

		// Limit to 4 tags
		return array_slice( $formatted, 0, 4 );
	}

	/**
	 * Get cover image URL
	 *
	 * CRITICAL: Must return absolute URL, not file path.
	 * Dev.to fetches images from the provided URL.
	 *
	 * Recommended ratio: 100:42 (e.g., 1000x420px)
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return string|null Absolute URL to cover image, or null if none.
	 */
	private function get_cover_image( int $post_id ): ?string {
		$thumbnail_id = get_post_thumbnail_id( $post_id );

		if ( ! $thumbnail_id ) {
			return null;
		}

		// Get full-size image URL
		$url = wp_get_attachment_url( $thumbnail_id );

		if ( ! $url ) {
			return null;
		}

		// Ensure absolute URL (not relative)
		if ( ! wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			$url = home_url( $url );
		}

		// Validate URL has http/https scheme
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return null;
		}

		return $url;
	}

	/**
	 * Get canonical URL for secondary publishing mode
	 *
	 * Returns URL only if mode is 'secondary' and base URL is configured.
	 *
	 * @param \WP_Post $post WordPress post object.
	 *
	/**
	 * Get series name (primary category)
	 *
	 * Dev.to series groups related articles.
	 * We use the primary WordPress category.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return string|null Series name or null.
	 */
	private function get_series( int $post_id ): ?string {
		$categories = get_the_category( $post_id );

		if ( empty( $categories ) ) {
			return null;
		}

		// Use first (primary) category
		return $categories[0]->name;
	}

	/**
	 * Convert post content to markdown
	 *
	 * Ensures all image URLs are absolute so Dev.to can fetch them.
	 *
	 * @param string $html WordPress post content (HTML).
	 *
	 * @return string Markdown content with absolute image URLs.
	 */
	private function convert_content( string $html ): string {
		// Convert HTML to Markdown
		$markdown = $this->html_to_markdown( $html );

		// Ensure all image URLs are absolute
		$markdown = $this->ensure_absolute_image_urls( $markdown );

		return $markdown;
	}

	/**
	 * Convert HTML to Markdown
	 *
	 * Basic conversion for common elements.
	 * WordPress HTML → Markdown
	 *
	 * @param string $html HTML content.
	 *
	 * @return string Markdown content.
	 */
	private function html_to_markdown( string $html ): string {
		// Remove WordPress blocks comments
		$html = preg_replace( '/<!-- wp:(.*?) -->(.*?)<!-- \/wp:\1 -->/s', '$2', $html );

		// Convert images: <img src="..." alt="..."> → ![alt](src)
		$html = preg_replace(
			'/<img[^>]+src=["\']([^"\']+)["\'][^>]*alt=["\']([^"\']*)["\'][^>]*>/i',
			'![$2]($1)',
			$html
		);
		$html = preg_replace(
			'/<img[^>]+alt=["\']([^"\']*)["\'][^>]+src=["\']([^"\']+)["\'][^>]*>/i',
			'![$1]($2)',
			$html
		);
		$html = preg_replace(
			'/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i',
			'![]($1)',
			$html
		);

		// Convert links: <a href="...">text</a> → [text](...)
		$html = preg_replace( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/i', '[$2]($1)', $html );

		// Convert strong/bold: <strong>text</strong> → **text**
		$html = preg_replace( '/<(strong|b)>(.*?)<\/\1>/i', '**$2**', $html );

		// Convert em/italic: <em>text</em> → *text*
		$html = preg_replace( '/<(em|i)>(.*?)<\/\1>/i', '*$2*', $html );

		// Convert headings: <h1>text</h1> → # text
		$html = preg_replace( '/<h1[^>]*>(.*?)<\/h1>/i', "\n# $1\n", $html );
		$html = preg_replace( '/<h2[^>]*>(.*?)<\/h2>/i', "\n## $1\n", $html );
		$html = preg_replace( '/<h3[^>]*>(.*?)<\/h3>/i', "\n### $1\n", $html );
		$html = preg_replace( '/<h4[^>]*>(.*?)<\/h4>/i', "\n#### $1\n", $html );
		$html = preg_replace( '/<h5[^>]*>(.*?)<\/h5>/i', "\n##### $1\n", $html );
		$html = preg_replace( '/<h6[^>]*>(.*?)<\/h6>/i', "\n###### $1\n", $html );

		// Convert lists: <ul><li>item</li></ul> → - item
		$html = preg_replace( '/<li>(.*?)<\/li>/i', "- $1\n", $html );
		$html = preg_replace( '/<\/?ul>/i', "\n", $html );
		$html = preg_replace( '/<\/?ol>/i', "\n", $html );

		// Convert code blocks: <pre><code>...</code></pre> → ```...```
		$html = preg_replace( '/<pre[^>]*><code[^>]*>(.*?)<\/code><\/pre>/is', "\n```\n$1\n```\n", $html );

		// Convert inline code: <code>text</code> → `text`
		$html = preg_replace( '/<code>(.*?)<\/code>/i', '`$1`', $html );

		// Convert blockquotes: <blockquote>text</blockquote> → > text
		$html = preg_replace( '/<blockquote[^>]*>(.*?)<\/blockquote>/is', "\n> $1\n", $html );

		// Remove paragraph tags
		$html = preg_replace( '/<\/?p[^>]*>/i', "\n\n", $html );

		// Remove line breaks
		$html = str_replace( '<br>', "\n", $html );
		$html = str_replace( '<br />', "\n", $html );
		$html = str_replace( '<br/>', "\n", $html );

		// Remove remaining HTML tags
		$html = wp_strip_all_tags( $html );

		// Clean up multiple newlines
		$html = preg_replace( "/\n{3,}/", "\n\n", $html );

		return trim( $html );
	}

	/**
	 * Ensure all image URLs in markdown are absolute
	 *
	 * Converts relative URLs to absolute so Dev.to can fetch them.
	 *
	 * @param string $markdown Markdown content.
	 *
	 * @return string Markdown with absolute image URLs.
	 */
	private function ensure_absolute_image_urls( string $markdown ): string {
		// Match markdown image syntax: ![alt](url)
		$markdown = preg_replace_callback(
			'/!\[([^\]]*)\]\(([^)]+)\)/',
			function ( $matches ) {
				$alt = $matches[1];
				$url = $matches[2];

				// If URL is relative, make it absolute
				if ( ! wp_parse_url( $url, PHP_URL_SCHEME ) ) {
					$url = home_url( $url );
				}

				return "![{$alt}]({$url})";
			},
			$markdown
		);

		return $markdown;
	}

	/**
	 * Build complete markdown with front matter
	 *
	 * Combines YAML front matter and content.
	 *
	 * @param array  $front_matter Front matter fields.
	 * @param string $content      Markdown content.
	 *
	 * @return string Complete markdown document.
	 */
	private function build_markdown( array $front_matter, string $content ): string {
		$yaml = "---\n";

		foreach ( $front_matter as $key => $value ) {
			if ( is_array( $value ) ) {
				// Tags array
				$yaml .= $key . ': ' . implode( ', ', $value ) . "\n";
			} elseif ( is_bool( $value ) ) {
				// Boolean values
				$yaml .= $key . ': ' . ( $value ? 'true' : 'false' ) . "\n";
			} else {
				// String values - escape if contains special chars
				$value_str = (string) $value;
				if ( preg_match( '/[:\n\r]/', $value_str ) ) {
					// Wrap in quotes and escape existing quotes
					$value_str = '"' . str_replace( '"', '\"', $value_str ) . '"';
				}
				$yaml .= $key . ': ' . $value_str . "\n";
			}
		}

		$yaml .= "---\n\n";

		return $yaml . $content;
	}
}

<?php
/**
 * Sync Runner Class
 *
 * @package WPJamstack
 */

declare(strict_types=1);

namespace WPJamstack\Core;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not permitted.' );
}

/**
 * Central sync orchestrator
 *
 * This class is the ONLY entry point for sync operations.
 * All sync logic must flow through this runner.
 */
class Sync_Runner {

	/**
	 * Run synchronization for a specific post
	 *
	 * Pipeline:
	 * 1. Validate post exists and is publishable
	 * 2. Fetch post data from database
	 * 3. Convert to Markdown via adapter (TODO)
	 * 4. Process and optimize images (TODO)
	 * 5. Upload to GitHub via API
	 * 6. Return success metadata or WP_Error
	 *
	 * @param int $post_id Post ID to synchronize.
	 *
	 * @return array|\WP_Error Success array with metadata or WP_Error on failure.
	 */
	public static function run( int $post_id ): array|\WP_Error {
		Logger::info( 'Sync runner started', array( 'post_id' => $post_id ) );

		// Validate post
		$post = get_post( $post_id );
		if ( ! $post ) {
			Logger::error( 'Post not found', array( 'post_id' => $post_id ) );
			return new \WP_Error( 'post_not_found', __( 'Post not found', 'wp-jamstack-sync' ) );
		}

		// Only sync published posts
		if ( 'publish' !== $post->post_status ) {
			Logger::warning( 'Post not published, skipping sync', array( 'post_id' => $post_id, 'status' => $post->post_status ) );
			return new \WP_Error( 'post_not_published', __( 'Only published posts can be synced', 'wp-jamstack-sync' ) );
		}

		// TODO: Convert to Markdown via adapter
		// For now, create simple content
		$content = self::generate_simple_markdown( $post );

		// Upload to GitHub
		$result = self::upload_to_github( $post, $content );

		if ( is_wp_error( $result ) ) {
			Logger::error( 'Sync failed', array( 'post_id' => $post_id, 'error' => $result->get_error_message() ) );
			return $result;
		}

		Logger::success( 'Sync completed', array( 'post_id' => $post_id ) );

		return array(
			'post_id' => $post_id,
			'success' => true,
			'commit'  => $result,
		);
	}

	/**
	 * Generate simple Markdown content
	 *
	 * TODO: Replace with proper adapter implementation
	 *
	 * @param \WP_Post $post Post object.
	 *
	 * @return string Markdown content.
	 */
	private static function generate_simple_markdown( \WP_Post $post ): string {
		$frontmatter = array(
			'title'   => $post->post_title,
			'date'    => $post->post_date,
			'slug'    => $post->post_name,
			'status'  => $post->post_status,
		);

		$yaml = "---\n";
		foreach ( $frontmatter as $key => $value ) {
			$yaml .= sprintf( "%s: %s\n", $key, $value );
		}
		$yaml .= "---\n\n";

		return $yaml . $post->post_content;
	}

	/**
	 * Upload content to GitHub
	 *
	 * @param \WP_Post $post    Post object.
	 * @param string   $content Markdown content.
	 *
	 * @return array|\WP_Error Commit data or WP_Error.
	 */
	private static function upload_to_github( \WP_Post $post, string $content ): array|\WP_Error {
		$git_api = new Git_API();

		// Generate file path
		$file_path = sprintf( 'content/posts/%s.md', $post->post_name );

		// Check if file exists (to get SHA for update)
		$existing_file = $git_api->get_file( $file_path );
		$sha = $existing_file['sha'] ?? null;

		// Create commit message
		$commit_message = sprintf(
			'%s: %s',
			null === $sha ? 'Create' : 'Update',
			$post->post_title
		);

		// Upload file
		$result = $git_api->create_or_update_file( $file_path, $content, $commit_message, $sha );

		return $result;
	}
}

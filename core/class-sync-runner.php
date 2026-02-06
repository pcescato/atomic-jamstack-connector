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
	 * 2. Process featured image (if set)
	 * 3. Process content images
	 * 4. Upload images to GitHub
	 * 5. Convert to Markdown via Hugo adapter (with image path mapping)
	 * 6. Upload Markdown to GitHub via API
	 * 7. Update post meta with sync status and timestamp
	 * 8. Return success metadata or WP_Error
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
			self::update_sync_meta( $post_id, 'error' );
			return new \WP_Error( 'post_not_found', __( 'Post not found', 'wp-jamstack-sync' ) );
		}

		// Only sync published posts
		if ( 'publish' !== $post->post_status ) {
			Logger::warning( 'Post not published, skipping sync', array( 'post_id' => $post_id, 'status' => $post->post_status ) );
			self::update_sync_meta( $post_id, 'error' );
			return new \WP_Error( 'post_not_published', __( 'Only published posts can be synced', 'wp-jamstack-sync' ) );
		}

		// Process featured image first
		require_once WPJAMSTACK_PATH . 'core/class-media-processor.php';
		$media_processor      = new Media_Processor();
		$featured_image_path  = $media_processor->process_featured_image( $post_id );

		// Process content images
		$image_mapping = $media_processor->process_post_images( $post_id, $post->post_content );

		if ( is_wp_error( $image_mapping ) ) {
			Logger::error(
				'Image processing failed',
				array(
					'post_id' => $post_id,
					'error'   => $image_mapping->get_error_message(),
				)
			);
			// Continue with sync even if images fail - non-blocking
			$image_mapping = array();
		}

		// Load adapter
		require_once WPJAMSTACK_PATH . 'adapters/interface-adapter.php';
		require_once WPJAMSTACK_PATH . 'adapters/class-hugo-adapter.php';

		$adapter = new \WPJamstack\Adapters\Hugo_Adapter();

		// Convert to Markdown with image path replacements and featured image
		try {
			$content   = $adapter->convert( $post, $image_mapping, $featured_image_path ?? '' );
			$file_path = $adapter->get_file_path( $post );
		} catch ( \Exception $e ) {
			Logger::error(
				'Adapter conversion failed',
				array(
					'post_id' => $post_id,
					'error'   => $e->getMessage(),
				)
			);
			self::update_sync_meta( $post_id, 'error' );
			return new \WP_Error( 'conversion_failed', $e->getMessage() );
		}

		// Upload to GitHub
		$result = self::upload_to_github( $post, $content, $file_path );

		if ( is_wp_error( $result ) ) {
			Logger::error( 'Sync failed', array( 'post_id' => $post_id, 'error' => $result->get_error_message() ) );
			self::update_sync_meta( $post_id, 'error' );
			return $result;
		}

		// Update success meta
		self::update_sync_meta( $post_id, 'success' );

		// Cache file path for future deletions
		update_post_meta( $post_id, '_jamstack_file_path', $file_path );

		Logger::success( 'Sync completed', array( 'post_id' => $post_id ) );

		return array(
			'post_id'   => $post_id,
			'success'   => true,
			'commit'    => $result,
			'file_path' => $file_path,
		);
	}

	/**
	 * Upload content to GitHub
	 *
	 * @param \WP_Post $post      Post object.
	 * @param string   $content   Markdown content.
	 * @param string   $file_path Repository file path.
	 *
	 * @return array|\WP_Error Commit data or WP_Error.
	 */
	private static function upload_to_github( \WP_Post $post, string $content, string $file_path ): array|\WP_Error {
		$git_api = new Git_API();

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

	/**
	 * Update sync meta for post
	 *
	 * Updates both status and timestamp. Maintains single source of truth.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $status  Sync status.
	 *
	 * @return void
	 */
	private static function update_sync_meta( int $post_id, string $status ): void {
		update_post_meta( $post_id, '_jamstack_sync_status', $status );
		update_post_meta( $post_id, '_jamstack_sync_last', current_time( 'mysql' ) );
	}

	/**
	 * Delete post from GitHub
	 *
	 * Removes the Markdown file and associated images from the repository.
	 * Handles cases where files don't exist gracefully.
	 *
	 * Pipeline:
	 * 1. Determine GitHub file path for Markdown
	 * 2. Delete Markdown file
	 * 3. List images in post directory
	 * 4. Delete each image file
	 * 5. Return summary of deleted files
	 *
	 * @param int $post_id Post ID to delete from GitHub.
	 *
	 * @return array|\WP_Error Success array with deleted files or WP_Error on failure.
	 */
	public static function delete( int $post_id ): array|\WP_Error {
		Logger::info( 'Deletion runner started', array( 'post_id' => $post_id ) );

		// Get post data (may be trashed or already deleted)
		$post = get_post( $post_id );

		// If post doesn't exist, check for cached meta to build file path
		if ( ! $post ) {
			Logger::warning(
				'Post not found, attempting deletion with cached meta',
				array( 'post_id' => $post_id )
			);

			// Try to get cached file path from meta
			$cached_path = get_post_meta( $post_id, '_jamstack_file_path', true );

			if ( empty( $cached_path ) ) {
				Logger::error(
					'Cannot determine file path for deleted post',
					array( 'post_id' => $post_id )
				);
				return new \WP_Error(
					'post_not_found',
					__( 'Post not found and no cached file path available', 'wp-jamstack-sync' )
				);
			}

			$file_path = $cached_path;
		} else {
			// Post exists, try to use cached path first
			$cached_path = get_post_meta( $post_id, '_jamstack_file_path', true );

			if ( ! empty( $cached_path ) ) {
				// Use cached path
				$file_path = $cached_path;
				Logger::info(
					'Using cached file path',
					array(
						'post_id' => $post_id,
						'path'    => $file_path,
					)
				);
			} else {
				// Generate file path using adapter
				require_once WPJAMSTACK_PATH . 'adapters/interface-adapter.php';
				require_once WPJAMSTACK_PATH . 'adapters/class-hugo-adapter.php';

				$adapter   = new \WPJamstack\Adapters\Hugo_Adapter();
				$file_path = $adapter->get_file_path( $post );

				// Cache the file path for future use
				update_post_meta( $post_id, '_jamstack_file_path', $file_path );

				Logger::info(
					'Generated and cached file path',
					array(
						'post_id' => $post_id,
						'path'    => $file_path,
					)
				);
			}
		}

		$git_api = new Git_API();
		$deleted = array();

		// Delete Markdown file
		Logger::info( 'Deleting Markdown file', array( 'path' => $file_path ) );

		$result = $git_api->delete_file(
			$file_path,
			sprintf( 'Delete: %s', $post ? $post->post_title : "Post #{$post_id}" )
		);

		if ( is_wp_error( $result ) ) {
			Logger::error(
				'Failed to delete Markdown file',
				array(
					'path'  => $file_path,
					'error' => $result->get_error_message(),
				)
			);
			return $result;
		}

		$deleted[] = $file_path;
		Logger::success( 'Markdown file deleted', array( 'path' => $file_path ) );

		// Delete images directory
		$images_dir = "static/images/{$post_id}";
		Logger::info( 'Checking for images to delete', array( 'dir' => $images_dir ) );

		$image_files = $git_api->list_directory( $images_dir );

		if ( is_wp_error( $image_files ) ) {
			// Log but don't fail - images might not exist
			Logger::warning(
				'Could not list image directory',
				array(
					'dir'   => $images_dir,
					'error' => $image_files->get_error_message(),
				)
			);
		} elseif ( ! empty( $image_files ) ) {
			Logger::info(
				'Found images to delete',
				array(
					'count' => count( $image_files ),
					'dir'   => $images_dir,
				)
			);

			// Delete each image file
			foreach ( $image_files as $file ) {
				if ( 'file' !== $file['type'] ) {
					continue; // Skip directories
				}

				$image_path = $file['path'];
				Logger::info( 'Deleting image', array( 'path' => $image_path ) );

				$image_result = $git_api->delete_file(
					$image_path,
					sprintf( 'Delete image: %s', basename( $image_path ) )
				);

				if ( is_wp_error( $image_result ) ) {
					// Log but continue with other images
					Logger::warning(
						'Failed to delete image',
						array(
							'path'  => $image_path,
							'error' => $image_result->get_error_message(),
						)
					);
				} else {
					$deleted[] = $image_path;
					Logger::success( 'Image deleted', array( 'path' => $image_path ) );
				}
			}
		} else {
			Logger::info( 'No images found to delete', array( 'dir' => $images_dir ) );
		}

		Logger::success(
			'Deletion completed',
			array(
				'post_id'       => $post_id,
				'deleted_count' => count( $deleted ),
			)
		);

		return array(
			'post_id' => $post_id,
			'success' => true,
			'deleted' => $deleted,
		);
	}
}

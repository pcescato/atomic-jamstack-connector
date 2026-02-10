<?php
/**
 * Sync Runner Class
 *
 * @package AtomicJamstack
 */

declare(strict_types=1);

namespace AtomicJamstack\Core;

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
	 * 2. Test GitHub connection before heavy processing
	 * 3. Process featured image (if set)
	 * 4. Process content images
	 * 5. Upload images to GitHub
	 * 6. Convert to Markdown via Hugo adapter (with image path mapping)
	 * 7. Upload Markdown to GitHub via API
	 * 8. Update post meta with sync status and timestamp
	 * 9. Return success metadata or WP_Error
	 *
	 * @param int $post_id Post ID to synchronize.
	 *
	 * @return array|\WP_Error Success array with metadata or WP_Error on failure.
	 */
	public static function run( int $post_id ): array|\WP_Error {
		Logger::info( 'Sync runner started', array( 'post_id' => $post_id ) );

		// Record sync start time for safety timeout
		update_post_meta( $post_id, '_jamstack_sync_start_time', time() );

		// Check for safety timeout (5 minutes)
		self::check_safety_timeout( $post_id );

		// Validate post
		$post = get_post( $post_id );
		if ( ! $post ) {
			Logger::error( 'Post not found', array( 'post_id' => $post_id ) );
			self::update_sync_meta( $post_id, 'failed' );
			return new \WP_Error( 'post_not_found', __( 'Post not found', 'atomic-jamstack-connector' ) );
		}

		// Only sync published posts
		if ( 'publish' !== $post->post_status ) {
			Logger::warning( 'Post not published, skipping sync', array( 'post_id' => $post_id, 'status' => $post->post_status ) );
			self::update_sync_meta( $post_id, 'failed' );
			return new \WP_Error( 'post_not_published', __( 'Only published posts can be synced', 'atomic-jamstack-connector' ) );
		}

		// Get publishing strategy from settings
		$settings = get_option( 'atomic_jamstack_settings', array() );
		$strategy = $settings['publishing_strategy'] ?? 'wordpress_only';

		// Migrate old settings to new strategy if needed
		if ( 'wordpress_only' === $strategy && isset( $settings['adapter_type'] ) ) {
			$strategy = self::migrate_old_settings( $settings );
		}

		Logger::info(
			'Publishing strategy determined',
			array(
				'post_id'  => $post->ID,
				'strategy' => $strategy,
			)
		);

		// Route to appropriate sync handler
		$results = array();

		switch ( $strategy ) {
			case 'wordpress_only':
				// Plugin configured but sync disabled
				Logger::info( 'Sync skipped (wordpress_only mode)', array( 'post_id' => $post_id ) );
				return array(
					'status'  => 'skipped',
					'message' => __( 'WordPress-only mode - sync disabled', 'atomic-jamstack-connector' ),
				);

			case 'wordpress_devto':
				// WordPress is canonical, optionally syndicate to dev.to
				$publish_devto = get_post_meta( $post_id, '_atomic_jamstack_publish_devto', true );
				
				if ( '1' === $publish_devto ) {
					$wordpress_url = $settings['devto_site_url'] ?? get_site_url();
					$canonical_url = trailingslashit( $wordpress_url ) . $post->post_name;
					
					Logger::info( 'Syndicating to dev.to', array( 'post_id' => $post_id, 'canonical_url' => $canonical_url ) );
					$results['devto'] = self::sync_to_devto( $post, $canonical_url );
				} else {
					Logger::info( 'Dev.to sync skipped (checkbox not checked)', array( 'post_id' => $post_id ) );
					return array(
						'status'  => 'skipped',
						'message' => __( 'Dev.to sync not enabled for this post', 'atomic-jamstack-connector' ),
					);
				}
				break;

			case 'github_only':
				// WordPress is headless, always sync to GitHub
				Logger::info( 'Syncing to GitHub only', array( 'post_id' => $post_id ) );
				$results['github'] = self::sync_to_github( $post );
				break;

			case 'devto_only':
				// WordPress is headless, always sync to dev.to (no canonical)
				Logger::info( 'Syncing to dev.to only', array( 'post_id' => $post_id ) );
				$results['devto'] = self::sync_to_devto( $post, null );
				break;

			case 'dual_github_devto':
				// WordPress is headless, always sync to GitHub, optionally to dev.to
				Logger::info( 'Dual publishing mode: GitHub + optional dev.to', array( 'post_id' => $post_id ) );
				
				// Always sync to GitHub
				$results['github'] = self::sync_to_github( $post );

				// Optionally sync to dev.to if checkbox checked
				$publish_devto = get_post_meta( $post_id, '_atomic_jamstack_publish_devto', true );
				
				if ( '1' === $publish_devto ) {
					$github_url    = $settings['github_site_url'] ?? '';
					$canonical_url = trailingslashit( $github_url ) . 'posts/' . $post->post_name;
					
					Logger::info( 'Syndicating to dev.to', array( 'post_id' => $post_id, 'canonical_url' => $canonical_url ) );
					$results['devto'] = self::sync_to_devto( $post, $canonical_url );
				} else {
					Logger::info( 'Dev.to sync skipped (checkbox not checked)', array( 'post_id' => $post_id ) );
				}
				break;

			default:
				Logger::error( 'Unknown publishing strategy', array( 'strategy' => $strategy ) );
				return new \WP_Error(
					'invalid_strategy',
					sprintf(
						/* translators: %s: strategy name */
						__( 'Unknown publishing strategy: %s', 'atomic-jamstack-connector' ),
						$strategy
					)
				);
		}

		return $results;
	}

	/**
	 * Migrate old settings format to new publishing strategy
	 *
	 * Backward compatibility helper for users upgrading from old version.
	 *
	 * @param array $settings Current settings array.
	 *
	 * @return string New publishing strategy.
	 */
	private static function migrate_old_settings( array $settings ): string {
		$adapter_type = $settings['adapter_type'] ?? 'hugo';
		$devto_mode   = $settings['devto_mode'] ?? 'primary';

		// Old: adapter_type = 'hugo' → New: github_only
		if ( 'hugo' === $adapter_type ) {
			return 'github_only';
		}

		// Old: adapter_type = 'devto', devto_mode = 'primary' → New: devto_only
		if ( 'devto' === $adapter_type && 'primary' === $devto_mode ) {
			return 'devto_only';
		}

		// Old: adapter_type = 'devto', devto_mode = 'secondary' → New: dual_github_devto
		if ( 'devto' === $adapter_type && 'secondary' === $devto_mode ) {
			return 'dual_github_devto';
		}

		// Default fallback
		return 'wordpress_only';
	}

	/**
	 * Dual publish: GitHub first (canonical), then Dev.to (syndication)
	 *
	 * Used when Dev.to mode is 'secondary' - the Hugo site is the primary source,
	 * and Dev.to syndicates with canonical_url pointing back to Hugo.
	 *
	 * @param \WP_Post $post WordPress post object.
	 *
	 * @return array|\WP_Error Combined results or WP_Error on failure.
	 */
	private static function sync_dual_publish( \WP_Post $post ): array|\WP_Error {
		$post_id = $post->ID;
		Logger::info( 'Starting dual publish workflow', array( 'post_id' => $post_id ) );

		// STEP 1: Sync to GitHub (Hugo) - this is the canonical source
		Logger::info( 'Step 1: Publishing to GitHub (canonical source)', array( 'post_id' => $post_id ) );
		$github_result = self::sync_to_github( $post );

		if ( is_wp_error( $github_result ) ) {
			Logger::error(
				'Dual publish failed at GitHub step',
				array(
					'post_id' => $post_id,
					'error'   => $github_result->get_error_message(),
				)
			);
			// Don't proceed to Dev.to if GitHub fails (canonical must exist first)
			return $github_result;
		}

		Logger::success( 'GitHub sync complete, proceeding to Dev.to syndication', array( 'post_id' => $post_id ) );

		// STEP 2: Syndicate to Dev.to with canonical_url
		Logger::info( 'Step 2: Syndicating to Dev.to (with canonical_url)', array( 'post_id' => $post_id ) );
		$devto_result = self::sync_to_devto( $post );

		if ( is_wp_error( $devto_result ) ) {
			Logger::warning(
				'Dual publish: GitHub succeeded but Dev.to failed',
				array(
					'post_id'      => $post_id,
					'github'       => 'success',
					'devto_error'  => $devto_result->get_error_message(),
				)
			);
			// GitHub succeeded, so not a total failure - return partial success
			return array(
				'status'       => 'partial',
				'github'       => $github_result,
				'devto'        => $devto_result,
				'message'      => __( 'Published to GitHub successfully, but Dev.to syndication failed.', 'atomic-jamstack-connector' ),
			);
		}

		Logger::success(
			'Dual publish complete: GitHub + Dev.to',
			array(
				'post_id'      => $post_id,
				'github'       => 'success',
				'devto'        => 'success',
				'devto_url'    => $devto_result['url'] ?? null,
			)
		);

		// Both succeeded
		return array(
			'status'  => 'success',
			'github'  => $github_result,
			'devto'   => $devto_result,
			'message' => __( 'Published to GitHub and syndicated to Dev.to successfully.', 'atomic-jamstack-connector' ),
		);
	}

	/**
	 * Sync post to Dev.to via API
	 *
	 * @param \WP_Post $post WordPress post object.
	 *
	 * @return array|\WP_Error Success array or WP_Error on failure.
	 */
	/**
	 * Sync post to Dev.to
	 *
	 * @param \WP_Post    $post          WordPress post object.
	 * @param string|null $canonical_url Optional canonical URL for syndication.
	 *
	 * @return array|\WP_Error Success array with article data or WP_Error.
	 */
	private static function sync_to_devto( \WP_Post $post, ?string $canonical_url = null ): array|\WP_Error {
		$post_id = $post->ID;
		Logger::info( 'Starting Dev.to sync', array( 'post_id' => $post_id, 'canonical_url' => $canonical_url ) );

		// Update status
		self::update_sync_meta( $post_id, 'processing' );

		$sync_result = null;
		$sync_error  = null;

		try {
			// Load Dev.to adapter
			require_once ATOMIC_JAMSTACK_PATH . 'adapters/class-devto-adapter.php';
			$adapter = new \AtomicJamstack\Adapters\DevTo_Adapter();

			// Convert to markdown with front matter (pass canonical URL)
			$markdown = $adapter->convert( $post, $canonical_url );

			// Initialize API client
			require_once ATOMIC_JAMSTACK_PATH . 'core/class-devto-api.php';
			$devto_api = new DevTo_API();

			// Check if article already published (get stored article ID)
			$article_id = get_post_meta( $post_id, '_devto_article_id', true );
			$article_id = $article_id ? (int) $article_id : null;

			// Publish or update
			$result = $devto_api->publish_article( $markdown, $article_id );

			if ( is_wp_error( $result ) ) {
				$sync_error = $result;
				throw new \Exception( $result->get_error_message() );
			}

			// Store article ID for future updates
			if ( isset( $result['id'] ) ) {
				update_post_meta( $post_id, '_devto_article_id', $result['id'] );
			}

			// Store article URL if available
			if ( isset( $result['url'] ) ) {
				update_post_meta( $post_id, '_devto_article_url', $result['url'] );
			}

			$sync_result = $result;

			Logger::success(
				'Dev.to sync complete',
				array(
					'post_id'    => $post_id,
					'article_id' => $result['id'] ?? null,
					'url'        => $result['url'] ?? null,
				)
			);

		} catch ( \Exception $e ) {
			$sync_error = new \WP_Error( 'sync_exception', $e->getMessage() );
			Logger::error(
				'Dev.to sync exception',
				array(
					'post_id'   => $post_id,
					'exception' => $e->getMessage(),
				)
			);
		} finally {
			// CRITICAL: Always update status and clear start time in finally block
			// This ensures cleanup happens even if script crashes
			if ( $sync_error ) {
				self::update_sync_meta( $post_id, 'failed', $sync_error->get_error_message() );
			} else {
				self::update_sync_meta( $post_id, 'success' );
				update_post_meta( $post_id, '_jamstack_sync_last', time() );
			}

			// Clear start time
			delete_post_meta( $post_id, '_jamstack_sync_start_time' );
		}

		// Return result or error
		if ( $sync_error ) {
			return $sync_error;
		}

		return $sync_result ?? array( 'status' => 'success' );
	}

	/**
	 * Sync post to GitHub (static site generator flow)
	 *
	 * Original sync logic for Hugo/Jekyll/etc via GitHub.
	 *
	 * @param \WP_Post $post WordPress post object.
	 *
	 * @return array|\WP_Error Success array or WP_Error on failure.
	 */
	private static function sync_to_github( \WP_Post $post ): array|\WP_Error {
		$post_id = $post->ID;

		// Test GitHub connection before heavy image processing to save resources
		$git_api = new Git_API();
		$connection_test = $git_api->test_connection();
		
		if ( is_wp_error( $connection_test ) ) {
			Logger::error(
				'GitHub connection test failed before sync',
				array(
					'post_id' => $post_id,
					'error'   => $connection_test->get_error_message(),
				)
			);
			self::update_sync_meta( $post_id, 'failed' );
			return $connection_test;
		}

		Logger::info( 'GitHub connection validated', array( 'post_id' => $post_id ) );

		// Initialize media processor and result
		$media_processor = null;
		$sync_result = null;
		$sync_error = null;
		
		try {
			// Set status to processing
			self::update_sync_meta( $post_id, 'processing' );
			
			// Wrap entire sync process in try-catch-finally for robust error handling
			require_once ATOMIC_JAMSTACK_PATH . 'core/class-media-processor.php';
			$media_processor = new Media_Processor();

			// Collect featured image data
			$featured_data = $media_processor->get_featured_image_data( $post_id );
			$featured_image_path = ! empty( $featured_data ) ? sprintf( '/images/%d/featured.webp', $post_id ) : '';

			// Collect content images data
			$images_result = $media_processor->get_post_images_data( $post_id, $post->post_content );
			$image_files = $images_result['files'] ?? array();
			$image_mapping = $images_result['mappings'] ?? array();

			// Load adapter
			require_once ATOMIC_JAMSTACK_PATH . 'adapters/interface-adapter.php';
			require_once ATOMIC_JAMSTACK_PATH . 'adapters/class-hugo-adapter.php';

			$adapter = new \AtomicJamstack\Adapters\Hugo_Adapter();

			// Convert to Markdown with image path replacements and featured image
			$markdown_content = $adapter->convert( $post, $image_mapping, $featured_image_path );
			$file_path = $adapter->get_file_path( $post );

			// Build payload for atomic commit
			$payload = array();

			// Add Markdown file
			$payload[ $file_path ] = $markdown_content;

			// Add featured images
			$payload = array_merge( $payload, $featured_data );

			// Add content images
			$payload = array_merge( $payload, $image_files );

			// Check payload size (10MB limit per ADR-04)
			$total_size = 0;
			foreach ( $payload as $content ) {
				$total_size += strlen( $content );
			}

			if ( $total_size > 10485760 ) { // 10MB in bytes
				Logger::warning(
					'Payload exceeds 10MB limit',
					array(
						'post_id' => $post_id,
						'size_mb' => round( $total_size / 1048576, 2 ),
						'files'   => count( $payload ),
					)
				);
			}

			Logger::info(
				'Atomic commit payload prepared',
				array(
					'post_id' => $post_id,
					'files'   => count( $payload ),
					'size_kb' => round( $total_size / 1024, 2 ),
				)
			);

			// Create atomic commit
			$commit_message = sprintf(
				'%s: %s',
				'Update', // We don't know if create or update in atomic mode
				$post->post_title
			);

			$result = $git_api->create_atomic_commit( $payload, $commit_message );

			if ( is_wp_error( $result ) ) {
				Logger::error(
					'Sync aborted: Atomic commit failed',
					array(
						'post_id' => $post_id,
						'error'   => $result->get_error_message(),
					)
				);
				
				// Store error for finally block
				$sync_error = $result;
				return $result;
			}

			// Cache file path for future deletions
			update_post_meta( $post_id, '_jamstack_file_path', $file_path );

			// Save commit URL for monitoring dashboard
			if ( isset( $result['commit_sha'] ) ) {
				$settings   = get_option( 'atomic_jamstack_settings', array() );
				$repo       = isset( $settings['github_repo'] ) ? $settings['github_repo'] : '';
				if ( ! empty( $repo ) ) {
					$commit_url = sprintf( 'https://github.com/%s/commit/%s', $repo, $result['commit_sha'] );
					update_post_meta( $post_id, '_jamstack_last_commit_url', $commit_url );
					Logger::info( 'Commit URL saved', array( 'post_id' => $post_id, 'url' => $commit_url ) );
				}
			}

			Logger::success( 'Sync completed', array( 'post_id' => $post_id, 'result' => $result ) );

			// Store success result for finally block
			$sync_result = array(
				'post_id'   => $post_id,
				'success'   => true,
				'commit'    => $result,
				'file_path' => $file_path,
			);

			return $sync_result;

		} catch ( \Exception $e ) {
			// Catch any unexpected errors during sync
			Logger::error(
				'Sync failed with exception',
				array(
					'post_id'   => $post_id,
					'error'     => $e->getMessage(),
					'file'      => $e->getFile(),
					'line'      => $e->getLine(),
					'trace'     => $e->getTraceAsString(),
				)
			);
			
			$sync_error = new \WP_Error( 'sync_exception', $e->getMessage() );
			return $sync_error;
			
		} catch ( \Throwable $e ) {
			// Catch fatal errors (PHP 7+)
			Logger::error(
				'Sync failed with fatal error',
				array(
					'post_id'   => $post_id,
					'error'     => $e->getMessage(),
					'file'      => $e->getFile(),
					'line'      => $e->getLine(),
				)
			);
			
			$sync_error = new \WP_Error( 'sync_fatal_error', $e->getMessage() );
			return $sync_error;
			
		} finally {
			// CRITICAL: Always cleanup temp files, even if script crashes
			if ( $media_processor ) {
				$media_processor->cleanup_temp_files( $post_id );
				Logger::info( 'Temp files cleaned up', array( 'post_id' => $post_id ) );
			}
			
			// CRITICAL: Always update sync status, even if script crashes
			if ( null !== $sync_error || is_wp_error( $sync_error ) ) {
				self::update_sync_meta( $post_id, 'failed' );
				Logger::warning( 'Sync status set to failed in finally block', array( 'post_id' => $post_id ) );
			} elseif ( null !== $sync_result ) {
				self::update_sync_meta( $post_id, 'success' );
				Logger::info( 'Sync status set to success in finally block', array( 'post_id' => $post_id ) );
			}
			
			// Clear start time
			delete_post_meta( $post_id, '_jamstack_sync_start_time' );
		}
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
	 * Check for safety timeout on stuck syncs
	 *
	 * If a sync has been running for more than 5 minutes, consider it failed.
	 * This prevents posts from being permanently stuck in "processing" status.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return void
	 */
	private static function check_safety_timeout( int $post_id ): void {
		$start_time = get_post_meta( $post_id, '_jamstack_sync_start_time', true );
		
		if ( ! $start_time ) {
			return; // No sync in progress
		}
		
		$elapsed = time() - (int) $start_time;
		
		// Safety timeout: 5 minutes (300 seconds)
		if ( $elapsed > 300 ) {
			Logger::error(
				'Sync timeout detected - forcing status to failed',
				array(
					'post_id' => $post_id,
					'elapsed' => $elapsed,
				)
			);
			
			self::update_sync_meta( $post_id, 'failed' );
			delete_post_meta( $post_id, '_jamstack_sync_start_time' );
		}
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
					__( 'Post not found and no cached file path available', 'atomic-jamstack-connector' )
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
				require_once ATOMIC_JAMSTACK_PATH . 'adapters/interface-adapter.php';
				require_once ATOMIC_JAMSTACK_PATH . 'adapters/class-hugo-adapter.php';

				$adapter   = new \AtomicJamstack\Adapters\Hugo_Adapter();
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
		Logger::info(
			'Starting Markdown file deletion',
			array(
				'post_id'   => $post_id,
				'file_path' => $file_path,
				'method'    => $post ? 'Hugo_Adapter' : 'cached',
			)
		);

		$result = $git_api->delete_file(
			$file_path,
			sprintf( 'Delete: %s', $post ? $post->post_title : "Post #{$post_id}" )
		);

		if ( is_wp_error( $result ) ) {
			Logger::error(
				'Failed to delete Markdown file',
				array(
					'post_id' => $post_id,
					'path'    => $file_path,
					'error'   => $result->get_error_message(),
					'code'    => $result->get_error_code(),
				)
			);
			return $result;
		}

		$deleted[] = $file_path;
		Logger::success(
			'Markdown file deleted successfully',
			array(
				'post_id' => $post_id,
				'path'    => $file_path,
			)
		);

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

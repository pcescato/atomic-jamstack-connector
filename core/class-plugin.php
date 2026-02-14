<?php
/**
 * Main Plugin Bootstrap Class
 *
 * @package AtomicJamstack
 */

declare(strict_types=1);

namespace AjcBridge\Core;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not permitted.' );
}

/**
 * Singleton plugin bootstrap class
 *
 * Orchestrates plugin initialization following WordPress lifecycle.
 * Controls when and how components are loaded.
 */
class Plugin {

	/**
	 * Single instance of the class
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Private constructor to prevent direct instantiation
	 */
	private function __construct() {
		// Initialization happens on plugins_loaded hook
		add_action( 'plugins_loaded', array( $this, 'init' ), 10 );
	}

	/**
	 * Get singleton instance
	 *
	 * @return Plugin
	 */
	public static function get_instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize plugin components
	 *
	 * Called on plugins_loaded hook to ensure WordPress is fully loaded.
	 * Initializes core systems and loads context-specific components.
	 *
	 * @return void
	 */
	public function init(): void {
		// Load core dependencies
		$this->load_core_classes();

		// Initialize core systems
		$this->init_core_systems();

		// Load context-specific components
		if ( is_admin() ) {
			$this->load_admin();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$this->load_cli();
		}

		// Register WordPress hooks
		$this->register_hooks();
	}

	/**
	 * Load core class files
	 *
	 * @return void
	 */
	private function load_core_classes(): void {
		require_once AJC_BRIDGE_PATH . 'core/class-logger.php';
		require_once AJC_BRIDGE_PATH . 'core/class-queue-manager.php';
		require_once AJC_BRIDGE_PATH . 'core/class-sync-runner.php';
		require_once AJC_BRIDGE_PATH . 'core/class-git-api.php';
		require_once AJC_BRIDGE_PATH . 'core/class-media-processor.php';
		require_once AJC_BRIDGE_PATH . 'core/class-headless-redirect.php';
	}

	/**
	 * Initialize core systems
	 *
	 * Calls init() methods on core components that need to register
	 * WordPress hooks or perform early setup.
	 *
	 * @return void
	 */
	private function init_core_systems(): void {
		// Initialize queue manager (registers async processing hooks)
		Queue_Manager::init();

		// Initialize headless redirect handler
		Headless_Redirect::init();

		// TODO: Initialize logger when persistence is implemented
		// Logger::init();
	}

	/**
	 * Load admin-related classes
	 *
	 * Only loaded in admin context for performance.
	 *
	 * @return void
	 */
	private function load_admin(): void {
		require_once AJC_BRIDGE_PATH . 'admin/class-settings.php';
		require_once AJC_BRIDGE_PATH . 'admin/class-columns.php';
		require_once AJC_BRIDGE_PATH . 'admin/class-post-meta-box.php';
		require_once AJC_BRIDGE_PATH . 'admin/class-admin.php';
		
		\AjcBridge\Admin\Admin::init();
		\AjcBridge\Admin\Post_Meta_Box::init();
	}

	/**
	 * Load WP-CLI command classes
	 *
	 * Only loaded when WP-CLI is available.
	 *
	 * @return void
	 */
	private function load_cli(): void {
		// TODO: Load CLI classes when implemented
		// require_once AJC_BRIDGE_PATH . 'cli/class-cli.php';
		// WP_CLI::add_command( 'jamstack', 'AjcBridge\CLI\CLI' );
	}

	/**
	 * Register WordPress hooks
	 *
	 * Registers action/filter hooks for core plugin functionality.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		// Use wp_after_insert_post (WP 5.6+) for reliable post save detection
		// This fires after post and meta are fully saved
		add_action( 'wp_after_insert_post', array( $this, 'handle_post_save' ), 10, 4 );

		// Register deletion hooks
		add_action( 'wp_trash_post', array( $this, 'handle_post_trash' ), 10, 1 );
		add_action( 'before_delete_post', array( $this, 'handle_post_delete' ), 10, 2 );
	}

	/**
	 * Handle post save events
	 *
	 * Triggered when a post is saved or updated.
	 * Enqueues eligible posts for sync.
	 *
	 * @param int      $post_id     Post ID.
	 * @param \WP_Post $post        Post object.
	 * @param bool     $update      Whether this is an update.
	 * @param \WP_Post $post_before Post object before update.
	 *
	 * @return void
	 */
	public function handle_post_save( int $post_id, \WP_Post $post, bool $update, $post_before ): void {
		// Skip autosaves
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Skip revisions
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Skip auto-drafts
		if ( 'auto-draft' === $post->post_status ) {
			return;
		}

		// Only sync published posts
		if ( 'publish' !== $post->post_status ) {
			Logger::info(
				'Skipping sync for non-published post',
				array(
					'post_id' => $post_id,
					'status'  => $post->post_status,
				)
			);
			return;
		}

		// Check if this post type should be synced
		if ( ! self::should_sync_post_type( $post->post_type ) ) {
			Logger::info(
				'Skipping sync for disabled post type',
				array(
					'post_id'   => $post_id,
					'post_type' => $post->post_type,
				)
			);
			return;
		}

		Logger::info(
			'Post saved, enqueuing for sync',
			array(
				'post_id' => $post_id,
				'title'   => $post->post_title,
				'update'  => $update,
			)
		);

		// Enqueue for async processing
		Queue_Manager::enqueue( $post_id );
	}

	/**
	 * Handle post trash events
	 *
	 * Triggered when a post is moved to trash.
	 * Enqueues post for deletion from GitHub.
	 *
	 * @param int $post_id Post ID being trashed.
	 *
	 * @return void
	 */
	public function handle_post_trash( int $post_id ): void {
		$post = get_post( $post_id );

		// Check if this post type should be synced
		if ( ! $post || ! self::should_sync_post_type( $post->post_type ) ) {
			return;
		}

		// Check if post was ever synced
		$sync_status = get_post_meta( $post_id, '_ajc_sync_status', true );

		if ( empty( $sync_status ) || 'success' !== $sync_status ) {
			Logger::info(
				'Post trashed but was never synced, skipping deletion',
				array( 'post_id' => $post_id )
			);
			return;
		}

		Logger::info(
			'Post trashed, enqueuing for deletion',
			array(
				'post_id' => $post_id,
				'title'   => $post->post_title,
			)
		);

		// Enqueue for deletion
		Queue_Manager::enqueue_deletion( $post_id );
	}

	/**
	 * Handle permanent post deletion
	 *
	 * Triggered when a post is permanently deleted (bypassing trash).
	 * Enqueues post for deletion from GitHub.
	 *
	 * @param int      $post_id Post ID being deleted.
	 * @param \WP_Post $post    Post object.
	 *
	 * @return void
	 */
	public function handle_post_delete( int $post_id, \WP_Post $post ): void {
		// Check if this post type should be synced
		if ( ! self::should_sync_post_type( $post->post_type ) ) {
			return;
		}

		// Check if post was ever synced
		$sync_status = get_post_meta( $post_id, '_ajc_sync_status', true );

		if ( empty( $sync_status ) || 'success' !== $sync_status ) {
			Logger::info(
				'Post deleted but was never synced, skipping deletion',
				array( 'post_id' => $post_id )
			);
			return;
		}

		Logger::info(
			'Post permanently deleted, enqueuing for deletion',
			array(
				'post_id' => $post_id,
				'title'   => $post->post_title,
			)
		);

		// Enqueue for deletion
		Queue_Manager::enqueue_deletion( $post_id );
	}

	/**
	 * Check if a post type should be synced
	 *
	 * Checks plugin settings to determine if the given post type is enabled.
	 *
	 * @param string $post_type Post type to check.
	 *
	 * @return bool True if should sync, false otherwise.
	 */
	private static function should_sync_post_type( string $post_type ): bool {
		$settings = get_option( 'ajc_bridge_settings', array() );
		$enabled_types = $settings['enabled_post_types'] ?? array( 'post' );

		// Ensure it's an array
		if ( ! is_array( $enabled_types ) ) {
			$enabled_types = array( 'post' );
		}

		return in_array( $post_type, $enabled_types, true );
	}

	/**
	 * Prevent cloning of singleton
	 *
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization of singleton
	 *
	 * @return void
	 */
	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize singleton' );
	}
}

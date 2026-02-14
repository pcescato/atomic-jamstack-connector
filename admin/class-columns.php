<?php
/**
 * Posts List Columns Class
 *
 * @package AtomicJamstack
 */

declare(strict_types=1);

namespace AjcBridge\Admin;

use AjcBridge\Core\Queue_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not permitted.' );
}

/**
 * Manage custom columns in Posts list
 *
 * Displays sync status with visual indicators in the WordPress admin.
 */
class Columns {

	/**
	 * Initialize columns
	 *
	 * @return void
	 */
	public static function init(): void {
		// Add column for posts
		add_filter( 'manage_posts_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );

		// Add column for pages
		add_filter( 'manage_pages_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_pages_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );

		// Enqueue admin assets
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		
		// Add row actions
		add_filter( 'post_row_actions', array( __CLASS__, 'add_row_actions' ), 10, 2 );
		
		// AJAX handlers
		add_action( 'wp_ajax_jamstack_sync_now', array( __CLASS__, 'ajax_sync_now' ) );
	}

	/**
	 * Add Jamstack Sync column to posts list
	 *
	 * @param array $columns Existing columns.
	 *
	 * @return array Modified columns.
	 */
	public static function add_column( array $columns ): array {
		// Insert after title column
		$new_columns = array();
		foreach ( $columns as $key => $value ) {
			$new_columns[ $key ] = $value;
			if ( 'title' === $key ) {
				$new_columns['jamstack_sync'] = __( 'Jamstack Sync', 'ajc-bridge' );
			}
		}

		return $new_columns;
	}

	/**
	 * Render column content
	 *
	 * @param string $column_name Column identifier.
	 * @param int    $post_id     Post ID.
	 *
	 * @return void
	 */
	public static function render_column( string $column_name, int $post_id ): void {
		if ( 'jamstack_sync' !== $column_name ) {
			return;
		}

		$status = Queue_Manager::get_status( $post_id );
		$icon   = self::get_status_icon( $status );
		$label  = self::get_status_label( $status );
		$class  = 'jamstack-status jamstack-status-' . esc_attr( $status );

		printf(
			'<span class="%s" title="%s">%s %s</span>',
			esc_attr( $class ),
			esc_attr( $label ),
			wp_kses_post( $icon ), // Escape HTML output
			esc_html( $label )
		);

		// Show timestamp if available
		$timestamp = get_post_meta( $post_id, Queue_Manager::META_TIMESTAMP, true );
		if ( $timestamp ) {
			$time_diff = human_time_diff( (int) $timestamp, current_time( 'timestamp' ) );
			printf(
				'<br><small class="jamstack-timestamp">%s %s</small>',
				esc_html( $label === __( 'Success', 'ajc-bridge' ) ? __( 'Synced', 'ajc-bridge' ) : __( 'Updated', 'ajc-bridge' ) ),
				/* translators: %s: human-readable time difference */
				esc_html( sprintf( __( '%s ago', 'ajc-bridge' ), $time_diff ) )
			);
		}
	}

	/**
	 * Get status icon (dashicon or emoji)
	 *
	 * @param string $status Sync status.
	 *
	 * @return string Icon HTML.
	 */
	private static function get_status_icon( string $status ): string {
		$icons = array(
			Queue_Manager::STATUS_PENDING    => '<span class="dashicons dashicons-clock"></span>',
			Queue_Manager::STATUS_PROCESSING => '<span class="dashicons dashicons-update spin"></span>',
			Queue_Manager::STATUS_SUCCESS    => '<span class="dashicons dashicons-yes-alt"></span>',
			Queue_Manager::STATUS_ERROR      => '<span class="dashicons dashicons-warning"></span>',
			Queue_Manager::STATUS_CANCELLED  => '<span class="dashicons dashicons-dismiss"></span>',
			'deleting'                       => '<span class="dashicons dashicons-trash"></span>',
			'deleted'                        => '<span class="dashicons dashicons-trash"></span>',
			'delete_error'                   => '<span class="dashicons dashicons-warning"></span>',
			'unknown'                        => '<span class="dashicons dashicons-minus"></span>',
		);

		return $icons[ $status ] ?? $icons['unknown'];
	}

	/**
	 * Get status label
	 *
	 * @param string $status Sync status.
	 *
	 * @return string Human-readable label.
	 */
	private static function get_status_label( string $status ): string {
		$labels = array(
			Queue_Manager::STATUS_PENDING    => __( 'Pending', 'ajc-bridge' ),
			Queue_Manager::STATUS_PROCESSING => __( 'Processing', 'ajc-bridge' ),
			Queue_Manager::STATUS_SUCCESS    => __( 'Success', 'ajc-bridge' ),
			Queue_Manager::STATUS_ERROR      => __( 'Error', 'ajc-bridge' ),
			Queue_Manager::STATUS_CANCELLED  => __( 'Cancelled', 'ajc-bridge' ),
			'deleting'                       => __( 'Deleting', 'ajc-bridge' ),
			'deleted'                        => __( 'Deleted', 'ajc-bridge' ),
			'delete_error'                   => __( 'Delete Error', 'ajc-bridge' ),
			'unknown'                        => __( 'Not Synced', 'ajc-bridge' ),
		);

		return $labels[ $status ] ?? $labels['unknown'];
	}

	/**
	 * Enqueue admin assets for posts list page
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue_admin_assets( string $hook ): void {
		// Only load on edit.php (posts list) and edit-tags.php (pages list)
		if ( 'edit.php' !== $hook && 'edit-tags.php' !== $hook ) {
			return;
		}

		// Enqueue styles
		wp_enqueue_style(
			'ajc-bridge-columns',
			AJC_BRIDGE_URL . 'admin/assets/css/columns.css',
			array(),
			AJC_BRIDGE_VERSION
		);

		// Enqueue scripts
		wp_enqueue_script(
			'ajc-bridge-columns',
			AJC_BRIDGE_URL . 'admin/assets/js/columns.js',
			array( 'jquery' ),
			AJC_BRIDGE_VERSION,
			true
		);

		// Localize script with translatable strings
		wp_localize_script(
			'ajc-bridge-columns',
			'ajcBridgeColumns',
			array(
				'textSyncing'    => __( 'Syncing...', 'ajc-bridge' ),
				'textEnqueued'   => __( 'Enqueued!', 'ajc-bridge' ),
				'textSyncNow'    => __( 'Sync Now', 'ajc-bridge' ),
				'textFailed'     => __( 'Failed to sync', 'ajc-bridge' ),
				'textAjaxError'  => __( 'AJAX error', 'ajc-bridge' ),
			)
		);
	}

	/**
	 * Add row actions to post list
	 *
	 * Adds "Sync Now" link to post row actions.
	 *
	 * @param array    $actions Existing actions.
	 * @param \WP_Post $post    Post object.
	 *
	 * @return array Modified actions.
	 */
	public static function add_row_actions( array $actions, \WP_Post $post ): array {
		// Only show for published posts
		if ( 'publish' !== $post->post_status ) {
			return $actions;
		}

		// Only show for 'post' post type
		if ( 'post' !== $post->post_type ) {
			return $actions;
		}

		$status = Queue_Manager::get_status( $post->ID );

		// Don't show if already processing
		if ( Queue_Manager::STATUS_PROCESSING === $status ) {
			return $actions;
		}

		$nonce = wp_create_nonce( 'jamstack_sync_now_' . $post->ID );
		$url   = admin_url( 'admin-ajax.php' );

		$actions['jamstack_sync'] = sprintf(
			'<a href="#" class="jamstack-sync-now" data-post-id="%d" data-nonce="%s" data-ajax-url="%s">%s</a>',
			esc_attr( $post->ID ),
			esc_attr( $nonce ),
			esc_url( $url ),
			esc_html__( 'Sync Now', 'ajc-bridge' )
		);

		return $actions;
	}

	/**
	 * AJAX handler for Sync Now action
	 *
	 * @return void
	 */
	public static function ajax_sync_now(): void {
		// Get post ID
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;

		// Verify nonce
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'jamstack_sync_now_' . $post_id ) ) {
			wp_send_json_error( __( 'Security check failed', 'ajc-bridge' ) );
			return;
		}

		// Check user permissions
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'ajc-bridge' ) );
			return;
		}

		// Verify post exists and is published
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			wp_send_json_error( __( 'Post not found or not published', 'ajc-bridge' ) );
			return;
		}

		// Enqueue the post
		Queue_Manager::enqueue( $post_id );

		wp_send_json_success( __( 'Post enqueued for sync', 'ajc-bridge' ) );
	}
}

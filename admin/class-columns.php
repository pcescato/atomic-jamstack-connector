<?php
/**
 * Posts List Columns Class
 *
 * @package WPJamstack
 */

declare(strict_types=1);

namespace WPJamstack\Admin;

use WPJamstack\Core\Queue_Manager;

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
		add_filter( 'manage_posts_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_action( 'admin_head', array( __CLASS__, 'add_column_styles' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'add_row_actions' ), 10, 2 );
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
				$new_columns['jamstack_sync'] = __( 'Jamstack Sync', 'wp-jamstack-sync' );
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
			$icon, // Already escaped in get_status_icon
			esc_html( $label )
		);

		// Show timestamp if available
		$timestamp = get_post_meta( $post_id, Queue_Manager::META_TIMESTAMP, true );
		if ( $timestamp ) {
			$time_diff = human_time_diff( (int) $timestamp, current_time( 'timestamp' ) );
			printf(
				'<br><small class="jamstack-timestamp">%s %s</small>',
				esc_html( $label === __( 'Success', 'wp-jamstack-sync' ) ? __( 'Synced', 'wp-jamstack-sync' ) : __( 'Updated', 'wp-jamstack-sync' ) ),
				esc_html( sprintf( __( '%s ago', 'wp-jamstack-sync' ), $time_diff ) )
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
			Queue_Manager::STATUS_PENDING    => __( 'Pending', 'wp-jamstack-sync' ),
			Queue_Manager::STATUS_PROCESSING => __( 'Processing', 'wp-jamstack-sync' ),
			Queue_Manager::STATUS_SUCCESS    => __( 'Success', 'wp-jamstack-sync' ),
			Queue_Manager::STATUS_ERROR      => __( 'Error', 'wp-jamstack-sync' ),
			Queue_Manager::STATUS_CANCELLED  => __( 'Cancelled', 'wp-jamstack-sync' ),
			'unknown'                        => __( 'Not Synced', 'wp-jamstack-sync' ),
		);

		return $labels[ $status ] ?? $labels['unknown'];
	}

	/**
	 * Add inline styles for status column
	 *
	 * @return void
	 */
	public static function add_column_styles(): void {
		?>
		<style>
			.jamstack-status {
				display: inline-flex;
				align-items: center;
				gap: 4px;
				font-weight: 500;
			}
			.jamstack-status .dashicons {
				font-size: 18px;
				width: 18px;
				height: 18px;
			}
			.jamstack-status-pending .dashicons {
				color: #f0b849;
			}
			.jamstack-status-processing .dashicons {
				color: #0073aa;
			}
			.jamstack-status-success .dashicons {
				color: #46b450;
			}
			.jamstack-status-error .dashicons {
				color: #dc3232;
			}
			.jamstack-status-cancelled .dashicons {
				color: #82878c;
			}
			.jamstack-status-unknown .dashicons {
				color: #dcdcde;
			}
			.jamstack-timestamp {
				color: #646970;
			}
			@keyframes spin {
				from { transform: rotate(0deg); }
				to { transform: rotate(360deg); }
			}
			.jamstack-status .spin {
				animation: spin 1s linear infinite;
			}
		</style>
		<?php
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
			esc_html__( 'Sync Now', 'wp-jamstack-sync' )
		);

		// Add inline script for AJAX handling (only once)
		static $script_added = false;
		if ( ! $script_added ) {
			add_action( 'admin_footer', array( __CLASS__, 'add_sync_now_script' ) );
			$script_added = true;
		}

		return $actions;
	}

	/**
	 * Add inline script for Sync Now AJAX
	 *
	 * @return void
	 */
	public static function add_sync_now_script(): void {
		?>
		<script type="text/javascript">
		jQuery(document).ready(function($) {
			$(document).on('click', '.jamstack-sync-now', function(e) {
				e.preventDefault();
				
				var $link = $(this);
				var postId = $link.data('post-id');
				var nonce = $link.data('nonce');
				var ajaxUrl = $link.data('ajax-url');
				
				// Disable link and show loading
				$link.css('opacity', '0.5').text('<?php echo esc_js( __( 'Syncing...', 'wp-jamstack-sync' ) ); ?>');
				
				$.ajax({
					url: ajaxUrl,
					type: 'POST',
					data: {
						action: 'jamstack_sync_now',
						post_id: postId,
						nonce: nonce
					},
					success: function(response) {
						if (response.success) {
							$link.text('<?php echo esc_js( __( 'Enqueued!', 'wp-jamstack-sync' ) ); ?>');
							// Reload page after 1 second to show updated status
							setTimeout(function() {
								location.reload();
							}, 1000);
						} else {
							$link.css('opacity', '1').text('<?php echo esc_js( __( 'Sync Now', 'wp-jamstack-sync' ) ); ?>');
							alert(response.data || '<?php echo esc_js( __( 'Failed to sync', 'wp-jamstack-sync' ) ); ?>');
						}
					},
					error: function() {
						$link.css('opacity', '1').text('<?php echo esc_js( __( 'Sync Now', 'wp-jamstack-sync' ) ); ?>');
						alert('<?php echo esc_js( __( 'AJAX error', 'wp-jamstack-sync' ) ); ?>');
					}
				});
			});
		});
		</script>
		<?php
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
			wp_send_json_error( __( 'Security check failed', 'wp-jamstack-sync' ) );
			return;
		}

		// Check user permissions
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'wp-jamstack-sync' ) );
			return;
		}

		// Verify post exists and is published
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			wp_send_json_error( __( 'Post not found or not published', 'wp-jamstack-sync' ) );
			return;
		}

		// Enqueue the post
		Queue_Manager::enqueue( $post_id );

		wp_send_json_success( __( 'Post enqueued for sync', 'wp-jamstack-sync' ) );
	}
}

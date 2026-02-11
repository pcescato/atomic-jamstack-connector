<?php
/**
 * Post Meta Box for Sync Control
 *
 * @package AtomicJamstack
 */

declare(strict_types=1);

namespace AtomicJamstack\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not permitted.' );
}

/**
 * Adds meta box to post editor for per-post sync control
 */
class Post_Meta_Box {

	/**
	 * Initialize meta box
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save_meta_box' ), 10, 2 );
	}

	/**
	 * Add meta box to post editor sidebar
	 *
	 * @return void
	 */
	public static function add_meta_box(): void {
		$settings = get_option( 'atomic_jamstack_settings', array() );
		$strategy = $settings['publishing_strategy'] ?? 'wordpress_only';

		// Only show meta box in modes where dev.to is optional per-post
		if ( ! in_array( $strategy, array( 'wordpress_devto', 'dual_github_devto' ), true ) ) {
			return;
		}

		add_meta_box(
			'atomic_jamstack_sync_control',
			__( 'Jamstack Publishing', 'atomic-jamstack-connector' ),
			array( __CLASS__, 'render_meta_box' ),
			'post',
			'side',
			'default'
		);
	}

	/**
	 * Render meta box content
	 *
	 * @param \WP_Post $post Current post object.
	 *
	 * @return void
	 */
	public static function render_meta_box( \WP_Post $post ): void {
		wp_nonce_field( 'atomic_jamstack_meta_box', 'atomic_jamstack_meta_box_nonce' );

		$settings = get_option( 'atomic_jamstack_settings', array() );
		$strategy = $settings['publishing_strategy'] ?? 'wordpress_only';
		
		$publish_to_devto = get_post_meta( $post->ID, '_atomic_jamstack_publish_devto', true );
		$checked          = ( '1' === $publish_to_devto || 1 === $publish_to_devto );

		?>
		<div class="atomic-jamstack-meta-box">
			<?php if ( 'wordpress_devto' === $strategy ) : ?>
				<p class="description">
					<?php esc_html_e( 'WordPress is your canonical site. Optionally syndicate to dev.to.', 'atomic-jamstack-connector' ); ?>
				</p>
			<?php elseif ( 'dual_github_devto' === $strategy ) : ?>
				<p class="description">
					<?php esc_html_e( 'Post syncs to GitHub automatically. Optionally syndicate to dev.to.', 'atomic-jamstack-connector' ); ?>
				</p>
			<?php endif; ?>

			<label style="display: block; margin: 10px 0;">
				<input 
					type="checkbox" 
					name="atomic_jamstack_publish_devto" 
					value="1" 
					<?php checked( $checked ); ?>
				/>
				<strong><?php esc_html_e( 'Publish to dev.to', 'atomic-jamstack-connector' ); ?></strong>
			</label>

			<?php
			// Show Dev.to article information if exists
			$devto_article_id = get_post_meta( $post->ID, '_atomic_jamstack_devto_id', true );
			if ( $devto_article_id ) :
				$devto_article_url = get_post_meta( $post->ID, '_atomic_jamstack_devto_url', true );
				$devto_sync_time   = get_post_meta( $post->ID, '_atomic_jamstack_devto_sync_time', true );
				?>
				<div style="margin: 10px 0; padding: 10px; background: #f0f0f1; border-radius: 4px;">
					<p style="margin: 0 0 5px 0;">
						<strong><?php esc_html_e( 'Dev.to Article:', 'atomic-jamstack-connector' ); ?></strong>
					</p>
					<p style="margin: 0 0 5px 0;">
						<?php
						printf(
							/* translators: %s: dev.to article ID */
							esc_html__( 'ID: %s', 'atomic-jamstack-connector' ),
							'<code>' . esc_html( $devto_article_id ) . '</code>'
						);
						?>
					</p>
					<?php if ( $devto_article_url ) : ?>
						<p style="margin: 0 0 5px 0;">
							<a href="<?php echo esc_url( $devto_article_url ); ?>" target="_blank" rel="noopener">
								<?php esc_html_e( 'View on dev.to', 'atomic-jamstack-connector' ); ?>
								<span class="dashicons dashicons-external" style="font-size: 14px; width: 14px; height: 14px;"></span>
							</a>
						</p>
					<?php endif; ?>
					<?php if ( $devto_sync_time ) : ?>
						<p style="margin: 0; color: #666; font-size: 12px;">
							<?php
							printf(
								/* translators: %s: human-readable time difference */
								esc_html__( 'Last synced: %s ago', 'atomic-jamstack-connector' ),
								esc_html( human_time_diff( (int) $devto_sync_time, time() ) )
							);
							?>
						</p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<hr style="margin: 15px 0;">

			<?php
			$sync_status = get_post_meta( $post->ID, '_jamstack_sync_status', true );
			$sync_last   = get_post_meta( $post->ID, '_jamstack_sync_last', true );

			if ( $sync_status ) :
				?>
				<p style="margin: 10px 0;">
					<strong><?php esc_html_e( 'Last Sync:', 'atomic-jamstack-connector' ); ?></strong><br>
					<?php
					if ( 'success' === $sync_status ) {
						echo '<span style="color: green;">✓ ' . esc_html__( 'Success', 'atomic-jamstack-connector' ) . '</span>';
					} elseif ( 'error' === $sync_status || 'failed' === $sync_status ) {
						echo '<span style="color: red;">✗ ' . esc_html__( 'Failed', 'atomic-jamstack-connector' ) . '</span>';
					} else {
						echo '<span style="color: gray;">' . esc_html( ucfirst( $sync_status ) ) . '</span>';
					}

					if ( $sync_last ) {
						echo '<br><small>' . esc_html( human_time_diff( (int) $sync_last, time() ) ) . ' ' . esc_html__( 'ago', 'atomic-jamstack-connector' ) . '</small>';
					}
					?>
				</p>
			<?php endif; ?>
		</div>

		<style>
		.atomic-jamstack-meta-box {
			padding: 10px 0;
		}
		.atomic-jamstack-meta-box label {
			font-weight: 400;
		}
		.atomic-jamstack-meta-box hr {
			border: 0;
			border-top: 1px solid #ddd;
		}
		.atomic-jamstack-meta-box .description {
			font-style: normal;
		}
		</style>
		<?php
	}

	/**
	 * Save meta box data
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 *
	 * @return void
	 */
	public static function save_meta_box( int $post_id, \WP_Post $post ): void {
		// Security checks
		if ( ! isset( $_POST['atomic_jamstack_meta_box_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['atomic_jamstack_meta_box_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'atomic_jamstack_meta_box' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Save checkbox value
		$publish_to_devto = isset( $_POST['atomic_jamstack_publish_devto'] ) ? '1' : '0';
		update_post_meta( $post_id, '_atomic_jamstack_publish_devto', $publish_to_devto );
	}
}

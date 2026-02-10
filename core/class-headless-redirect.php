<?php
/**
 * Headless Mode Redirect Handler
 *
 * @package AtomicJamstack
 */

declare(strict_types=1);

namespace AtomicJamstack\Core;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not permitted.' );
}

/**
 * Handles frontend redirects for headless WordPress modes
 *
 * Redirects public WordPress frontend to external destinations
 * (GitHub Pages, dev.to) when WordPress is configured as headless.
 */
class Headless_Redirect {

	/**
	 * Initialize redirect handler
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 1 );
	}

	/**
	 * Redirect frontend requests if in headless mode
	 *
	 * @return void
	 */
	public static function maybe_redirect(): void {
		// Don't redirect admin, logged-in users, or AJAX
		if ( is_admin() || is_user_logged_in() || wp_doing_ajax() ) {
			return;
		}

		// Don't redirect REST API requests
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		$settings = get_option( 'atomic_jamstack_settings', array() );
		$strategy = $settings['publishing_strategy'] ?? 'wordpress_only';

		// Only redirect in headless strategies
		$headless_strategies = array( 'github_only', 'devto_only', 'dual_github_devto' );

		if ( ! in_array( $strategy, $headless_strategies, true ) ) {
			return; // WordPress frontend remains public
		}

		// Determine redirect destination
		$redirect_base = self::get_redirect_base( $strategy, $settings );

		if ( empty( $redirect_base ) ) {
			// No redirect configured, show headless notice
			self::show_headless_notice( $strategy );
			return;
		}

		// Build redirect URL
		$path = self::get_redirect_path( $strategy );

		if ( empty( $path ) ) {
			// Could not determine path, redirect to homepage
			$redirect_url = $redirect_base;
		} else {
			$redirect_url = rtrim( $redirect_base, '/' ) . $path;
		}

		Logger::info(
			'Redirecting frontend request (headless mode)',
			array(
				'strategy' => $strategy,
				'from'     => isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
				'to'       => $redirect_url,
			)
		);

		// Use wp_redirect() instead of wp_safe_redirect() because we're redirecting to external domains
		// The redirect URLs come from our own settings, so they are safe
		wp_redirect( $redirect_url, 301 );
		exit;
	}

	/**
	 * Get redirect base URL based on strategy
	 *
	 * @param string $strategy Publishing strategy.
	 * @param array  $settings Plugin settings.
	 *
	 * @return string Redirect base URL or empty string.
	 */
	private static function get_redirect_base( string $strategy, array $settings ): string {
		switch ( $strategy ) {
			case 'github_only':
			case 'dual_github_devto':
				return rtrim( $settings['github_site_url'] ?? '', '/' );

			case 'devto_only':
				$site_url = $settings['devto_site_url'] ?? '';
				return rtrim( $site_url, '/' );

			default:
				return '';
		}
	}

	/**
	 * Get redirect path based on current request
	 *
	 * @param string $strategy Publishing strategy.
	 *
	 * @return string URL path.
	 */
	private static function get_redirect_path( string $strategy ): string {
		global $post;

		// Single post
		if ( is_single() && $post ) {
			if ( 'devto_only' === $strategy ) {
				// Dev.to URL structure: /username/post-slug
				return '/' . $post->post_name;
			} else {
				// GitHub Pages URL structure: /posts/post-slug
				return '/posts/' . $post->post_name;
			}
		}

		// Homepage/Front page
		if ( is_home() || is_front_page() ) {
			return '/';
		}

		// Archive pages - redirect to homepage
		if ( is_archive() ) {
			return '/';
		}

		// Default: try to preserve current path
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		return $request_uri;
	}

	/**
	 * Show headless notice page
	 *
	 * Displayed when WordPress is in headless mode but no redirect URL is configured.
	 *
	 * @param string $strategy Current publishing strategy.
	 *
	 * @return void
	 */
	private static function show_headless_notice( string $strategy ): void {
		status_header( 200 );

		$admin_url = admin_url( 'admin.php?page=atomic-jamstack-settings' );

		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="robots" content="noindex, nofollow">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php esc_html_e( 'Headless WordPress', 'atomic-jamstack-connector' ); ?></title>
			<style>
				body {
					font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, sans-serif;
					max-width: 600px;
					margin: 100px auto;
					padding: 20px;
					text-align: center;
					color: #333;
					line-height: 1.6;
				}
				h1 {
					color: #0073aa;
					margin-bottom: 20px;
				}
				p {
					margin: 15px 0;
					color: #666;
				}
				code {
					background: #f5f5f5;
					padding: 3px 8px;
					border-radius: 3px;
					font-family: 'Courier New', monospace;
					font-size: 0.9em;
				}
				.notice {
					background: #fff8e5;
					border-left: 4px solid #ffba00;
					padding: 15px;
					margin: 30px 0;
				}
				.button {
					display: inline-block;
					background: #0073aa;
					color: white;
					padding: 10px 20px;
					text-decoration: none;
					border-radius: 3px;
					margin-top: 20px;
				}
				.button:hover {
					background: #005a87;
				}
			</style>
		</head>
		<body>
			<h1><?php esc_html_e( 'Headless WordPress Installation', 'atomic-jamstack-connector' ); ?></h1>
			
			<p><?php esc_html_e( 'This WordPress site is configured for headless operation.', 'atomic-jamstack-connector' ); ?></p>
			
			<p><?php esc_html_e( 'Content is published to external platforms via the Atomic Jamstack Connector plugin.', 'atomic-jamstack-connector' ); ?></p>

			<div class="notice">
				<p><strong><?php esc_html_e( 'Configuration Needed', 'atomic-jamstack-connector' ); ?></strong></p>
				<p>
					<?php
					if ( 'devto_only' === $strategy ) {
						printf(
							/* translators: %s: code tag */
							esc_html__( 'Please configure your %s in plugin settings.', 'atomic-jamstack-connector' ),
							'<code>' . esc_html__( 'Dev.to Site URL', 'atomic-jamstack-connector' ) . '</code>'
						);
					} else {
						printf(
							/* translators: %s: code tag */
							esc_html__( 'Please configure your %s in plugin settings.', 'atomic-jamstack-connector' ),
							'<code>' . esc_html__( 'GitHub Site URL', 'atomic-jamstack-connector' ) . '</code>'
						);
					}
					?>
				</p>
			</div>

			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<a href="<?php echo esc_url( $admin_url ); ?>" class="button">
					<?php esc_html_e( 'Go to Plugin Settings', 'atomic-jamstack-connector' ); ?>
				</a>
			<?php endif; ?>
		</body>
		</html>
		<?php
		exit;
	}
}

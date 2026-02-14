<?php
/**
 * Settings Page Class
 *
 * @package AtomicJamstack
 */

declare(strict_types=1);

namespace AjcBridge\Admin;

use AjcBridge\Core\Git_API;
use AjcBridge\Core\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not permitted.' );
}

/**
 * Settings page management
 *
 * Handles plugin settings registration, rendering, and validation.
 */
class Settings {

	/**
	 * Option name for settings
	 */
	public const OPTION_NAME = 'ajc_bridge_settings';

	/**
	 * Settings page slug
	 */
	public const PAGE_SLUG = 'atomic-jamstack-settings';

	/**
	 * History page slug
	 */
	public const HISTORY_PAGE_SLUG = 'atomic-jamstack-history';

	/**
	 * Initialize settings
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_settings_redirect' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_ajc_bridge_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_ajc_bridge_test_devto', array( __CLASS__, 'ajax_test_devto_connection' ) );
		add_action( 'wp_ajax_ajc_bridge_bulk_sync', array( __CLASS__, 'ajax_bulk_sync' ) );
		add_action( 'wp_ajax_ajc_bridge_get_stats', array( __CLASS__, 'ajax_get_stats' ) );
		add_action( 'wp_ajax_ajc_bridge_sync_single', array( __CLASS__, 'ajax_sync_single' ) );
	}

	/**
	 * Enqueue admin assets for settings page
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue_admin_assets( string $hook ): void {
		// Load on all our plugin pages
		$allowed_hooks = array(
			'toplevel_page_ajc-bridge',
			'ajc-bridge_page_ajc-bridge-bulk',
			'ajc-bridge_page_ajc-bridge-history',
		);
		
		if ( ! in_array( $hook, $allowed_hooks, true ) ) {
			return;
		}

		// Enqueue styles
		wp_enqueue_style(
			'ajc-bridge-settings',
			AJC_BRIDGE_URL . 'admin/assets/css/settings.css',
			array(),
			AJC_BRIDGE_VERSION
		);

		// Enqueue scripts
		wp_enqueue_script(
			'ajc-bridge-settings',
			AJC_BRIDGE_URL . 'admin/assets/js/settings.js',
			array( 'jquery' ),
			AJC_BRIDGE_VERSION,
			true
		);

		// Localize script with translatable strings and nonces
		wp_localize_script(
			'ajc-bridge-settings',
			'ajcBridgeSettings',
			array(
				'ajaxurl'            => admin_url( 'admin-ajax.php' ),
				'bulkSyncNonce'      => wp_create_nonce( 'ajc-bridge-bulk-sync' ),
				'syncSingleNonce'    => wp_create_nonce( 'ajc-bridge-sync-single' ),
				'statsNonce'         => wp_create_nonce( 'ajc-bridge-stats' ),
				'testConnectionNonce' => wp_create_nonce( 'ajc-bridge-test-connection' ),
				'textBulkConfirm'    => __( 'Are you sure you want to synchronize all published posts? This may take several minutes.', 'ajc-bridge' ),
				'textStarting'       => __( 'Starting...', 'ajc-bridge' ),
				'textSynchronize'    => __( 'Synchronize All Posts', 'ajc-bridge' ),
				'textRequestFailed'  => __( 'Request failed', 'ajc-bridge' ),
				'textSyncing'        => __( 'Syncing...', 'ajc-bridge' ),
				'textSynced'         => __( 'Synced!', 'ajc-bridge' ),
				'textSyncNow'        => __( 'Sync Now', 'ajc-bridge' ),
				'textError'          => __( 'Error', 'ajc-bridge' ),
				'textTesting'        => __( 'Testing...', 'ajc-bridge' ),
				'textConnected'      => __( '✓ Connected', 'ajc-bridge' ),
			)
		);
	}

	/**
	 * Handle redirect after settings save to preserve active tab
	 *
	 * @return void
	 */
	public static function handle_settings_redirect(): void {
		// Check if we're saving settings
		if ( ! isset( $_POST['option_page'] ) || sanitize_text_field( wp_unslash( $_POST['option_page'] ) ) !== self::PAGE_SLUG ) {
			return;
		}

		// Verify nonce for security (WordPress Settings API creates this)
		// The nonce field name is: '_wpnonce' and the action is: '{option_group}-options'
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::PAGE_SLUG . '-options' ) ) {
			wp_die(
				esc_html__( 'Security check failed. Please try again.', 'ajc-bridge' ),
				esc_html__( 'Security Error', 'ajc-bridge' ),
				array( 'response' => 403 )
			);
		}

		// Check if settings_tab was submitted
		if ( isset( $_POST['settings_tab'] ) ) {
			$settings_tab = sanitize_key( wp_unslash( $_POST['settings_tab'] ) );
			// Add filter to modify redirect URL
			add_filter( 'wp_redirect', function( $location ) use ( $settings_tab ) {
				return add_query_arg( 'settings_tab', $settings_tab, $location );
			} );
		}
	}

	/**
	 * Register settings fields
	 *
	 * @return void
	 */
	public static function register_settings(): void {
		register_setting(
			self::PAGE_SLUG,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
			)
		);

		// Determine which tab we're on for conditional section registration
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_tab = isset( $_GET['settings_tab'] ) ? sanitize_key( $_GET['settings_tab'] ) : 'general';

		// GENERAL TAB SECTIONS
		if ( 'general' === $current_tab ) {
			// Post Types Section
			add_settings_section(
				'ajc_bridge_posttypes_section',
				__( 'Content Types', 'ajc-bridge' ),
				array( __CLASS__, 'render_posttypes_section' ),
				self::PAGE_SLUG
			);

			add_settings_field(
				'enabled_post_types',
				__( 'Synchronize', 'ajc-bridge' ),
				array( __CLASS__, 'render_posttypes_field' ),
				self::PAGE_SLUG,
				'ajc_bridge_posttypes_section'
			);

			add_settings_field(
				'publishing_strategy',
				__( 'Publishing Strategy', 'ajc-bridge' ),
				array( __CLASS__, 'render_publishing_strategy_field' ),
				self::PAGE_SLUG,
				'ajc_bridge_posttypes_section'
			);

			add_settings_field(
				'github_site_url',
				__( 'GitHub Site URL', 'ajc-bridge' ),
				array( __CLASS__, 'render_github_site_url_field' ),
				self::PAGE_SLUG,
				'ajc_bridge_posttypes_section'
			);

			add_settings_field(
				'devto_site_url',
				__( 'Dev.to Site URL', 'ajc-bridge' ),
				array( __CLASS__, 'render_devto_site_url_field' ),
				self::PAGE_SLUG,
				'ajc_bridge_posttypes_section'
			);

			// Hugo Settings Section
			add_settings_section(
				'ajc_bridge_hugo_section',
				__( 'Hugo Configuration', 'ajc-bridge' ),
				array( __CLASS__, 'render_hugo_section' ),
				self::PAGE_SLUG
			);

			add_settings_field(
				'hugo_front_matter_template',
				__( 'Custom Front Matter Template', 'ajc-bridge' ),
				array( __CLASS__, 'render_front_matter_template_field' ),
				self::PAGE_SLUG,
				'ajc_bridge_hugo_section'
			);

			// Debug Settings Section
			add_settings_section(
				'ajc_bridge_debug_section',
				__( 'Debug Settings', 'ajc-bridge' ),
				array( __CLASS__, 'render_debug_section' ),
				self::PAGE_SLUG
			);

			add_settings_field(
				'debug_mode',
				__( 'Enable Debug Logging', 'ajc-bridge' ),
				array( __CLASS__, 'render_debug_field' ),
				self::PAGE_SLUG,
				'ajc_bridge_debug_section'
			);

			add_settings_field(
				'delete_data_on_uninstall',
				__( 'Delete data on uninstall', 'ajc-bridge' ),
				array( __CLASS__, 'render_uninstall_field' ),
				self::PAGE_SLUG,
				'ajc_bridge_debug_section'
			);
		}

		// GITHUB CREDENTIALS TAB SECTIONS
		if ( 'credentials' === $current_tab ) {
			// GitHub Settings Section
			add_settings_section(
				'ajc_bridge_github_section',
				__( 'GitHub Configuration', 'ajc-bridge' ),
				array( __CLASS__, 'render_github_section' ),
				self::PAGE_SLUG
			);

			add_settings_field(
				'github_repo',
				__( 'Repository', 'ajc-bridge' ),
				array( __CLASS__, 'render_repo_field' ),
				self::PAGE_SLUG,
				'ajc_bridge_github_section'
			);

			add_settings_field(
				'github_branch',
				__( 'Branch', 'ajc-bridge' ),
				array( __CLASS__, 'render_branch_field' ),
				self::PAGE_SLUG,
				'ajc_bridge_github_section'
			);

			add_settings_field(
				'github_token',
				__( 'Personal Access Token', 'ajc-bridge' ),
				array( __CLASS__, 'render_token_field' ),
				self::PAGE_SLUG,
				'ajc_bridge_github_section'
			);

			// Dev.to Settings Section
			add_settings_section(
				'ajc_bridge_devto_section',
				__( 'Dev.to Publishing', 'ajc-bridge' ),
				array( __CLASS__, 'render_devto_section' ),
				self::PAGE_SLUG
			);

			add_settings_field(
				'devto_api_key',
				__( 'Dev.to API Key', 'ajc-bridge' ),
				array( __CLASS__, 'render_devto_api_key_field' ),
				self::PAGE_SLUG,
				'ajc_bridge_devto_section'
			);
		}
	}

	/**
	 * Sanitize settings before saving
	 *
	 * IMPORTANT: Uses merge logic to prevent data loss when saving from tabbed interface.
	 * Only fields present in $input are updated, all other existing settings are preserved.
	 *
	 * @param array $input Raw input values.
	 *
	 * @return array Sanitized values merged with existing settings.
	 */
	public static function sanitize_settings( array $input ): array {
		// CRITICAL: Load existing settings first to preserve fields not in current POST
		$existing_settings = get_option( self::OPTION_NAME, array() );
		$sanitized = array();

		// Sanitize repository (owner/repo format)
		if ( isset( $input['github_repo'] ) ) {
			$sanitized['github_repo'] = sanitize_text_field( $input['github_repo'] );

			// Validate format if not empty
			if ( ! empty( $sanitized['github_repo'] ) && substr_count( $sanitized['github_repo'], '/' ) !== 1 ) {
				add_settings_error(
					self::OPTION_NAME,
					'invalid_repo',
					__( 'Repository must be in format: owner/repo', 'ajc-bridge' ),
					'error'
				);
			}
		}

		// Sanitize branch
		if ( isset( $input['github_branch'] ) ) {
			$sanitized['github_branch'] = ! empty( $input['github_branch'] ) 
				? sanitize_text_field( $input['github_branch'] ) 
				: 'main';
		}

		// Sanitize and encrypt token
		// CRITICAL: Token must ALWAYS be preserved unless explicitly being updated
		// CRITICAL: Detect already-encrypted tokens to prevent double-encryption
		if ( isset( $input['github_token'] ) ) {
			$token = sanitize_text_field( trim( $input['github_token'] ) );
			
			// Only update if not empty and not the masked placeholder
			if ( ! empty( $token ) && $token !== '••••••••••••••••' ) {
				// CRITICAL: Check if token is already encrypted
				// All GitHub tokens start with 'github_pat_' (new format) or 'ghp_' (classic format)
				// If it doesn't start with these prefixes, it's already encrypted
				$is_plain_text_token = (
					str_starts_with( $token, 'github_pat_' ) ||
					str_starts_with( $token, 'ghp_' )
				);

				if ( $is_plain_text_token ) {
					// Plain text token - encrypt it
					$sanitized['github_token'] = self::encrypt_token( $token );
				} else {
					// Token is already encrypted - preserve it without re-encrypting
					Logger::warning(
						'Detected already-encrypted GitHub token, preserving without re-encryption',
						array( 'token_length' => strlen( $token ) )
					);
					$sanitized['github_token'] = $token;
				}
			} else {
				// CRITICAL: Explicitly preserve existing token if input is empty or masked
				if ( ! empty( $existing_settings['github_token'] ) ) {
					$sanitized['github_token'] = $existing_settings['github_token'];
				}
			}
		} else {
			// Token field not in POST (saving from different tab)
			// CRITICAL: Explicitly preserve it
			if ( ! empty( $existing_settings['github_token'] ) ) {
				$sanitized['github_token'] = $existing_settings['github_token'];
			}
		}
		// If not in POST at all (different tab), merge will preserve existing token

		// Sanitize debug mode checkbox
		// Note: Unchecked checkboxes don't appear in POST, only update if present
		if ( isset( $input['debug_mode'] ) ) {
			$sanitized['debug_mode'] = ! empty( $input['debug_mode'] );
		}

		// Sanitize delete data on uninstall checkbox
		if ( isset( $input['delete_data_on_uninstall'] ) ) {
			$sanitized['delete_data_on_uninstall'] = ! empty( $input['delete_data_on_uninstall'] );
		}

		// Sanitize enabled post types
		if ( isset( $input['enabled_post_types'] ) ) {
			if ( ! empty( $input['enabled_post_types'] ) && is_array( $input['enabled_post_types'] ) ) {
				// Only allow 'post' and 'page'
				$sanitized['enabled_post_types'] = array_intersect(
					$input['enabled_post_types'],
					array( 'post', 'page' )
				);
			} else {
				// If field is present but empty, set default
				$sanitized['enabled_post_types'] = array( 'post' );
			}
		}

		// Sanitize publishing strategy (5 modes)
		if ( isset( $input['publishing_strategy'] ) ) {
			$strategy = $input['publishing_strategy'];
			$allowed  = array( 'wordpress_only', 'wordpress_devto', 'github_only', 'devto_only', 'dual_github_devto' );
			$sanitized['publishing_strategy'] = in_array( $strategy, $allowed, true ) 
				? $strategy 
				: 'wordpress_only';
		} else {
			// Migration: If publishing_strategy not set, check for old adapter_type settings
			if ( ! isset( $existing_settings['publishing_strategy'] ) ) {
				if ( isset( $existing_settings['adapter_type'] ) ) {
					$adapter_type = $existing_settings['adapter_type'];
					$devto_mode   = $existing_settings['devto_mode'] ?? 'primary';

					if ( 'hugo' === $adapter_type ) {
						$sanitized['publishing_strategy'] = 'github_only';
					} elseif ( 'devto' === $adapter_type && 'primary' === $devto_mode ) {
						$sanitized['publishing_strategy'] = 'devto_only';
					} elseif ( 'devto' === $adapter_type && 'secondary' === $devto_mode ) {
						$sanitized['publishing_strategy'] = 'dual_github_devto';
					} else {
						$sanitized['publishing_strategy'] = 'wordpress_only';
					}

					Logger::info(
						'Migrated old publishing settings to new strategy',
						array(
							'old_adapter_type' => $adapter_type,
							'old_devto_mode'   => $devto_mode,
							'new_strategy'     => $sanitized['publishing_strategy'],
						)
					);
				}
			}
		}

		// Sanitize GitHub Site URL
		if ( isset( $input['github_site_url'] ) ) {
			$url = esc_url_raw( trim( $input['github_site_url'] ) );
			if ( ! empty( $url ) && wp_parse_url( $url, PHP_URL_SCHEME ) ) {
				$sanitized['github_site_url'] = rtrim( $url, '/' );
			}
		}

		// Sanitize Dev.to Site URL
		if ( isset( $input['devto_site_url'] ) ) {
			$url = esc_url_raw( trim( $input['devto_site_url'] ) );
			if ( ! empty( $url ) && wp_parse_url( $url, PHP_URL_SCHEME ) ) {
				$sanitized['devto_site_url'] = rtrim( $url, '/' );
			}
		}

		// Sanitize Front Matter template
		// Allow necessary characters for YAML/TOML but prevent XSS
		if ( isset( $input['hugo_front_matter_template'] ) ) {
			// Strip potential script tags but preserve template structure
			$template = $input['hugo_front_matter_template'];
			// Remove any script/style tags
			$template = preg_replace( '#<script[^>]*?>.*?</script>#is', '', $template );
			$template = preg_replace( '#<style[^>]*?>.*?</style>#is', '', $template );
			// Preserve the template as-is (it's just text data, not HTML to be rendered)
			$sanitized['hugo_front_matter_template'] = sanitize_textarea_field( $template );
		}

		// Sanitize Dev.to API key
		// CRITICAL: Only update if not empty, otherwise preserve existing
		if ( isset( $input['devto_api_key'] ) ) {
			$api_key = sanitize_text_field( trim( $input['devto_api_key'] ) );
			
			// Only update if not empty
			if ( ! empty( $api_key ) ) {
				$sanitized['devto_api_key'] = $api_key;
			} else {
				// Preserve existing API key if input is empty
				if ( ! empty( $existing_settings['devto_api_key'] ) ) {
					$sanitized['devto_api_key'] = $existing_settings['devto_api_key'];
				}
			}
		} else {
			// Field not in POST (saving from different tab)
			// Explicitly preserve existing API key
			if ( ! empty( $existing_settings['devto_api_key'] ) ) {
				$sanitized['devto_api_key'] = $existing_settings['devto_api_key'];
			}
		}

		// CRITICAL: Merge sanitized values with existing settings
		// This ensures fields not present in current POST are preserved
		// Example: When saving General tab, Credentials tab fields are kept
		$merged_settings = array_merge( $existing_settings, $sanitized );

		return $merged_settings;
	}

	/**
	 * Encrypt GitHub token using AES-256-CBC
	 *
	 * Uses WordPress salts for encryption key and IV.
	 *
	 * @param string $token Plain text token.
	 *
	 * @return string Encrypted token (base64 encoded).
	 */
	private static function encrypt_token( string $token ): string {
		$method = 'AES-256-CBC';
		$key    = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv     = substr( hash( 'sha256', wp_salt( 'nonce' ), true ), 0, 16 );

		$encrypted = openssl_encrypt( $token, $method, $key, 0, $iv );

		return base64_encode( $encrypted );
	}

	/**
	 * Decrypt GitHub token
	 *
	 * @param string $encrypted_token Encrypted token (base64 encoded).
	 *
	 * @return string Plain text token.
	 */
	public static function decrypt_token( string $encrypted_token ): string {
		$method = 'AES-256-CBC';
		$key    = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv     = substr( hash( 'sha256', wp_salt( 'nonce' ), true ), 0, 16 );

		$decoded   = base64_decode( $encrypted_token );
		$decrypted = openssl_decrypt( $decoded, $method, $key, 0, $iv );

		return $decrypted ? $decrypted : '';
	}

	/**
	 * Render GitHub section description
	 *
	 * @return void
	 */
	public static function render_github_section(): void {
		echo '<p>';
		esc_html_e( 'Configure your GitHub repository connection. You will need a Personal Access Token with repository write permissions.', 'ajc-bridge' );
		echo '</p>';
	}

	/**
	 * Render repository field
	 *
	 * @return void
	 */
	public static function render_repo_field(): void {
		$settings = get_option( self::OPTION_NAME, array() );
		$value    = $settings['github_repo'] ?? '';
		?>
		<input 
			type="text" 
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[github_repo]" 
			value="<?php echo esc_attr( $value ); ?>" 
			class="regular-text" 
			placeholder="owner/repository"
			required
		/>
		<p class="description">
			<?php esc_html_e( 'Format: owner/repository (e.g., johndoe/my-hugo-site)', 'ajc-bridge' ); ?>
		</p>
		<?php
	}

	/**
	 * Render branch field
	 *
	 * @return void
	 */
	public static function render_branch_field(): void {
		$settings = get_option( self::OPTION_NAME, array() );
		$value    = $settings['github_branch'] ?? 'main';
		?>
		<input 
			type="text" 
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[github_branch]" 
			value="<?php echo esc_attr( $value ); ?>" 
			class="regular-text" 
			placeholder="main"
		/>
		<p class="description">
			<?php esc_html_e( 'Target branch for commits (default: main)', 'ajc-bridge' ); ?>
		</p>
		<?php
	}

	/**
	 * Render token field
	 *
	 * @return void
	 */
	public static function render_token_field(): void {
		$settings   = get_option( self::OPTION_NAME, array() );
		$has_token  = ! empty( $settings['github_token'] );
		$show_value = $has_token ? '••••••••••••••••' : '';
		$placeholder = $has_token ? __( 'Token already saved', 'ajc-bridge' ) : 'ghp_xxxxxxxxxxxx';
		?>
		<input 
			type="password" 
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[github_token]" 
			value="<?php echo esc_attr( $show_value ); ?>" 
			class="regular-text" 
			placeholder="<?php echo esc_attr( $placeholder ); ?>"
		/>
		<p class="description">
			<?php
			if ( $has_token ) {
				esc_html_e( 'Token is securely stored. Leave blank to keep existing token, or enter a new token to update.', 'ajc-bridge' );
			} else {
				printf(
					/* translators: %s: GitHub tokens URL */
					esc_html__( 'Create a token at %s with repo permissions.', 'ajc-bridge' ),
					'<a href="https://github.com/settings/tokens" target="_blank" rel="noopener">github.com/settings/tokens</a>'
				);
			}
			?>
		</p>
		<p>
			<button type="button" id="ajc-bridge-test-github" class="button button-secondary">
				<?php esc_html_e( 'Test Connection', 'ajc-bridge' ); ?>
			</button>
			<span id="ajc-bridge-test-github-result"></span>
		</p>
		<?php
	}

	/**
	 * Render debug section description
	 *
	 * @return void
	 */
	public static function render_debug_section(): void {
		echo '<p>';
		esc_html_e( 'Enable debug logging to troubleshoot sync issues.', 'ajc-bridge' );
		echo '</p>';
	}

	/**
	 * Render debug field
	 *
	 * @return void
	 */
	public static function render_debug_field(): void {
		$settings = get_option( self::OPTION_NAME, array() );
		$checked  = ! empty( $settings['debug_mode'] );
		?>
		<label>
			<input 
				type="checkbox" 
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[debug_mode]" 
				value="1"
				<?php checked( $checked ); ?>
			/>
			<?php esc_html_e( 'Enable detailed logging for debugging', 'ajc-bridge' ); ?>
		</label>
		<p class="description">
			<?php
			esc_html_e( 'Logs will be written to wp-content/uploads/atomic-jamstack-logs/', 'ajc-bridge' );
			
			// Show log file path if debug is enabled
			if ( $checked ) {
				$log_file = \AjcBridge\Core\Logger::get_log_file_path();
				if ( $log_file ) {
					echo '<br>';
					printf(
						/* translators: %s: log file path */
						esc_html__( 'Current log file: %s', 'ajc-bridge' ),
						'<code>' . esc_html( $log_file ) . '</code>'
					);
					
					if ( file_exists( $log_file ) ) {
						$file_size = size_format( filesize( $log_file ) );
						echo ' (' . esc_html( $file_size ) . ')';
					} else {
						echo ' <span style="color: #d63638;">(' . esc_html__( 'File not created yet', 'ajc-bridge' ) . ')</span>';
					}
				} else {
					echo '<br><span style="color: #d63638;">';
					esc_html_e( 'Warning: Upload directory is not accessible. Logs will only go to WordPress debug.log', 'ajc-bridge' );
					echo '</span>';
				}
			}
			?>
		</p>
		<?php
	}

	/**
	 * Render delete data on uninstall checkbox
	 *
	 * @return void
	 */
	public static function render_uninstall_field(): void {
		$settings = get_option( self::OPTION_NAME, array() );
		$checked  = ! empty( $settings['delete_data_on_uninstall'] );
		?>
		<label>
			<input 
				type="checkbox" 
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[delete_data_on_uninstall]" 
				value="1"
				<?php checked( $checked ); ?>
			/>
			<?php esc_html_e( 'Permanently delete all plugin data when uninstalling', 'ajc-bridge' ); ?>
		</label>
		<p class="description" style="color: #d63638;">
			<strong><?php esc_html_e( 'Warning:', 'ajc-bridge' ); ?></strong>
			<?php esc_html_e( 'If checked, all settings and synchronization logs will be permanently deleted from the database when the plugin is uninstalled. This action cannot be undone.', 'ajc-bridge' ); ?>
		</p>
		<?php
	}

	/**
	 * Render post types section description
	 *
	 * @return void
	 */
	public static function render_posttypes_section(): void {
		echo '<p>';
		esc_html_e( 'Choose which content types should be synchronized to your Hugo site.', 'ajc-bridge' );
		echo '</p>';
	}

	/**
	 * Render post types checkboxes
	 *
	 * @return void
	 */
	public static function render_posttypes_field(): void {
		$settings = get_option( self::OPTION_NAME, array() );
		$enabled = $settings['enabled_post_types'] ?? array( 'post' );

		$post_types = array(
			'post' => array(
				'label' => __( 'Posts', 'ajc-bridge' ),
				'description' => __( 'Standard blog posts (synced to content/posts/)', 'ajc-bridge' ),
			),
			'page' => array(
				'label' => __( 'Pages', 'ajc-bridge' ),
				'description' => __( 'Static pages (synced to content/)', 'ajc-bridge' ),
			),
		);

		foreach ( $post_types as $type => $info ) :
			?>
			<label style="display: block; margin-bottom: 10px;">
				<input
					type="checkbox"
					name="<?php echo esc_attr( self::OPTION_NAME ); ?>[enabled_post_types][]"
					value="<?php echo esc_attr( $type ); ?>"
					<?php checked( in_array( $type, $enabled, true ) ); ?>
				/>
				<strong><?php echo esc_html( $info['label'] ); ?></strong>
				<br />
				<span class="description" style="margin-left: 20px;">
					<?php echo esc_html( $info['description'] ); ?>
				</span>
			</label>
			<?php
		endforeach;
	}

	/**
	 * Render adapter type field (publishing destination selector)
	 *
	 * @return void
	 */
	/**
	 * Render publishing strategy field
	 *
	 * @return void
	 */
	public static function render_publishing_strategy_field(): void {
		$settings = get_option( self::OPTION_NAME, array() );
		$strategy = $settings['publishing_strategy'] ?? 'wordpress_only';

		// Auto-migrate old settings for display
		if ( 'wordpress_only' === $strategy && isset( $settings['adapter_type'] ) ) {
			$adapter_type = $settings['adapter_type'] ?? 'hugo';
			$devto_mode   = $settings['devto_mode'] ?? 'primary';

			if ( 'hugo' === $adapter_type ) {
				$strategy = 'github_only';
			} elseif ( 'devto' === $adapter_type && 'primary' === $devto_mode ) {
				$strategy = 'devto_only';
			} elseif ( 'devto' === $adapter_type && 'secondary' === $devto_mode ) {
				$strategy = 'dual_github_devto';
			}
		}

		$strategies = array(
			'wordpress_only'     => array(
				'label'       => __( 'WordPress Only', 'ajc-bridge' ),
				'description' => __( 'No external sync. Plugin settings available but sync disabled. WordPress remains your public site.', 'ajc-bridge' ),
			),
			'wordpress_devto'    => array(
				'label'       => __( 'WordPress + dev.to Syndication', 'ajc-bridge' ),
				'description' => __( 'WordPress remains your public site (canonical). Optionally syndicate posts to dev.to with canonical_url pointing to WordPress. Check "Publish to dev.to" per post.', 'ajc-bridge' ),
			),
			'github_only'        => array(
				'label'       => __( 'GitHub Only (Headless)', 'ajc-bridge' ),
				'description' => __( 'WordPress is headless (admin-only). All published posts sync to Hugo/Jekyll on GitHub Pages. WordPress frontend redirects to your static site.', 'ajc-bridge' ),
			),
			'devto_only'         => array(
				'label'       => __( 'Dev.to Only (Headless)', 'ajc-bridge' ),
				'description' => __( 'WordPress is headless. All published posts sync to dev.to. WordPress frontend redirects to dev.to.', 'ajc-bridge' ),
			),
			'dual_github_devto'  => array(
				'label'       => __( 'Dual Publishing (GitHub + dev.to)', 'ajc-bridge' ),
				'description' => __( 'WordPress is headless. Posts sync to GitHub (canonical). Optionally syndicate to dev.to with canonical_url. Check "Publish to dev.to" per post.', 'ajc-bridge' ),
			),
		);

		foreach ( $strategies as $type => $info ) :
			?>
			<label style="display: block; margin-bottom: 15px;">
				<input
					type="radio"
					name="<?php echo esc_attr( self::OPTION_NAME ); ?>[publishing_strategy]"
					value="<?php echo esc_attr( $type ); ?>"
					<?php checked( $strategy, $type ); ?>
				/>
				<strong><?php echo esc_html( $info['label'] ); ?></strong>
				<br />
				<span class="description" style="margin-left: 20px;">
					<?php echo esc_html( $info['description'] ); ?>
				</span>
			</label>
			<?php
		endforeach;
		?>
		<p class="description">
			<?php esc_html_e( 'Configure credentials in the Credentials tab. For headless modes, configure redirect URLs below.', 'ajc-bridge' ); ?>
		</p>
		<?php
	}

	/**
	 * Render GitHub site URL field
	 *
	 * @return void
	 */
	public static function render_github_site_url_field(): void {
		$settings = get_option( self::OPTION_NAME, array() );
		// Try to migrate from old devto_canonical_url if github_site_url not set
		$value = $settings['github_site_url'] ?? $settings['devto_canonical_url'] ?? '';
		?>
		<input 
			type="url" 
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[github_site_url]" 
			value="<?php echo esc_attr( $value ); ?>" 
			placeholder="https://username.github.io/repo"
			class="regular-text"
		/>
		<p class="description">
			<?php esc_html_e( 'Your deployed Hugo/Jekyll site URL (e.g., GitHub Pages). Used for canonical URLs in dual publishing mode and WordPress frontend redirects in GitHub headless mode.', 'ajc-bridge' ); ?>
		</p>
		<?php
	}

	/**
	 * Render Dev.to site URL field
	 *
	 * @return void
	 */
	public static function render_devto_site_url_field(): void {
		$settings = get_option( self::OPTION_NAME, array() );
		$value    = $settings['devto_site_url'] ?? '';
		?>
		<input 
			type="url" 
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[devto_site_url]" 
			value="<?php echo esc_attr( $value ); ?>" 
			placeholder="https://dev.to/username or https://yourblog.com"
			class="regular-text"
		/>
		<p class="description">
			<?php esc_html_e( 'Your dev.to profile URL or your WordPress site URL for canonical links. Used for WordPress frontend redirects in dev.to-only mode and as canonical URL when syndicating from WordPress.', 'ajc-bridge' ); ?>
		</p>
		<?php
	}

	/**
	 * Render Hugo section description
	 *
	 * @return void
	 */
	public static function render_hugo_section(): void {
		echo '<p>';
		esc_html_e( 'Customize how WordPress content is converted to Hugo Markdown format.', 'ajc-bridge' );
		echo '</p>';
	}

	/**
	 * Render front matter template field
	 *
	 * @return void
	 */
	public static function render_front_matter_template_field(): void {
		$settings = get_option( self::OPTION_NAME, array() );
		
		// Default YAML template
		$default = "---\ntitle: \"{{title}}\"\ndate: {{date}}\nauthor: \"{{author}}\"\ncover:\n  image: \"{{image_avif}}\"\n  alt: \"{{title}}\"\n---";
		
		$value = $settings['hugo_front_matter_template'] ?? $default;
		?>
		<textarea 
			id="<?php echo esc_attr( self::OPTION_NAME ); ?>[hugo_front_matter_template]"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[hugo_front_matter_template]" 
			rows="15" 
			class="large-text code"
		><?php echo esc_textarea( $value ); ?></textarea>
		<p class="description">
			<?php esc_html_e( 'Define your raw Front Matter here. You must include your own delimiters (e.g., --- for YAML or +++ for TOML).', 'ajc-bridge' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( 'Available placeholders:', 'ajc-bridge' ); ?>
			<code>{{title}}</code>, 
			<code>{{date}}</code>, 
			<code>{{author}}</code>, 
			<code>{{slug}}</code>, 
			<code>{{id}}</code>, 
			<code>{{image_avif}}</code>, 
			<code>{{image_webp}}</code>, 
			<code>{{image_original}}</code>
		</p>
		<?php
	}

	/**
	 * Render Dev.to section description
	 *
	 * @return void
	 */
	public static function render_devto_section(): void {
		?>
		<p>
			<?php
			esc_html_e( 'Configure Dev.to API integration for publishing posts directly to your Dev.to account.', 'ajc-bridge' );
			?>
		</p>
		<?php
	}

	/**
	 * Render Dev.to API key field
	 *
	 * @return void
	 */
	public static function render_devto_api_key_field(): void {
		$settings = get_option( self::OPTION_NAME, array() );
		$value    = $settings['devto_api_key'] ?? '';
		?>
		<input 
			type="password" 
			id="<?php echo esc_attr( self::OPTION_NAME ); ?>_devto_api_key"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[devto_api_key]" 
			value="<?php echo esc_attr( $value ); ?>" 
			class="regular-text"
			autocomplete="off"
		/>
		<p class="description">
			<?php
			printf(
				/* translators: %s: Dev.to API settings URL */
				esc_html__( 'Get your API key from %s', 'ajc-bridge' ),
				'<a href="https://dev.to/settings/extensions" target="_blank" rel="noopener noreferrer">dev.to/settings/extensions</a>'
			);
			?>
		</p>
		<p>
			<button type="button" id="ajc-bridge-test-devto" class="button">
				<?php esc_html_e( 'Test Connection', 'ajc-bridge' ); ?>
			</button>
			<span id="ajc-bridge-test-devto-result"></span>
		</p>
		<?php
	}

	/**
	 * Render settings page (with sub-tabs for General/Credentials)
	 *
	 * @return void
	 */
	public static function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have sufficient permissions to access this page.', 'ajc-bridge' )
			);
		}

		// Get active settings sub-tab
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$settings_tab = isset( $_GET['settings_tab'] ) ? sanitize_key( $_GET['settings_tab'] ) : 'general';
		?>
		<div class="wrap atomic-jamstack-settings-wrap">
			<h1><?php esc_html_e( 'Jamstack Sync Settings', 'ajc-bridge' ); ?></h1>
			
			<!-- Settings Sub-Tab Navigation -->
			<div class="atomic-jamstack-subtabs">
				<h2 class="nav-tab-wrapper">
					<a href="?page=ajc-bridge&settings_tab=general" 
					   class="nav-tab <?php echo 'general' === $settings_tab ? 'nav-tab-active' : ''; ?>">
						<?php esc_html_e( 'General', 'ajc-bridge' ); ?>
					</a>
					<a href="?page=ajc-bridge&settings_tab=credentials" 
					   class="nav-tab <?php echo 'credentials' === $settings_tab ? 'nav-tab-active' : ''; ?>">
						<?php esc_html_e( 'Credentials', 'ajc-bridge' ); ?>
					</a>
				</h2>
			</div>

			<?php settings_errors( self::OPTION_NAME ); ?>
			
			<div class="atomic-jamstack-settings-form">
				<form method="post" action="options.php">
					<?php
					settings_fields( self::PAGE_SLUG );
					do_settings_sections( self::PAGE_SLUG );
					submit_button();
					?>
					<!-- Hidden field to preserve active tab after save -->
					<input type="hidden" name="settings_tab" value="<?php echo esc_attr( $settings_tab ); ?>" />
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Render bulk operations page
	 *
	 * @return void
	 */
	public static function render_bulk_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have sufficient permissions to access this page.', 'ajc-bridge' )
			);
		}
		?>
		<div class="wrap atomic-jamstack-settings-wrap">
			<h1><?php esc_html_e( 'Bulk Operations', 'ajc-bridge' ); ?></h1>
			<?php self::render_bulk_tab(); ?>
		</div>
		<?php
	}

	/**
	 * Render standalone sync history page
	 *
	 * @return void
	 */
	public static function render_history_page(): void {
		if ( ! current_user_can( 'publish_posts' ) ) {
			wp_die(
				esc_html__( 'You do not have sufficient permissions to access this page.', 'ajc-bridge' )
			);
		}
		?>
		<div class="wrap atomic-jamstack-settings-wrap">
			<h1><?php esc_html_e( 'Sync History', 'ajc-bridge' ); ?></h1>
			<?php self::render_monitor_tab(); ?>
		</div>
		<?php
	}

	/**
	 * Render bulk operations tab
	 *
	 * @return void
	 */
	private static function render_bulk_tab(): void {
		?>
			
			<div id="atomic-jamstack-bulk-sync-section">
				<button type="button" id="atomic-jamstack-bulk-sync-button" class="button button-secondary">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Synchronize All Posts', 'ajc-bridge' ); ?>
				</button>
				
				<div id="atomic-jamstack-bulk-status" style="margin-top: 15px; display: none;">
					<p>
						<strong><?php esc_html_e( 'Bulk Sync Status:', 'ajc-bridge' ); ?></strong>
						<span id="atomic-jamstack-bulk-message"></span>
					</p>
					<div class="atomic-jamstack-progress-bar" style="background: #f0f0f1; height: 30px; border-radius: 3px; overflow: hidden; position: relative;">
						<div id="atomic-jamstack-progress-fill" style="background: #2271b1; height: 100%; width: 0%; transition: width 0.3s;"></div>
						<div id="atomic-jamstack-progress-text" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: #2c3338; font-weight: 600;"></div>
					</div>
				</div>

				<div id="atomic-jamstack-queue-stats" style="margin-top: 20px;">
					<h3><?php esc_html_e( 'Queue Statistics', 'ajc-bridge' ); ?></h3>
					<table class="widefat" style="max-width: 600px;">
						<tbody>
							<tr>
								<td><?php esc_html_e( 'Total Posts:', 'ajc-bridge' ); ?></td>
								<td><strong id="stat-total">-</strong></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Successfully Synced:', 'ajc-bridge' ); ?></td>
								<td><strong id="stat-success" style="color: #46b450;">-</strong></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Pending:', 'ajc-bridge' ); ?></td>
								<td><strong id="stat-pending" style="color: #f0ad4e;">-</strong></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Processing:', 'ajc-bridge' ); ?></td>
								<td><strong id="stat-processing" style="color: #0073aa;">-</strong></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Errors:', 'ajc-bridge' ); ?></td>
								<td><strong id="stat-error" style="color: #dc3232;">-</strong></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Not Synced:', 'ajc-bridge' ); ?></td>
								<td><strong id="stat-not-synced">-</strong></td>
							</tr>
						</tbody>
					</table>
					<button type="button" id="atomic-jamstack-refresh-stats" class="button button-small" style="margin-top: 10px;">
						<?php esc_html_e( 'Refresh Stats', 'ajc-bridge' ); ?>
					</button>
				</div>
			</div>




		<?php
	}

	/**
	 * Render sync history monitor tab
	 *
	 * @return void
	 */
	private static function render_monitor_tab(): void {
		// Check if current user is admin
		$is_admin = current_user_can( 'manage_options' );
		$current_user_id = get_current_user_id();

		?>
		<h2><?php esc_html_e( 'Sync History', 'ajc-bridge' ); ?></h2>
		<?php if ( $is_admin ) : ?>
			<p><?php esc_html_e( 'View the most recent sync operations and their status.', 'ajc-bridge' ); ?></p>
		<?php else : ?>
			<p><?php esc_html_e( 'View your recent sync operations and their status.', 'ajc-bridge' ); ?></p>
		<?php endif; ?>

		<?php
		// Build query args
		$query_args = array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'any',
			'posts_per_page' => 20,
			'orderby'        => 'meta_value',
			'order'          => 'DESC',
			'meta_key'       => '_ajc_sync_last',
			'meta_query'     => array(
				array(
					'key'     => '_ajc_sync_status',
					'compare' => 'EXISTS',
				),
			),
		);

		// Filter by author for non-admin users
		if ( ! $is_admin ) {
			$query_args['author'] = $current_user_id;
		}

		// Query posts with sync meta
		$query = new \WP_Query( $query_args );

		if ( ! $query->have_posts() ) {
			?>
			<div class="notice notice-info">
				<p><?php esc_html_e( 'No sync history found. Sync a post to see it appear here.', 'ajc-bridge' ); ?></p>
			</div>
			<?php
			return;
		}
		?>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th scope="col" class="manage-column column-primary" style="width: 40%;">
						<?php esc_html_e( 'Post Title', 'ajc-bridge' ); ?>
					</th>
					<th scope="col" class="manage-column" style="width: 80px;">
						<?php esc_html_e( 'ID', 'ajc-bridge' ); ?>
					</th>
					<?php if ( $is_admin ) : ?>
						<th scope="col" class="manage-column" style="width: 120px;">
							<?php esc_html_e( 'Author', 'ajc-bridge' ); ?>
						</th>
					<?php endif; ?>
					<th scope="col" class="manage-column" style="width: 100px;">
						<?php esc_html_e( 'Type', 'ajc-bridge' ); ?>
					</th>
					<th scope="col" class="manage-column" style="width: 120px;">
						<?php esc_html_e( 'Status', 'ajc-bridge' ); ?>
					</th>
					<th scope="col" class="manage-column" style="width: 180px;">
						<?php esc_html_e( 'Last Sync', 'ajc-bridge' ); ?>
					</th>
					<th scope="col" class="manage-column" style="width: 120px;">
						<?php esc_html_e( 'Commit', 'ajc-bridge' ); ?>
					</th>
					<th scope="col" class="manage-column" style="width: 120px;">
						<?php esc_html_e( 'Actions', 'ajc-bridge' ); ?>
					</th>
				</tr>
			</thead>
			<tbody>
				<?php
				while ( $query->have_posts() ) :
					$query->the_post();
					$post_id = get_the_ID();
					$status = get_post_meta( $post_id, '_ajc_sync_status', true );
					$last_sync = get_post_meta( $post_id, '_ajc_sync_last', true );
					$commit_url = get_post_meta( $post_id, '_ajc_last_commit_url', true );
					$post_type = get_post_type( $post_id );

					// Status icon and color
					$status_icon = '';
					$status_color = '';
					$status_label = '';

					switch ( $status ) {
						case 'success':
							$status_icon = '●';
							$status_color = '#46b450';
							$status_label = __( 'Success', 'ajc-bridge' );
							break;
						case 'error':
							$status_icon = '●';
							$status_color = '#dc3232';
							$status_label = __( 'Error', 'ajc-bridge' );
							break;
						case 'processing':
							$status_icon = '◐';
							$status_color = '#0073aa';
							$status_label = __( 'Processing', 'ajc-bridge' );
							break;
						case 'pending':
							$status_icon = '○';
							$status_color = '#f0ad4e';
							$status_label = __( 'Pending', 'ajc-bridge' );
							break;
						default:
							$status_icon = '○';
							$status_color = '#999';
							$status_label = ucfirst( $status );
							break;
					}

					// Format last sync time
					$time_ago = $last_sync ? human_time_diff( strtotime( $last_sync ), current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'ajc-bridge' ) : __( 'Never', 'ajc-bridge' );
					?>
					<tr>
						<td class="column-primary">
							<strong>
								<a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>">
									<?php echo esc_html( get_the_title() ); ?>
								</a>
							</strong>
						</td>
						<td><?php echo esc_html( $post_id ); ?></td>
						<?php if ( $is_admin ) : ?>
							<td>
								<?php
								$author_id = get_post_field( 'post_author', $post_id );
								$author = get_user_by( 'id', $author_id );
								echo $author ? esc_html( $author->display_name ) : esc_html__( 'Unknown', 'ajc-bridge' );
								?>
							</td>
						<?php endif; ?>
						<td><?php echo esc_html( ucfirst( $post_type ) ); ?></td>
						<td>
							<span style="color: <?php echo esc_attr( $status_color ); ?>; font-size: 20px;" title="<?php echo esc_attr( $status_label ); ?>">
								<?php echo esc_html( $status_icon ); ?>
							</span>
							<?php echo esc_html( $status_label ); ?>
						</td>
						<td><?php echo esc_html( $time_ago ); ?></td>
						<td>
							<?php if ( $commit_url ) : ?>
								<a href="<?php echo esc_url( $commit_url ); ?>" target="_blank" class="button button-small">
									<span class="dashicons dashicons-external" style="font-size: 13px; width: 13px; height: 13px;"></span>
									<?php esc_html_e( 'View Commit', 'ajc-bridge' ); ?>
								</a>
							<?php else : ?>
								<span style="color: #999;">—</span>
							<?php endif; ?>
						</td>
						<td>
							<button type="button" 
									class="button button-small atomic-jamstack-sync-now" 
									data-post-id="<?php echo esc_attr( $post_id ); ?>"
									<?php echo $status === 'processing' ? 'disabled' : ''; ?>>
								<span class="dashicons dashicons-update" style="font-size: 13px; width: 13px; height: 13px;"></span>
								<?php esc_html_e( 'Sync Now', 'ajc-bridge' ); ?>
							</button>
						</td>
					</tr>
				<?php endwhile; ?>
			</tbody>
		</table>

		<?php wp_reset_postdata(); ?>




		<?php
	}

	/**
	 * AJAX handler for bulk sync
	 *
	 * @return void
	 */
	public static function ajax_bulk_sync(): void {
		check_ajax_referer( 'atomic-jamstack-bulk-sync', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'ajc-bridge' ) ) );
		}

		$result = \AjcBridge\Core\Queue_Manager::bulk_enqueue();

		wp_send_json_success(
			array(
				'message'  => sprintf(
					/* translators: 1: Number of posts enqueued, 2: Total posts, 3: Number skipped */
					__( '%1$d of %2$d posts enqueued for sync (%3$d already in queue).', 'ajc-bridge' ),
					$result['enqueued'],
					$result['total'],
					$result['skipped']
				),
				'total'    => $result['total'],
				'enqueued' => $result['enqueued'],
				'skipped'  => $result['skipped'],
			)
		);
	}

	/**
	 * AJAX handler for queue statistics
	 *
	 * @return void
	 */
	public static function ajax_get_stats(): void {
		check_ajax_referer( 'atomic-jamstack-get-stats', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'ajc-bridge' ) ) );
		}

		$stats = \AjcBridge\Core\Queue_Manager::get_queue_stats();

		wp_send_json_success( $stats );
	}

	/**
	 * AJAX handler for connection test
	 *
	 * @return void
	 */
	public static function ajax_test_connection(): void {
		check_ajax_referer( 'ajc-bridge-test-connection', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'ajc-bridge' ) ) );
		}

		$git_api = new Git_API();
		$result  = $git_api->test_connection();

		if ( is_wp_error( $result ) ) {
			Logger::error( 'Connection test failed', array( 'error' => $result->get_error_message() ) );
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		Logger::success( 'Connection test successful' );
		wp_send_json_success( array( 'message' => __( 'Connection successful!', 'ajc-bridge' ) ) );
	}

	/**
	 * AJAX handler for single post sync
	 *
	 * @return void
	 */
	public static function ajax_sync_single(): void {
		check_ajax_referer( 'atomic-jamstack-sync-single', 'nonce' );

		if ( ! current_user_can( 'publish_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'ajc-bridge' ) ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post ID', 'ajc-bridge' ) ) );
		}

		// Enqueue the post for sync
		require_once AJC_BRIDGE_PATH . 'core/class-queue-manager.php';
		\AjcBridge\Core\Queue_Manager::enqueue( $post_id, 5 ); // High priority

		wp_send_json_success( array(
			'message' => __( 'Post enqueued for synchronization', 'ajc-bridge' ),
			'post_id' => $post_id,
		) );
	}

	/**
	 * AJAX handler for Dev.to connection test
	 *
	 * CRITICAL: Does NOT use update_option() to avoid triggering sanitize_settings()
	 * which would double-encrypt the GitHub token.
	 *
	 * @return void
	 */
	public static function ajax_test_devto_connection(): void {
		check_ajax_referer( 'ajc-bridge-test-connection', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'ajc-bridge' ) ) );
		}

		$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

		if ( empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => __( 'API key required', 'ajc-bridge' ) ) );
		}

		// Test connection WITHOUT saving to database
		// This avoids triggering sanitize_settings() which would double-encrypt GitHub token
		require_once AJC_BRIDGE_PATH . 'core/class-devto-api.php';
		
		// Temporarily override settings for this request only using a filter
		add_filter(
			'option_' . self::OPTION_NAME,
			function( $value ) use ( $api_key ) {
				if ( is_array( $value ) ) {
					$value['devto_api_key'] = $api_key;
				}
				return $value;
			},
			999
		);

		$devto_api = new \AjcBridge\Core\DevTo_API();
		$result    = $devto_api->test_connection();

		if ( is_wp_error( $result ) ) {
			Logger::error( 'Dev.to connection test failed', array( 'error' => $result->get_error_message() ) );
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		Logger::success( 'Dev.to connection test successful' );
		wp_send_json_success( array( 'message' => __( 'Connection successful!', 'ajc-bridge' ) ) );
	}
}

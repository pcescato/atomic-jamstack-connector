<?php
/**
 * Settings Page Class
 *
 * @package AtomicJamstack
 */

declare(strict_types=1);

namespace AtomicJamstack\Admin;

use AtomicJamstack\Core\Git_API;
use AtomicJamstack\Core\Logger;

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
	public const OPTION_NAME = 'atomic_jamstack_settings';

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
		add_action( 'wp_ajax_atomic_jamstack_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_atomic_jamstack_bulk_sync', array( __CLASS__, 'ajax_bulk_sync' ) );
		add_action( 'wp_ajax_atomic_jamstack_get_stats', array( __CLASS__, 'ajax_get_stats' ) );
		add_action( 'wp_ajax_atomic_jamstack_sync_single', array( __CLASS__, 'ajax_sync_single' ) );
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
				esc_html__( 'Security check failed. Please try again.', 'atomic-jamstack-connector' ),
				esc_html__( 'Security Error', 'atomic-jamstack-connector' ),
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
				'atomic_jamstack_posttypes_section',
				__( 'Content Types', 'atomic-jamstack-connector' ),
				array( __CLASS__, 'render_posttypes_section' ),
				self::PAGE_SLUG
			);

			add_settings_field(
				'enabled_post_types',
				__( 'Synchronize', 'atomic-jamstack-connector' ),
				array( __CLASS__, 'render_posttypes_field' ),
				self::PAGE_SLUG,
				'atomic_jamstack_posttypes_section'
			);

			// Hugo Settings Section
			add_settings_section(
				'atomic_jamstack_hugo_section',
				__( 'Hugo Configuration', 'atomic-jamstack-connector' ),
				array( __CLASS__, 'render_hugo_section' ),
				self::PAGE_SLUG
			);

			add_settings_field(
				'hugo_front_matter_template',
				__( 'Custom Front Matter Template', 'atomic-jamstack-connector' ),
				array( __CLASS__, 'render_front_matter_template_field' ),
				self::PAGE_SLUG,
				'atomic_jamstack_hugo_section'
			);

			// Debug Settings Section
			add_settings_section(
				'atomic_jamstack_debug_section',
				__( 'Debug Settings', 'atomic-jamstack-connector' ),
				array( __CLASS__, 'render_debug_section' ),
				self::PAGE_SLUG
			);

			add_settings_field(
				'debug_mode',
				__( 'Enable Debug Logging', 'atomic-jamstack-connector' ),
				array( __CLASS__, 'render_debug_field' ),
				self::PAGE_SLUG,
				'atomic_jamstack_debug_section'
			);

			add_settings_field(
				'delete_data_on_uninstall',
				__( 'Delete data on uninstall', 'atomic-jamstack-connector' ),
				array( __CLASS__, 'render_uninstall_field' ),
				self::PAGE_SLUG,
				'atomic_jamstack_debug_section'
			);
		}

		// GITHUB CREDENTIALS TAB SECTIONS
		if ( 'credentials' === $current_tab ) {
			// GitHub Settings Section
			add_settings_section(
				'atomic_jamstack_github_section',
				__( 'GitHub Configuration', 'atomic-jamstack-connector' ),
				array( __CLASS__, 'render_github_section' ),
				self::PAGE_SLUG
			);

			add_settings_field(
				'github_repo',
				__( 'Repository', 'atomic-jamstack-connector' ),
				array( __CLASS__, 'render_repo_field' ),
				self::PAGE_SLUG,
				'atomic_jamstack_github_section'
			);

			add_settings_field(
				'github_branch',
				__( 'Branch', 'atomic-jamstack-connector' ),
				array( __CLASS__, 'render_branch_field' ),
				self::PAGE_SLUG,
				'atomic_jamstack_github_section'
			);

			add_settings_field(
				'github_token',
				__( 'Personal Access Token', 'atomic-jamstack-connector' ),
				array( __CLASS__, 'render_token_field' ),
				self::PAGE_SLUG,
				'atomic_jamstack_github_section'
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
					__( 'Repository must be in format: owner/repo', 'atomic-jamstack-connector' ),
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
		if ( isset( $input['github_token'] ) ) {
			$token = sanitize_text_field( trim( $input['github_token'] ) );
			
			// Only update if not empty and not the masked placeholder
			if ( ! empty( $token ) && $token !== '••••••••••••••••' ) {
				$sanitized['github_token'] = self::encrypt_token( $token );
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
		esc_html_e( 'Configure your GitHub repository connection. You will need a Personal Access Token with repository write permissions.', 'atomic-jamstack-connector' );
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
			<?php esc_html_e( 'Format: owner/repository (e.g., johndoe/my-hugo-site)', 'atomic-jamstack-connector' ); ?>
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
			<?php esc_html_e( 'Target branch for commits (default: main)', 'atomic-jamstack-connector' ); ?>
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
		$placeholder = $has_token ? __( 'Token already saved', 'atomic-jamstack-connector' ) : 'ghp_xxxxxxxxxxxx';
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
				esc_html_e( 'Token is securely stored. Leave blank to keep existing token, or enter a new token to update.', 'atomic-jamstack-connector' );
			} else {
				printf(
					/* translators: %s: GitHub tokens URL */
					esc_html__( 'Create a token at %s with repo permissions.', 'atomic-jamstack-connector' ),
					'<a href="https://github.com/settings/tokens" target="_blank" rel="noopener">github.com/settings/tokens</a>'
				);
			}
			?>
		</p>
		<p>
			<button type="button" id="atomic-jamstack-test-connection" class="button button-secondary">
				<?php esc_html_e( 'Test Connection', 'atomic-jamstack-connector' ); ?>
			</button>
			<span id="atomic-jamstack-test-result"></span>
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
		esc_html_e( 'Enable debug logging to troubleshoot sync issues.', 'atomic-jamstack-connector' );
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
			<?php esc_html_e( 'Enable detailed logging for debugging', 'atomic-jamstack-connector' ); ?>
		</label>
		<p class="description">
			<?php
			esc_html_e( 'Logs will be written to wp-content/uploads/atomic-jamstack-logs/', 'atomic-jamstack-connector' );
			
			// Show log file path if debug is enabled
			if ( $checked ) {
				$log_file = \AtomicJamstack\Core\Logger::get_log_file_path();
				if ( $log_file ) {
					echo '<br>';
					printf(
						/* translators: %s: log file path */
						esc_html__( 'Current log file: %s', 'atomic-jamstack-connector' ),
						'<code>' . esc_html( $log_file ) . '</code>'
					);
					
					if ( file_exists( $log_file ) ) {
						$file_size = size_format( filesize( $log_file ) );
						echo ' (' . esc_html( $file_size ) . ')';
					} else {
						echo ' <span style="color: #d63638;">(' . esc_html__( 'File not created yet', 'atomic-jamstack-connector' ) . ')</span>';
					}
				} else {
					echo '<br><span style="color: #d63638;">';
					esc_html_e( 'Warning: Upload directory is not accessible. Logs will only go to WordPress debug.log', 'atomic-jamstack-connector' );
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
			<?php esc_html_e( 'Permanently delete all plugin data when uninstalling', 'atomic-jamstack-connector' ); ?>
		</label>
		<p class="description" style="color: #d63638;">
			<strong><?php esc_html_e( 'Warning:', 'atomic-jamstack-connector' ); ?></strong>
			<?php esc_html_e( 'If checked, all settings and synchronization logs will be permanently deleted from the database when the plugin is uninstalled. This action cannot be undone.', 'atomic-jamstack-connector' ); ?>
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
		esc_html_e( 'Choose which content types should be synchronized to your Hugo site.', 'atomic-jamstack-connector' );
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
				'label' => __( 'Posts', 'atomic-jamstack-connector' ),
				'description' => __( 'Standard blog posts (synced to content/posts/)', 'atomic-jamstack-connector' ),
			),
			'page' => array(
				'label' => __( 'Pages', 'atomic-jamstack-connector' ),
				'description' => __( 'Static pages (synced to content/)', 'atomic-jamstack-connector' ),
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
	 * Render Hugo section description
	 *
	 * @return void
	 */
	public static function render_hugo_section(): void {
		echo '<p>';
		esc_html_e( 'Customize how WordPress content is converted to Hugo Markdown format.', 'atomic-jamstack-connector' );
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
			<?php esc_html_e( 'Define your raw Front Matter here. You must include your own delimiters (e.g., --- for YAML or +++ for TOML).', 'atomic-jamstack-connector' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( 'Available placeholders:', 'atomic-jamstack-connector' ); ?>
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
	 * Render settings page (with sub-tabs for General/Credentials)
	 *
	 * @return void
	 */
	public static function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have sufficient permissions to access this page.', 'atomic-jamstack-connector' )
			);
		}

		// Get active settings sub-tab
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$settings_tab = isset( $_GET['settings_tab'] ) ? sanitize_key( $_GET['settings_tab'] ) : 'general';
		?>
		<div class="wrap atomic-jamstack-settings-wrap">
			<h1><?php esc_html_e( 'Jamstack Sync Settings', 'atomic-jamstack-connector' ); ?></h1>
			
			<!-- Settings Sub-Tab Navigation -->
			<div class="atomic-jamstack-subtabs">
				<h2 class="nav-tab-wrapper">
					<a href="?page=jamstack-sync&settings_tab=general" 
					   class="nav-tab <?php echo 'general' === $settings_tab ? 'nav-tab-active' : ''; ?>">
						<?php esc_html_e( 'General', 'atomic-jamstack-connector' ); ?>
					</a>
					<a href="?page=jamstack-sync&settings_tab=credentials" 
					   class="nav-tab <?php echo 'credentials' === $settings_tab ? 'nav-tab-active' : ''; ?>">
						<?php esc_html_e( 'GitHub Credentials', 'atomic-jamstack-connector' ); ?>
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
				esc_html__( 'You do not have sufficient permissions to access this page.', 'atomic-jamstack-connector' )
			);
		}
		?>
		<div class="wrap atomic-jamstack-settings-wrap">
			<h1><?php esc_html_e( 'Bulk Operations', 'atomic-jamstack-connector' ); ?></h1>
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
				esc_html__( 'You do not have sufficient permissions to access this page.', 'atomic-jamstack-connector' )
			);
		}
		?>
		<div class="wrap atomic-jamstack-settings-wrap">
			<h1><?php esc_html_e( 'Sync History', 'atomic-jamstack-connector' ); ?></h1>
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
					<?php esc_html_e( 'Synchronize All Posts', 'atomic-jamstack-connector' ); ?>
				</button>
				
				<div id="atomic-jamstack-bulk-status" style="margin-top: 15px; display: none;">
					<p>
						<strong><?php esc_html_e( 'Bulk Sync Status:', 'atomic-jamstack-connector' ); ?></strong>
						<span id="atomic-jamstack-bulk-message"></span>
					</p>
					<div class="atomic-jamstack-progress-bar" style="background: #f0f0f1; height: 30px; border-radius: 3px; overflow: hidden; position: relative;">
						<div id="atomic-jamstack-progress-fill" style="background: #2271b1; height: 100%; width: 0%; transition: width 0.3s;"></div>
						<div id="atomic-jamstack-progress-text" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: #2c3338; font-weight: 600;"></div>
					</div>
				</div>

				<div id="atomic-jamstack-queue-stats" style="margin-top: 20px;">
					<h3><?php esc_html_e( 'Queue Statistics', 'atomic-jamstack-connector' ); ?></h3>
					<table class="widefat" style="max-width: 600px;">
						<tbody>
							<tr>
								<td><?php esc_html_e( 'Total Posts:', 'atomic-jamstack-connector' ); ?></td>
								<td><strong id="stat-total">-</strong></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Successfully Synced:', 'atomic-jamstack-connector' ); ?></td>
								<td><strong id="stat-success" style="color: #46b450;">-</strong></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Pending:', 'atomic-jamstack-connector' ); ?></td>
								<td><strong id="stat-pending" style="color: #f0ad4e;">-</strong></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Processing:', 'atomic-jamstack-connector' ); ?></td>
								<td><strong id="stat-processing" style="color: #0073aa;">-</strong></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Errors:', 'atomic-jamstack-connector' ); ?></td>
								<td><strong id="stat-error" style="color: #dc3232;">-</strong></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Not Synced:', 'atomic-jamstack-connector' ); ?></td>
								<td><strong id="stat-not-synced">-</strong></td>
							</tr>
						</tbody>
					</table>
					<button type="button" id="atomic-jamstack-refresh-stats" class="button button-small" style="margin-top: 10px;">
						<?php esc_html_e( 'Refresh Stats', 'atomic-jamstack-connector' ); ?>
					</button>
				</div>
			</div>

			<script>
			jQuery(document).ready(function($) {
				// Load initial stats
				loadStats();

				// Bulk sync button
				$('#atomic-jamstack-bulk-sync-button').on('click', function() {
					if (!confirm('<?php echo esc_js( __( 'Are you sure you want to synchronize all published posts? This may take several minutes.', 'atomic-jamstack-connector' ) ); ?>')) {
						return;
					}

					var $button = $(this);
					var $status = $('#atomic-jamstack-bulk-status');
					var $message = $('#atomic-jamstack-bulk-message');

					$button.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> <?php esc_html_e( 'Starting...', 'atomic-jamstack-connector' ); ?>');
					$status.show();

					$.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'atomic_jamstack_bulk_sync',
							nonce: '<?php echo esc_js( wp_create_nonce( 'atomic-jamstack-bulk-sync' ) ); ?>'
						},
						success: function(response) {
							if (response.success) {
								$message.html('✓ ' + response.data.message);
								$('#atomic-jamstack-progress-text').text(response.data.enqueued + ' / ' + response.data.total + ' posts enqueued');
								$('#atomic-jamstack-progress-fill').css('width', '100%');
								
								// Start polling
								startPolling();
							} else {
								$message.html('✗ ' + response.data.message);
							}
							$button.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Synchronize All Posts', 'atomic-jamstack-connector' ); ?>');
						},
						error: function() {
							$message.html('✗ <?php echo esc_js( __( 'Request failed', 'atomic-jamstack-connector' ) ); ?>');
							$button.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Synchronize All Posts', 'atomic-jamstack-connector' ); ?>');
						}
					});
				});

				// Refresh stats button
				$('#atomic-jamstack-refresh-stats').on('click', loadStats);

				// Load stats function
				function loadStats() {
					$.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'atomic_jamstack_get_stats',
							nonce: '<?php echo esc_js( wp_create_nonce( 'atomic-jamstack-get-stats' ) ); ?>'
						},
						success: function(response) {
							if (response.success) {
								var stats = response.data;
								$('#stat-total').text(stats.total);
								$('#stat-success').text(stats.success);
								$('#stat-pending').text(stats.pending);
								$('#stat-processing').text(stats.processing);
								$('#stat-error').text(stats.error);
								$('#stat-not-synced').text(stats.not_synced);
							}
						}
					});
				}

				// Polling function to update progress
				var pollInterval;
				function startPolling() {
					pollInterval = setInterval(function() {
						loadStats();
						
						// Check if done
						var pending = parseInt($('#stat-pending').text());
						var processing = parseInt($('#stat-processing').text());
						
						if (pending === 0 && processing === 0) {
							clearInterval(pollInterval);
							$('#atomic-jamstack-bulk-message').html('✓ <?php echo esc_js( __( 'Bulk sync completed!', 'atomic-jamstack-connector' ) ); ?>');
						}
					}, 3000); // Poll every 3 seconds
				}
			});
			</script>

			<style>
			.dashicons.spin {
				animation: spin 1s linear infinite;
			}
			@keyframes spin {
				0% { transform: rotate(0deg); }
				100% { transform: rotate(360deg); }
			}
			</style>
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
		<h2><?php esc_html_e( 'Sync History', 'atomic-jamstack-connector' ); ?></h2>
		<?php if ( $is_admin ) : ?>
			<p><?php esc_html_e( 'View the most recent sync operations and their status.', 'atomic-jamstack-connector' ); ?></p>
		<?php else : ?>
			<p><?php esc_html_e( 'View your recent sync operations and their status.', 'atomic-jamstack-connector' ); ?></p>
		<?php endif; ?>

		<?php
		// Build query args
		$query_args = array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'any',
			'posts_per_page' => 20,
			'orderby'        => 'meta_value',
			'order'          => 'DESC',
			'meta_key'       => '_jamstack_sync_last',
			'meta_query'     => array(
				array(
					'key'     => '_jamstack_sync_status',
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
				<p><?php esc_html_e( 'No sync history found. Sync a post to see it appear here.', 'atomic-jamstack-connector' ); ?></p>
			</div>
			<?php
			return;
		}
		?>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th scope="col" class="manage-column column-primary" style="width: 40%;">
						<?php esc_html_e( 'Post Title', 'atomic-jamstack-connector' ); ?>
					</th>
					<th scope="col" class="manage-column" style="width: 80px;">
						<?php esc_html_e( 'ID', 'atomic-jamstack-connector' ); ?>
					</th>
					<?php if ( $is_admin ) : ?>
						<th scope="col" class="manage-column" style="width: 120px;">
							<?php esc_html_e( 'Author', 'atomic-jamstack-connector' ); ?>
						</th>
					<?php endif; ?>
					<th scope="col" class="manage-column" style="width: 100px;">
						<?php esc_html_e( 'Type', 'atomic-jamstack-connector' ); ?>
					</th>
					<th scope="col" class="manage-column" style="width: 120px;">
						<?php esc_html_e( 'Status', 'atomic-jamstack-connector' ); ?>
					</th>
					<th scope="col" class="manage-column" style="width: 180px;">
						<?php esc_html_e( 'Last Sync', 'atomic-jamstack-connector' ); ?>
					</th>
					<th scope="col" class="manage-column" style="width: 120px;">
						<?php esc_html_e( 'Commit', 'atomic-jamstack-connector' ); ?>
					</th>
					<th scope="col" class="manage-column" style="width: 120px;">
						<?php esc_html_e( 'Actions', 'atomic-jamstack-connector' ); ?>
					</th>
				</tr>
			</thead>
			<tbody>
				<?php
				while ( $query->have_posts() ) :
					$query->the_post();
					$post_id = get_the_ID();
					$status = get_post_meta( $post_id, '_jamstack_sync_status', true );
					$last_sync = get_post_meta( $post_id, '_jamstack_sync_last', true );
					$commit_url = get_post_meta( $post_id, '_jamstack_last_commit_url', true );
					$post_type = get_post_type( $post_id );

					// Status icon and color
					$status_icon = '';
					$status_color = '';
					$status_label = '';

					switch ( $status ) {
						case 'success':
							$status_icon = '●';
							$status_color = '#46b450';
							$status_label = __( 'Success', 'atomic-jamstack-connector' );
							break;
						case 'error':
							$status_icon = '●';
							$status_color = '#dc3232';
							$status_label = __( 'Error', 'atomic-jamstack-connector' );
							break;
						case 'processing':
							$status_icon = '◐';
							$status_color = '#0073aa';
							$status_label = __( 'Processing', 'atomic-jamstack-connector' );
							break;
						case 'pending':
							$status_icon = '○';
							$status_color = '#f0ad4e';
							$status_label = __( 'Pending', 'atomic-jamstack-connector' );
							break;
						default:
							$status_icon = '○';
							$status_color = '#999';
							$status_label = ucfirst( $status );
							break;
					}

					// Format last sync time
					$time_ago = $last_sync ? human_time_diff( strtotime( $last_sync ), current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'atomic-jamstack-connector' ) : __( 'Never', 'atomic-jamstack-connector' );
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
								echo $author ? esc_html( $author->display_name ) : esc_html__( 'Unknown', 'atomic-jamstack-connector' );
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
									<?php esc_html_e( 'View Commit', 'atomic-jamstack-connector' ); ?>
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
								<?php esc_html_e( 'Sync Now', 'atomic-jamstack-connector' ); ?>
							</button>
						</td>
					</tr>
				<?php endwhile; ?>
			</tbody>
		</table>

		<?php wp_reset_postdata(); ?>

		<script>
		jQuery(document).ready(function($) {
			$('.atomic-jamstack-sync-now').on('click', function() {
				var $button = $(this);
				var postId = $button.data('post-id');
				
				$button.prop('disabled', true).html('<span class="dashicons dashicons-update dashicons-spin"></span> <?php esc_html_e( 'Syncing...', 'atomic-jamstack-connector' ); ?>');
				
				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'atomic_jamstack_sync_single',
						nonce: '<?php echo esc_js( wp_create_nonce( 'atomic-jamstack-sync-single' ) ); ?>',
						post_id: postId
					},
					success: function(response) {
						if (response.success) {
							$button.html('<span class="dashicons dashicons-yes" style="color: #46b450;"></span> <?php esc_html_e( 'Synced!', 'atomic-jamstack-connector' ); ?>');
							// Reload page after 2 seconds
							setTimeout(function() {
								location.reload();
							}, 2000);
						} else {
							$button.html('<span class="dashicons dashicons-no" style="color: #dc3232;"></span> ' + response.data.message);
							$button.prop('disabled', false);
							setTimeout(function() {
								$button.html('<span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Sync Now', 'atomic-jamstack-connector' ); ?>');
							}, 3000);
						}
					},
					error: function() {
						$button.html('<span class="dashicons dashicons-no"></span> <?php esc_html_e( 'Error', 'atomic-jamstack-connector' ); ?>');
						$button.prop('disabled', false);
						setTimeout(function() {
							$button.html('<span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Sync Now', 'atomic-jamstack-connector' ); ?>');
						}, 3000);
					}
				});
			});
		});
		</script>

		<style>
		.dashicons-spin {
			animation: atomic-jamstack-spin 1s linear infinite;
		}
		@keyframes atomic-jamstack-spin {
			0% { transform: rotate(0deg); }
			100% { transform: rotate(360deg); }
		}
		</style>
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
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'atomic-jamstack-connector' ) ) );
		}

		$result = \AtomicJamstack\Core\Queue_Manager::bulk_enqueue();

		wp_send_json_success(
			array(
				'message'  => sprintf(
					/* translators: 1: Number of posts enqueued, 2: Total posts, 3: Number skipped */
					__( '%1$d of %2$d posts enqueued for sync (%3$d already in queue).', 'atomic-jamstack-connector' ),
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
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'atomic-jamstack-connector' ) ) );
		}

		$stats = \AtomicJamstack\Core\Queue_Manager::get_queue_stats();

		wp_send_json_success( $stats );
	}

	/**
	 * AJAX handler for connection test
	 *
	 * @return void
	 */
	public static function ajax_test_connection(): void {
		check_ajax_referer( 'atomic-jamstack-test-connection', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'atomic-jamstack-connector' ) ) );
		}

		$git_api = new Git_API();
		$result  = $git_api->test_connection();

		if ( is_wp_error( $result ) ) {
			Logger::error( 'Connection test failed', array( 'error' => $result->get_error_message() ) );
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		Logger::success( 'Connection test successful' );
		wp_send_json_success( array( 'message' => __( 'Connection successful!', 'atomic-jamstack-connector' ) ) );
	}

	/**
	 * AJAX handler for single post sync
	 *
	 * @return void
	 */
	public static function ajax_sync_single(): void {
		check_ajax_referer( 'atomic-jamstack-sync-single', 'nonce' );

		if ( ! current_user_can( 'publish_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'atomic-jamstack-connector' ) ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post ID', 'atomic-jamstack-connector' ) ) );
		}

		// Enqueue the post for sync
		require_once ATOMIC_JAMSTACK_PATH . 'core/class-queue-manager.php';
		\AtomicJamstack\Core\Queue_Manager::enqueue( $post_id, 5 ); // High priority

		wp_send_json_success( array(
			'message' => __( 'Post enqueued for synchronization', 'atomic-jamstack-connector' ),
			'post_id' => $post_id,
		) );
	}
}

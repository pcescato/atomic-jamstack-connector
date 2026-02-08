<?php
/**
 * Logger Class
 *
 * @package AtomicJamstack
 */

declare(strict_types=1);

namespace AtomicJamstack\Core;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not permitted.' );
}

/**
 * Centralized logging interface
 *
 * Provides unified logging across the plugin.
 * Logs are for debugging and monitoring only.
 *
 * IMPORTANT: Logs must NEVER drive business logic or control flow.
 */
class Logger {

	/**
	 * Log levels
	 */
	public const LEVEL_INFO    = 'info';
	public const LEVEL_SUCCESS = 'success';
	public const LEVEL_WARNING = 'warning';
	public const LEVEL_ERROR   = 'error';

	/**
	 * Log a message with specified level
	 *
	 * @param string $level   Log level: 'info', 'success', 'warning', 'error'.
	 * @param string $message Log message.
	 * @param array  $context Optional context data (post_id, user_id, etc.).
	 *
	 * @return void
	 */
	public static function log( string $level, string $message, array $context = array() ): void {
		// Only log if debug mode enabled
		if ( ! self::is_debug_enabled() ) {
			return;
		}

		// Format log entry
		$timestamp = current_time( 'Y-m-d H:i:s' );
		$context_str = ! empty( $context ) ? ' ' . wp_json_encode( $context ) : '';
		$log_entry = sprintf(
			'[%s] [%s] %s%s',
			$timestamp,
			strtoupper( $level ),
			$message,
			$context_str
		);

		// Write to WordPress debug log if enabled
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'Atomic-Jamstack-Connector: ' . $log_entry );
		}

		// Write to plugin log file
		self::write_to_file( $log_entry );

		// Store in database for admin display (last 100 entries)
		self::store_in_database( $level, $message, $context );
	}

	/**
	 * Check if debug mode is enabled
	 *
	 * @return bool True if debug logging is enabled.
	 */
	private static function is_debug_enabled(): bool {
		$settings = get_option( 'atomic_jamstack_settings', array() );
		return ! empty( $settings['debug_mode'] );
	}

	/**
	 * Write log entry to file
	 *
	 * @param string $log_entry Formatted log entry.
	 *
	 * @return void
	 */
	private static function write_to_file( string $log_entry ): void {
		$upload_dir = wp_upload_dir();
		
		// Check if upload directory is accessible
		if ( ! empty( $upload_dir['error'] ) ) {
			// Can't write to log file, silently fail
			return;
		}
		
		$log_dir = $upload_dir['basedir'] . '/atomic-jamstack-logs';

		// Create log directory if it doesn't exist
		if ( ! file_exists( $log_dir ) ) {
			$created = wp_mkdir_p( $log_dir );
			
			if ( ! $created ) {
				// Failed to create directory, silently fail
				return;
			}
			
			// Add .htaccess to protect logs
			$htaccess = $log_dir . '/.htaccess';
			if ( ! file_exists( $htaccess ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $htaccess, "# Protect log files\nDeny from all\n<FilesMatch \"\\.(log)$\">\n  Deny from all\n</FilesMatch>" );
			}
			
			// Add index.php to prevent directory listing
			$index_file = $log_dir . '/index.php';
			if ( ! file_exists( $index_file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
			}
		}

		$log_file = $log_dir . '/atomic-jamstack-' . gmdate( 'Y-m-d' ) . '.log';

		// Append to log file with error handling
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$result = @file_put_contents( $log_file, $log_entry . PHP_EOL, FILE_APPEND );
		
		// If file writing fails, try to log to WordPress debug log as fallback
		if ( false === $result && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'Atomic-Jamstack-Connector: Failed to write to log file: ' . $log_file );
		}
	}

	/**
	 * Store log entry in database
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $context Context data.
	 *
	 * @return void
	 */
	private static function store_in_database( string $level, string $message, array $context ): void {
		$logs = get_option( 'atomic_jamstack_logs', array() );

		// Add new entry
		$logs[] = array(
			'timestamp' => current_time( 'mysql' ),
			'level'     => $level,
			'message'   => $message,
			'context'   => $context,
		);

		// Keep only last 100 entries
		if ( count( $logs ) > 100 ) {
			$logs = array_slice( $logs, -100 );
		}

		update_option( 'atomic_jamstack_logs', $logs, false );
	}

	/**
	 * Helper: Log info message
	 *
	 * @param string $message Log message.
	 * @param array  $context Optional context data.
	 *
	 * @return void
	 */
	public static function info( string $message, array $context = array() ): void {
		self::log( self::LEVEL_INFO, $message, $context );
	}

	/**
	 * Helper: Log success message
	 *
	 * @param string $message Log message.
	 * @param array  $context Optional context data.
	 *
	 * @return void
	 */
	public static function success( string $message, array $context = array() ): void {
		self::log( self::LEVEL_SUCCESS, $message, $context );
	}

	/**
	 * Helper: Log warning message
	 *
	 * @param string $message Log message.
	 * @param array  $context Optional context data.
	 *
	 * @return void
	 */
	public static function warning( string $message, array $context = array() ): void {
		self::log( self::LEVEL_WARNING, $message, $context );
	}

	/**
	 * Helper: Log error message
	 *
	 * @param string $message Log message.
	 * @param array  $context Optional context data.
	 *
	 * @return void
	 */
	public static function error( string $message, array $context = array() ): void {
		self::log( self::LEVEL_ERROR, $message, $context );
	}

	/**
	 * Get log file path for current date
	 *
	 * @return string|false Log file path or false if upload dir not accessible.
	 */
	public static function get_log_file_path() {
		$upload_dir = wp_upload_dir();
		
		if ( ! empty( $upload_dir['error'] ) ) {
			return false;
		}
		
		$log_dir = $upload_dir['basedir'] . '/atomic-jamstack-logs';
		$log_file = $log_dir . '/atomic-jamstack-' . gmdate( 'Y-m-d' ) . '.log';
		
		return $log_file;
	}

	/**
	 * Get log directory path
	 *
	 * @return string|false Log directory path or false if upload dir not accessible.
	 */
	public static function get_log_dir_path() {
		$upload_dir = wp_upload_dir();
		
		if ( ! empty( $upload_dir['error'] ) ) {
			return false;
		}
		
		return $upload_dir['basedir'] . '/atomic-jamstack-logs';
	}
}

<?php
/**
 * Plugin Name: AJC Bridge
 * Plugin URI: https://github.com/pcescato/ajc-bridge
 * Description: Automated WordPress to Hugo publishing system with async GitHub API integration.
 * Version: 1.2.0
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Author: Pascal CESCATO
 * License: GPL 3+
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: ajc-bridge
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not permitted.' );
}

// Plugin constants
define( 'AJC_BRIDGE_VERSION', '1.2.0' );
define( 'AJC_BRIDGE_PATH', plugin_dir_path( __FILE__ ) );
define( 'AJC_BRIDGE_URL', plugin_dir_url( __FILE__ ) );

// Load Action Scheduler library before anything else
if ( file_exists( AJC_BRIDGE_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
	require_once AJC_BRIDGE_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
}

// Load Composer autoloader if available
if ( file_exists( AJC_BRIDGE_PATH . 'vendor/autoload.php' ) ) {
	require_once AJC_BRIDGE_PATH . 'vendor/autoload.php';
}

// Development .env loader (only in development environment)
if ( function_exists( 'wp_get_environment_type' ) && 'development' === wp_get_environment_type() ) {
	ajc_bridge_load_env();
}

/**
 * Load environment variables from .env file (development only)
 *
 * @return void
 */
function ajc_bridge_load_env() {
	$env_file = AJC_BRIDGE_PATH . '.env';
	
	if ( ! file_exists( $env_file ) ) {
		return;
	}
	
	$lines = @file( $env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	
	if ( false === $lines ) {
		return;
	}
	
	foreach ( $lines as $line ) {
		// Skip comments
		if ( strpos( trim( $line ), '#' ) === 0 ) {
			continue;
		}
		
		// Parse KEY=VALUE pairs
		if ( strpos( $line, '=' ) !== false ) {
			list( $key, $value ) = explode( '=', $line, 2 );
			$key   = trim( $key );
			$value = trim( $value );
			
			// Remove quotes if present
			$value = trim( $value, '"' );
			$value = trim( $value, "'" );
			
			// Set in $_ENV and putenv
			$_ENV[ $key ] = $value;
			putenv( "$key=$value" );
		}
	}
}

/**
 * Activation hook - verify system requirements
 *
 * @return void
 */
function ajc_bridge_activate() {
	global $wp_version;
	
	$errors = array();
	
	// Check WordPress version
	if ( version_compare( $wp_version, '6.9', '<' ) ) {
		$errors[] = sprintf(
			/* translators: %s: Required WordPress version */
			__( 'AJC Bridge requires WordPress %s or higher.', 'ajc-bridge' ),
			'6.9'
		);
	}
	
	// Check PHP version
	if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
		$errors[] = sprintf(
			/* translators: %s: Required PHP version */
			__( 'AJC Bridge requires PHP %s or higher.', 'ajc-bridge' ),
			'8.1'
		);
	}
	
	// If errors exist, deactivate and show message
	if ( ! empty( $errors ) ) {
		// Deactivate the plugin
		deactivate_plugins( plugin_basename( __FILE__ ) );
		
		// Show error message
		wp_die(
			'<h1>' . esc_html__( 'Plugin Activation Failed', 'ajc-bridge' ) . '</h1>' .
			'<p>' . implode( '</p><p>', array_map( 'esc_html', $errors ) ) . '</p>' .
			'<p><a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">' .
			esc_html__( 'Return to Plugins', 'ajc-bridge' ) . '</a></p>',
			esc_html__( 'Plugin Activation Error', 'ajc-bridge' ),
			array( 'back_link' => true )
		);
	}
	
	// Activation successful - future tasks go here
	// (e.g., create tables, set default options, schedule cron)
}

/**
 * Deactivation hook - cleanup tasks
 *
 * @return void
 */
function ajc_bridge_deactivate() {
	// Future cleanup tasks go here
	// (e.g., clear scheduled tasks, flush caches)
}

// Register activation/deactivation hooks
register_activation_hook( __FILE__, 'ajc_bridge_activate' );
register_deactivation_hook( __FILE__, 'ajc_bridge_deactivate' );

// Load main plugin class
require_once AJC_BRIDGE_PATH . 'core/class-plugin.php';

// Initialize plugin
\AjcBridge\Core\Plugin::get_instance();

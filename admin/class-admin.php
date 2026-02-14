<?php
/**
 * Admin UI Class
 *
 * @package AtomicJamstack
 */

declare(strict_types=1);

namespace AjcBridge\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not permitted.' );
}

/**
 * Admin interface coordinator
 *
 * Manages admin menus, scripts, and settings registration.
 */
class Admin {

	/**
	 * Initialize admin hooks
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_pages' ) );

		// Initialize settings and columns (they handle their own asset enqueuing)
		Settings::init();
		Columns::init();
	}

	/**
	 * Add admin menu pages
	 *
	 * @return void
	 */
	public static function add_menu_pages(): void {
		// Main top-level menu - Visible to authors and above
		add_menu_page(
			__( 'AJC Bridge', 'ajc-bridge' ),
			__( 'AJC Bridge', 'ajc-bridge' ),
			'publish_posts',
			'ajc-bridge',
			array( Settings::class, 'render_settings_page' ),
			'dashicons-cloud-upload',
			26
		);

		// Submenu 1: Settings (default) - Admin only
		add_submenu_page(
			'ajc-bridge',
			__( 'Settings', 'ajc-bridge' ),
			__( 'Settings', 'ajc-bridge' ),
			'manage_options',
			'ajc-bridge',
			array( Settings::class, 'render_settings_page' )
		);

		// Submenu 2: Bulk Operations - Admin only
		add_submenu_page(
			'ajc-bridge',
			__( 'Bulk Operations', 'ajc-bridge' ),
			__( 'Bulk Operations', 'ajc-bridge' ),
			'manage_options',
			'ajc-bridge-bulk',
			array( Settings::class, 'render_bulk_page' )
		);

		// Submenu 3: Sync History - Authors and above
		add_submenu_page(
			'ajc-bridge',
			__( 'Sync History', 'ajc-bridge' ),
			__( 'Sync History', 'ajc-bridge' ),
			'publish_posts',
			'ajc-bridge-history',
			array( Settings::class, 'render_history_page' )
		);
	}
}

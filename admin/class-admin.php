<?php
/**
 * Admin UI Class
 *
 * @package AtomicJamstack
 */

declare(strict_types=1);

namespace AtomicJamstack\Admin;

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
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );

		// Initialize settings and columns
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
			__( 'Jamstack Sync', 'atomic-jamstack-connector' ),
			__( 'Jamstack Sync', 'atomic-jamstack-connector' ),
			'publish_posts',
			'jamstack-sync',
			array( Settings::class, 'render_settings_page' ),
			'dashicons-cloud-upload',
			26
		);

		// Submenu 1: Settings (default) - Admin only
		add_submenu_page(
			'jamstack-sync',
			__( 'Settings', 'atomic-jamstack-connector' ),
			__( 'Settings', 'atomic-jamstack-connector' ),
			'manage_options',
			'jamstack-sync',
			array( Settings::class, 'render_settings_page' )
		);

		// Submenu 2: Bulk Operations - Admin only
		add_submenu_page(
			'jamstack-sync',
			__( 'Bulk Operations', 'atomic-jamstack-connector' ),
			__( 'Bulk Operations', 'atomic-jamstack-connector' ),
			'manage_options',
			'jamstack-sync-bulk',
			array( Settings::class, 'render_bulk_page' )
		);

		// Submenu 3: Sync History - Authors and above
		add_submenu_page(
			'jamstack-sync',
			__( 'Sync History', 'atomic-jamstack-connector' ),
			__( 'Sync History', 'atomic-jamstack-connector' ),
			'publish_posts',
			'jamstack-sync-history',
			array( Settings::class, 'render_history_page' )
		);
	}

	/**
	 * Enqueue admin scripts and styles
	 *
	 * @param string $hook Current admin page hook.
	 *
	 * @return void
	 */
	public static function enqueue_scripts( string $hook ): void {
		// Load on all Jamstack Sync pages
		$allowed_pages = array(
			'toplevel_page_jamstack-sync',         // Main menu/Settings
			'jamstack-sync_page_jamstack-sync-bulk', // Bulk Operations submenu
			'jamstack-sync_page_jamstack-sync-history', // Sync History submenu
		);

		if ( ! in_array( $hook, $allowed_pages, true ) ) {
			return;
		}

		// Enqueue admin styles
		wp_enqueue_style(
			'atomic-jamstack-admin',
			ATOMIC_JAMSTACK_URL . 'assets/css/admin.css',
			array(),
			ATOMIC_JAMSTACK_VERSION
		);

		// Enqueue admin scripts
		wp_enqueue_script(
			'atomic-jamstack-admin',
			ATOMIC_JAMSTACK_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			ATOMIC_JAMSTACK_VERSION,
			true
		);

		// Localize script for AJAX
		wp_localize_script(
			'atomic-jamstack-admin',
			'atomicJamstackAdmin',
			array(
				'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
				'testConnectionNonce' => wp_create_nonce( 'atomic-jamstack-test-connection' ),
				'strings'            => array(
					'testing'  => __( 'Testing connection...', 'atomic-jamstack-connector' ),
					'success'  => __( 'Connection successful!', 'atomic-jamstack-connector' ),
					'error'    => __( 'Connection failed:', 'atomic-jamstack-connector' ),
				),
			)
		);
	}
}

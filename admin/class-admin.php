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
		add_options_page(
			__( 'Jamstack Sync Settings', 'atomic-jamstack-connector' ),
			__( 'Jamstack Sync', 'atomic-jamstack-connector' ),
			'manage_options',
			Settings::PAGE_SLUG,
			array( Settings::class, 'render_page' )
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
		// Only load on plugin settings page
		if ( 'settings_page_' . Settings::PAGE_SLUG !== $hook ) {
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

<?php
/**
 * Uninstall Script
 *
 * Conditionally removes all plugin data from the database when the plugin is uninstalled.
 * Only executes if the user has enabled "Delete data on uninstall" in plugin settings.
 *
 * @package AtomicJamstack
 */

// Security check: Exit if uninstall not called from WordPress
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Load plugin settings to check if data deletion is enabled
$settings = get_option( 'atomic_jamstack_settings', array() );

// Only proceed with cleanup if user explicitly enabled data deletion
if ( empty( $settings['delete_data_on_uninstall'] ) ) {
	// User wants to keep data - exit without doing anything
	return;
}

/**
 * User has opted in to clean uninstall - proceed with data deletion
 */

// 1. Delete plugin options
delete_option( 'atomic_jamstack_settings' );

// 2. Delete all post meta created by the plugin
$post_meta_keys = array(
	'_jamstack_sync_status',        // Sync status (pending, processing, success, failed)
	'_jamstack_sync_last',          // Last sync timestamp
	'_jamstack_file_path',          // GitHub file path for the post
	'_jamstack_last_commit_url',    // GitHub commit URL
	'_jamstack_sync_start_time',    // Sync start time for timeout detection
);

foreach ( $post_meta_keys as $meta_key ) {
	delete_post_meta_by_key( $meta_key );
}

// 3. Delete all transients (locks) created by the plugin
// Note: WordPress doesn't have a built-in function to delete transients by pattern
// We need to query the database directly for this
global $wpdb;

// Delete transients with the jamstack_lock_ prefix
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} 
		WHERE option_name LIKE %s 
		OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_jamstack_lock_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_jamstack_lock_' ) . '%'
	)
);

// 4. Delete Action Scheduler actions for this plugin (if any are queued)
// Action Scheduler stores actions in custom tables or options
if ( class_exists( 'ActionScheduler_DBStore' ) ) {
	// Action Scheduler 3.0+ uses custom tables
	$store = \ActionScheduler_Store::instance();
	
	// Get all pending/in-progress actions for our plugin
	$action_groups = array(
		'atomic_jamstack_sync',
		'atomic_jamstack_deletion',
	);
	
	foreach ( $action_groups as $group ) {
		$actions = $store->query_actions(
			array(
				'group'    => $group,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			)
		);
		
		// Cancel each action
		foreach ( $actions as $action_id ) {
			$store->cancel_action( $action_id );
		}
	}
}

// 5. Optional: Clear any cached data
wp_cache_delete( 'atomic_jamstack_settings', 'options' );

/**
 * Note: We do NOT delete log files from the file system
 * Reason: 
 * - Log files may be useful for debugging even after uninstall
 * - File deletion could fail due to permissions
 * - WordPress doesn't guarantee file system access in uninstall
 * 
 * Users can manually delete: wp-content/uploads/atomic-jamstack-logs/
 * if they want to remove log files.
 */

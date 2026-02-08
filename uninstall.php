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
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$jamstack_settings = get_option( 'atomic_jamstack_settings', array() );

// Only proceed with cleanup if user explicitly enabled data deletion
if ( empty( $jamstack_settings['delete_data_on_uninstall'] ) ) {
	// User wants to keep data - exit without doing anything
	return;
}

/**
 * User has opted in to clean uninstall - proceed with data deletion
 * 
 * IMPLEMENTATION NOTE:
 * This uninstall script uses native WordPress APIs wherever possible:
 * - delete_option() for plugin settings
 * - delete_post_meta_by_key() for post meta
 * - wp_cache_delete() for cache
 * 
 * Direct database queries are ONLY used where WordPress provides no API:
 * - Pattern-based transient deletion (no delete_transient_by_pattern() exists)
 * 
 * All direct queries use $wpdb->prepare() for SQL injection protection.
 */

// 1. Delete plugin options using native WordPress API
delete_option( 'atomic_jamstack_settings' );

// 2. Delete all post meta using native WordPress API
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$jamstack_post_meta_keys = array(
	'_jamstack_sync_status',        // Sync status (pending, processing, success, failed)
	'_jamstack_sync_last',          // Last sync timestamp
	'_jamstack_file_path',          // GitHub file path for the post
	'_jamstack_last_commit_url',    // GitHub commit URL
	'_jamstack_sync_start_time',    // Sync start time for timeout detection
);

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
foreach ( $jamstack_post_meta_keys as $jamstack_meta_key ) {
	delete_post_meta_by_key( $jamstack_meta_key );
}

// 3. Delete all transients (locks) created by the plugin
// Note: WordPress doesn't provide an API to delete transients by pattern
// Using direct database query is the only option for cleanup during uninstall
global $wpdb;

// Delete transients with the jamstack_lock_ prefix
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
// Reason: No WordPress API exists for pattern-based transient deletion
// This only runs on uninstall (rare operation) and is properly prepared
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} 
		WHERE option_name LIKE %s 
		OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_jamstack_lock_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_jamstack_lock_' ) . '%'
	)
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

// 4. Delete Action Scheduler actions for this plugin (if any are queued)
// Action Scheduler stores actions in custom tables or options
if ( class_exists( 'ActionScheduler_DBStore' ) ) {
	// Action Scheduler 3.0+ uses custom tables
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
	$jamstack_store = \ActionScheduler_Store::instance();
	
	// Get all pending/in-progress actions for our plugin
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
	$jamstack_action_groups = array(
		'atomic_jamstack_sync',
		'atomic_jamstack_deletion',
	);
	
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
	foreach ( $jamstack_action_groups as $jamstack_group ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		$jamstack_actions = $jamstack_store->query_actions(
			array(
				'group'    => $jamstack_group,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			)
		);
		
		// Cancel each action
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		foreach ( $jamstack_actions as $jamstack_action_id ) {
			$jamstack_store->cancel_action( $jamstack_action_id );
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

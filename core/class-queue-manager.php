<?php
/**
 * Queue Manager Class
 *
 * @package WPJamstack
 */

declare(strict_types=1);

namespace WPJamstack\Core;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not permitted.' );
}

/**
 * Abstraction layer for asynchronous queue operations
 *
 * This class provides an interface to Action Scheduler without
 * direct coupling. All async operations must use this manager.
 *
 * Future implementation will use Action Scheduler library for:
 * - Background processing
 * - Retry logic with exponential backoff
 * - Concurrency control (max 3 simultaneous syncs)
 * - Priority-based execution
 */
class Queue_Manager {

	/**
	 * Enqueue a post for synchronization
	 *
	 * TODO: Implement Action Scheduler task creation:
	 * - Schedule async action for post sync
	 * - Set priority level
	 * - Store task metadata in post meta
	 * - Return immediately (non-blocking)
	 *
	 * @param int $post_id  Post ID to enqueue.
	 * @param int $priority Priority level (lower = higher priority). Default 10.
	 *
	 * @return void
	 */
	public static function enqueue( int $post_id, int $priority = 10 ): void {
		// TODO: Implement Action Scheduler enqueue
		// as_enqueue_async_action( 'wpjamstack_sync_post', [ 'post_id' => $post_id ], '', false, $priority );
	}

	/**
	 * Cancel pending sync task for a post
	 *
	 * TODO: Implement Action Scheduler cancellation:
	 * - Find pending/running tasks for post
	 * - Cancel all matching tasks
	 * - Update post meta status
	 *
	 * @param int $post_id Post ID to cancel sync for.
	 *
	 * @return void
	 */
	public static function cancel( int $post_id ): void {
		// TODO: Implement Action Scheduler cancellation
		// as_unschedule_all_actions( 'wpjamstack_sync_post', [ 'post_id' => $post_id ] );
	}

	/**
	 * Get sync status for post(s)
	 *
	 * TODO: Implement status retrieval:
	 * - If post_id provided: return single status string
	 * - If null: return array of all posts with statuses
	 * - Possible statuses: 'pending', 'processing', 'completed', 'failed'
	 *
	 * @param int|null $post_id Optional post ID. If null, return all statuses.
	 *
	 * @return string|array Single status string or array of statuses.
	 */
	public static function get_status( ?int $post_id = null ): string|array {
		// TODO: Implement status check via Action Scheduler and post meta
		if ( null === $post_id ) {
			return array();
		}

		return 'unknown';
	}

	/**
	 * Retry all failed sync tasks
	 *
	 * TODO: Implement bulk retry logic:
	 * - Query all posts with 'failed' sync status
	 * - Re-enqueue each post
	 * - Reset failure counters
	 * - Log retry attempt
	 *
	 * @return void
	 */
	public static function retry_failed(): void {
		// TODO: Implement bulk retry for failed tasks
	}
}

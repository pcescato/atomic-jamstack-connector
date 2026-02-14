/**
 * AJC Bridge - Settings Page JavaScript
 * 
 * Handles bulk sync, stats refresh, and sync history functionality
 * on the plugin settings page.
 * 
 * @package AjcBridge
 */

(function($) {
	'use strict';
	
	/**
	 * Load and display sync statistics
	 */
	function loadStats() {
		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'ajc_bridge_get_stats',
				nonce: ajcBridgeSettings.statsNonce
			},
			success: function(response) {
				if (response.success) {
					updateStatsDisplay(response.data);
				}
			}
		});
	}
	
	/**
	 * Update stats display in the DOM
	 */
	function updateStatsDisplay(stats) {
		// Update stat values if elements exist
		if ($('#ajc-stats-total').length) {
			$('#ajc-stats-total').text(stats.total || 0);
		}
		if ($('#ajc-stats-pending').length) {
			$('#ajc-stats-pending').text(stats.pending || 0);
		}
		if ($('#ajc-stats-processing').length) {
			$('#ajc-stats-processing').text(stats.processing || 0);
		}
		if ($('#ajc-stats-success').length) {
			$('#ajc-stats-success').text(stats.success || 0);
		}
		if ($('#ajc-stats-error').length) {
			$('#ajc-stats-error').text(stats.error || 0);
		}
	}
	
	/**
	 * Start polling for bulk sync progress
	 */
	function startPolling() {
		var pollInterval = setInterval(function() {
			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'ajc_bridge_get_stats',
					nonce: ajcBridgeSettings.statsNonce
				},
				success: function(response) {
					if (response.success) {
						var stats = response.data;
						var total = stats.total || 0;
						var completed = (stats.success || 0) + (stats.error || 0);
						var percentage = total > 0 ? Math.round((completed / total) * 100) : 0;
						
						$('#atomic-jamstack-progress-text').text(completed + ' / ' + total + ' posts processed');
						$('#atomic-jamstack-progress-fill').css('width', percentage + '%');
						
						// Stop polling if all done
						if (stats.pending === 0 && stats.processing === 0) {
							clearInterval(pollInterval);
							updateStatsDisplay(stats);
						}
					}
				}
			});
		}, 3000); // Poll every 3 seconds
	}
	
	/**
	 * Initialize when document is ready
	 */
	$(document).ready(function() {
		// Load initial stats
		loadStats();
		
		// Bulk sync button
		$('#atomic-jamstack-bulk-sync-button').on('click', function() {
			if (!confirm(ajcBridgeSettings.textBulkConfirm)) {
				return;
			}
			
			var $button = $(this);
			var $status = $('#atomic-jamstack-bulk-status');
			var $message = $('#atomic-jamstack-bulk-message');
			
			$button.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> ' + ajcBridgeSettings.textStarting);
			$status.show();
			
			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'ajc_bridge_bulk_sync',
					nonce: ajcBridgeSettings.bulkSyncNonce
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
					$button.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> ' + ajcBridgeSettings.textSynchronize);
				},
				error: function() {
					$message.html('✗ ' + ajcBridgeSettings.textRequestFailed);
					$button.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> ' + ajcBridgeSettings.textSynchronize);
				}
			});
		});
		
		// Refresh stats button
		$('#atomic-jamstack-refresh-stats').on('click', function() {
			loadStats();
		});
		
		// Sync Now buttons in history table
		$('.atomic-jamstack-sync-now').on('click', function() {
			var $button = $(this);
			var postId = $button.data('post-id');
			
			$button.prop('disabled', true).html('<span class="dashicons dashicons-update dashicons-spin"></span> ' + ajcBridgeSettings.textSyncing);
			
			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'ajc_bridge_sync_single',
					nonce: ajcBridgeSettings.syncSingleNonce,
					post_id: postId
				},
				success: function(response) {
					if (response.success) {
						$button.html('<span class="dashicons dashicons-yes" style="color: #46b450;"></span> ' + ajcBridgeSettings.textSynced);
						// Reload page after 2 seconds
						setTimeout(function() {
							location.reload();
						}, 2000);
					} else {
						$button.html('<span class="dashicons dashicons-no" style="color: #dc3232;"></span> ' + response.data.message);
						$button.prop('disabled', false);
						setTimeout(function() {
							$button.html('<span class="dashicons dashicons-update"></span> ' + ajcBridgeSettings.textSyncNow);
						}, 3000);
					}
				},
				error: function() {
					$button.html('<span class="dashicons dashicons-no"></span> ' + ajcBridgeSettings.textError);
					$button.prop('disabled', false);
					setTimeout(function() {
						$button.html('<span class="dashicons dashicons-update"></span> ' + ajcBridgeSettings.textSyncNow);
					}, 3000);
				}
			});
		});
	});
	
})(jQuery);

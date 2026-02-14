/**
 * AJC Bridge - Admin Columns JavaScript
 * 
 * Handles the "Sync Now" AJAX functionality in the posts list table.
 * 
 * @package AjcBridge
 */

(function($) {
	'use strict';
	
	$(document).ready(function() {
		// Handle Sync Now link clicks
		$(document).on('click', '.jamstack-sync-now', function(e) {
			e.preventDefault();
			
			var $link = $(this);
			var postId = $link.data('post-id');
			var nonce = $link.data('nonce');
			var ajaxUrl = $link.data('ajax-url');
			
			// Disable link and show loading
			$link.css('opacity', '0.5').text(ajcBridgeColumns.textSyncing);
			
			$.ajax({
				url: ajaxUrl,
				type: 'POST',
				data: {
					action: 'jamstack_sync_now',
					post_id: postId,
					nonce: nonce
				},
				success: function(response) {
					if (response.success) {
						$link.text(ajcBridgeColumns.textEnqueued);
						// Reload page after 1 second to show updated status
						setTimeout(function() {
							location.reload();
						}, 1000);
					} else {
						$link.css('opacity', '1').text(ajcBridgeColumns.textSyncNow);
						alert(response.data || ajcBridgeColumns.textFailed);
					}
				},
				error: function() {
					$link.css('opacity', '1').text(ajcBridgeColumns.textSyncNow);
					alert(ajcBridgeColumns.textAjaxError);
				}
			});
		});
	});
	
})(jQuery);

/**
 * Atomic Jamstack Connector - Admin JavaScript
 */

(function($) {
	'use strict';

	$(document).ready(function() {
		// Test connection button
		$('#atomic-jamstack-test-connection').on('click', function(e) {
			e.preventDefault();
			
			var $button = $(this);
			var $result = $('#atomic-jamstack-test-result');
			
			// Disable button
			$button.prop('disabled', true);
			$result.html('<span class="atomic-jamstack-test-result testing">' + atomicJamstackAdmin.strings.testing + '</span>');
			
			// Make AJAX request
			$.ajax({
				url: atomicJamstackAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'atomic_jamstack_test_connection',
					nonce: atomicJamstackAdmin.testConnectionNonce
				},
				success: function(response) {
					if (response.success) {
						$result.html('<span class="atomic-jamstack-test-result success">✓ ' + response.data.message + '</span>');
					} else {
						$result.html('<span class="atomic-jamstack-test-result error">✗ ' + atomicJamstackAdmin.strings.error + ' ' + response.data.message + '</span>');
					}
				},
				error: function() {
					$result.html('<span class="atomic-jamstack-test-result error">✗ ' + atomicJamstackAdmin.strings.error + ' Network error</span>');
				},
				complete: function() {
					$button.prop('disabled', false);
				}
			});
		});
	});

})(jQuery);

<?php
/**
 * Dev.to API Client
 *
 * @package AtomicJamstack
 */

declare(strict_types=1);

namespace AjcBridge\Core;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not permitted.' );
}

/**
 * Dev.to (Forem) API client
 *
 * Handles communication with Dev.to REST API for article publishing.
 *
 * API Documentation: https://developers.forem.com/api/v1
 */
class DevTo_API {

	/**
	 * API base URL
	 */
	private const API_BASE = 'https://dev.to/api';

	/**
	 * API key for authentication
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Constructor
	 *
	 * Loads API key from plugin settings.
	 */
	public function __construct() {
		$settings = get_option( 'ajc_bridge_settings', array() );
		$this->api_key = $settings['devto_api_key'] ?? '';
	}

	/**
	 * Publish or update article on Dev.to
	 *
	 * Creates new article (POST) or updates existing (PUT) based on article_id.
	 *
	 * @param string   $markdown   Complete markdown content with front matter.
	 * @param int|null $article_id Optional. Existing article ID for updates.
	 *
	 * @return array|\WP_Error Article data on success, WP_Error on failure.
	 */
	public function publish_article( string $markdown, ?int $article_id = null ): array|\WP_Error {
		if ( empty( $this->api_key ) ) {
			return new \WP_Error(
				'missing_api_key',
				__( 'Dev.to API key is not configured.', 'ajc-bridge' )
			);
		}

		$method = $article_id ? 'PUT' : 'POST';
		$url    = $article_id 
			? self::API_BASE . '/articles/' . $article_id 
			: self::API_BASE . '/articles';

		$body = array(
			'article' => array(
				'body_markdown' => $markdown,
			),
		);

		$response = wp_remote_request(
			$url,
			array(
				'method'  => $method,
				'headers' => array(
					'api-key'      => $this->api_key,
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
			)
		);

		// Check for network errors
		if ( is_wp_error( $response ) ) {
			Logger::error(
				'Dev.to API request failed',
				array(
					'error'   => $response->get_error_message(),
					'method'  => $method,
					'url'     => $url,
				)
			);
			return $response;
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$body_data = wp_remote_retrieve_body( $response );
		$data      = json_decode( $body_data, true );

		// Log response for debugging
		Logger::info(
			'Dev.to API response',
			array(
				'http_code' => $http_code,
				'method'    => $method,
				'url'       => $url,
				'success'   => in_array( $http_code, array( 200, 201 ), true ),
			)
		);

		// Check HTTP status
		if ( ! in_array( $http_code, array( 200, 201 ), true ) ) {
			$error_message = $this->extract_error_message( $data, $http_code );
			
			Logger::error(
				'Dev.to API error response',
				array(
					'http_code' => $http_code,
					'error'     => $error_message,
					'response'  => $body_data,
				)
			);

			return new \WP_Error(
				'api_error',
				sprintf(
					/* translators: %1$s: HTTP code, %2$s: Error message */
					__( 'Dev.to API error (HTTP %1$s): %2$s', 'ajc-bridge' ),
					$http_code,
					$error_message
				)
			);
		}

		return $data ?? array();
	}

	/**
	 * Create new article on Dev.to
	 *
	 * @param string $markdown Complete markdown content with front matter.
	 *
	 * @return array|\WP_Error Article data with 'id' on success, WP_Error on failure.
	 */
	public function create_article( string $markdown ): array|\WP_Error {
		Logger::info( 'Creating new Dev.to article', array() );
		
		$result = $this->publish_article( $markdown, null );
		
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		
		// Ensure 'id' is present in response
		if ( ! isset( $result['id'] ) ) {
			Logger::error( 'Dev.to API response missing article ID', array( 'response' => $result ) );
			return new \WP_Error(
				'missing_id',
				__( 'Dev.to API response missing article ID', 'ajc-bridge' )
			);
		}
		
		Logger::success(
			'Dev.to article created',
			array(
				'article_id' => $result['id'],
				'url'        => $result['url'] ?? '',
			)
		);
		
		return $result;
	}

	/**
	 * Update existing article on Dev.to
	 *
	 * @param int    $article_id Dev.to article ID.
	 * @param string $markdown   Complete markdown content with front matter.
	 *
	 * @return array|\WP_Error Article data on success, WP_Error on failure.
	 */
	public function update_article( int $article_id, string $markdown ): array|\WP_Error {
		Logger::info(
			'Updating Dev.to article',
			array( 'article_id' => $article_id )
		);
		
		$result = $this->publish_article( $markdown, $article_id );
		
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		
		Logger::success(
			'Dev.to article updated',
			array(
				'article_id' => $article_id,
				'url'        => $result['url'] ?? '',
			)
		);
		
		return $result;
	}

	/**
	 * Test API connection
	 *
	 * Verifies API key by fetching user's published articles.
	 *
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function test_connection(): bool|\WP_Error {
		if ( empty( $this->api_key ) ) {
			return new \WP_Error(
				'missing_api_key',
				__( 'Dev.to API key is required.', 'ajc-bridge' )
			);
		}

		$url = self::API_BASE . '/articles/me/published';

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'api-key' => $this->api_key,
				),
				'timeout' => 15,
			)
		);

		// Check for network errors
		if ( is_wp_error( $response ) ) {
			Logger::error(
				'Dev.to connection test failed',
				array( 'error' => $response->get_error_message() )
			);
			return $response;
		}

		$http_code = wp_remote_retrieve_response_code( $response );

		// Check HTTP status
		if ( 200 !== $http_code ) {
			$body_data = wp_remote_retrieve_body( $response );
			$data      = json_decode( $body_data, true );
			$error_message = $this->extract_error_message( $data, $http_code );

			Logger::error(
				'Dev.to connection test failed',
				array(
					'http_code' => $http_code,
					'error'     => $error_message,
				)
			);

			return new \WP_Error(
				'connection_failed',
				sprintf(
					/* translators: %1$s: HTTP code, %2$s: Error message */
					__( 'Connection failed (HTTP %1$s): %2$s', 'ajc-bridge' ),
					$http_code,
					$error_message
				)
			);
		}

		Logger::success( 'Dev.to connection test successful', array() );

		return true;
	}

	/**
	 * Extract error message from API response
	 *
	 * Parses error response and returns human-readable message.
	 *
	 * @param array|null $data      Decoded JSON response.
	 * @param int        $http_code HTTP status code.
	 *
	 * @return string Error message.
	 */
	private function extract_error_message( ?array $data, int $http_code ): string {
		// Try to extract error from response data
		if ( is_array( $data ) ) {
			// Dev.to error format: {"error": "message"}
			if ( isset( $data['error'] ) ) {
				return is_string( $data['error'] ) ? $data['error'] : wp_json_encode( $data['error'] );
			}

			// Alternative format: {"errors": ["msg1", "msg2"]}
			if ( isset( $data['errors'] ) && is_array( $data['errors'] ) ) {
				return implode( ', ', $data['errors'] );
			}

			// Generic message with status
			if ( isset( $data['status'] ) ) {
				return 'Status: ' . $data['status'];
			}
		}

		// Fallback to HTTP status text
		$status_messages = array(
			400 => 'Bad Request - Invalid article data',
			401 => 'Unauthorized - Invalid API key',
			403 => 'Forbidden - Access denied',
			404 => 'Not Found - Article or endpoint not found',
			422 => 'Unprocessable Entity - Validation failed',
			429 => 'Too Many Requests - Rate limit exceeded',
			500 => 'Internal Server Error',
			503 => 'Service Unavailable',
		);

		return $status_messages[ $http_code ] ?? 'Unknown error';
	}

	/**
	 * Get user's published articles
	 *
	 * Fetches list of articles for debugging and management.
	 *
	 * @param int $page     Page number (default 1).
	 * @param int $per_page Articles per page (default 30, max 1000).
	 *
	 * @return array|\WP_Error Array of articles or WP_Error.
	 */
	public function get_articles( int $page = 1, int $per_page = 30 ): array|\WP_Error {
		if ( empty( $this->api_key ) ) {
			return new \WP_Error(
				'missing_api_key',
				__( 'Dev.to API key is required.', 'ajc-bridge' )
			);
		}

		$url = add_query_arg(
			array(
				'page'     => $page,
				'per_page' => min( $per_page, 1000 ), // Max 1000 per API docs
			),
			self::API_BASE . '/articles/me/published'
		);

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'api-key' => $this->api_key,
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$http_code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $http_code ) {
			$body_data = wp_remote_retrieve_body( $response );
			$data      = json_decode( $body_data, true );
			$error_message = $this->extract_error_message( $data, $http_code );

			return new \WP_Error(
				'api_error',
				sprintf(
					/* translators: %1$s: HTTP code, %2$s: Error message */
					__( 'Failed to fetch articles (HTTP %1$s): %2$s', 'ajc-bridge' ),
					$http_code,
					$error_message
				)
			);
		}

		$body_data = wp_remote_retrieve_body( $response );
		$data      = json_decode( $body_data, true );

		return is_array( $data ) ? $data : array();
	}
}

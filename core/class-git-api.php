<?php
/**
 * Git API Client Class
 *
 * @package AtomicJamstack
 */

declare(strict_types=1);

namespace AjcBridge\Core;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not permitted.' );
}

/**
 * Remote Git API abstraction layer
 *
 * Generic interface for Git operations via HTTPS API.
 * Primary implementation: GitHub REST API
 * Future: GitLab, Bitbucket support possible
 *
 * Uses WordPress native wp_remote_* functions exclusively.
 * No shell commands, no Git CLI.
 */
class Git_API {

	/**
	 * API authentication token
	 *
	 * @var string|null
	 */
	private ?string $token = null;

	/**
	 * Repository identifier (e.g., "owner/repo")
	 *
	 * @var string|null
	 */
	private ?string $repo = null;

	/**
	 * Target branch name
	 *
	 * @var string
	 */
	private string $branch = 'main';

	/**
	 * GitHub API base URL
	 *
	 * @var string
	 */
	private string $api_base = 'https://api.github.com';

	/**
	 * Constructor
	 *
	 * Loads configuration from WordPress options.
	 * Decrypts GitHub token for use in API requests.
	 */
	public function __construct() {
		$settings = get_option( 'ajc_bridge_settings', array() );

		// Decrypt token if present
		if ( ! empty( $settings['github_token'] ) ) {
			$encrypted_token = $settings['github_token'];
			$decrypted = $this->decrypt_token( $encrypted_token );
			
			// Debug logging to diagnose token issues
			Logger::info(
				'Token decryption attempt',
				array(
					'encrypted_length' => strlen( $encrypted_token ),
					'decrypted_length' => $decrypted !== false ? strlen( $decrypted ) : 0,
					'decryption_success' => $decrypted !== false && ! empty( $decrypted ),
					// SECURITY: Never log token values, even partial previews
				)
			);
			
			// If decryption fails, fall back to plain text and log warning
			if ( false === $decrypted || empty( $decrypted ) ) {
				Logger::warning(
					'Token decryption failed, falling back to plain text',
					array( 'token_length' => strlen( $settings['github_token'] ) )
				);
				$this->token = $settings['github_token'];
			} else {
				$this->token = $decrypted;
				Logger::info(
					'Token decrypted successfully',
					array( 'decrypted_token_length' => strlen( $this->token ) )
				);
			}
		}

		$this->repo   = $settings['github_repo'] ?? null;
		$this->branch = $settings['github_branch'] ?? 'main';

		// Allow custom API base URL for future multi-provider support
		if ( ! empty( $settings['api_base_url'] ) ) {
			$this->api_base = $settings['api_base_url'];
		}
	}

	/**
	 * Parse repository string into owner and repo parts
	 *
	 * Safely handles null or invalid repository strings.
	 *
	 * @return array{0: string, 1: string}|\WP_Error Array with [owner, repo] or WP_Error on failure.
	 */
	private function parse_repo(): array|\WP_Error {
		if ( null === $this->repo || empty( $this->repo ) ) {
			Logger::error(
				'GitHub repository not configured',
				array( 'repo' => $this->repo )
			);
			return new \WP_Error(
				'repo_not_configured',
				__( 'GitHub repository is not configured. Please check your settings.', 'ajc-bridge' )
			);
		}

		if ( ! is_string( $this->repo ) ) {
			Logger::error(
				'GitHub repository is not a string',
				array( 'repo' => $this->repo, 'type' => gettype( $this->repo ) )
			);
			return new \WP_Error(
				'repo_invalid_type',
				__( 'GitHub repository setting has invalid type.', 'ajc-bridge' )
			);
		}

		if ( false === strpos( $this->repo, '/' ) ) {
			Logger::error(
				'GitHub repository format invalid (missing /)',
				array( 'repo' => $this->repo )
			);
			return new \WP_Error(
				'repo_invalid_format',
				__( 'GitHub repository must be in format "owner/repo".', 'ajc-bridge' )
			);
		}

		$parts = explode( '/', $this->repo, 2 );
		
		if ( count( $parts ) !== 2 || empty( $parts[0] ) || empty( $parts[1] ) ) {
			Logger::error(
				'GitHub repository format invalid (empty parts)',
				array( 'repo' => $this->repo, 'parts' => $parts )
			);
			return new \WP_Error(
				'repo_invalid_format',
				__( 'GitHub repository must be in format "owner/repo".', 'ajc-bridge' )
			);
		}

		return $parts;
	}

	/**
	 * Decrypt GitHub token
	 *
	 * @param string $encrypted_token Encrypted token.
	 *
	 * @return string|false Decrypted token or false on failure.
	 */
	private function decrypt_token( string $encrypted_token ): string|false {
		$method = 'AES-256-CBC';
		$key    = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv     = substr( hash( 'sha256', wp_salt( 'nonce' ), true ), 0, 16 );

		$decoded   = base64_decode( $encrypted_token, true );
		
		// If base64 decode fails, token is likely plain text
		if ( false === $decoded ) {
			return false;
		}
		
		$decrypted = openssl_decrypt( $decoded, $method, $key, 0, $iv );

		return $decrypted !== false ? $decrypted : false;
	}

	/**
	 * Get standard headers for GitHub API requests
	 *
	 * @return array HTTP headers.
	 */
	private function get_headers(): array {
		return array(
			'Authorization' => 'Bearer ' . $this->token,
			'Accept'        => 'application/vnd.github+json',
			'Content-Type'  => 'application/json',
			'User-Agent'    => 'WP-Jamstack-Sync/' . AJC_BRIDGE_VERSION,
		);
	}

	/**
	 * Test API connection and credentials
	 *
	 * Verifies:
	 * - Token is valid
	 * - Repository exists
	 * - User has access to repository
	 *
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function test_connection(): bool|\WP_Error {
		// Validate required settings
		if ( empty( $this->token ) ) {
			Logger::error( 'GitHub token not configured' );
			return new \WP_Error(
				'missing_token',
				__( 'GitHub token is not configured. Please add your token in plugin settings.', 'ajc-bridge' )
			);
		}

		if ( empty( $this->repo ) ) {
			Logger::error( 'GitHub repository not configured' );
			return new \WP_Error(
				'missing_repo',
				__( 'GitHub repository is not configured. Please add repository in format: owner/repo', 'ajc-bridge' )
			);
		}

		// Validate repository format (owner/repo)
		if ( substr_count( $this->repo, '/' ) !== 1 ) {
			Logger::error(
				'Invalid repository format',
				array( 'repo' => $this->repo )
			);
			return new \WP_Error(
				'invalid_repo_format',
				__( 'Repository must be in format: owner/repo', 'ajc-bridge' )
			);
		}

		// Build API URL
		$url = sprintf( '%s/repos/%s', $this->api_base, $this->repo );

		Logger::info(
			'Testing GitHub connection',
			array(
				'repo' => $this->repo,
				'url'  => $url,
			)
		);

		// Make API request
		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->token,
					'Accept'        => 'application/vnd.github+json',
					'User-Agent'    => 'WP-Jamstack-Sync/' . AJC_BRIDGE_VERSION,
				),
				'timeout' => 15,
			)
		);

		// Handle network errors
		if ( is_wp_error( $response ) ) {
			Logger::error(
				'GitHub API request failed',
				array(
					'error' => $response->get_error_message(),
					'code'  => $response->get_error_code(),
				)
			);
			return new \WP_Error(
				'network_error',
				sprintf(
					/* translators: %s: Error message */
					__( 'Failed to connect to GitHub: %s', 'ajc-bridge' ),
					$response->get_error_message()
				)
			);
		}

		// Get response code
		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body, true );

		// Log the full response for debugging (excluding sensitive token)
		Logger::info(
			'GitHub API response received',
			array(
				'status_code' => $status_code,
				'body'        => $body,
				'headers'     => wp_remote_retrieve_headers( $response )->getAll(),
			)
		);

		// Handle HTTP errors
		switch ( $status_code ) {
			case 200:
				// Success
				Logger::success(
					'GitHub connection test successful',
					array(
						'repo'        => $this->repo,
						'full_name'   => $data['full_name'] ?? '',
						'private'     => $data['private'] ?? false,
						'permissions' => $data['permissions'] ?? array(),
					)
				);

				// Check if we have push permissions
				if ( isset( $data['permissions']['push'] ) && false === $data['permissions']['push'] ) {
					Logger::warning(
						'No push permission to repository',
						array( 'repo' => $this->repo )
					);
					return new \WP_Error(
						'no_push_permission',
						__( 'GitHub token does not have push permission to this repository. Please check token permissions.', 'ajc-bridge' )
					);
				}

				return true;

			case 401:
				// Unauthorized - invalid token
				Logger::error(
					'GitHub authentication failed - 401 Bad credentials',
					array(
						'status'       => $status_code,
						'error'        => $data['message'] ?? 'Unauthorized',
						'full_body'    => $body,
						'token_length' => strlen( $this->token ?? '' ),
					)
				);
				return new \WP_Error(
					'invalid_token',
					__( 'GitHub token is invalid or expired. Please check your token.', 'ajc-bridge' )
				);

			case 403:
				// Forbidden - could be rate limit or token permissions
				$error_message = $data['message'] ?? 'Forbidden';

				// Check if rate limited
				$rate_limit_remaining = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );
				if ( '0' === $rate_limit_remaining ) {
					$rate_limit_reset = wp_remote_retrieve_header( $response, 'x-ratelimit-reset' );
					$reset_time       = ! empty( $rate_limit_reset ) ? gmdate( 'Y-m-d H:i:s', (int) $rate_limit_reset ) : 'unknown';

					Logger::error(
						'GitHub rate limit exceeded',
						array(
							'reset_time' => $reset_time,
						)
					);
					return new \WP_Error(
						'rate_limit_exceeded',
						sprintf(
							/* translators: %s: Reset time */
							__( 'GitHub API rate limit exceeded. Resets at: %s UTC', 'ajc-bridge' ),
							$reset_time
						)
					);
				}

				Logger::error(
					'GitHub access forbidden',
					array(
						'status' => $status_code,
						'error'  => $error_message,
					)
				);
				return new \WP_Error(
					'access_forbidden',
					sprintf(
						/* translators: %s: Error message */
						__( 'Access forbidden: %s', 'ajc-bridge' ),
						$error_message
					)
				);

			case 404:
				// Repository not found
				Logger::error(
					'GitHub repository not found',
					array(
						'repo'   => $this->repo,
						'status' => $status_code,
					)
				);
				return new \WP_Error(
					'repo_not_found',
					sprintf(
						/* translators: %s: Repository name */
						__( 'Repository not found: %s. Please check the repository name and your access permissions.', 'ajc-bridge' ),
						$this->repo
					)
				);

			default:
				// Other errors
				$error_message = $data['message'] ?? 'Unknown error';

				Logger::error(
					'GitHub API error',
					array(
						'status'  => $status_code,
						'message' => $error_message,
					)
				);
				return new \WP_Error(
					'api_error',
					sprintf(
						/* translators: 1: HTTP status code, 2: Error message */
						__( 'GitHub API error (HTTP %1$d): %2$s', 'ajc-bridge' ),
						$status_code,
						$error_message
					)
				);
		}
	}

	/**
	 * Get current SHA hash of branch HEAD
	 *
	 * @return string|\WP_Error SHA hash or WP_Error on failure.
	 */
	public function get_branch_sha(): string|\WP_Error {
		if ( empty( $this->token ) || empty( $this->repo ) ) {
			return new \WP_Error( 'missing_config', __( 'GitHub configuration missing', 'ajc-bridge' ) );
		}

		$url = sprintf( '%s/repos/%s/git/ref/heads/%s', $this->api_base, $this->repo, $this->branch );

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->token,
					'Accept'        => 'application/vnd.github+json',
					'User-Agent'    => 'WP-Jamstack-Sync/' . AJC_BRIDGE_VERSION,
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );
			return new \WP_Error( 'api_error', $data['message'] ?? 'Failed to get branch SHA' );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		return $data['object']['sha'] ?? new \WP_Error( 'invalid_response', __( 'Invalid API response', 'ajc-bridge' ) );
	}

	/**
	 * Create or update a file in the repository
	 *
	 * @param string      $path    File path in repository.
	 * @param string      $content File content (plain text or binary).
	 * @param string      $message Commit message.
	 * @param string|null $sha     Existing file SHA (null for new file).
	 *
	 * @return array|\WP_Error Commit metadata or WP_Error on failure.
	 */
	public function create_or_update_file( string $path, string $content, string $message, ?string $sha = null ): array|\WP_Error {
		if ( empty( $this->token ) || empty( $this->repo ) ) {
			return new \WP_Error( 'missing_config', __( 'GitHub configuration missing', 'ajc-bridge' ) );
		}

		$url = sprintf( '%s/repos/%s/contents/%s', $this->api_base, $this->repo, ltrim( $path, '/' ) );

		$body = array(
			'message' => $message,
			'content' => base64_encode( $content ),
			'branch'  => $this->branch,
		);

		if ( null !== $sha ) {
			$body['sha'] = $sha;
		}

		$response = wp_remote_request(
			$url,
			array(
				'method'  => 'PUT',
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->token,
					'Accept'        => 'application/vnd.github+json',
					'Content-Type'  => 'application/json',
					'User-Agent'    => 'WP-Jamstack-Sync/' . AJC_BRIDGE_VERSION,
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 60, // Increased timeout for slow networks
			)
		);

		if ( is_wp_error( $response ) ) {
			Logger::error(
				'Failed to create/update file',
				array(
					'path'          => $path,
					'error_code'    => $response->get_error_code(),
					'error_message' => $response->get_error_message(),
				)
			);
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body_data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! in_array( $status_code, array( 200, 201 ), true ) ) {
			Logger::error(
				'GitHub API error on file create/update',
				array(
					'path'   => $path,
					'status' => $status_code,
					'body'   => $body_data,
				)
			);
			return new \WP_Error( 'api_error', $body_data['message'] ?? 'Failed to create/update file' );
		}

		Logger::success( 'File created/updated on GitHub', array( 'path' => $path, 'status' => $status_code ) );
		return $body_data;
	}

	/**
	 * Get file contents from repository
	 *
	 * @param string $path File path in repository.
	 *
	 * @return array|null File data array or null if not found.
	 */
	public function get_file( string $path ): ?array {
		if ( empty( $this->token ) || empty( $this->repo ) ) {
			Logger::error(
				'Cannot get file: missing configuration',
				array( 'path' => $path )
			);
			return null;
		}

		$url = sprintf( '%s/repos/%s/contents/%s', $this->api_base, $this->repo, ltrim( $path, '/' ) );

		Logger::info(
			'Fetching file from GitHub',
			array(
				'path'   => $path,
				'repo'   => $this->repo,
				'branch' => $this->branch,
				'url'    => $url,
			)
		);

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->token,
					'Accept'        => 'application/vnd.github+json',
					'User-Agent'    => 'WP-Jamstack-Sync/' . AJC_BRIDGE_VERSION,
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			Logger::error(
				'Network error fetching file',
				array(
					'path'  => $path,
					'error' => $response->get_error_message(),
				)
			);
			return null;
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( 404 === $status_code ) {
			Logger::warning(
				'File not found on GitHub (404)',
				array(
					'path'   => $path,
					'repo'   => $this->repo,
					'branch' => $this->branch,
				)
			);
			return null;
		}

		if ( 200 !== $status_code ) {
			$body = wp_remote_retrieve_body( $response );
			Logger::error(
				'GitHub API error fetching file',
				array(
					'path'     => $path,
					'status'   => $status_code,
					'response' => substr( $body, 0, 500 ),
				)
			);
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! empty( $data['content'] ) ) {
			$data['content'] = base64_decode( $data['content'] );
		}

		Logger::info(
			'File fetched successfully',
			array(
				'path' => $path,
				'sha'  => substr( $data['sha'] ?? 'unknown', 0, 7 ),
				'size' => $data['size'] ?? 0,
			)
		);

		return $data;
	}

	/**
	 * Delete a file from repository
	 *
	 * Fetches the file's current SHA first, then deletes it.
	 * Handles 404 errors gracefully (file already deleted or never existed).
	 *
	 * @param string $path    File path in repository.
	 * @param string $message Commit message.
	 *
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function delete_file( string $path, string $message ): bool|\WP_Error {
		if ( empty( $this->token ) || empty( $this->repo ) ) {
			return new \WP_Error( 'missing_config', __( 'GitHub configuration missing', 'ajc-bridge' ) );
		}

		Logger::info(
			'Attempting to delete file',
			array(
				'path'   => $path,
				'repo'   => $this->repo,
				'branch' => $this->branch,
			)
		);

		// First, fetch the file to get its SHA
		$file_data = $this->get_file( $path );

		// Handle 404: file doesn't exist (already deleted or never synced)
		if ( null === $file_data ) {
			Logger::warning(
				'File not found on GitHub - cannot delete (404)',
				array(
					'path'   => $path,
					'reason' => 'File may have been manually deleted or never synced',
				)
			);
			return true; // Not an error - file is gone, which is what we want
		}

		// Extract SHA from file data
		if ( empty( $file_data['sha'] ) ) {
			return new \WP_Error(
				'missing_sha',
				__( 'Unable to retrieve file SHA for deletion', 'ajc-bridge' )
			);
		}

		$sha = $file_data['sha'];

		Logger::info(
			'File SHA retrieved, proceeding with deletion',
			array(
				'path' => $path,
				'sha'  => substr( $sha, 0, 7 ),
			)
		);

		// Execute deletion
		$url = sprintf( '%s/repos/%s/contents/%s', $this->api_base, $this->repo, ltrim( $path, '/' ) );

		$body = array(
			'message' => $message,
			'sha'     => $sha,
			'branch'  => $this->branch,
		);

		$response = wp_remote_request(
			$url,
			array(
				'method'  => 'DELETE',
				'headers' => $this->get_headers(),
				'body'    => wp_json_encode( $body ),
				'timeout' => 60, // Increased timeout for slow networks
			)
		);

		if ( is_wp_error( $response ) ) {
			Logger::error(
				'Network error during file deletion',
				array(
					'path'          => $path,
					'error_code'    => $response->get_error_code(),
					'error_message' => $response->get_error_message(),
				)
			);
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		// Success: 200 or 204
		if ( 200 === $status_code || 204 === $status_code ) {
			Logger::success(
				'File deleted successfully',
				array(
					'path'   => $path,
					'status' => $status_code,
				)
			);
			return true;
		}

		// Handle 404 during deletion (rare but possible if deleted between get_file and delete)
		if ( 404 === $status_code ) {
			Logger::info(
				'File was already deleted (404 during deletion)',
				array( 'path' => $path )
			);
			return true;
		}

		// Other errors
		$body_data = json_decode( wp_remote_retrieve_body( $response ), true );
		$error_message = $body_data['message'] ?? 'Failed to delete file';

		Logger::error(
			'GitHub API error during deletion',
			array(
				'path'    => $path,
				'status'  => $status_code,
				'message' => $error_message,
			)
		);

		return new \WP_Error(
			'api_error',
			sprintf(
				/* translators: 1: HTTP status code, 2: Error message */
				__( 'GitHub API error (HTTP %1$d): %2$s', 'ajc-bridge' ),
				$status_code,
				$error_message
			)
		);
	}

	/**
	 * Get API rate limit status
	 *
	 * @return array|\WP_Error Rate limit data or WP_Error on failure.
	 */
	public function get_rate_limit(): array|\WP_Error {
		if ( empty( $this->token ) ) {
			return new \WP_Error( 'missing_token', __( 'GitHub token missing', 'ajc-bridge' ) );
		}

		$url = $this->api_base . '/rate_limit';

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->token,
					'Accept'        => 'application/vnd.github+json',
					'User-Agent'    => 'WP-Jamstack-Sync/' . AJC_BRIDGE_VERSION,
				),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return new \WP_Error( 'api_error', __( 'Failed to get rate limit', 'ajc-bridge' ) );
		}

		$body = wp_remote_retrieve_body( $response );
		return json_decode( $body, true ) ?? array();
	}

	/**
	 * List files in a directory
	 *
	 * Retrieves the contents of a directory from GitHub.
	 * Used primarily for finding image files to delete.
	 *
	 * @param string $path Directory path in repository.
	 *
	 * @return array|\WP_Error Array of file objects or WP_Error on failure.
	 */
	public function list_directory( string $path ): array|\WP_Error {
		if ( empty( $this->token ) || empty( $this->repo ) ) {
			return new \WP_Error( 'missing_config', __( 'GitHub configuration missing', 'ajc-bridge' ) );
		}

		$url = sprintf(
			'%s/repos/%s/contents/%s?ref=%s',
			$this->api_base,
			$this->repo,
			ltrim( $path, '/' ),
			$this->branch
		);

		$response = wp_remote_get(
			$url,
			array(
				'headers' => $this->get_headers(),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		// Directory doesn't exist
		if ( 404 === $status_code ) {
			return array(); // Empty array, not an error
		}

		// Other errors
		if ( 200 !== $status_code ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			return new \WP_Error(
				'api_error',
				$body['message'] ?? 'Failed to list directory'
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		// Ensure we got an array (directory listing)
		if ( ! is_array( $data ) ) {
			return new \WP_Error(
				'invalid_response',
				__( 'Expected directory listing, got single file', 'ajc-bridge' )
			);
		}

		return $data;
	}

	/**
	 * Create atomic commit with multiple files
	 *
	 * Uses GitHub Git Data API to create a single commit with multiple files.
	 * Flow: Get Branch Ref -> Get Base Tree -> Create Blobs -> Create Tree -> Create Commit -> Update Ref.
	 *
	 * @param array  $files   Associative array of file paths => file contents (binary or text).
	 * @param string $message Commit message.
	 *
	 * @return array|\WP_Error Commit data on success, WP_Error on failure.
	 */
	public function create_atomic_commit( array $files, string $message ): array|\WP_Error {
		if ( empty( $files ) ) {
			return new \WP_Error(
				'empty_payload',
				__( 'No files provided for atomic commit', 'ajc-bridge' )
			);
		}

		// Step 1: Get current branch reference
		$ref_data = $this->get_branch_ref();
		if ( is_wp_error( $ref_data ) ) {
			Logger::error( 'Failed to get branch reference', array(
				'branch' => $this->branch,
				'error' => $ref_data->get_error_message(),
			) );
			return $ref_data;
		}

		$base_sha = $ref_data['object']['sha'];
		Logger::info( 'Retrieved branch reference', array(
			'branch' => $this->branch,
			'sha' => substr( $base_sha, 0, 7 ),
		) );

		// Step 2: Get base tree SHA
		$base_tree_sha = $ref_data['object']['sha']; // This is the commit SHA, we need tree SHA from commit
		$commit_data = $this->get_commit_data( $base_sha );
		if ( is_wp_error( $commit_data ) ) {
			Logger::error( 'Failed to get commit data', array( 'error' => $commit_data->get_error_message() ) );
			return $commit_data;
		}

		$base_tree_sha = $commit_data['tree']['sha'];
		Logger::info( 'Retrieved base tree', array( 'sha' => substr( $base_tree_sha, 0, 7 ) ) );

		// Step 3: Create blobs for all files
		$tree_items = array();
		foreach ( $files as $path => $content ) {
			$blob_sha = $this->create_blob( $content );
			if ( is_wp_error( $blob_sha ) ) {
				Logger::error( 'Failed to create blob', array(
					'path' => $path,
					'error' => $blob_sha->get_error_message(),
				) );
				return $blob_sha;
			}

			$tree_items[] = array(
				'path' => $path,
				'mode' => '100644', // Regular file
				'type' => 'blob',
				'sha'  => $blob_sha,
			);

			Logger::info( 'Blob created', array(
				'path' => $path,
				'sha' => substr( $blob_sha, 0, 7 ),
				'size' => strlen( $content ),
			) );
		}

		// Step 4: Create new tree
		$tree_sha = $this->create_tree( $tree_items, $base_tree_sha );
		if ( is_wp_error( $tree_sha ) ) {
			Logger::error( 'Failed to create tree', array( 'error' => $tree_sha->get_error_message() ) );
			return $tree_sha;
		}

		Logger::info( 'Tree created', array(
			'sha' => substr( $tree_sha, 0, 7 ),
			'files' => count( $tree_items ),
		) );

		// Step 5: Create commit
		$commit_sha = $this->create_commit( $message, $tree_sha, $base_sha );
		if ( is_wp_error( $commit_sha ) ) {
			Logger::error( 'Failed to create commit', array( 'error' => $commit_sha->get_error_message() ) );
			return $commit_sha;
		}

		Logger::info( 'Commit created', array(
			'sha' => substr( $commit_sha, 0, 7 ),
			'message' => $message,
		) );

		// Step 6: Update branch reference
		$update_result = $this->update_ref( $commit_sha );
		if ( is_wp_error( $update_result ) ) {
			Logger::error( 'Failed to update branch reference', array( 'error' => $update_result->get_error_message() ) );
			return $update_result;
		}

		Logger::success( 'Atomic commit completed', array(
			'sha' => substr( $commit_sha, 0, 7 ),
			'branch' => $this->branch,
			'files' => count( $files ),
		) );

		return array(
			'commit_sha' => $commit_sha,
			'tree_sha'   => $tree_sha,
			'files'      => array_keys( $files ),
			'message'    => $message,
		);
	}

	/**
	 * Get branch reference
	 *
	 * @return array|\WP_Error Reference data or error.
	 */
	private function get_branch_ref(): array|\WP_Error {
		$repo_parts = $this->parse_repo();
		if ( is_wp_error( $repo_parts ) ) {
			return $repo_parts;
		}
		
		list( $owner, $repo ) = $repo_parts;
		$url = sprintf(
			'https://api.github.com/repos/%s/%s/git/refs/heads/%s',
			$owner,
			$repo,
			$this->branch
		);

		$response = wp_remote_get(
			$url,
			array(
				'headers' => $this->get_headers(),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			return new \WP_Error(
				'api_error',
				$body['message'] ?? 'Failed to get branch reference',
				array( 'status' => $status )
			);
		}

		return json_decode( wp_remote_retrieve_body( $response ), true );
	}

	/**
	 * Get commit data
	 *
	 * @param string $commit_sha Commit SHA.
	 *
	 * @return array|\WP_Error Commit data or error.
	 */
	private function get_commit_data( string $commit_sha ): array|\WP_Error {
		$repo_parts = $this->parse_repo();
		if ( is_wp_error( $repo_parts ) ) {
			return $repo_parts;
		}
		
		list( $owner, $repo ) = $repo_parts;
		$url = sprintf(
			'https://api.github.com/repos/%s/%s/git/commits/%s',
			$owner,
			$repo,
			$commit_sha
		);

		$response = wp_remote_get(
			$url,
			array(
				'headers' => $this->get_headers(),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			return new \WP_Error(
				'api_error',
				$body['message'] ?? 'Failed to get commit data',
				array( 'status' => $status )
			);
		}

		return json_decode( wp_remote_retrieve_body( $response ), true );
	}

	/**
	 * Create blob from content
	 *
	 * @param string $content File content (will be base64 encoded).
	 *
	 * @return string|\WP_Error Blob SHA or error.
	 */
	private function create_blob( string $content ): string|\WP_Error {
		$repo_parts = $this->parse_repo();
		if ( is_wp_error( $repo_parts ) ) {
			return $repo_parts;
		}
		
		list( $owner, $repo ) = $repo_parts;
		$url = sprintf(
			'https://api.github.com/repos/%s/%s/git/blobs',
			$owner,
			$repo
		);

		$payload = wp_json_encode( array(
			'content'  => base64_encode( $content ),
			'encoding' => 'base64',
		) );

		$response = wp_remote_post(
			$url,
			array(
				'headers' => $this->get_headers(),
				'body'    => $payload,
				'timeout' => 60, // Increased timeout for slow networks
			)
		);

		// Log detailed response information
		if ( is_wp_error( $response ) ) {
			Logger::error(
				'GitHub API request failed (create_blob)',
				array(
					'url'           => $url,
					'error_code'    => $response->get_error_code(),
					'error_message' => $response->get_error_message(),
					'timeout'       => 60,
				)
			);
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		
		Logger::info(
			'GitHub API response (create_blob)',
			array(
				'url'        => $url,
				'status'     => $status,
				'body_size'  => strlen( $body ),
			)
		);
		
		if ( 201 !== $status ) {
			$body_data = json_decode( $body, true );
			$error_message = $body_data['message'] ?? 'Failed to create blob';
			
			Logger::error(
				'GitHub API returned non-success status (create_blob)',
				array(
					'url'     => $url,
					'status'  => $status,
					'message' => $error_message,
					'body'    => $body_data,
				)
			);
			
			return new \WP_Error(
				'api_error',
				$error_message,
				array( 'status' => $status, 'body' => $body_data )
			);
		}

		$data = json_decode( $body, true );
		return $data['sha'];
	}

	/**
	 * Create tree from items
	 *
	 * @param array  $tree_items Array of tree item objects.
	 * @param string $base_tree  Base tree SHA to build upon.
	 *
	 * @return string|\WP_Error Tree SHA or error.
	 */
	private function create_tree( array $tree_items, string $base_tree ): string|\WP_Error {
		$repo_parts = $this->parse_repo();
		if ( is_wp_error( $repo_parts ) ) {
			return $repo_parts;
		}
		
		list( $owner, $repo ) = $repo_parts;
		$url = sprintf(
			'https://api.github.com/repos/%s/%s/git/trees',
			$owner,
			$repo
		);

		$payload = wp_json_encode( array(
			'base_tree' => $base_tree,
			'tree'      => $tree_items,
		) );

		$response = wp_remote_post(
			$url,
			array(
				'headers' => $this->get_headers(),
				'body'    => $payload,
				'timeout' => 60, // Increased timeout for slow networks
			)
		);

		// Log detailed response information
		if ( is_wp_error( $response ) ) {
			Logger::error(
				'GitHub API request failed (create_tree)',
				array(
					'url'           => $url,
					'error_code'    => $response->get_error_code(),
					'error_message' => $response->get_error_message(),
					'tree_items'    => count( $tree_items ),
					'timeout'       => 60,
				)
			);
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		
		Logger::info(
			'GitHub API response (create_tree)',
			array(
				'url'         => $url,
				'status'      => $status,
				'tree_items'  => count( $tree_items ),
			)
		);
		
		if ( 201 !== $status ) {
			$body_data = json_decode( $body, true );
			$error_message = $body_data['message'] ?? 'Failed to create tree';
			
			Logger::error(
				'GitHub API returned non-success status (create_tree)',
				array(
					'url'     => $url,
					'status'  => $status,
					'message' => $error_message,
					'body'    => $body_data,
				)
			);
			
			return new \WP_Error(
				'api_error',
				$error_message,
				array( 'status' => $status, 'body' => $body_data )
			);
		}

		$data = json_decode( $body, true );
		return $data['sha'];
	}

	/**
	 * Create commit
	 *
	 * @param string $message   Commit message.
	 * @param string $tree_sha  Tree SHA.
	 * @param string $parent_sha Parent commit SHA.
	 *
	 * @return string|\WP_Error Commit SHA or error.
	 */
	private function create_commit( string $message, string $tree_sha, string $parent_sha ): string|\WP_Error {
		$repo_parts = $this->parse_repo();
		if ( is_wp_error( $repo_parts ) ) {
			return $repo_parts;
		}
		
		list( $owner, $repo ) = $repo_parts;
		$url = sprintf(
			'https://api.github.com/repos/%s/%s/git/commits',
			$owner,
			$repo
		);

		$payload = wp_json_encode( array(
			'message' => $message,
			'tree'    => $tree_sha,
			'parents' => array( $parent_sha ),
		) );

		$response = wp_remote_post(
			$url,
			array(
				'headers' => $this->get_headers(),
				'body'    => $payload,
				'timeout' => 60, // Increased timeout for slow networks
			)
		);

		// Log detailed response information
		if ( is_wp_error( $response ) ) {
			Logger::error(
				'GitHub API request failed (create_commit)',
				array(
					'url'           => $url,
					'error_code'    => $response->get_error_code(),
					'error_message' => $response->get_error_message(),
					'timeout'       => 60,
				)
			);
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		
		Logger::info(
			'GitHub API response (create_commit)',
			array(
				'url'    => $url,
				'status' => $status,
			)
		);
		
		if ( 201 !== $status ) {
			$body_data = json_decode( $body, true );
			$error_message = $body_data['message'] ?? 'Failed to create commit';
			
			Logger::error(
				'GitHub API returned non-success status (create_commit)',
				array(
					'url'     => $url,
					'status'  => $status,
					'message' => $error_message,
					'body'    => $body_data,
				)
			);
			
			return new \WP_Error(
				'api_error',
				$error_message,
				array( 'status' => $status, 'body' => $body_data )
			);
		}

		$data = json_decode( $body, true );
		return $data['sha'];
	}

	/**
	 * Update branch reference to new commit
	 *
	 * @param string $commit_sha New commit SHA.
	 *
	 * @return array|\WP_Error Updated reference data or error.
	 */
	private function update_ref( string $commit_sha ): array|\WP_Error {
		$repo_parts = $this->parse_repo();
		if ( is_wp_error( $repo_parts ) ) {
			return $repo_parts;
		}
		
		list( $owner, $repo ) = $repo_parts;
		$url = sprintf(
			'https://api.github.com/repos/%s/%s/git/refs/heads/%s',
			$owner,
			$repo,
			$this->branch
		);

		$payload = wp_json_encode( array(
			'sha'   => $commit_sha,
			'force' => false, // Don't allow force push
		) );

		$response = wp_remote_request(
			$url,
			array(
				'method'  => 'PATCH',
				'headers' => $this->get_headers(),
				'body'    => $payload,
				'timeout' => 60, // Increased timeout for slow networks
			)
		);

		// Log detailed response information
		if ( is_wp_error( $response ) ) {
			Logger::error(
				'GitHub API request failed (update_ref)',
				array(
					'url'           => $url,
					'error_code'    => $response->get_error_code(),
					'error_message' => $response->get_error_message(),
					'timeout'       => 60,
				)
			);
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		
		Logger::info(
			'GitHub API response (update_ref)',
			array(
				'url'    => $url,
				'status' => $status,
			)
		);
		
		if ( 200 !== $status ) {
			$body_data = json_decode( $body, true );
			$error_message = $body_data['message'] ?? 'Failed to update reference';
			
			Logger::error(
				'GitHub API returned non-success status (update_ref)',
				array(
					'url'     => $url,
					'status'  => $status,
					'message' => $error_message,
					'body'    => $body_data,
				)
			);
			
			return new \WP_Error(
				'api_error',
				$error_message,
				array( 'status' => $status, 'body' => $body_data )
			);
		}

		return json_decode( $body, true );
	}
}

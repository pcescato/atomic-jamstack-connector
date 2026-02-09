<?php
/**
 * Token Encryption Diagnostic Tool
 *
 * This temporary file helps diagnose token encryption/decryption issues.
 * Run it via: php token-diagnostic.php
 *
 * @package AtomicJamstack
 */

// Simulate WordPress environment minimally
define( 'ABSPATH', '/path/to/wordpress/' );

/**
 * Mock wp_salt function for testing
 *
 * @param string $scheme Salt scheme.
 * @return string
 */
function wp_salt( $scheme = 'auth' ) {
	// Use fixed values for testing
	$salts = array(
		'auth'  => 'test_auth_salt_12345678901234567890123456789012',
		'nonce' => 'test_nonce_salt_12345678901234567890123456789012',
	);
	return $salts[ $scheme ] ?? 'default_salt';
}

/**
 * Encrypt token (from Settings class)
 *
 * @param string $token Plain text token.
 * @return string Encrypted token.
 */
function encrypt_token( string $token ): string {
	$method = 'AES-256-CBC';
	$key    = hash( 'sha256', wp_salt( 'auth' ), true );
	$iv     = substr( hash( 'sha256', wp_salt( 'nonce' ), true ), 0, 16 );

	$encrypted = openssl_encrypt( $token, $method, $key, 0, $iv );

	return base64_encode( $encrypted );
}

/**
 * Decrypt token (from Git_API class)
 *
 * @param string $encrypted_token Encrypted token.
 * @return string|false Decrypted token or false.
 */
function decrypt_token( string $encrypted_token ) {
	$method = 'AES-256-CBC';
	$key    = hash( 'sha256', wp_salt( 'auth' ), true );
	$iv     = substr( hash( 'sha256', wp_salt( 'nonce' ), true ), 0, 16 );

	$decoded   = base64_decode( $encrypted_token, true );
	
	if ( false === $decoded ) {
		return false;
	}
	
	$decrypted = openssl_decrypt( $decoded, $method, $key, 0, $iv );

	return $decrypted !== false ? $decrypted : false;
}

// Test
echo "=== Token Encryption/Decryption Diagnostic ===\n\n";

$test_token = 'ghp_test_token_1234567890abcdef';
echo "Original token: {$test_token}\n";
echo "Original length: " . strlen( $test_token ) . "\n\n";

$encrypted = encrypt_token( $test_token );
echo "Encrypted token: {$encrypted}\n";
echo "Encrypted length: " . strlen( $encrypted ) . "\n\n";

$decrypted = decrypt_token( $encrypted );
echo "Decrypted token: " . ( $decrypted !== false ? $decrypted : 'FAILED' ) . "\n";
echo "Decrypted length: " . ( $decrypted !== false ? strlen( $decrypted ) : 0 ) . "\n";
echo "Match: " . ( $decrypted === $test_token ? 'YES ✓' : 'NO ✗' ) . "\n\n";

// Test with GitHub token format
$github_token = 'ghp_1234567890abcdefghijklmnopqrstuvwxyz1234';
echo "GitHub token test:\n";
echo "Original: {$github_token}\n";
echo "Original length: " . strlen( $github_token ) . "\n";

$encrypted_gh = encrypt_token( $github_token );
echo "Encrypted length: " . strlen( $encrypted_gh ) . "\n";

$decrypted_gh = decrypt_token( $encrypted_gh );
echo "Decrypted: " . ( $decrypted_gh !== false ? $decrypted_gh : 'FAILED' ) . "\n";
echo "Match: " . ( $decrypted_gh === $github_token ? 'YES ✓' : 'NO ✗' ) . "\n\n";

// Test what happens if token gets double-encrypted
echo "=== Double Encryption Test (the bug?) ===\n";
$double_encrypted = encrypt_token( $encrypted );
echo "Double encrypted length: " . strlen( $double_encrypted ) . "\n";

$attempt_decrypt_once = decrypt_token( $double_encrypted );
echo "Decrypt once: " . ( $attempt_decrypt_once !== false ? substr( $attempt_decrypt_once, 0, 50 ) . '...' : 'FAILED' ) . "\n";

if ( $attempt_decrypt_once !== false ) {
	$attempt_decrypt_twice = decrypt_token( $attempt_decrypt_once );
	echo "Decrypt twice: " . ( $attempt_decrypt_twice !== false ? $attempt_decrypt_twice : 'FAILED' ) . "\n";
	echo "Match after double decrypt: " . ( $attempt_decrypt_twice === $test_token ? 'YES ✓' : 'NO ✗' ) . "\n";
}

echo "\n=== Diagnostic Complete ===\n";

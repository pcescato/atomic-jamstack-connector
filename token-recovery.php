<?php
/**
 * Token Recovery Script
 *
 * If your GitHub token got double-encrypted, run this script to fix it.
 *
 * IMPORTANT: Before running, back up your database!
 *
 * To run: php token-recovery.php from WordPress root
 *
 * @package AtomicJamstack
 */

// Load WordPress
require_once __DIR__ . '/../../../wp-load.php';

if ( ! defined( 'ABSPATH' ) ) {
	die( 'WordPress not loaded' );
}

if ( php_sapi_name() !== 'cli' ) {
	die( 'This script must be run from command line' );
}

echo "=== GitHub Token Recovery Tool ===\n\n";

$settings = get_option( 'atomic_jamstack_settings', array() );

if ( empty( $settings['github_token'] ) ) {
	echo "❌ No GitHub token found in settings.\n";
	exit( 1 );
}

$stored_token = $settings['github_token'];
$token_length = strlen( $stored_token );

echo "Current stored token length: {$token_length} characters\n\n";

// Decryption function (from Git_API)
function decrypt_token( $encrypted_token ) {
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

// Try to decrypt
$decrypted = decrypt_token( $stored_token );

if ( false === $decrypted || empty( $decrypted ) ) {
	echo "❌ Failed to decrypt token. It may be corrupted or use different encryption.\n";
	exit( 1 );
}

$decrypted_length = strlen( $decrypted );
echo "After first decryption: {$decrypted_length} characters\n";
echo "Preview: " . substr( $decrypted, 0, 15 ) . "...\n";

// Check if it looks like a plain GitHub token (if so, we're done - single encryption)
$is_plain_text = (
	str_starts_with( $decrypted, 'github_pat_' ) ||
	str_starts_with( $decrypted, 'ghp_' )
);

if ( $is_plain_text ) {
	echo "✅ Token appears to be correctly encrypted (single encryption).\n";
	echo "Token starts with: " . substr( $decrypted, 0, 10 ) . "...\n";
	exit( 0 );
}

echo "⚠️  Token appears to be DOUBLE-ENCRYPTED (doesn't start with 'github_pat_' or 'ghp_')!\n";
echo "Attempting second decryption...\n\n";

$double_decrypted = decrypt_token( $decrypted );

if ( false === $double_decrypted || empty( $double_decrypted ) ) {
	echo "❌ Second decryption failed. Manual intervention required.\n";
	exit( 1 );
}

$final_length = strlen( $double_decrypted );
echo "After second decryption: {$final_length} characters\n";
echo "Preview: " . substr( $double_decrypted, 0, 10 ) . "...\n\n";

// Validate it looks like a GitHub token
if ( ! str_starts_with( $double_decrypted, 'ghp_' ) && ! str_starts_with( $double_decrypted, 'github_pat_' ) ) {
	echo "⚠️  Warning: Decrypted token doesn't start with 'ghp_' or 'github_pat_'\n";
	echo "It may not be a valid GitHub token.\n";
	echo "Continue anyway? (yes/no): ";
	$response = trim( fgets( STDIN ) );
	if ( strtolower( $response ) !== 'yes' ) {
		echo "Aborted.\n";
		exit( 0 );
	}
}

echo "Do you want to fix the double-encryption? (yes/no): ";
$response = trim( fgets( STDIN ) );

if ( strtolower( $response ) !== 'yes' ) {
	echo "Aborted. No changes made.\n";
	exit( 0 );
}

// Re-encrypt correctly (single encryption)
require_once ATOMIC_JAMSTACK_PATH . 'admin/class-settings.php';
$correctly_encrypted = \AtomicJamstack\Admin\Settings::encrypt_token( $double_decrypted );

// But wait - encrypt_token is private. Let's just use the decrypt result
// and store it encrypted once
function encrypt_token( $token ) {
	$method = 'AES-256-CBC';
	$key    = hash( 'sha256', wp_salt( 'auth' ), true );
	$iv     = substr( hash( 'sha256', wp_salt( 'nonce' ), true ), 0, 16 );

	$encrypted = openssl_encrypt( $token, $method, $key, 0, $iv );

	return base64_encode( $encrypted );
}

$correctly_encrypted = encrypt_token( $double_decrypted );

// Save
$settings['github_token'] = $correctly_encrypted;

// CRITICAL: Use update_option with FALSE to bypass sanitize_settings callback
// Otherwise it will re-encrypt!
remove_all_filters( 'sanitize_option_atomic_jamstack_settings' );
update_option( 'atomic_jamstack_settings', $settings, false );

echo "\n✅ Token fixed! Stored length: " . strlen( $correctly_encrypted ) . " characters\n";
echo "✅ The token is now correctly encrypted (single encryption).\n";
echo "✅ Please test your GitHub connection.\n";

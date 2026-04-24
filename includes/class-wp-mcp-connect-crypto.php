<?php
defined( 'ABSPATH' ) || exit;

/**
 * AES-256-GCM encryption helpers for plugin-stored secrets.
 *
 * Key is derived from the WordPress AUTH_KEY + SECURE_AUTH_KEY constants via
 * HKDF-SHA256. The caller supplies a context string so different kinds of
 * secret don't share a derived key (domain separation).
 *
 * Storage format: base64( iv(12) || tag(16) || ciphertext ).
 *
 * @since   1.0.1
 * @package WP_MCP_Connect
 */
class WP_MCP_Connect_Crypto {

	/**
	 * Encrypt a plaintext secret under the given context.
	 *
	 * @param string $plaintext Secret bytes to encrypt.
	 * @param string $context   Domain-separation string (e.g. 'cwp_webhook_secret_v1').
	 * @return string|WP_Error  Base64-encoded ciphertext on success.
	 */
	public static function encrypt( $plaintext, $context ) {
		if ( '' === (string) $plaintext ) {
			return '';
		}

		$key = self::get_key( $context );
		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$iv        = random_bytes( 12 );
		$tag       = '';
		$encrypted = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
		if ( false === $encrypted ) {
			return new WP_Error( 'encrypt_failed', 'Encryption failed.' );
		}

		return base64_encode( $iv . $tag . $encrypted );
	}

	/**
	 * Decrypt a stored ciphertext under the given context.
	 *
	 * @param string $encrypted Base64-encoded ciphertext.
	 * @param string $context   Same domain-separation string used at encrypt time.
	 * @return string|WP_Error  Plaintext on success. Empty string for empty input.
	 */
	public static function decrypt( $encrypted, $context ) {
		if ( '' === (string) $encrypted ) {
			return '';
		}

		$key = self::get_key( $context );
		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$data = base64_decode( $encrypted, true );
		if ( false === $data || strlen( $data ) < 28 ) {
			return new WP_Error( 'decrypt_malformed', 'Malformed ciphertext.' );
		}

		$iv             = substr( $data, 0, 12 );
		$tag            = substr( $data, 12, 16 );
		$encrypted_data = substr( $data, 28 );

		$decrypted = openssl_decrypt( $encrypted_data, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $decrypted ) {
			return new WP_Error( 'decrypt_failed', 'Decryption failed (tampered ciphertext or key rotation).' );
		}

		return $decrypted;
	}

	/**
	 * Derive a 32-byte key from AUTH_KEY + SECURE_AUTH_KEY for the given context.
	 *
	 * @param string $context Domain-separation string.
	 * @return string|WP_Error Raw 32-byte key on success.
	 */
	private static function get_key( $context ) {
		$auth_key        = defined( 'AUTH_KEY' ) ? AUTH_KEY : '';
		$secure_auth_key = defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '';

		if ( '' === $auth_key && '' === $secure_auth_key ) {
			return new WP_Error(
				'encryption_keys_missing',
				'WordPress security keys (AUTH_KEY, SECURE_AUTH_KEY) must be configured in wp-config.php.'
			);
		}

		if ( 'put your unique phrase here' === $auth_key ||
			'put your unique phrase here' === $secure_auth_key ) {
			return new WP_Error(
				'encryption_keys_default',
				'WordPress security keys are using default placeholder values.'
			);
		}

		$ikm = $auth_key . $secure_auth_key;

		if ( function_exists( 'hash_hkdf' ) ) {
			return hash_hkdf( 'sha256', $ikm, 32, (string) $context );
		}

		// Plugin requires PHP 7.4+, so hash_hkdf is always present. This branch
		// exists only as an extreme defensive fallback.
		return new WP_Error( 'hkdf_unavailable', 'hash_hkdf() is required.' );
	}
}

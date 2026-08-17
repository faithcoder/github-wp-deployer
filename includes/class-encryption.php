<?php
/**
 * Token encryption using Sodium secretbox keyed from WordPress salts.
 *
 * @package GitHubWPDeployer
 */

namespace GitHubWPDeployer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encrypts and decrypts secrets at rest.
 *
 * Uses libsodium secretbox with a key derived from AUTH_KEY + AUTH_SALT.
 * When libsodium is unavailable the caller falls back to plaintext storage,
 * which is surfaced to the administrator as a warning.
 */
final class Encryption {

	const PREFIX = 'sodium:';

	/**
	 * Whether encryption can be used.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'sodium_crypto_secretbox' )
			&& defined( 'AUTH_KEY' ) && '' !== AUTH_KEY
			&& defined( 'AUTH_SALT' ) && '' !== AUTH_SALT;
	}

	/**
	 * Encrypt a plaintext value.
	 *
	 * @param string $plaintext Value to encrypt.
	 * @return string|false Encoded ciphertext, or false when unavailable.
	 */
	public static function encrypt( $plaintext ) {
		if ( ! self::is_available() ) {
			return false;
		}

		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$key   = self::derive_key();

		$ciphertext = sodium_crypto_secretbox( (string) $plaintext, $nonce, $key );

		sodium_memzero( $key );

		return self::PREFIX . base64_encode( $nonce . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding binary ciphertext for storage.
	}

	/**
	 * Decrypt an encoded value produced by encrypt().
	 *
	 * @param string $value Encoded value.
	 * @return string|false Plaintext, or false on failure.
	 */
	public static function decrypt( $value ) {
		if ( ! is_string( $value ) || ! self::is_available() ) {
			return false;
		}

		if ( 0 !== strpos( $value, self::PREFIX ) ) {
			return false;
		}

		$decoded = base64_decode( substr( $value, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding stored binary ciphertext.
		if ( false === $decoded || strlen( $decoded ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return false;
		}

		$nonce      = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$key        = self::derive_key();

		$plaintext = sodium_crypto_secretbox_open( $ciphertext, $nonce, $key );

		sodium_memzero( $key );

		if ( false === $plaintext ) {
			return false;
		}

		return $plaintext;
	}

	/**
	 * Derive a stable 32-byte key from the WordPress auth salts.
	 *
	 * @return string
	 */
	private static function derive_key() {
		return sodium_crypto_generichash( AUTH_KEY . AUTH_SALT, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}

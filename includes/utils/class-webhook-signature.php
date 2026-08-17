<?php
/**
 * Webhook signature verification and replay protection.
 *
 * @package GitHubWPDeployer
 */

namespace GitHubWPDeployer\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HMAC-SHA256 webhook signature helpers.
 */
final class WebhookSignature {

	/**
	 * Verify a GitHub X-Hub-Signature-256 header.
	 *
	 * @param string $secret        Webhook secret.
	 * @param string $raw_body      Raw request body.
	 * @param string $signature     Header value, e.g. "sha256=abcd...".
	 * @return bool
	 */
	public static function verify( $secret, $raw_body, $signature ) {
		if ( ! is_string( $secret ) || '' === $secret || ! is_string( $signature ) ) {
			return false;
		}

		if ( 0 !== strpos( $signature, 'sha256=' ) ) {
			return false;
		}

		$provided = substr( $signature, strlen( 'sha256=' ) );

		if ( '' === $provided ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', (string) $raw_body, $secret );

		return hash_equals( $expected, $provided );
	}

	/**
	 * Generate a high-entropy webhook secret.
	 *
	 * @return string
	 */
	public static function generate_secret() {
		return bin2hex( random_bytes( 32 ) );
	}
}

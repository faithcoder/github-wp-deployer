<?php
/**
 * Webhook signature verification tests.
 *
 * @package GitHubWPDeployer
 */

namespace GitHubWPDeployer\Tests;

use GitHubWPDeployer\Utils\WebhookSignature;
use PHPUnit\Framework\TestCase;

final class SignatureTest extends TestCase {

	public function test_verifies_valid_signature() {
		$secret = 's3cret';
		$body   = '{"ref":"refs/heads/main"}';

		$signature = 'sha256=' . hash_hmac( 'sha256', $body, $secret );

		$this->assertTrue( WebhookSignature::verify( $secret, $body, $signature ) );
	}

	public function test_rejects_wrong_secret() {
		$signature = 'sha256=' . hash_hmac( 'sha256', 'body', 'right-secret' );

		$this->assertFalse( WebhookSignature::verify( 'wrong-secret', 'body', $signature ) );
	}

	public function test_rejects_missing_prefix() {
		$this->assertFalse( WebhookSignature::verify( 's', 'body', hash_hmac( 'sha256', 'body', 's' ) ) );
	}

	public function test_rejects_empty_secret() {
		$this->assertFalse( WebhookSignature::verify( '', 'body', 'sha256=abc' ) );
	}

	public function test_generated_secret_is_64_hex_chars() {
		$secret = WebhookSignature::generate_secret();

		$this->assertSame( 64, strlen( $secret ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $secret );
	}
}

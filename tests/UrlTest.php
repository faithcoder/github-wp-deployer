<?php
/**
 * URL parsing tests.
 *
 * @package GitHubWPDeployer
 */

namespace GitHubWPDeployer\Tests;

use GitHubWPDeployer\Utils\Url;
use PHPUnit\Framework\TestCase;

final class UrlTest extends TestCase {

	public function test_parses_https_url() {
		$result = Url::parse( 'https://github.com/WordPress/two-factor' );

		$this->assertSame( 'WordPress', $result['owner'] );
		$this->assertSame( 'two-factor', $result['repo'] );
	}

	public function test_parses_git_suffix() {
		$result = Url::parse( 'https://github.com/acme/my-plugin.git' );

		$this->assertSame( 'acme', $result['owner'] );
		$this->assertSame( 'my-plugin', $result['repo'] );
	}

	public function test_parses_tree_url() {
		$result = Url::parse( 'https://github.com/acme/my-plugin/tree/main' );

		$this->assertSame( 'acme', $result['owner'] );
		$this->assertSame( 'my-plugin', $result['repo'] );
	}

	public function test_parses_shorthand() {
		$result = Url::parse( 'acme/my-plugin' );

		$this->assertSame( 'acme', $result['owner'] );
		$this->assertSame( 'my-plugin', $result['repo'] );
	}

	public function test_rejects_non_github_host() {
		$this->assertFalse( Url::parse( 'https://gitlab.com/acme/my-plugin' ) );
		$this->assertFalse( Url::parse( 'https://example.com/acme/my-plugin' ) );
	}

	public function test_rejects_missing_repo() {
		$this->assertFalse( Url::parse( 'https://github.com/acme' ) );
	}

	public function test_rejects_traversal_in_repo() {
		$this->assertFalse( Url::parse( 'https://github.com/acme/../evil' ) );
		$this->assertFalse( Url::parse( 'acme/..' ) );
	}

	public function test_rejects_invalid_owner() {
		$this->assertFalse( Url::parse( 'https://github.com/-bad/repo' ) );
		$this->assertFalse( Url::parse( 'https://github.com/.bad/repo' ) );
	}

	public function test_normalizes_case_insensitive_domain() {
		$result = Url::parse( 'HTTP://GITHUB.COM/acme/repo' );

		$this->assertSame( 'acme', $result['owner'] );
		$this->assertSame( 'repo', $result['repo'] );
	}
}

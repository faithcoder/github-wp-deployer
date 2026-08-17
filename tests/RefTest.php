<?php
/**
 * Ref validation and branch matching tests.
 *
 * @package GitHubWPDeployer
 */

namespace GitHubWPDeployer\Tests;

use GitHubWPDeployer\Utils\Ref;
use PHPUnit\Framework\TestCase;

final class RefTest extends TestCase {

	public function test_accepts_valid_refs() {
		$this->assertTrue( Ref::validate( 'main' ) );
		$this->assertTrue( Ref::validate( 'feature/my-branch' ) );
		$this->assertTrue( Ref::validate( 'v1.2.3' ) );
	}

	public function test_rejects_traversal() {
		$this->assertFalse( Ref::validate( '..' ) );
		$this->assertFalse( Ref::validate( '../etc' ) );
		$this->assertFalse( Ref::validate( 'foo/../bar' ) );
	}

	public function test_rejects_leading_dot_and_slash() {
		$this->assertFalse( Ref::validate( '.hidden' ) );
		$this->assertFalse( Ref::validate( '/main' ) );
		$this->assertFalse( Ref::validate( 'main/' ) );
	}

	public function test_rejects_control_and_meta_chars() {
		$this->assertFalse( Ref::validate( "ma\nin" ) );
		$this->assertFalse( Ref::validate( 'main~' ) );
		$this->assertFalse( Ref::validate( 'main^' ) );
		$this->assertFalse( Ref::validate( 'ma:in' ) );
	}

	public function test_branch_matches() {
		$this->assertTrue( Ref::branch_matches( 'refs/heads/main', 'main' ) );
		$this->assertTrue( Ref::branch_matches( 'refs/heads/feature/x', 'feature/x' ) );
	}

	public function test_branch_mismatch() {
		$this->assertFalse( Ref::branch_matches( 'refs/heads/main', 'develop' ) );
		$this->assertFalse( Ref::branch_matches( 'refs/tags/v1.0', 'v1.0' ) );
		$this->assertFalse( Ref::branch_matches( 'main', 'main' ) );
	}

	public function test_branch_from_ref() {
		$this->assertSame( 'main', Ref::branch_from_ref( 'refs/heads/main' ) );
		$this->assertSame( '', Ref::branch_from_ref( 'refs/tags/v1.0' ) );
	}
}

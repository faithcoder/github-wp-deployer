<?php
/**
 * Slug validation tests.
 *
 * @package PushWP
 */

namespace PushWP\Tests;

use PushWP\Utils\Slug;
use PHPUnit\Framework\TestCase;

final class SlugTest extends TestCase {

	public function test_accepts_valid_slugs() {
		$this->assertTrue( Slug::validate( 'two-factor' ) );
		$this->assertTrue( Slug::validate( 'my_plugin' ) );
		$this->assertTrue( Slug::validate( 'Plugin_Name-2' ) );
	}

	public function test_rejects_empty() {
		$this->assertFalse( Slug::validate( '' ) );
	}

	public function test_rejects_traversal() {
		$this->assertFalse( Slug::validate( '..' ) );
		$this->assertFalse( Slug::validate( '../evil' ) );
		$this->assertFalse( Slug::validate( 'a/b' ) );
	}

	public function test_rejects_leading_punctuation() {
		$this->assertFalse( Slug::validate( '-leading' ) );
		$this->assertFalse( Slug::validate( '_leading' ) );
	}

	public function test_rejects_too_long() {
		$this->assertFalse( Slug::validate( str_repeat( 'a', 65 ) ) );
	}

	public function test_sanitize_derives_stable_slug() {
		$this->assertSame( 'my-cool-plugin', Slug::sanitize( 'My Cool Plugin!' ) );
		$this->assertSame( '', Slug::sanitize( '!!!' ) );
	}

	public function test_from_name() {
		$this->assertSame( 'two-factor', Slug::from_name( 'Two Factor' ) );
	}
}

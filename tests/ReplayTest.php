<?php
/**
 * Replay protection tests.
 *
 * @package PushWP
 */

namespace PushWP\Tests;

use PushWP\Utils\ReplayGuard;
use PHPUnit\Framework\TestCase;

final class ReplayTest extends TestCase {

	public function test_first_occurrence_is_not_replay() {
		$registry = array();
		$now      = 1000;

		$this->assertFalse( ReplayGuard::is_replay( 'delivery-1', $registry, $now ) );
		$this->assertArrayHasKey( 'delivery-1', $registry );
	}

	public function test_duplicate_is_replay() {
		$registry = array();
		$now      = 1000;

		ReplayGuard::is_replay( 'delivery-1', $registry, $now );

		$this->assertTrue( ReplayGuard::is_replay( 'delivery-1', $registry, $now + 1 ) );
	}

	public function test_expired_entries_pruned() {
		$registry = array();
		$now      = 1000;

		ReplayGuard::is_replay( 'old', $registry, $now );

		// Advance past the TTL; a new delivery should prune the old entry.
		ReplayGuard::is_replay( 'new', $registry, $now + ReplayGuard::TTL + 1 );

		$this->assertArrayNotHasKey( 'old', $registry );
		$this->assertArrayHasKey( 'new', $registry );
	}

	public function test_empty_delivery_is_replay() {
		$registry = array();

		$this->assertTrue( ReplayGuard::is_replay( '', $registry, 1000 ) );
	}
}

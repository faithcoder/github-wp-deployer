<?php
/**
 * Replay protection for webhook delivery IDs.
 *
 * @package PushWP
 */

namespace PushWP\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replay protection for GitHub webhook delivery IDs.
 */
final class ReplayGuard {

	const TTL = DAY_IN_SECONDS;

	/**
	 * Check whether a delivery ID has already been processed.
	 *
	 * The registry is a map of delivery ID => expiration timestamp. This is a
	 * pure function so it can be unit tested against an in-memory store.
	 *
	 * @param string $delivery_id Delivery ID.
	 * @param array  $registry    Reference to the registry map.
	 * @param int    $now         Current timestamp.
	 * @return bool True when the ID is a replay (already seen).
	 */
	public static function is_replay( $delivery_id, array &$registry, $now ) {
		if ( ! is_string( $delivery_id ) || '' === $delivery_id ) {
			return true;
		}

		if ( isset( $registry[ $delivery_id ] ) && $registry[ $delivery_id ] > $now ) {
			return true;
		}

		$registry[ $delivery_id ] = $now + self::TTL;

		// Prune expired entries opportunistically.
		foreach ( $registry as $id => $expires ) {
			if ( $expires <= $now ) {
				unset( $registry[ $id ] );
			}
		}

		return false;
	}
}

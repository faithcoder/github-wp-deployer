<?php
/**
 * Git ref (branch/tag) validation and matching.
 *
 * @package PushWP
 */

namespace PushWP\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates branch/tag names and matches pushed refs.
 */
final class Ref {

	/**
	 * Validate a branch or tag name against GitHub rules.
	 *
	 * @param string $ref Branch or tag name.
	 * @return bool
	 */
	public static function validate( $ref ) {
		if ( ! is_string( $ref ) || '' === $ref ) {
			return false;
		}

		if ( strlen( $ref ) > 255 ) {
			return false;
		}

		// Reject traversal, control chars, and Git-ref metacharacters.
		if ( preg_match( '/[\x00-\x1f\x7f~^:?*\[\]\\\\]/', $ref ) ) {
			return false;
		}

		if ( 0 === strpos( $ref, '/' ) || '/' === substr( $ref, -1 ) ) {
			return false;
		}

		if ( 0 === strpos( $ref, '.' ) || strpos( $ref, '..' ) !== false ) {
			return false;
		}

		if ( strpos( $ref, '@{' ) !== false || strpos( $ref, '//' ) !== false ) {
			return false;
		}

		if ( '.' === substr( $ref, -1 ) || '.lock' === substr( $ref, -5 ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether a webhook push ref matches a configured branch.
	 *
	 * @param string $pushed_ref    Full ref from the webhook, e.g. "refs/heads/main".
	 * @param string $configured_ref Configured branch name, e.g. "main".
	 * @return bool
	 */
	public static function branch_matches( $pushed_ref, $configured_ref ) {
		if ( ! is_string( $pushed_ref ) || ! is_string( $configured_ref ) ) {
			return false;
		}

		if ( 0 !== strpos( $pushed_ref, 'refs/heads/' ) ) {
			return false;
		}

		$branch = substr( $pushed_ref, strlen( 'refs/heads/' ) );

		return hash_equals( $configured_ref, $branch );
	}

	/**
	 * Extract the branch name from a full ref.
	 *
	 * @param string $pushed_ref Full ref.
	 * @return string Empty string for non-branch refs.
	 */
	public static function branch_from_ref( $pushed_ref ) {
		if ( ! is_string( $pushed_ref ) || 0 !== strpos( $pushed_ref, 'refs/heads/' ) ) {
			return '';
		}

		return substr( $pushed_ref, strlen( 'refs/heads/' ) );
	}
}

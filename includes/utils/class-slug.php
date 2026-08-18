<?php
/**
 * Destination slug validation.
 *
 * @package PushWP
 */

namespace PushWP\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates and normalizes plugin/theme destination slugs.
 */
final class Slug {

	/**
	 * Validate a destination slug.
	 *
	 * WordPress plugin and theme directories allow lowercase letters, digits,
	 * underscores and hyphens. The slug must not start with a digit-only token
	 * that could be confused with a reserved name and must be reasonably short.
	 *
	 * @param string $slug Candidate slug.
	 * @return bool
	 */
	public static function validate( $slug ) {
		if ( ! is_string( $slug ) || '' === $slug ) {
			return false;
		}

		if ( strlen( $slug ) > 64 ) {
			return false;
		}

		return 1 === preg_match( '/^[a-z0-9][a-z0-9_-]*$/i', $slug );
	}

	/**
	 * Sanitize an arbitrary string into a valid slug.
	 *
	 * @param string $value Raw value.
	 * @return string Empty string when nothing usable remains.
	 */
	public static function sanitize( $value ) {
		$slug = strtolower( (string) $value );
		$slug = preg_replace( '/[^a-z0-9_-]+/', '-', $slug );
		$slug = trim( $slug, '-_' );

		if ( ! is_string( $slug ) || '' === $slug ) {
			return '';
		}

		return substr( $slug, 0, 64 );
	}

	/**
	 * Derive a stable slug from a detected package name.
	 *
	 * @param string $name Detected name.
	 * @return string Empty string when derivation fails.
	 */
	public static function from_name( $name ) {
		$slug = self::sanitize( $name );

		if ( '' === $slug ) {
			return '';
		}

		return $slug;
	}
}

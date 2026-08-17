<?php
/**
 * GitHub repository URL parsing and validation.
 *
 * @package GitHubWPDeployer
 */

namespace GitHubWPDeployer\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses and validates GitHub repository URLs.
 */
final class Url {

	/**
	 * Parse a repository URL or shorthand into owner/repository.
	 *
	 * Accepted forms:
	 *   - https://github.com/owner/repository
	 *   - https://github.com/owner/repository.git
	 *   - https://github.com/owner/repository/tree/main
	 *   - owner/repository
	 *
	 * @param string $input User input.
	 * @return array{owner:string,repo:string}|false False when invalid.
	 */
	public static function parse( $input ) {
		$input = trim( (string) $input );

		if ( '' === $input ) {
			return false;
		}

		$path = $input;

		if ( preg_match( '#^(?:https?://)?github\.com/(.+)$#i', $input, $m ) ) {
			$path = $m[1];
		} elseif ( preg_match( '#^(?:[a-z0-9_.-]+)/([a-z0-9_.-]+)$#i', $input ) ) {
			$path = $input;
		} else {
			return false;
		}

		$path = trim( $path, '/' );
		$path = preg_replace( '#\.git$#i', '', $path );

		if ( ! is_string( $path ) || '' === $path ) {
			return false;
		}

		$parts = explode( '/', $path );

		if ( count( $parts ) < 2 ) {
			return false;
		}

		$owner = $parts[0];
		$repo  = $parts[1];

		if ( ! self::is_valid_owner( $owner ) || ! self::is_valid_repo( $repo ) ) {
			return false;
		}

		return array(
			'owner' => $owner,
			'repo'  => $repo,
		);
	}

	/**
	 * Validate a GitHub owner (user or organization) name.
	 *
	 * @param string $owner Owner name.
	 * @return bool
	 */
	public static function is_valid_owner( $owner ) {
		return 1 === preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,38})[a-z0-9]?$/i', (string) $owner );
	}

	/**
	 * Validate a GitHub repository name.
	 *
	 * @param string $repo Repository name.
	 * @return bool
	 */
	public static function is_valid_repo( $repo ) {
		if ( '.' === $repo || '..' === $repo ) {
			return false;
		}

		return 1 === preg_match( '/^[a-z0-9_.-]{1,100}$/i', (string) $repo );
	}

	/**
	 * Normalize a GitHub full name (owner/repository).
	 *
	 * @param string $full_name Full name.
	 * @return array{owner:string,repo:string}|false
	 */
	public static function parse_full_name( $full_name ) {
		$full_name = trim( (string) $full_name, '/' );

		if ( 2 !== count( explode( '/', $full_name ) ) ) {
			return false;
		}

		return self::parse( 'https://github.com/' . $full_name );
	}
}

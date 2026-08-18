<?php
/**
 * GitHub API client built on the WordPress HTTP API.
 *
 * @package PushWP
 */

namespace PushWP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper over wp_remote_request for api.github.com.
 */
final class GitHubClient {

	const API_BASE = 'https://api.github.com/';

	/**
	 * Settings store.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Request timeout for API calls (seconds).
	 *
	 * @return int
	 */
	public function api_timeout() {
		return (int) apply_filters( 'pushwp_api_timeout', 20 );
	}

	/**
	 * Request timeout for archive downloads (seconds).
	 *
	 * @return int
	 */
	public function download_timeout() {
		return (int) apply_filters( 'pushwp_download_timeout', 300 );
	}

	/**
	 * Maximum archive size in bytes.
	 *
	 * @return int
	 */
	public function max_archive_bytes() {
		return (int) apply_filters( 'pushwp_max_archive_bytes', 50 * MB_IN_BYTES );
	}

	/**
	 * Perform an authenticated request against the GitHub API.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $path   API path, e.g. "user".
	 * @param array<string, mixed> $args   Extra request args.
	 * @return array|WP_Error Decoded response body or error.
	 */
	public function request( $method, $path, $args = array() ) {
		$url = self::API_BASE . ltrim( $path, '/' );

		$headers = array(
			'Accept'               => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
			'User-Agent'           => 'pushwp/' . PUSHWP_VERSION,
		);

		$token = $this->settings->get_token();
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$args = wp_parse_args(
			$args,
			array(
				'method'  => $method,
				'headers' => $headers,
				'timeout' => $this->api_timeout(),
			)
		);

		$response = wp_remote_request( $url, $args );

		return $this->parse_response( $response );
	}

	/**
	 * Parse a raw HTTP response into data or a WP_Error.
	 *
	 * @param array|WP_Error $response Response from the HTTP API.
	 * @return array|WP_Error
	 */
	private function parse_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		$data = json_decode( $body, true );

		if ( $code >= 400 ) {
			return $this->error_from_status( $code, $data );
		}

		if ( ! is_array( $data ) && ! is_object( $data ) ) {
			return new \WP_Error( 'github_invalid_response', __( 'GitHub returned an unexpected response.', 'pushwp' ) );
		}

		return (array) $data;
	}

	/**
	 * Build a descriptive WP_Error from an HTTP status.
	 *
	 * @param int   $code HTTP status.
	 * @param mixed $data Decoded body.
	 * @return WP_Error
	 */
	private function error_from_status( $code, $data ) {
		$message = '';

		if ( is_array( $data ) && isset( $data['message'] ) ) {
			$message = $data['message'];
		}

		switch ( $code ) {
			case 401:
				return new \WP_Error( 'github_auth', __( 'GitHub authentication failed. The token may be expired or revoked. Please reconnect GitHub.', 'pushwp' ) );
			case 403:
				if ( false !== stripos( $message, 'rate limit' ) || false !== stripos( $message, 'secondary rate limit' ) ) {
					return new \WP_Error( 'github_rate_limit', __( 'GitHub API rate limit reached. Please wait and try again later.', 'pushwp' ) );
				}
				return new \WP_Error( 'github_forbidden', __( 'GitHub access denied. This repository may be private or the token lacks the required scope.', 'pushwp' ) );
			case 404:
				return new \WP_Error( 'github_not_found', __( 'GitHub repository or reference was not found. Check the URL and branch/tag.', 'pushwp' ) );
			default:
				return new \WP_Error( 'github_http_' . $code, sprintf( /* translators: %d: HTTP status code. */ __( 'GitHub request failed with status %d.', 'pushwp' ), $code ) );
		}
	}

	/**
	 * Get the authenticated GitHub user.
	 *
	 * @return array|WP_Error
	 */
	public function get_user() {
		return $this->request( 'GET', 'user' );
	}

	/**
	 * Resolve a branch, tag, or SHA to its latest commit SHA.
	 *
	 * @param string $owner Owner.
	 * @param string $repo  Repository.
	 * @param string $ref   Branch or tag name.
	 * @return string|WP_Error Commit SHA.
	 */
	public function resolve_ref( $owner, $repo, $ref ) {
		$path = sprintf( 'repos/%s/%s/commits/%s', rawurlencode( $owner ), rawurlencode( $repo ), rawurlencode( $ref ) );

		$result = $this->request( 'GET', $path );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! isset( $result['sha'] ) || ! is_string( $result['sha'] ) ) {
			return new \WP_Error( 'github_invalid_commit', __( 'Could not resolve the commit SHA for the requested branch or tag.', 'pushwp' ) );
		}

		return $result['sha'];
	}

	/**
	 * Get the latest release (optionally excluding prereleases).
	 *
	 * @param string $owner             Owner.
	 * @param string $repo              Repository.
	 * @param bool   $include_prerelease Whether to allow prereleases.
	 * @return array|WP_Error Release data.
	 */
	public function get_latest_release( $owner, $repo, $include_prerelease = false ) {
		if ( $include_prerelease ) {
			$result = $this->request( 'GET', sprintf( 'repos/%s/%s/releases/latest', rawurlencode( $owner ), rawurlencode( $repo ) ) );
		} else {
			$result = $this->request( 'GET', sprintf( 'repos/%s/%s/releases', rawurlencode( $owner ), rawurlencode( $repo ) ) );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			foreach ( $result as $release ) {
				if ( is_array( $release ) && empty( $release['prerelease'] ) && empty( $release['draft'] ) ) {
					return $release;
				}
			}

			return new \WP_Error( 'github_no_release', __( 'No stable GitHub release found for this repository.', 'pushwp' ) );
		}

		return $result;
	}

	/**
	 * Download an archive of a repository ref to a local file.
	 *
	 * Uses the authenticated zipball endpoint so private repositories work.
	 *
	 * @param string $owner     Owner.
	 * @param string $repo      Repository.
	 * @param string $ref       Branch, tag, or SHA.
	 * @param string $dest_file Destination path (temporary).
	 * @return true|WP_Error
	 */
	public function download_archive( $owner, $repo, $ref, $dest_file ) {
		$url = sprintf(
			'%srepos/%s/%s/zipball/%s',
			self::API_BASE,
			rawurlencode( $owner ),
			rawurlencode( $repo ),
			rawurlencode( $ref )
		);

		$headers = array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'pushwp/' . PUSHWP_VERSION,
		);

		$token = $this->settings->get_token();
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$limit = $this->max_archive_bytes();

		// Best-effort early rejection using Content-Length.
		$head = wp_remote_head(
			$url,
			array(
				'headers'     => $headers,
				'timeout'     => $this->api_timeout(),
				'redirection' => 5,
			)
		);

		if ( ! is_wp_error( $head ) ) {
			$content_length = wp_remote_retrieve_header( $head, 'content-length' );
			if ( $content_length && (int) $content_length > $limit ) {
				return new \WP_Error( 'archive_too_large', __( 'The repository archive exceeds the maximum allowed size.', 'pushwp' ) );
			}
		}

		$response = wp_remote_get(
			$url,
			array(
				'headers'     => $headers,
				'timeout'     => $this->download_timeout(),
				'redirection' => 5,
				'stream'      => true,
				'filename'    => $dest_file,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_delete_file( $dest_file );

			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code >= 400 ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			wp_delete_file( $dest_file );

			return $this->error_from_status( $code, is_array( $data ) ? $data : array() );
		}

		if ( ! file_exists( $dest_file ) ) {
			return new \WP_Error( 'archive_download_failed', __( 'The archive download did not produce a file.', 'pushwp' ) );
		}

		if ( filesize( $dest_file ) > $limit ) {
			wp_delete_file( $dest_file );

			return new \WP_Error( 'archive_too_large', __( 'The repository archive exceeds the maximum allowed size.', 'pushwp' ) );
		}

		return true;
	}
}

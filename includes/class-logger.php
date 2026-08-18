<?php
/**
 * Deployment log.
 *
 * @package PushWP
 */

namespace PushWP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Append-only, capped, sanitized deployment log.
 */
final class Logger {

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
	 * Add a log entry.
	 *
	 * @param string $repository   Repository slug (owner/repo).
	 * @param string $ref          Branch or tag.
	 * @param string $sha          Commit SHA.
	 * @param string $operation    Operation label.
	 * @param string $result       'success' or 'failure'.
	 * @param string $initiator    User ID or webhook delivery ID.
	 * @param string $message      Sanitized message.
	 * @return void
	 */
	public function add( $repository, $ref, $sha, $operation, $result, $initiator, $message ) {
		$entry = array(
			'time'       => current_time( 'mysql' ),
			'repository' => sanitize_text_field( $repository ),
			'ref'        => sanitize_text_field( $ref ),
			'sha'        => sanitize_text_field( $sha ),
			'operation'  => sanitize_text_field( $operation ),
			'result'     => 'success' === $result ? 'success' : 'failure',
			'initiator'  => sanitize_text_field( $initiator ),
			'message'    => sanitize_text_field( $message ),
		);

		$logs = $this->settings->get_logs();

		array_unshift( $logs, $entry );

		$logs = array_slice( $logs, 0, $this->settings->get_log_limit() );

		$this->settings->save_logs( $logs );
	}

	/**
	 * All log entries (newest first).
	 *
	 * @return array<int, array>
	 */
	public function get_entries() {
		return $this->settings->get_logs();
	}

	/**
	 * Remove all log entries.
	 *
	 * @return void
	 */
	public function clear() {
		$this->settings->save_logs( array() );
	}
}

<?php
/**
 * GitHub push webhook endpoint.
 *
 * @package GitHubWPDeployer
 */

namespace GitHubWPDeployer;

use GitHubWPDeployer\Utils\Ref;
use GitHubWPDeployer\Utils\ReplayGuard;
use GitHubWPDeployer\Utils\WebhookSignature;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST endpoint that verifies and schedules push-triggered deployments.
 */
final class Webhook {

	const ROUTE_NAMESPACE = 'github-wp-deployer/v1';

	/**
	 * Repository manager.
	 *
	 * @var RepositoryManager
	 */
	private $repos;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param RepositoryManager $repos  Repository manager.
	 * @param Logger            $logger Logger.
	 */
	public function __construct( RepositoryManager $repos, Logger $logger ) {
		$this->repos  = $repos;
		$this->logger = $logger;

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the webhook route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/webhook/(?P<repo_id>[A-Za-z0-9_-]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Webhook endpoint URL for a repository.
	 *
	 * @param array $repo Repository record.
	 * @return string
	 */
	public function webhook_url( array $repo ) {
		$id = isset( $repo['id'] ) ? rawurlencode( $repo['id'] ) : '';

		return rest_url( self::ROUTE_NAMESPACE . '/webhook/' . $id );
	}

	/**
	 * Handle a webhook request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle( \WP_REST_Request $request ) {
		$repo = $this->repos->get( (string) $request['repo_id'] );

		if ( null === $repo ) {
			return new \WP_REST_Response( array( 'error' => 'unknown_repository' ), 404 );
		}

		$signature = (string) $request->get_header( 'x-hub-signature-256' );
		$event     = (string) $request->get_header( 'x-github-event' );
		$delivery  = (string) $request->get_header( 'x-github-delivery' );
		$body      = $request->get_body();

		$secret = isset( $repo['webhook_secret'] ) ? $repo['webhook_secret'] : '';

		if ( ! WebhookSignature::verify( $secret, $body, $signature ) ) {
			return new \WP_REST_Response( array( 'error' => 'invalid_signature' ), 401 );
		}

		if ( 'push' !== $event ) {
			return new \WP_REST_Response(
				array(
					'status' => 'ignored',
					'reason' => 'not_push',
				),
				202
			);
		}

		if ( '' === $delivery ) {
			return new \WP_REST_Response( array( 'error' => 'missing_delivery_id' ), 400 );
		}

		// Replay protection.
		$registry = get_option( Settings::OPTION_DELIVERIES, array() );

		if ( ! is_array( $registry ) ) {
			$registry = array();
		}

		if ( ReplayGuard::is_replay( $delivery, $registry, time() ) ) {
			return new \WP_REST_Response( array( 'status' => 'duplicate' ), 202 );
		}

		update_option( Settings::OPTION_DELIVERIES, $registry, false );

		$payload = json_decode( $body, true );

		if ( ! is_array( $payload ) ) {
			return new \WP_REST_Response( array( 'error' => 'invalid_payload' ), 400 );
		}

		// Validate repository identity against the saved configuration.
		$full_name = isset( $payload['repository']['full_name'] ) ? $payload['repository']['full_name'] : '';
		$expected  = ( isset( $repo['owner'] ) ? $repo['owner'] : '' ) . '/' . ( isset( $repo['repo'] ) ? $repo['repo'] : '' );

		if ( ! is_string( $full_name ) || ! hash_equals( strtolower( $expected ), strtolower( $full_name ) ) ) {
			return new \WP_REST_Response(
				array(
					'status' => 'ignored',
					'reason' => 'repo_mismatch',
				),
				202
			);
		}

		// Only branch deployments respond to push events.
		$ref_type = isset( $repo['ref_type'] ) ? $repo['ref_type'] : 'branch';

		if ( 'branch' !== $ref_type ) {
			return new \WP_REST_Response(
				array(
					'status' => 'ignored',
					'reason' => 'not_branch',
				),
				202
			);
		}

		$ref = isset( $payload['ref'] ) ? $payload['ref'] : '';

		if ( ! Ref::branch_matches( $ref, isset( $repo['ref'] ) ? $repo['ref'] : '' ) ) {
			return new \WP_REST_Response(
				array(
					'status' => 'ignored',
					'reason' => 'branch_mismatch',
				),
				202
			);
		}

		// Schedule a background deployment; never deploy synchronously.
		wp_schedule_single_event( time() + 1, UpdateChecker::CRON_DEPLOY, array( $repo['id'], $delivery ) );

		$this->logger->add(
			$expected,
			$repo['ref'],
			'',
			'webhook',
			'success',
			'webhook:' . $delivery,
			__( 'Push event verified and deployment scheduled.', 'github-wp-deployer' )
		);

		return new \WP_REST_Response( array( 'status' => 'scheduled' ), 202 );
	}
}

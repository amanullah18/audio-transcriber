<?php
/**
 * Webhook receiver.
 *
 * @package AudioTranscriber
 */

namespace AudioTranscriber;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * REST endpoint the API calls when a job finishes: POST /wp-json/audio-transcriber/v1/webhook.
 *
 * The payload only says which job changed. The result is then fetched from the API with the API key,
 * so a forged request could at most trigger a fetch, and forged requests are rejected by the signature check.
 */
final class Webhook {

	const REST_NAMESPACE = 'audio-transcriber/v1';
	const ROUTE          = '/webhook';

	/**
	 * Maximum age of a signed request, in seconds (replay protection).
	 */
	const MAX_AGE = 300;

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_route' ) );
	}

	/**
	 * Public URL of the endpoint.
	 */
	public static function url(): string {
		return rest_url( self::REST_NAMESPACE . self::ROUTE );
	}

	/**
	 * Register the REST route.
	 */
	public static function register_route(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle' ),
				'permission_callback' => array( self::class, 'authorize' ),
			)
		);
	}

	/**
	 * Only accept requests signed with this site's webhook secret.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function authorize( WP_REST_Request $request ) {
		$valid = self::verify(
			Settings::webhook_secret(),
			(string) $request->get_header( 'x_transcriber_timestamp' ),
			(string) $request->get_header( 'x_transcriber_signature' ),
			$request->get_body(),
			time()
		);
		return $valid ? true : new WP_Error( 'audio_transcriber_bad_signature', __( 'Invalid webhook signature.', 'audio-transcriber' ), array( 'status' => 401 ) );
	}

	/**
	 * Verify an HMAC-SHA256 signature over "<timestamp>.<body>".
	 *
	 * @param string $secret    Shared secret.
	 * @param string $timestamp Unix timestamp header.
	 * @param string $signature Signature header, "sha256=<hex>".
	 * @param string $body      Raw request body.
	 * @param int    $now       Current Unix time.
	 */
	public static function verify( string $secret, string $timestamp, string $signature, string $body, int $now ): bool {
		if ( '' === $secret || '' === $signature || ! ctype_digit( $timestamp ) ) {
			return false;
		}
		if ( abs( $now - (int) $timestamp ) > self::MAX_AGE ) {
			return false;
		}
		$expected = 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
		return hash_equals( $expected, $signature );
	}

	/**
	 * Sync the attachment the job belongs to.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$payload = json_decode( $request->get_body(), true );
		$job_id  = is_array( $payload ) ? sanitize_text_field( (string) ( $payload['id'] ?? '' ) ) : '';

		$attachment_id = '' !== $job_id ? Jobs::find_by_job_id( $job_id ) : 0;
		if ( ! $attachment_id ) {
			// 404 tells the API not to retry: the attachment was deleted, or the job belongs to another site.
			return new WP_REST_Response( array( 'error' => 'unknown job' ), 404 );
		}

		$status = Jobs::sync( $attachment_id );
		if ( is_wp_error( $status ) ) {
			// 503 makes the API retry later.
			return new WP_REST_Response( array( 'error' => $status->get_error_message() ), 503 );
		}
		return new WP_REST_Response( array( 'status' => $status ), 200 );
	}
}

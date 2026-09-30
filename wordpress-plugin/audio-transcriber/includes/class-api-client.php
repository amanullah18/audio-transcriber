<?php
/**
 * HTTP client for the Audio Transcriber API.
 *
 * @package AudioTranscriber
 */

namespace AudioTranscriber;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around the WordPress HTTP API. Every method returns data or a WP_Error.
 */
class Api_Client {

	/**
	 * API base URL without trailing slash, e.g. https://transcriber.example.com.
	 *
	 * @var string
	 */
	private string $base_url;

	/**
	 * API key sent in the X-API-Key header.
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Constructor.
	 *
	 * @param string $base_url API base URL.
	 * @param string $api_key  API key.
	 */
	public function __construct( string $base_url, string $api_key ) {
		$this->base_url = untrailingslashit( $base_url );
		$this->api_key  = $api_key;
	}

	/**
	 * Client configured from the plugin settings.
	 */
	public static function from_settings(): self {
		return new self( Settings::get( 'api_url' ), Settings::get( 'api_key' ) );
	}

	/**
	 * Dependency health (public endpoint).
	 *
	 * @return array|WP_Error
	 */
	public function health() {
		return $this->json( 'GET', '/health' );
	}

	/**
	 * Verifies that the API key is accepted.
	 *
	 * @return array|WP_Error
	 */
	public function check_auth() {
		return $this->json( 'GET', '/transcriptions?limit=1' );
	}

	/**
	 * Supported languages as a list of {code, name}.
	 *
	 * @return array|WP_Error
	 */
	public function languages() {
		return $this->json( 'GET', '/languages' );
	}

	/**
	 * Upload a file for transcription.
	 *
	 * @param string $file_path Absolute path of the file to upload.
	 * @param string $filename  File name reported to the API.
	 * @param array  $fields    Optional form fields: model, language, callback_url, callback_secret.
	 * @return array|WP_Error   {id, status} on success.
	 */
	public function submit( string $file_path, string $filename, array $fields = array() ) {
		if ( ! is_readable( $file_path ) ) {
			return new WP_Error( 'audio_transcriber_file_missing', __( 'The media file could not be read from disk.', 'audio-transcriber' ) );
		}
		$boundary = 'audio-transcriber-' . wp_generate_password( 24, false );

		return $this->json(
			'POST',
			'/transcriptions',
			array(
				'timeout' => 120,
				'headers' => array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ),
				'body'    => self::build_multipart( $fields, 'file', $file_path, $filename, $boundary ),
			)
		);
	}

	/**
	 * Job status and, once completed, the transcript.
	 *
	 * @param string $job_id Job ID returned by submit().
	 * @return array|WP_Error
	 */
	public function get_job( string $job_id ) {
		return $this->json( 'GET', '/transcriptions/' . rawurlencode( $job_id ) );
	}

	/**
	 * Transcript as subtitles.
	 *
	 * @param string $job_id Job ID.
	 * @param string $format vtt or srt.
	 * @return string|WP_Error
	 */
	public function get_subtitles( string $job_id, string $format = 'vtt' ) {
		$response = $this->request( 'GET', '/transcriptions/' . rawurlencode( $job_id ) . '/subtitles?format=' . rawurlencode( $format ) );
		return is_wp_error( $response ) ? $response : $response['body'];
	}

	/**
	 * Build a multipart/form-data body. The WordPress HTTP API has no built-in multipart support.
	 *
	 * @param array  $fields     Text fields; null or empty values are skipped.
	 * @param string $file_field Name of the file field.
	 * @param string $file_path  File to include.
	 * @param string $filename   File name to report.
	 * @param string $boundary   Multipart boundary.
	 */
	public static function build_multipart( array $fields, string $file_field, string $file_path, string $filename, string $boundary ): string {
		$eol  = "\r\n";
		$body = '';
		foreach ( $fields as $name => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}
			$body .= '--' . $boundary . $eol
				. 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol
				. $value . $eol;
		}
		$safe_filename = str_replace( array( '"', "\r", "\n" ), '', $filename );
		$body         .= '--' . $boundary . $eol
			. 'Content-Disposition: form-data; name="' . $file_field . '"; filename="' . $safe_filename . '"' . $eol
			. 'Content-Type: application/octet-stream' . $eol . $eol
			. file_get_contents( $file_path ) . $eol // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local file, not a URL.
			. '--' . $boundary . '--' . $eol;
		return $body;
	}

	/**
	 * Perform a request and decode the JSON response.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Path relative to the API base URL.
	 * @param array  $args   Extra wp_remote_request() arguments.
	 * @return array|WP_Error
	 */
	private function json( string $method, string $path, array $args = array() ) {
		$response = $this->request( $method, $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = json_decode( $response['body'], true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'audio_transcriber_bad_response', __( 'The transcription API returned an invalid response.', 'audio-transcriber' ) );
		}
		return $data;
	}

	/**
	 * Perform a request. Non-2xx responses become a WP_Error carrying the HTTP status.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Path relative to the API base URL.
	 * @param array  $args   Extra wp_remote_request() arguments.
	 * @return array{code: int, body: string}|WP_Error
	 */
	private function request( string $method, string $path, array $args = array() ) {
		if ( '' === $this->base_url ) {
			return new WP_Error( 'audio_transcriber_not_configured', __( 'The transcription API URL is not configured.', 'audio-transcriber' ) );
		}
		$args            = array_merge( array( 'timeout' => 15 ), $args );
		$args['method']  = $method;
		$args['headers'] = array_merge(
			array(
				'Accept'    => 'application/json',
				'X-API-Key' => $this->api_key,
			),
			$args['headers'] ?? array()
		);

		/**
		 * Filters the arguments of every request made to the transcription API.
		 *
		 * @param array  $args   wp_remote_request() arguments.
		 * @param string $method HTTP method.
		 * @param string $path   API path.
		 */
		$args = apply_filters( 'audio_transcriber_request_args', $args, $method, $path );

		// Not wp_safe_remote_request(): the API is admin-configured and usually on a private network.
		$response = wp_remote_request( $this->base_url . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'audio_transcriber_http_' . $code,
				/* translators: 1: HTTP status code, 2: error message from the API. */
				sprintf( __( 'Transcription API error (HTTP %1$d): %2$s', 'audio-transcriber' ), $code, self::error_detail( $body ) ),
				array( 'status' => $code )
			);
		}
		return array(
			'code' => $code,
			'body' => $body,
		);
	}

	/**
	 * Extract a readable message from a FastAPI error body.
	 *
	 * @param string $body Response body.
	 */
	public static function error_detail( string $body ): string {
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || ! isset( $data['detail'] ) ) {
			return '' === $body ? __( 'no details', 'audio-transcriber' ) : wp_strip_all_tags( substr( $body, 0, 200 ) );
		}
		if ( is_string( $data['detail'] ) ) {
			return $data['detail'];
		}
		// Validation errors: a list of {loc, msg}.
		$messages = array();
		foreach ( (array) $data['detail'] as $error ) {
			if ( is_array( $error ) && isset( $error['msg'] ) ) {
				$field      = isset( $error['loc'] ) ? (string) end( $error['loc'] ) : '';
				$messages[] = ( '' !== $field ? $field . ': ' : '' ) . $error['msg'];
			}
		}
		return implode( '; ', $messages );
	}
}

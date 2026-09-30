<?php
/**
 * WP-CLI commands.
 *
 * @package AudioTranscriber
 */

namespace AudioTranscriber;

use WP_CLI;
use WP_CLI\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Transcribe media and inspect transcripts from the command line.
 *
 * ## EXAMPLES
 *
 *     # Check the API connection
 *     wp audio-transcriber check
 *
 *     # Transcribe every audio/video file that has no transcript yet, and wait for the results
 *     wp audio-transcriber transcribe --missing --wait
 *
 *     # Show transcript status for all media
 *     wp audio-transcriber status
 */
class Cli {

	/**
	 * Check that the API is reachable and the API key works.
	 */
	public function check(): void {
		$client = Api_Client::from_settings();
		$health = $client->health();
		if ( is_wp_error( $health ) ) {
			WP_CLI::error( $health->get_error_message() );
		}
		WP_CLI::log( 'Health: ' . wp_json_encode( $health ) );
		$auth = $client->check_auth();
		if ( is_wp_error( $auth ) ) {
			WP_CLI::error( $auth->get_error_message() );
		}
		WP_CLI::success( 'Connected, and the API key is valid.' );
	}

	/**
	 * Submit attachments for transcription.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Attachment IDs.
	 *
	 * [--missing]
	 * : Transcribe every supported attachment that has no transcript yet.
	 *
	 * [--model=<model>]
	 * : Whisper model (tiny, base, small, medium, large-v3). Defaults to the plugin setting.
	 *
	 * [--language=<code>]
	 * : Language code, e.g. en, hi, ur. Defaults to the plugin setting.
	 *
	 * [--wait]
	 * : Wait until all submitted jobs have finished.
	 *
	 * [--timeout=<seconds>]
	 * : How long --wait waits at most.
	 * ---
	 * default: 1800
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function transcribe( array $args, array $assoc_args ): void {
		$ids = array_map( 'intval', $args );
		if ( Utils\get_flag_value( $assoc_args, 'missing', false ) ) {
			$ids = array_merge( $ids, $this->untranscribed_ids() );
		}
		$ids = array_unique( $ids );
		if ( ! $ids ) {
			WP_CLI::error( 'Nothing to transcribe. Pass attachment IDs or --missing.' );
		}

		$overrides = array_filter(
			array(
				'model'    => $assoc_args['model'] ?? null,
				'language' => $assoc_args['language'] ?? null,
			)
		);
		$submitted = array();
		foreach ( $ids as $id ) {
			$result = Jobs::submit( $id, $overrides );
			if ( is_wp_error( $result ) ) {
				WP_CLI::warning( "#{$id}: " . $result->get_error_message() );
				continue;
			}
			WP_CLI::log( "#{$id}: submitted (job {$result})" );
			$submitted[] = $id;
		}

		if ( $submitted && Utils\get_flag_value( $assoc_args, 'wait', false ) ) {
			$this->wait( $submitted, (int) ( $assoc_args['timeout'] ?? 1800 ) );
		}
		WP_CLI::success( sprintf( '%d of %d submitted.', count( $submitted ), count( $ids ) ) );
	}

	/**
	 * Show transcript status.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Attachment IDs. Defaults to every supported attachment.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function status( array $args, array $assoc_args ): void {
		$ids  = $args ? array_map( 'intval', $args ) : $this->supported_ids();
		$rows = array();
		foreach ( $ids as $id ) {
			$meta   = Meta::get( $id );
			$rows[] = array(
				'id'       => $id,
				'file'     => wp_basename( (string) get_attached_file( $id ) ),
				'status'   => '' !== $meta['status'] ? $meta['status'] : '-',
				'language' => $meta['language'],
				'words'    => str_word_count( $meta['text'] ),
				'error'    => $meta['error'],
			);
		}
		Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'id', 'file', 'status', 'language', 'words', 'error' ) );
	}

	/**
	 * Fetch the latest status of all pending jobs from the API (what the 5-minute cron does).
	 */
	public function sync(): void {
		$ids = Jobs::pending_attachment_ids();
		foreach ( $ids as $id ) {
			$status = Jobs::sync( $id );
			WP_CLI::log( "#{$id}: " . ( is_wp_error( $status ) ? 'error: ' . $status->get_error_message() : $status ) );
		}
		WP_CLI::success( sprintf( '%d pending job(s) checked.', count( $ids ) ) );
	}

	/**
	 * Poll until the given attachments are no longer pending.
	 *
	 * @param int[] $ids     Attachment IDs.
	 * @param int   $timeout Maximum seconds to wait.
	 */
	private function wait( array $ids, int $timeout ): void {
		$pending  = $ids;
		$deadline = time() + $timeout;
		while ( $pending ) {
			if ( time() > $deadline ) {
				WP_CLI::warning( sprintf( 'Stopped waiting after %ds; still pending: #%s', $timeout, implode( ', #', $pending ) ) );
				return;
			}
			sleep( 3 );
			foreach ( $pending as $key => $id ) {
				$status = Jobs::sync( $id );
				if ( is_string( $status ) && ! in_array( $status, Meta::PENDING_STATUSES, true ) ) {
					WP_CLI::log( "#{$id}: {$status}" );
					unset( $pending[ $key ] );
				}
			}
		}
	}

	/**
	 * Supported attachments.
	 *
	 * @return int[]
	 */
	private function supported_ids(): array {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array( 'audio', 'video' ),
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		);
		return array_values( array_filter( array_map( 'intval', $ids ), array( Jobs::class, 'is_supported' ) ) );
	}

	/**
	 * Supported attachments without a transcript (never submitted, or failed).
	 *
	 * @return int[]
	 */
	private function untranscribed_ids(): array {
		return array_values(
			array_filter(
				$this->supported_ids(),
				static fn( int $id ) => in_array( Meta::get( $id )['status'], array( '', 'failed' ), true )
			)
		);
	}
}

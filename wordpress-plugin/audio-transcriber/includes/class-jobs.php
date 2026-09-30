<?php
/**
 * Transcription job lifecycle.
 *
 * @package AudioTranscriber
 */

namespace AudioTranscriber;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Submits attachments to the API and syncs results back into post meta.
 *
 * Results arrive by webhook when the API can reach the site; a WP-Cron poll every 5 minutes is the fallback.
 */
final class Jobs {

	const POLL_HOOK       = 'audio_transcriber_poll';
	const SUBMIT_HOOK     = 'audio_transcriber_submit';
	const SCHEDULE        = 'audio_transcriber_five_minutes';
	const NO_WEBHOOK_FLAG = 'audio_transcriber_webhook_rejected';
	const POLL_BATCH      = 20;

	/**
	 * File extensions the API accepts.
	 */
	const SUPPORTED_EXTENSIONS = array( 'ogg', 'opus', 'mp3', 'm4a', 'wav', 'flac', 'webm', 'mp4' );

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_filter( 'cron_schedules', array( self::class, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- 5 minutes is intended.
		add_action( self::POLL_HOOK, array( self::class, 'poll' ) );
		add_action( self::SUBMIT_HOOK, array( self::class, 'submit' ) );
	}

	/**
	 * Add a 5-minute cron schedule.
	 *
	 * @param array $schedules Existing schedules.
	 */
	public static function add_schedule( array $schedules ): array {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes', 'audio-transcriber' ),
		);
		return $schedules;
	}

	/**
	 * Start the polling fallback.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::POLL_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, self::SCHEDULE, self::POLL_HOOK );
		}
	}

	/**
	 * Stop the polling fallback.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::POLL_HOOK );
	}

	/**
	 * Whether the attachment is a file type the API can transcribe.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function is_supported( int $attachment_id ): bool {
		$file = get_attached_file( $attachment_id );
		if ( ! $file ) {
			return false;
		}
		return in_array( strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ), self::SUPPORTED_EXTENSIONS, true );
	}

	/**
	 * Transcribe in the background (via WP-Cron), so the current request isn't slowed by the upload.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function submit_async( int $attachment_id ): void {
		update_post_meta( $attachment_id, Meta::STATUS, 'submitting' );
		wp_schedule_single_event( time(), self::SUBMIT_HOOK, array( $attachment_id ) );
	}

	/**
	 * Upload an attachment to the API for transcription.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $overrides     Optional: model, language.
	 * @return string|WP_Error     API job ID.
	 */
	public static function submit( int $attachment_id, array $overrides = array() ) {
		if ( ! Settings::is_configured() ) {
			return self::fail( $attachment_id, new WP_Error( 'audio_transcriber_not_configured', __( 'Audio Transcriber is not configured. Set the API URL and key under Settings > Audio Transcriber.', 'audio-transcriber' ) ) );
		}
		if ( ! self::is_supported( $attachment_id ) ) {
			return self::fail( $attachment_id, new WP_Error( 'audio_transcriber_unsupported', __( 'This file type cannot be transcribed.', 'audio-transcriber' ) ) );
		}

		$fields = array(
			'model'    => $overrides['model'] ?? Settings::get( 'model' ),
			'language' => $overrides['language'] ?? Settings::get( 'language' ),
		);
		if ( Settings::get( 'use_webhook' ) && ! get_transient( self::NO_WEBHOOK_FLAG ) ) {
			$fields['callback_url']    = Settings::callback_url();
			$fields['callback_secret'] = Settings::webhook_secret();
		}

		/**
		 * Filters the form fields sent with a transcription request.
		 *
		 * @param array $fields        Fields: model, language, callback_url, callback_secret.
		 * @param int   $attachment_id Attachment ID.
		 */
		$fields = apply_filters( 'audio_transcriber_submit_fields', $fields, $attachment_id );

		$file   = (string) get_attached_file( $attachment_id );
		$client = Api_Client::from_settings();
		$result = $client->submit( $file, wp_basename( $file ), $fields );

		// The API rejects callback URLs it considers private (e.g. a site on localhost). Fall back to polling.
		if ( is_wp_error( $result ) && isset( $fields['callback_url'] ) && self::is_callback_rejection( $result ) ) {
			set_transient( self::NO_WEBHOOK_FLAG, $result->get_error_message(), DAY_IN_SECONDS );
			unset( $fields['callback_url'], $fields['callback_secret'] );
			$result = $client->submit( $file, wp_basename( $file ), $fields );
		}
		if ( is_wp_error( $result ) ) {
			return self::fail( $attachment_id, $result );
		}

		$job_id = (string) ( $result['id'] ?? '' );
		update_post_meta( $attachment_id, Meta::JOB_ID, $job_id );
		update_post_meta( $attachment_id, Meta::STATUS, (string) ( $result['status'] ?? 'queued' ) );
		delete_post_meta( $attachment_id, Meta::ERROR );

		/**
		 * Fires after an attachment was submitted for transcription.
		 *
		 * @param int    $attachment_id Attachment ID.
		 * @param string $job_id        API job ID.
		 */
		do_action( 'audio_transcriber_submitted', $attachment_id, $job_id );
		return $job_id;
	}

	/**
	 * Fetch the job from the API and store the result if it's finished.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string|WP_Error   The job status.
	 */
	public static function sync( int $attachment_id ) {
		$meta = Meta::get( $attachment_id );
		if ( '' === $meta['job_id'] ) {
			return new WP_Error( 'audio_transcriber_no_job', __( 'This file has not been submitted for transcription.', 'audio-transcriber' ) );
		}
		if ( ! in_array( $meta['status'], Meta::PENDING_STATUSES, true ) ) {
			return $meta['status']; // Already final. Webhook and poll can both arrive; only handle the first.
		}

		$client = Api_Client::from_settings();
		$job    = $client->get_job( $meta['job_id'] );
		if ( is_wp_error( $job ) ) {
			$status = $job->get_error_data()['status'] ?? 0;
			if ( 404 === $status ) {
				self::fail( $attachment_id, new WP_Error( 'audio_transcriber_job_gone', __( 'The job no longer exists on the transcription API.', 'audio-transcriber' ) ) );
				return 'failed';
			}
			return $job; // Temporary problem (network, API down): try again on the next poll.
		}

		$status = (string) ( $job['status'] ?? '' );
		if ( 'completed' === $status ) {
			self::store_result( $attachment_id, $job, $client );
		} elseif ( 'failed' === $status ) {
			self::fail( $attachment_id, new WP_Error( 'audio_transcriber_job_failed', (string) ( $job['error'] ?? __( 'Transcription failed.', 'audio-transcriber' ) ) ) );
		} else {
			update_post_meta( $attachment_id, Meta::STATUS, $status );
		}
		return $status;
	}

	/**
	 * WP-Cron fallback: sync pending jobs.
	 */
	public static function poll(): void {
		if ( ! Settings::is_configured() ) {
			return;
		}
		foreach ( self::pending_attachment_ids( self::POLL_BATCH ) as $attachment_id ) {
			self::sync( $attachment_id );
		}
	}

	/**
	 * Attachments with a job the API hasn't finished yet.
	 *
	 * @param int $limit Maximum number of IDs.
	 * @return int[]
	 */
	public static function pending_attachment_ids( int $limit = -1 ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'fields'         => 'ids',
					'posts_per_page' => $limit,
					'orderby'        => 'modified',
					'order'          => 'ASC',
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small, indexed meta_key lookup.
						array(
							'key'     => Meta::STATUS,
							'value'   => Meta::PENDING_STATUSES,
							'compare' => 'IN',
						),
					),
				)
			)
		);
	}

	/**
	 * Find the attachment a job belongs to.
	 *
	 * @param string $job_id API job ID.
	 */
	public static function find_by_job_id( string $job_id ): int {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_key'       => Meta::JOB_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $job_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Save a completed transcript, its captions, and notify listeners.
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param array      $job           Job from the API.
	 * @param Api_Client $client        API client.
	 */
	private static function store_result( int $attachment_id, array $job, Api_Client $client ): void {
		$segments = array();
		foreach ( (array) ( $job['segments'] ?? array() ) as $segment ) {
			$segments[] = array(
				'start' => (float) ( $segment['start'] ?? 0 ),
				'end'   => (float) ( $segment['end'] ?? 0 ),
				'text'  => sanitize_text_field( (string) ( $segment['text'] ?? '' ) ),
			);
		}
		update_post_meta( $attachment_id, Meta::TEXT, sanitize_textarea_field( (string) ( $job['text'] ?? '' ) ) );
		update_post_meta( $attachment_id, Meta::SEGMENTS, $segments );
		update_post_meta( $attachment_id, Meta::LANGUAGE, sanitize_key( (string) ( $job['detected_language'] ?? '' ) ) );

		$vtt = $client->get_subtitles( (string) $job['id'], 'vtt' );
		if ( ! is_wp_error( $vtt ) ) {
			Captions::save( $attachment_id, $vtt );
		}

		delete_post_meta( $attachment_id, Meta::ERROR );
		update_post_meta( $attachment_id, Meta::STATUS, 'completed' );
		Settings::languages(); // Warm the cache here (background request) so caption labels show language names.

		/**
		 * Fires when an attachment's transcript is ready.
		 *
		 * @param int   $attachment_id Attachment ID.
		 * @param array $transcript    Transcript: status, text, segments, language, captions_url, ...
		 */
		do_action( 'audio_transcriber_completed', $attachment_id, Meta::get( $attachment_id ) );
	}

	/**
	 * Record a failure and notify listeners.
	 *
	 * @param int      $attachment_id Attachment ID.
	 * @param WP_Error $error         The error.
	 * @return WP_Error The same error, for returning.
	 */
	private static function fail( int $attachment_id, WP_Error $error ): WP_Error {
		update_post_meta( $attachment_id, Meta::STATUS, 'failed' );
		update_post_meta( $attachment_id, Meta::ERROR, $error->get_error_message() );

		/**
		 * Fires when transcribing an attachment failed.
		 *
		 * @param int      $attachment_id Attachment ID.
		 * @param WP_Error $error         What went wrong.
		 */
		do_action( 'audio_transcriber_failed', $attachment_id, $error );
		return $error;
	}

	/**
	 * Whether an API error is the callback URL being rejected (as opposed to e.g. a bad file).
	 *
	 * @param WP_Error $error Error from submit().
	 */
	public static function is_callback_rejection( WP_Error $error ): bool {
		$status = $error->get_error_data()['status'] ?? 0;
		return 422 === $status && false !== stripos( $error->get_error_message(), 'callback' );
	}
}

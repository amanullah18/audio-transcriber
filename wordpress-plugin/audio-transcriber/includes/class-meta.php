<?php
/**
 * Attachment post meta used to store transcripts.
 *
 * @package AudioTranscriber
 */

namespace AudioTranscriber;

defined( 'ABSPATH' ) || exit;

/**
 * Meta keys and a typed reader for an attachment's transcription state.
 */
final class Meta {

	const JOB_ID   = '_audio_transcriber_job_id';
	const STATUS   = '_audio_transcriber_status';
	const TEXT     = '_audio_transcriber_text';
	const SEGMENTS = '_audio_transcriber_segments';
	const LANGUAGE = '_audio_transcriber_language';
	const ERROR    = '_audio_transcriber_error';
	const CAPTIONS = '_audio_transcriber_captions';

	/**
	 * Statuses that mean the API is still working on the job.
	 */
	const PENDING_STATUSES = array( 'queued', 'processing' );

	/**
	 * All meta keys, e.g. for cleanup on uninstall.
	 *
	 * @return string[]
	 */
	public static function keys(): array {
		return array( self::JOB_ID, self::STATUS, self::TEXT, self::SEGMENTS, self::LANGUAGE, self::ERROR, self::CAPTIONS );
	}

	/**
	 * Read an attachment's transcription state.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{status: string, job_id: string, text: string, segments: array, language: string, error: string, captions_url: ?string}
	 */
	public static function get( int $attachment_id ): array {
		$segments = get_post_meta( $attachment_id, self::SEGMENTS, true );

		return array(
			'status'       => (string) get_post_meta( $attachment_id, self::STATUS, true ),
			'job_id'       => (string) get_post_meta( $attachment_id, self::JOB_ID, true ),
			'text'         => (string) get_post_meta( $attachment_id, self::TEXT, true ),
			'segments'     => is_array( $segments ) ? $segments : array(),
			'language'     => (string) get_post_meta( $attachment_id, self::LANGUAGE, true ),
			'error'        => (string) get_post_meta( $attachment_id, self::ERROR, true ),
			'captions_url' => Captions::url( $attachment_id ),
		);
	}
}

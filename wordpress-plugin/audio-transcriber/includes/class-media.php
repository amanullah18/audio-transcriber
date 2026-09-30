<?php
/**
 * Media Library integration.
 *
 * @package AudioTranscriber
 */

namespace AudioTranscriber;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Transcribe button in attachment details, list-table column, row and bulk actions, and auto-transcribe.
 */
final class Media {

	const ACTION = 'audio_transcriber_transcribe';

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_filter( 'attachment_fields_to_edit', array( self::class, 'attachment_fields' ), 10, 2 );
		add_filter( 'manage_media_columns', array( self::class, 'add_column' ) );
		add_action( 'manage_media_custom_column', array( self::class, 'render_column' ), 10, 2 );
		add_filter( 'media_row_actions', array( self::class, 'row_actions' ), 10, 2 );
		add_filter( 'bulk_actions-upload', array( self::class, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-upload', array( self::class, 'handle_bulk_action' ), 10, 3 );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle_single' ) );
		add_action( 'admin_notices', array( self::class, 'render_notices' ) );
		add_action( 'add_attachment', array( self::class, 'maybe_auto_transcribe' ) );
	}

	/**
	 * Nonce-protected URL that transcribes one attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function transcribe_url( int $attachment_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'        => self::ACTION,
					'attachment_id' => $attachment_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $attachment_id
		);
	}

	/**
	 * Human-readable status.
	 *
	 * @param string $status Status from meta.
	 */
	public static function status_label( string $status ): string {
		$labels = array(
			''           => __( 'Not transcribed', 'audio-transcriber' ),
			'submitting' => __( 'Submitting…', 'audio-transcriber' ),
			'queued'     => __( 'Queued', 'audio-transcriber' ),
			'processing' => __( 'Transcribing…', 'audio-transcriber' ),
			'completed'  => __( 'Transcribed', 'audio-transcriber' ),
			'failed'     => __( 'Failed', 'audio-transcriber' ),
		);
		return $labels[ $status ] ?? $status;
	}

	/**
	 * Transcript field in the attachment details (media modal and edit screen).
	 *
	 * @param array   $fields Form fields.
	 * @param WP_Post $post   Attachment.
	 */
	public static function attachment_fields( array $fields, WP_Post $post ): array {
		if ( ! Jobs::is_supported( $post->ID ) || ! current_user_can( 'upload_files' ) ) {
			return $fields;
		}
		$meta = Meta::get( $post->ID );

		$html = '<p><strong>' . esc_html( self::status_label( $meta['status'] ) ) . '</strong>';
		if ( $meta['language'] ) {
			$html .= ' &middot; ' . esc_html( strtoupper( $meta['language'] ) );
		}
		$html .= '</p>';
		if ( 'failed' === $meta['status'] && $meta['error'] ) {
			$html .= '<p class="description" style="color:#b32d2e">' . esc_html( $meta['error'] ) . '</p>';
		}
		if ( 'completed' === $meta['status'] ) {
			$html .= '<textarea readonly rows="6" class="widefat">' . esc_textarea( $meta['text'] ) . '</textarea>';
			if ( $meta['captions_url'] ) {
				$html .= '<p><a href="' . esc_url( $meta['captions_url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Captions (.vtt)', 'audio-transcriber' ) . '</a></p>';
			}
		}
		if ( ! in_array( $meta['status'], array_merge( Meta::PENDING_STATUSES, array( 'submitting' ) ), true ) ) {
			$label = 'completed' === $meta['status'] ? __( 'Transcribe again', 'audio-transcriber' ) : __( 'Transcribe', 'audio-transcriber' );
			$html .= '<p><a class="button" href="' . esc_url( self::transcribe_url( $post->ID ) ) . '">' . esc_html( $label ) . '</a></p>';
		}

		$fields['audio_transcriber'] = array(
			'label' => __( 'Transcript', 'audio-transcriber' ),
			'input' => 'html',
			'html'  => $html,
		);
		return $fields;
	}

	/**
	 * Add the Transcript column.
	 *
	 * @param array $columns Columns.
	 */
	public static function add_column( array $columns ): array {
		$columns['audio_transcriber'] = __( 'Transcript', 'audio-transcriber' );
		return $columns;
	}

	/**
	 * Render the Transcript column.
	 *
	 * @param string $column        Column name.
	 * @param int    $attachment_id Attachment ID.
	 */
	public static function render_column( string $column, int $attachment_id ): void {
		if ( 'audio_transcriber' !== $column || ! Jobs::is_supported( $attachment_id ) ) {
			return;
		}
		$meta = Meta::get( $attachment_id );
		echo esc_html( self::status_label( $meta['status'] ) );
		if ( 'failed' === $meta['status'] && $meta['error'] ) {
			echo '<br><small title="' . esc_attr( $meta['error'] ) . '">' . esc_html( wp_trim_words( $meta['error'], 8 ) ) . '</small>';
		}
	}

	/**
	 * "Transcribe" row action.
	 *
	 * @param array   $actions Row actions.
	 * @param WP_Post $post    Attachment.
	 */
	public static function row_actions( array $actions, WP_Post $post ): array {
		if ( Jobs::is_supported( $post->ID ) && current_user_can( 'upload_files' ) ) {
			$actions['audio_transcriber'] = '<a href="' . esc_url( self::transcribe_url( $post->ID ) ) . '">' . esc_html__( 'Transcribe', 'audio-transcriber' ) . '</a>';
		}
		return $actions;
	}

	/**
	 * "Transcribe" bulk action.
	 *
	 * @param array $actions Bulk actions.
	 */
	public static function bulk_actions( array $actions ): array {
		if ( current_user_can( 'upload_files' ) ) {
			$actions[ self::ACTION ] = __( 'Transcribe', 'audio-transcriber' );
		}
		return $actions;
	}

	/**
	 * Queue the selected attachments. Uploads happen in the background, so large selections don't time out.
	 *
	 * @param string $redirect Redirect URL.
	 * @param string $action   Selected action.
	 * @param int[]  $ids      Selected attachment IDs.
	 */
	public static function handle_bulk_action( string $redirect, string $action, array $ids ): string {
		if ( self::ACTION !== $action || ! current_user_can( 'upload_files' ) ) {
			return $redirect;
		}
		$queued = 0;
		foreach ( array_map( 'intval', $ids ) as $attachment_id ) {
			if ( Jobs::is_supported( $attachment_id ) && current_user_can( 'edit_post', $attachment_id ) ) {
				Jobs::submit_async( $attachment_id );
				++$queued;
			}
		}
		/* translators: %d: number of files. */
		self::flash( 'success', sprintf( _n( '%d file queued for transcription.', '%d files queued for transcription.', $queued, 'audio-transcriber' ), $queued ) );
		return $redirect;
	}

	/**
	 * Transcribe a single attachment (admin-post handler).
	 */
	public static function handle_single(): void {
		$attachment_id = isset( $_GET['attachment_id'] ) ? absint( $_GET['attachment_id'] ) : 0;
		check_admin_referer( self::ACTION . '_' . $attachment_id );
		if ( ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_die( esc_html__( 'You are not allowed to transcribe this file.', 'audio-transcriber' ), 403 );
		}

		$result = Jobs::submit( $attachment_id );
		if ( is_wp_error( $result ) ) {
			self::flash( 'error', $result->get_error_message() );
		} else {
			self::flash( 'success', __( 'Transcription started. The transcript will appear here when it\'s ready.', 'audio-transcriber' ) );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'upload.php' ) );
		exit;
	}

	/**
	 * Transcribe new uploads if enabled.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function maybe_auto_transcribe( int $attachment_id ): void {
		if ( Settings::get( 'auto_transcribe' ) && Settings::is_configured() && Jobs::is_supported( $attachment_id ) ) {
			Jobs::submit_async( $attachment_id );
		}
	}

	/**
	 * Store a one-time admin notice for the current user.
	 *
	 * @param string $type    success|error|warning|info.
	 * @param string $message Message.
	 */
	public static function flash( string $type, string $message ): void {
		set_transient( 'audio_transcriber_notice_' . get_current_user_id(), array( $type, $message ), MINUTE_IN_SECONDS );
	}

	/**
	 * Show and clear the current user's notice.
	 */
	public static function render_notices(): void {
		$key    = 'audio_transcriber_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $notice[0] ),
			esc_html( $notice[1] )
		);
	}
}

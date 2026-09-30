<?php
/**
 * Interactive Transcript block and shortcode.
 *
 * @package AudioTranscriber
 */

namespace AudioTranscriber;

defined( 'ABSPATH' ) || exit;

/**
 * Renders an audio/video player with a transcript whose timestamps seek the player.
 *
 * Block: audio-transcriber/interactive-transcript. Shortcode: [audio_transcript id="123"].
 */
final class Transcript_Block {

	const BLOCK = 'audio-transcriber/interactive-transcript';

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_block' ) );
	}

	/**
	 * Register the block (from block.json) and the shortcode.
	 */
	public static function register_block(): void {
		register_block_type(
			AUDIO_TRANSCRIBER_DIR . 'blocks/interactive-transcript',
			array( 'render_callback' => array( self::class, 'render_block' ) )
		);
		add_shortcode( 'audio_transcript', array( self::class, 'render_shortcode' ) );
	}

	/**
	 * Block render callback.
	 *
	 * @param array $attributes Block attributes.
	 */
	public static function render_block( array $attributes ): string {
		return self::render( (int) ( $attributes['attachmentId'] ?? 0 ), get_block_wrapper_attributes() );
	}

	/**
	 * Shortcode handler.
	 *
	 * @param array|string $atts Shortcode attributes.
	 */
	public static function render_shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'id' => 0 ), (array) $atts, 'audio_transcript' );
		// Block assets are only enqueued automatically for blocks, not shortcodes.
		wp_enqueue_script( generate_block_asset_handle( self::BLOCK, 'viewScript' ) );
		wp_enqueue_style( generate_block_asset_handle( self::BLOCK, 'style' ) );
		return self::render( absint( $atts['id'] ), 'class="wp-block-audio-transcriber-interactive-transcript"' );
	}

	/**
	 * Render the player and transcript.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $wrapper_attrs Pre-escaped wrapper attributes.
	 */
	public static function render( int $attachment_id, string $wrapper_attrs ): string {
		$src = $attachment_id ? wp_get_attachment_url( $attachment_id ) : false;
		if ( ! $src ) {
			return '';
		}
		$meta  = Meta::get( $attachment_id );
		$tag   = str_starts_with( (string) get_post_mime_type( $attachment_id ), 'video/' ) ? 'video' : 'audio';
		$media = sprintf( '<%1$s controls preload="metadata" src="%2$s"></%1$s>', $tag, esc_url( $src ) );
		if ( $meta['captions_url'] ) {
			$media = Captions::add_track( $media, $tag, $meta['captions_url'], $meta['language'] ? $meta['language'] : 'en', __( 'Captions', 'audio-transcriber' ) );
		}

		if ( 'completed' !== $meta['status'] || ! $meta['segments'] ) {
			$notice = '';
			if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) { // Editor preview only; visitors just get the player.
				/* translators: %s: transcription status. */
				$notice = '<p><em>' . esc_html( sprintf( __( 'No transcript yet (%s). Transcribe this file from the Media Library.', 'audio-transcriber' ), Media::status_label( $meta['status'] ) ) ) . '</em></p>';
			}
			return '<figure ' . $wrapper_attrs . '>' . $media . $notice . '</figure>';
		}

		$items = '';
		foreach ( $meta['segments'] as $segment ) {
			$items .= sprintf(
				'<li class="audio-transcriber__segment" data-start="%1$s" data-end="%2$s"><button type="button" class="audio-transcriber__time" data-start="%1$s" aria-label="%3$s">%4$s</button> <span class="audio-transcriber__text">%5$s</span></li>',
				esc_attr( (string) $segment['start'] ),
				esc_attr( (string) $segment['end'] ),
				/* translators: %s: timestamp, e.g. 1:05. */
				esc_attr( sprintf( __( 'Play from %s', 'audio-transcriber' ), self::format_time( (float) $segment['start'] ) ) ),
				esc_html( self::format_time( (float) $segment['start'] ) ),
				esc_html( $segment['text'] )
			);
		}

		return '<figure ' . $wrapper_attrs . ' data-audio-transcriber>'
			. $media
			. '<ol class="audio-transcriber__segments" aria-label="' . esc_attr__( 'Transcript', 'audio-transcriber' ) . '">' . $items . '</ol>'
			. '</figure>';
	}

	/**
	 * Format seconds as m:ss or h:mm:ss.
	 *
	 * @param float $seconds Seconds.
	 */
	public static function format_time( float $seconds ): string {
		$total   = (int) floor( max( 0, $seconds ) );
		$hours   = intdiv( $total, 3600 );
		$minutes = intdiv( $total % 3600, 60 );
		$secs    = $total % 60;
		return $hours > 0 ? sprintf( '%d:%02d:%02d', $hours, $minutes, $secs ) : sprintf( '%d:%02d', $minutes, $secs );
	}
}

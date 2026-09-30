<?php
/**
 * WebVTT captions.
 *
 * @package AudioTranscriber
 */

namespace AudioTranscriber;

defined( 'ABSPATH' ) || exit;

/**
 * Stores a .vtt file per transcribed attachment and adds it as a <track> to core Audio and Video blocks.
 *
 * Browsers display captions for <video>. For <audio> the track is still valid markup, but browsers don't
 * render it; use the Interactive Transcript block for a visible, accessible transcript of audio.
 */
final class Captions {

	const SUBDIR = 'audio-transcriber';

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_filter( 'render_block', array( self::class, 'filter_block' ), 10, 2 );
		add_action( 'delete_attachment', array( self::class, 'delete' ) );
	}

	/**
	 * Save captions for an attachment.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $vtt           WebVTT content.
	 */
	public static function save( int $attachment_id, string $vtt ): bool {
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . self::SUBDIR;
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$relative = self::SUBDIR . '/' . $attachment_id . '.vtt';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing into uploads, same as core.
		if ( false === file_put_contents( trailingslashit( $uploads['basedir'] ) . $relative, $vtt ) ) {
			return false;
		}
		update_post_meta( $attachment_id, Meta::CAPTIONS, $relative );
		return true;
	}

	/**
	 * Public URL of an attachment's captions, if any.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function url( int $attachment_id ): ?string {
		$relative = (string) get_post_meta( $attachment_id, Meta::CAPTIONS, true );
		if ( '' === $relative ) {
			return null;
		}
		return trailingslashit( wp_upload_dir()['baseurl'] ) . $relative;
	}

	/**
	 * Remove an attachment's captions file.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function delete( int $attachment_id ): void {
		$relative = (string) get_post_meta( $attachment_id, Meta::CAPTIONS, true );
		if ( '' !== $relative ) {
			wp_delete_file( trailingslashit( wp_upload_dir()['basedir'] ) . $relative );
		}
	}

	/**
	 * Add captions to core/audio and core/video blocks.
	 *
	 * @param string $html  Rendered block HTML.
	 * @param array  $block Parsed block.
	 */
	public static function filter_block( string $html, array $block ): string {
		$tags = array(
			'core/audio' => 'audio',
			'core/video' => 'video',
		);
		$name = $block['blockName'] ?? '';
		if ( ! isset( $tags[ $name ] ) || empty( $block['attrs']['id'] ) ) {
			return $html;
		}
		$attachment_id = (int) $block['attrs']['id'];
		$url           = self::url( $attachment_id );
		if ( null === $url ) {
			return $html;
		}
		$meta = Meta::get( $attachment_id );
		return self::add_track( $html, $tags[ $name ], $url, $meta['language'] ? $meta['language'] : 'en', self::language_label( $meta['language'] ) );
	}

	/**
	 * Insert a captions <track> into the first <audio>/<video> element, unless it already has one.
	 *
	 * @param string $html   Element HTML.
	 * @param string $tag    audio or video.
	 * @param string $src    Captions URL.
	 * @param string $lang   Language code.
	 * @param string $label  Human-readable label.
	 */
	public static function add_track( string $html, string $tag, string $src, string $lang, string $label ): string {
		$close = '</' . $tag . '>';
		$pos   = stripos( $html, $close );
		if ( false === $pos || false !== stripos( $html, '<track' ) ) {
			return $html;
		}
		$track = sprintf(
			'<track kind="captions" src="%s" srclang="%s" label="%s" default>',
			esc_url( $src ),
			esc_attr( $lang ),
			esc_attr( $label )
		);
		return substr( $html, 0, $pos ) . $track . substr( $html, $pos );
	}

	/**
	 * Label for a caption track, e.g. "Urdu (auto-generated)".
	 *
	 * @param string $code Language code.
	 */
	private static function language_label( string $code ): string {
		$languages = Settings::languages( false ); // Never call the API while rendering a page.
		$name      = isset( $languages[ $code ] ) ? ucwords( str_replace( '_', ' ', $languages[ $code ] ) ) : strtoupper( $code );
		/* translators: %s: language name. */
		return sprintf( __( '%s (auto-generated)', 'audio-transcriber' ), '' !== $name ? $name : __( 'Captions', 'audio-transcriber' ) );
	}
}

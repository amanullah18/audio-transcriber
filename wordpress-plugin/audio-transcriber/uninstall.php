<?php
/**
 * Remove all plugin data when the plugin is deleted.
 *
 * @package AudioTranscriber
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'audio_transcriber_settings' );
delete_option( 'audio_transcriber_webhook_secret' );
delete_transient( 'audio_transcriber_languages' );
delete_transient( 'audio_transcriber_webhook_rejected' );

$audio_transcriber_meta_keys = array(
	'_audio_transcriber_job_id',
	'_audio_transcriber_status',
	'_audio_transcriber_text',
	'_audio_transcriber_segments',
	'_audio_transcriber_language',
	'_audio_transcriber_error',
	'_audio_transcriber_captions',
);
foreach ( $audio_transcriber_meta_keys as $audio_transcriber_key ) {
	delete_post_meta_by_key( $audio_transcriber_key );
}

// Caption files.
$audio_transcriber_dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'audio-transcriber';
foreach ( (array) glob( $audio_transcriber_dir . '/*.vtt' ) as $audio_transcriber_file ) {
	wp_delete_file( $audio_transcriber_file );
}
@rmdir( $audio_transcriber_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- best effort; may not exist.

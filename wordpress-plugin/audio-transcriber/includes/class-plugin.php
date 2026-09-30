<?php
/**
 * Plugin bootstrap.
 *
 * @package AudioTranscriber
 */

namespace AudioTranscriber;

defined( 'ABSPATH' ) || exit;

/**
 * Wires every component into WordPress.
 */
final class Plugin {

	/**
	 * Register all hooks.
	 */
	public static function boot(): void {
		Settings::register();
		Jobs::register();
		Webhook::register();
		Media::register();
		Captions::register();
		Transcript_Block::register();
		Rest_Fields::register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once AUDIO_TRANSCRIBER_DIR . 'includes/class-cli.php';
			\WP_CLI::add_command( 'audio-transcriber', Cli::class );
		}
	}

	/**
	 * Activation: create the webhook secret and schedule the polling fallback.
	 */
	public static function activate(): void {
		Settings::webhook_secret();
		Jobs::schedule();
	}

	/**
	 * Deactivation: stop polling.
	 */
	public static function deactivate(): void {
		Jobs::unschedule();
	}
}

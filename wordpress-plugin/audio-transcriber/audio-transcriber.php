<?php
/**
 * Plugin Name:       Audio Transcriber
 * Plugin URI:        https://github.com/amanullah18/audio-transcriber
 * Description:       Transcribe audio and video from the Media Library with your own self-hosted Whisper API. Adds an interactive transcript block, automatic captions, and developer hooks. Audio never leaves your servers.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            amanullah18
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       audio-transcriber
 *
 * @package AudioTranscriber
 */

defined( 'ABSPATH' ) || exit;

define( 'AUDIO_TRANSCRIBER_VERSION', '1.0.0' );
define( 'AUDIO_TRANSCRIBER_FILE', __FILE__ );
define( 'AUDIO_TRANSCRIBER_DIR', plugin_dir_path( __FILE__ ) );

require_once AUDIO_TRANSCRIBER_DIR . 'includes/class-meta.php';
require_once AUDIO_TRANSCRIBER_DIR . 'includes/class-api-client.php';
require_once AUDIO_TRANSCRIBER_DIR . 'includes/class-settings.php';
require_once AUDIO_TRANSCRIBER_DIR . 'includes/class-captions.php';
require_once AUDIO_TRANSCRIBER_DIR . 'includes/class-jobs.php';
require_once AUDIO_TRANSCRIBER_DIR . 'includes/class-webhook.php';
require_once AUDIO_TRANSCRIBER_DIR . 'includes/class-media.php';
require_once AUDIO_TRANSCRIBER_DIR . 'includes/class-transcript-block.php';
require_once AUDIO_TRANSCRIBER_DIR . 'includes/class-rest-fields.php';
require_once AUDIO_TRANSCRIBER_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( AudioTranscriber\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( AudioTranscriber\Plugin::class, 'deactivate' ) );

AudioTranscriber\Plugin::boot();

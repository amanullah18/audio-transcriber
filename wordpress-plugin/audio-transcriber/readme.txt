=== Audio Transcriber ===
Contributors: amanullah18
Tags: transcription, podcast, captions, accessibility, speech-to-text
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Transcribe audio and video with your own self-hosted Whisper server. Interactive transcripts, automatic captions, 100 languages. Audio never leaves your servers.

== Description ==

Audio Transcriber connects your Media Library to a self-hosted [Audio Transcriber API](https://github.com/amanullah18/audio-transcriber), an open-source speech-to-text service built on OpenAI's Whisper model (via faster-whisper).

Unlike plugins that send audio to a paid cloud API, **your audio goes only to a server you run**. There are no per-minute fees and no third-party data processing.

**Features**

* **Transcribe from the Media Library.** A "Transcribe" button on audio and video files, a bulk action, and a status column.
* **Auto-transcribe uploads** (optional).
* **Interactive Transcript block.** A player with the transcript beside it: click any timestamp to jump there, and the current line is highlighted while it plays. Also available as the `[audio_transcript id="123"]` shortcode.
* **Automatic captions.** A WebVTT captions file is generated and added to core Video blocks, so viewers can turn on subtitles.
* **100 languages**, including Urdu, Hindi, Arabic and many more, with auto-detection.
* **Instant results by webhook**, with a 5-minute background check as a fallback.

**For developers**

* Transcripts in the REST API: `GET /wp-json/wp/v2/media/<id>` includes an `audio_transcriber` field.
* Actions: `audio_transcriber_submitted`, `audio_transcriber_completed`, `audio_transcriber_failed`.
* Filters: `audio_transcriber_submit_fields`, `audio_transcriber_request_args`, `audio_transcriber_callback_url`.
* WP-CLI: `wp audio-transcriber check|transcribe|status|sync`.

Example: publish a draft post with the transcript as soon as it's ready.

`add_action( 'audio_transcriber_completed', function ( $attachment_id, $transcript ) {
	wp_insert_post( array(
		'post_title'   => get_the_title( $attachment_id ) . ' (transcript)',
		'post_content' => $transcript['text'],
		'post_status'  => 'draft',
	) );
}, 10, 2 );`

== Installation ==

1. Run the Audio Transcriber API on a server you control (Docker). See the [quick start](https://github.com/amanullah18/audio-transcriber#quick-start).
2. Install and activate this plugin.
3. Go to **Settings > Audio Transcriber**, enter the API URL and API key, and click **Test connection**.

For better security, define the key in `wp-config.php` instead of saving it in the database:

`define( 'AUDIO_TRANSCRIBER_API_KEY', 'your-key' );`

== Frequently Asked Questions ==

= Does my audio get sent to a third party? =

No. Files are uploaded only to the API URL you configure, which is a server you run.

= Do I need a GPU? =

No. The API runs on CPU. Smaller models (tiny, base, small) are fast on any modern server; larger ones are more accurate but slower.

= Results take up to 5 minutes to appear. Why? =

The API couldn't reach your site's webhook URL (common for sites on localhost or behind a firewall), so the plugin falls back to checking every 5 minutes. WP-Cron only runs when your site gets visits; use a real cron job for reliable timing.

= Why don't captions show on audio players? =

Browsers only display captions for video. For audio, use the Interactive Transcript block.

== Changelog ==

= 1.0.0 =
* Initial release.

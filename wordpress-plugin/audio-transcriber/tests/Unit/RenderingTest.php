<?php
/**
 * Captions track injection, timestamps, and error classification.
 *
 * @package AudioTranscriber
 */

namespace AudioTranscriber\Tests\Unit;

use AudioTranscriber\Captions;
use AudioTranscriber\Jobs;
use AudioTranscriber\Transcript_Block;

final class RenderingTest extends TestCase {

	public function test_adds_captions_track_inside_video_element(): void {
		$html = '<figure class="wp-block-video"><video controls src="v.mp4"></video></figure>';

		$this->assertSame(
			'<figure class="wp-block-video"><video controls src="v.mp4"><track kind="captions" src="https://site.test/c.vtt" srclang="ur" label="Urdu (auto-generated)" default></video></figure>',
			Captions::add_track( $html, 'video', 'https://site.test/c.vtt', 'ur', 'Urdu (auto-generated)' )
		);
	}

	public function test_does_not_add_a_second_track(): void {
		$html = '<video><track kind="captions" src="own.vtt"></video>';
		$this->assertSame( $html, Captions::add_track( $html, 'video', 'x.vtt', 'en', 'English' ) );
	}

	public function test_leaves_html_without_the_element_unchanged(): void {
		$html = '<figure>no media here</figure>';
		$this->assertSame( $html, Captions::add_track( $html, 'audio', 'x.vtt', 'en', 'English' ) );
	}

	/**
	 * @dataProvider times
	 */
	public function test_format_time( float $seconds, string $expected ): void {
		$this->assertSame( $expected, Transcript_Block::format_time( $seconds ) );
	}

	public function times(): array {
		return array(
			array( 0, '0:00' ),
			array( 5.9, '0:05' ),
			array( 65, '1:05' ),
			array( 3661.5, '1:01:01' ),
			array( -3, '0:00' ),
		);
	}

	public function test_detects_callback_rejection(): void {
		$rejected = new \WP_Error( 'audio_transcriber_http_422', 'Transcription API error (HTTP 422): callback_url must not point to a private address', array( 'status' => 422 ) );
		$bad_file = new \WP_Error( 'audio_transcriber_http_422', 'Transcription API error (HTTP 422): language: Input should be ...', array( 'status' => 422 ) );
		$down     = new \WP_Error( 'http_request_failed', 'cURL error 7: callback' );

		$this->assertTrue( Jobs::is_callback_rejection( $rejected ) );
		$this->assertFalse( Jobs::is_callback_rejection( $bad_file ) );
		$this->assertFalse( Jobs::is_callback_rejection( $down ) );
	}
}

<?php
/**
 * Webhook signature verification.
 *
 * @package AudioTranscriber
 */

namespace AudioTranscriber\Tests\Unit;

use AudioTranscriber\Webhook;

final class WebhookTest extends TestCase {

	const SECRET    = 'test-secret-0123456789abcdef';
	const TIMESTAMP = '1790000000';
	const BODY      = '{"event": "transcription.completed", "id": "4d03e536-7165-431e-8789-e57dcab579d5", "status": "completed"}';
	// Produced by the Python API's app.webhooks.sign() for the values above, so both sides agree on the format.
	const SIGNATURE = 'sha256=67c1aaa30b939d2a1f3f539b49e9068af09a82a93506bcbc594acf889786c5d5';

	public function test_accepts_signature_made_by_the_api(): void {
		$this->assertTrue( Webhook::verify( self::SECRET, self::TIMESTAMP, self::SIGNATURE, self::BODY, (int) self::TIMESTAMP + 10 ) );
	}

	public function test_rejects_wrong_secret(): void {
		$this->assertFalse( Webhook::verify( 'another-secret', self::TIMESTAMP, self::SIGNATURE, self::BODY, (int) self::TIMESTAMP ) );
	}

	public function test_rejects_tampered_body(): void {
		$body = str_replace( 'completed"}', 'failed"}', self::BODY );
		$this->assertFalse( Webhook::verify( self::SECRET, self::TIMESTAMP, self::SIGNATURE, $body, (int) self::TIMESTAMP ) );
	}

	public function test_rejects_replayed_old_request(): void {
		$this->assertFalse( Webhook::verify( self::SECRET, self::TIMESTAMP, self::SIGNATURE, self::BODY, (int) self::TIMESTAMP + Webhook::MAX_AGE + 1 ) );
	}

	public function test_rejects_timestamp_from_the_future(): void {
		$this->assertFalse( Webhook::verify( self::SECRET, self::TIMESTAMP, self::SIGNATURE, self::BODY, (int) self::TIMESTAMP - Webhook::MAX_AGE - 1 ) );
	}

	public function test_rejects_missing_or_malformed_headers(): void {
		$now = (int) self::TIMESTAMP;
		$this->assertFalse( Webhook::verify( self::SECRET, '', self::SIGNATURE, self::BODY, $now ) );
		$this->assertFalse( Webhook::verify( self::SECRET, '17e8', self::SIGNATURE, self::BODY, $now ) );
		$this->assertFalse( Webhook::verify( self::SECRET, self::TIMESTAMP, '', self::BODY, $now ) );
		$this->assertFalse( Webhook::verify( '', self::TIMESTAMP, self::SIGNATURE, self::BODY, $now ) );
	}
}

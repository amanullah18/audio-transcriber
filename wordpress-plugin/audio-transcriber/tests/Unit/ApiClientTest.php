<?php
/**
 * API client.
 *
 * @package AudioTranscriber
 */

namespace AudioTranscriber\Tests\Unit;

use AudioTranscriber\Api_Client;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class ApiClientTest extends TestCase {

	private string $file;

	protected function setUp(): void {
		parent::setUp();
		$this->file = tempnam( sys_get_temp_dir(), 'audio' );
		file_put_contents( $this->file, 'AUDIO-BYTES' );
		Functions\when( 'wp_generate_password' )->justReturn( 'BOUNDARY' );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $r ) => $r['response']['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $r ) => $r['body'] );
	}

	protected function tearDown(): void {
		unlink( $this->file );
		parent::tearDown();
	}

	private static function response( int $code, string $body ): array {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => $body,
		);
	}

	public function test_multipart_body_contains_fields_and_file(): void {
		$body = Api_Client::build_multipart(
			array(
				'model'    => 'small',
				'language' => '',
				'x'        => null,
			),
			'file',
			$this->file,
			'my "clip".ogg',
			'B'
		);

		$this->assertSame(
			"--B\r\nContent-Disposition: form-data; name=\"model\"\r\n\r\nsmall\r\n"
			. "--B\r\nContent-Disposition: form-data; name=\"file\"; filename=\"my clip.ogg\"\r\nContent-Type: application/octet-stream\r\n\r\nAUDIO-BYTES\r\n"
			. "--B--\r\n",
			$body
		);
	}

	public function test_submit_sends_authenticated_multipart_request(): void {
		Functions\expect( 'wp_remote_request' )
			->once()
			->andReturnUsing(
				function ( $url, $args ) {
					$this->assertSame( 'https://api.test/transcriptions', $url );
					$this->assertSame( 'POST', $args['method'] );
					$this->assertSame( 'secret-key', $args['headers']['X-API-Key'] );
					$this->assertSame( 'multipart/form-data; boundary=audio-transcriber-BOUNDARY', $args['headers']['Content-Type'] );
					$this->assertStringContainsString( "name=\"language\"\r\n\r\nur\r\n", $args['body'] );
					return self::response( 202, '{"id":"job-1","status":"queued"}' );
				}
			);

		$client = new Api_Client( 'https://api.test/', 'secret-key' );
		$this->assertSame(
			array(
				'id'     => 'job-1',
				'status' => 'queued',
			),
			$client->submit( $this->file, 'clip.ogg', array( 'language' => 'ur' ) )
		);
	}

	public function test_request_args_can_be_filtered(): void {
		Filters\expectApplied( 'audio_transcriber_request_args' )->once()->andReturnUsing(
			static function ( $args ) {
				$args['timeout'] = 99;
				return $args;
			}
		);
		Functions\expect( 'wp_remote_request' )->once()->andReturnUsing(
			function ( $url, $args ) {
				$this->assertSame( 99, $args['timeout'] );
				return self::response( 200, '{"status":"ok"}' );
			}
		);

		( new Api_Client( 'https://api.test', 'k' ) )->health();
	}

	public function test_http_error_becomes_wp_error_with_status_and_detail(): void {
		Functions\when( 'wp_remote_request' )->justReturn( self::response( 401, '{"detail":"Missing or invalid API key"}' ) );

		$result = ( new Api_Client( 'https://api.test', 'wrong' ) )->get_job( 'job-1' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( array( 'status' => 401 ), $result->get_error_data() );
		$this->assertStringContainsString( 'Missing or invalid API key', $result->get_error_message() );
	}

	public function test_unconfigured_client_does_not_make_requests(): void {
		Functions\expect( 'wp_remote_request' )->never();
		$this->assertInstanceOf( \WP_Error::class, ( new Api_Client( '', 'k' ) )->health() );
	}

	public function test_error_detail_formats_validation_errors(): void {
		$body = '{"detail":[{"loc":["body","language"],"msg":"Input should be \'urdu\'"},{"loc":["body","file"],"msg":"Field required"}]}';
		$this->assertSame( "language: Input should be 'urdu'; file: Field required", Api_Client::error_detail( $body ) );
		$this->assertSame( 'Bad Gateway', Api_Client::error_detail( '<h1>Bad Gateway</h1>' ) );
	}
}

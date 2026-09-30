<?php
/**
 * Unit test bootstrap. WordPress functions are mocked with Brain Monkey; no WordPress install is needed.
 *
 * @package AudioTranscriber
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

define( 'ABSPATH', '/tmp/wordpress/' );
define( 'AUDIO_TRANSCRIBER_DIR', dirname( __DIR__ ) . '/' );
define( 'AUDIO_TRANSCRIBER_FILE', AUDIO_TRANSCRIBER_DIR . 'audio-transcriber.php' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal stand-in for WordPress's WP_Error.
	 */
	class WP_Error {
		private string $code;
		private string $message;
		private $data;

		public function __construct( string $code = '', string $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

foreach ( glob( AUDIO_TRANSCRIBER_DIR . 'includes/class-*.php' ) as $file ) {
	if ( ! str_ends_with( $file, 'class-cli.php' ) ) {
		require_once $file;
	}
}

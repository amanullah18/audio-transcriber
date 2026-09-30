<?php
/**
 * Settings page.
 *
 * @package AudioTranscriber
 */

namespace AudioTranscriber;

defined( 'ABSPATH' ) || exit;

/**
 * Stores and renders the plugin settings (Settings > Audio Transcriber).
 *
 * The API URL, API key and webhook URL can also be set as constants in wp-config.php, which is preferable
 * for the key: AUDIO_TRANSCRIBER_API_URL, AUDIO_TRANSCRIBER_API_KEY, AUDIO_TRANSCRIBER_CALLBACK_URL.
 */
final class Settings {

	const OPTION           = 'audio_transcriber_settings';
	const SECRET_OPTION    = 'audio_transcriber_webhook_secret';
	const LANGUAGES_CACHE  = 'audio_transcriber_languages';
	const PAGE             = 'audio-transcriber';
	const TEST_ACTION      = 'audio_transcriber_test_connection';
	const MODELS           = array( 'tiny', 'base', 'small', 'medium', 'large-v3' );
	const CONSTANT_BACKING = array(
		'api_url' => 'AUDIO_TRANSCRIBER_API_URL',
		'api_key' => 'AUDIO_TRANSCRIBER_API_KEY',
	);

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_init', array( self::class, 'register_setting' ) );
		add_action( 'admin_post_' . self::TEST_ACTION, array( self::class, 'handle_test_connection' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( AUDIO_TRANSCRIBER_FILE ), array( self::class, 'action_links' ) );
	}

	/**
	 * Default values.
	 */
	public static function defaults(): array {
		return array(
			'api_url'         => '',
			'api_key'         => '',
			'model'           => 'small',
			'language'        => '',
			'auto_transcribe' => false,
			'use_webhook'     => true,
		);
	}

	/**
	 * All settings, with wp-config.php constants taking precedence.
	 */
	public static function all(): array {
		$settings = array_merge( self::defaults(), (array) get_option( self::OPTION, array() ) );
		foreach ( self::CONSTANT_BACKING as $key => $constant ) {
			if ( defined( $constant ) ) {
				$settings[ $key ] = (string) constant( $constant );
			}
		}
		return $settings;
	}

	/**
	 * A single setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public static function get( string $key ) {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Whether the API URL and key are set.
	 */
	public static function is_configured(): bool {
		return '' !== self::get( 'api_url' ) && '' !== self::get( 'api_key' );
	}

	/**
	 * Per-site secret used by the API to sign webhooks. Created on first use.
	 */
	public static function webhook_secret(): string {
		$secret = (string) get_option( self::SECRET_OPTION, '' );
		if ( '' === $secret ) {
			$secret = wp_generate_password( 48, false );
			update_option( self::SECRET_OPTION, $secret, false );
		}
		return $secret;
	}

	/**
	 * URL the API should call when a job finishes.
	 */
	public static function callback_url(): string {
		$url = defined( 'AUDIO_TRANSCRIBER_CALLBACK_URL' ) ? (string) AUDIO_TRANSCRIBER_CALLBACK_URL : Webhook::url();

		/**
		 * Filters the webhook URL sent to the API, e.g. when the API reaches WordPress by an internal hostname.
		 *
		 * @param string $url Webhook URL.
		 */
		return (string) apply_filters( 'audio_transcriber_callback_url', $url );
	}

	/**
	 * Supported languages ({code => name}), cached for a day. Empty if the API is unreachable.
	 *
	 * @param bool $fetch Whether to call the API on a cache miss. Pass false on front-end requests.
	 */
	public static function languages( bool $fetch = true ): array {
		$cached = get_transient( self::LANGUAGES_CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( ! $fetch ) {
			return array();
		}
		$response = Api_Client::from_settings()->languages();
		if ( is_wp_error( $response ) ) {
			return array();
		}
		$languages = array();
		foreach ( $response as $language ) {
			if ( isset( $language['code'], $language['name'] ) ) {
				$languages[ (string) $language['code'] ] = (string) $language['name'];
			}
		}
		set_transient( self::LANGUAGES_CACHE, $languages, DAY_IN_SECONDS );
		return $languages;
	}

	/**
	 * Add the settings page.
	 */
	public static function add_page(): void {
		add_options_page(
			__( 'Audio Transcriber', 'audio-transcriber' ),
			__( 'Audio Transcriber', 'audio-transcriber' ),
			'manage_options',
			self::PAGE,
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 */
	public static function action_links( array $links ): array {
		$url = admin_url( 'options-general.php?page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'audio-transcriber' ) . '</a>' );
		return $links;
	}

	/**
	 * Register the option with its sanitizer.
	 */
	public static function register_setting(): void {
		register_setting(
			self::PAGE,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanitize submitted settings.
	 *
	 * @param mixed $input Raw input.
	 */
	public static function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$previous = array_merge( self::defaults(), (array) get_option( self::OPTION, array() ) );
		$api_key  = isset( $input['api_key'] ) ? trim( sanitize_text_field( $input['api_key'] ) ) : '';
		$model    = isset( $input['model'] ) ? sanitize_text_field( $input['model'] ) : 'small';
		$language = isset( $input['language'] ) ? sanitize_key( $input['language'] ) : '';

		$clean = array(
			'api_url'         => untrailingslashit( esc_url_raw( trim( (string) ( $input['api_url'] ?? '' ) ) ) ),
			// The key is never printed back into the form, so an empty field means "keep the saved key".
			'api_key'         => '' !== $api_key ? $api_key : $previous['api_key'],
			'model'           => in_array( $model, self::MODELS, true ) ? $model : 'small',
			'language'        => $language,
			'auto_transcribe' => ! empty( $input['auto_transcribe'] ),
			'use_webhook'     => ! empty( $input['use_webhook'] ),
		);

		if ( $clean['api_url'] !== $previous['api_url'] ) {
			delete_transient( self::LANGUAGES_CACHE );
		}
		return $clean;
	}

	/**
	 * Run the connection test and report the result as an admin notice.
	 */
	public static function handle_test_connection(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'audio-transcriber' ), 403 );
		}
		check_admin_referer( self::TEST_ACTION );

		$client = Api_Client::from_settings();
		$health = $client->health();
		if ( is_wp_error( $health ) ) {
			$notice = array( 'error', $health->get_error_message() );
		} elseif ( 'ok' !== ( $health['status'] ?? '' ) ) {
			/* translators: %s: JSON health report from the API. */
			$notice = array( 'error', sprintf( __( 'The API is reachable but unhealthy: %s', 'audio-transcriber' ), wp_json_encode( $health ) ) );
		} else {
			$auth   = $client->check_auth();
			$notice = is_wp_error( $auth )
				? array( 'error', $auth->get_error_message() )
				: array( 'success', __( 'Connected. The API is healthy and your API key works.', 'audio-transcriber' ) );
		}

		delete_transient( self::LANGUAGES_CACHE );
		Media::flash( $notice[0], $notice[1] );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE ) );
		exit;
	}

	/**
	 * Render the settings page.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings   = self::all();
		$languages  = self::is_configured() ? self::languages() : array();
		$name       = self::OPTION;
		$key_locked = defined( 'AUDIO_TRANSCRIBER_API_KEY' );
		$url_locked = defined( 'AUDIO_TRANSCRIBER_API_URL' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Audio Transcriber', 'audio-transcriber' ); ?></h1>
			<p>
				<?php esc_html_e( 'Connect to your self-hosted Audio Transcriber API. Audio is sent only to this server.', 'audio-transcriber' ); ?>
				<a href="https://github.com/amanullah18/audio-transcriber#quick-start" target="_blank" rel="noopener"><?php esc_html_e( 'How to run the API', 'audio-transcriber' ); ?></a>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( self::PAGE ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="audio-transcriber-api-url"><?php esc_html_e( 'API URL', 'audio-transcriber' ); ?></label></th>
						<td>
							<input type="url" class="regular-text" id="audio-transcriber-api-url" name="<?php echo esc_attr( $name ); ?>[api_url]"
								value="<?php echo esc_attr( $settings['api_url'] ); ?>" placeholder="https://transcriber.example.com" <?php disabled( $url_locked ); ?>>
							<?php if ( $url_locked ) : ?>
								<p class="description"><?php esc_html_e( 'Set by AUDIO_TRANSCRIBER_API_URL in wp-config.php.', 'audio-transcriber' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="audio-transcriber-api-key"><?php esc_html_e( 'API key', 'audio-transcriber' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="audio-transcriber-api-key" name="<?php echo esc_attr( $name ); ?>[api_key]" value="" autocomplete="new-password"
								placeholder="<?php echo esc_attr( '' !== $settings['api_key'] ? __( 'Saved. Leave empty to keep it.', 'audio-transcriber' ) : '' ); ?>" <?php disabled( $key_locked ); ?>>
							<p class="description">
								<?php
								echo $key_locked
									? esc_html__( 'Set by AUDIO_TRANSCRIBER_API_KEY in wp-config.php.', 'audio-transcriber' )
									: esc_html__( 'Tip: define AUDIO_TRANSCRIBER_API_KEY in wp-config.php instead of storing the key in the database.', 'audio-transcriber' );
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="audio-transcriber-model"><?php esc_html_e( 'Default model', 'audio-transcriber' ); ?></label></th>
						<td>
							<select id="audio-transcriber-model" name="<?php echo esc_attr( $name ); ?>[model]">
								<?php foreach ( self::MODELS as $model ) : ?>
									<option value="<?php echo esc_attr( $model ); ?>" <?php selected( $settings['model'], $model ); ?>><?php echo esc_html( $model ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Larger models are more accurate but slower.', 'audio-transcriber' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="audio-transcriber-language"><?php esc_html_e( 'Default language', 'audio-transcriber' ); ?></label></th>
						<td>
							<?php if ( $languages ) : ?>
								<select id="audio-transcriber-language" name="<?php echo esc_attr( $name ); ?>[language]">
									<option value=""><?php esc_html_e( 'Auto-detect', 'audio-transcriber' ); ?></option>
									<?php foreach ( $languages as $code => $language_name ) : ?>
										<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $settings['language'], $code ); ?>><?php echo esc_html( ucwords( str_replace( '_', ' ', $language_name ) ) ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php else : ?>
								<input type="text" class="small-text" id="audio-transcriber-language" name="<?php echo esc_attr( $name ); ?>[language]" value="<?php echo esc_attr( $settings['language'] ); ?>" placeholder="ur">
								<p class="description"><?php esc_html_e( 'ISO code (e.g. en, hi, ur), or empty to auto-detect. Connect to the API to choose from a list.', 'audio-transcriber' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Automation', 'audio-transcriber' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[auto_transcribe]" value="1" <?php checked( $settings['auto_transcribe'] ); ?>>
								<?php esc_html_e( 'Automatically transcribe new audio and video uploads', 'audio-transcriber' ); ?>
							</label>
							<br>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[use_webhook]" value="1" <?php checked( $settings['use_webhook'] ); ?>>
								<?php esc_html_e( 'Receive results instantly by webhook', 'audio-transcriber' ); ?>
							</label>
							<p class="description">
								<?php
								/* translators: %s: webhook URL. */
								printf( esc_html__( 'The API calls %s when a job finishes. If it can\'t reach your site, results are still collected every 5 minutes.', 'audio-transcriber' ), '<code>' . esc_html( self::callback_url() ) . '</code>' );
								?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Connection', 'audio-transcriber' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::TEST_ACTION ); ?>">
				<?php wp_nonce_field( self::TEST_ACTION ); ?>
				<?php submit_button( __( 'Test connection', 'audio-transcriber' ), 'secondary', 'submit', false, self::is_configured() ? array() : array( 'disabled' => 'disabled' ) ); ?>
			</form>
		</div>
		<?php
	}
}

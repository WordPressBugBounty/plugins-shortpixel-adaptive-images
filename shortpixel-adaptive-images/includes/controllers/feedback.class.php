<?php

	namespace ShortPixel\AI;

	use ShortPixelAI;

	/**
	 * Deactivation feedback popup and the NPS-style rating survey, both sent to the ShortPixel feedback API.
	 *
	 * Endpoint: `POST https://api.shortpixel.com/v2/feedback.php` (`ShortPixelAI::SP_API . 'v2/feedback.php'`) through
	 * `Request::post( $url, true, [ 'body' => $body ] )`. Payload:
	 * `{ user: { email, first_name, last_name, domain } (empty strings when anonymous),
	 *    wordpress: { deactivated_plugin: { slug, name, version, author, uninstall_reason, uninstall_details },
	 *                 [locale, wp_version, multisite, php_version, themes: { installed, active }, plugins: { installed,
	 *                 active }] (only on beta versions, `ShortPixelAI::is_beta()`) },
	 *    key?: <API key> (not anonymous) }`.
	 *
	 * AJAX: `wp_ajax_shortpixel_ai_handle_feedback_action` -> `Feedback\Actions::handle` (deactivation popup, nonce +
	 * `activate_plugins`); `wp_ajax_shortpixel_ai_send_rating` and `wp_ajax_shortpixel_ai_send_feedback` ->
	 * `handleSurveyAjax()` (survey buttons of the `recommend_survey` notice).
	 *
	 * Instantiated by `ShortPixelAI` in the back end and in `shortpixel_ai*` admin AJAX requests that carry `data`.
	 */
	class Feedback {
		/**
		 * @var \ShortPixel\AI\Feedback $instance The singleton, set by the first constructor call.
		 */
		private static $instance;

		/**
		 * @var \ShortPixelAI $controller AI Controller
		 */
		protected $controller;

        /**
         * @var \ShortPixelAILogger $logger Plugin logger (set in the constructor, not used by this class).
         */
        private $logger;

		/**
		 * Returns the singleton, creating it (and registering the hooks) on first call.
		 *
		 * @param \ShortPixelAI $controller
		 *
		 * @return \ShortPixel\AI\Feedback
		 */
		public static function _( $controller ) {
			return self::$instance instanceof self ? self::$instance : new self( $controller );
		}

		/**
		 * Sends the deactivation feedback.
		 *
		 * `uninstall_details` is the suggestions text plus, when not anonymous, a `Domain: <site domain>` line. The
		 * API key is added only when not anonymous and `get_cdn_domain_usage()` (remote) returns something truthy.
		 *
		 * @param string      $reason      Value of the popup's `feedback` radio (`temp`, `setup`, `documentation`,
		 *                                 `features`, `better-plugin`, `incompatibility`, `maintenance`).
		 * @param string|null $suggestions Free text from the popup textarea (sent unsanitised).
		 * @param bool        $anonymous   True to omit the user data, domain and key.
		 *
		 * @return mixed|\WP_Error|false Decoded API response, WP_Error, or false for an empty/non-string reason.
		 */
		public static function send( $reason, $suggestions = null, $anonymous = false ) {
			if ( !is_string( $reason ) || empty( $reason ) ) {
				return false;
			}

			$wordpress = self::collectWordpressData( ShortPixelAI::is_beta() );

			$wordpress[ 'deactivated_plugin' ][ 'uninstall_reason' ]  = $reason;
			$wordpress[ 'deactivated_plugin' ][ 'uninstall_details' ] = '';

			if ( !empty( $suggestions ) ) {
				$wordpress[ 'deactivated_plugin' ][ 'uninstall_details' ] .= $suggestions;
			}

			if ( !$anonymous ) {
				$wordpress[ 'deactivated_plugin' ][ 'uninstall_details' ] .= ( empty( $wordpress[ 'deactivated_plugin' ][ 'uninstall_details' ] ) ? '' : PHP_EOL . PHP_EOL ) . 'Domain: ' . \ShortPixelDomainTools::get_site_domain();
			}

			$body = [
				'user'      => self::collectUserData( $anonymous ),
				'wordpress' => $wordpress,
			];

			$spai_key = Options::_()->settings_general_apiKey;

			if ( !empty( $spai_key ) && !!\ShortPixelDomainTools::get_cdn_domain_usage(null, $spai_key ) && !$anonymous ) {
				$body[ 'key' ] = $spai_key;
			}

            return Request::post( ShortPixelAI::SP_API . 'v2/feedback.php', true, [ 'body' => $body ] );
		}

		/**
		 * `admin_footer-plugins.php` callback: prints the hidden deactivation popup (`.deactivation-popup`,
		 * `data-slug` = text domain) used by `admin.js`.
		 *
		 * Fields: `feedback` radios (reasons), `suggestions` textarea, `anonymous` checkbox, `settings` radios
		 * (`keep` | `remove`; `revert` is behind `if(false)`), and the `[data-action="deactivation"]` button. The
		 * nonce comes from the localized `spaiNonce.nonce`, not from this markup.
		 *
		 * @return void
		 */
		public function generatePopUp() {
			$plugin_data = get_plugin_data( SHORTPIXEL_AI_PLUGIN_FILE );

			?>
			<div class="deactivation-popup hidden" data-type="wrapper" data-slug="<?= $plugin_data[ 'TextDomain' ]; ?>">
				<div class="overlay">
					<div class="close"></div>
					<div class="body">
						<section class="title-wrap"><?= __( 'Sorry to see you go', 'shortpixel-adaptive-images' ); /*$plugin_data[ 'Name' ];*/ ?></section>
						<section class="messages-wrap">
							<p><?= __( 'Before you deactivate the plugin, would you quickly give us your reason for doing so?', 'shortpixel-adaptive-images' ); ?></p>
						</section>
						<section class="options-wrap">
                            <label>
                                <input type="radio" name="feedback" value="temp">
                                <?= __( 'Temporary deactivation', 'shortpixel-adaptive-images' ); ?>
                            </label>
							<label>
								<input type="radio" name="feedback" value="setup">
								<?= __( 'Set up is too difficult', 'shortpixel-adaptive-images' ); ?>
							</label>
							<label>
								<input type="radio" name="feedback" value="documentation">
								<?= __( 'Lack of documentation', 'shortpixel-adaptive-images' ); ?>
							</label>
							<label>
								<input type="radio" name="feedback" value="features">
								<?= __( 'Not the features I wanted', 'shortpixel-adaptive-images' ); ?>
							</label>
							<label>
								<input type="radio" name="feedback" value="better-plugin">
								<?= __( 'Found a better plugin', 'shortpixel-adaptive-images' ); ?>
							</label>
							<label>
								<input type="radio" name="feedback" value="incompatibility">
								<?= __( 'Incompatible with theme or plugin', 'shortpixel-adaptive-images' ); ?>
							</label>
							<label>
								<input type="radio" name="feedback" value="maintenance">
								<?= __( 'Other', 'shortpixel-adaptive-images' ); ?>
							</label>
						</section>
						<section class="messages-wrap hidden" data-feedback>
							<p class="hidden" data-feedback="setup"><?= __( 'What was the difficult part?', 'shortpixel-adaptive-images' ); ?></p>
							<p class="hidden" data-feedback="documentation"><?= __( 'What can we describe more?', 'shortpixel-adaptive-images' ); ?></p>
							<p class="hidden" data-feedback="features"><?= __( 'How could we improve?', 'shortpixel-adaptive-images' ); ?></p>
							<p class="hidden" data-feedback="better-plugin"><?= __( 'Can you mention it?', 'shortpixel-adaptive-images' ); ?></p>
							<p class="hidden" data-feedback="incompatibility"><?= __( 'With what plugin or theme is incompatible?', 'shortpixel-adaptive-images' ); ?></p>
							<p class="hidden" data-feedback="maintenance"><?= __( 'Please specify:', 'shortpixel-adaptive-images' ); ?></p>
						</section>
						<section class="options-wrap hidden" data-feedback>
							<label>
								<textarea name="suggestions" rows="2"></textarea>
							</label>
						</section>
						<section class="messages-wrap hidden" data-feedback>
							<p><?= __( 'Would you like to share your e-mail with us so that we can write you back?', 'shortpixel-adaptive-images' ); ?></p>
						</section>
						<section class="options-wrap hidden" data-feedback>
							<label>
								<input type="checkbox" name="anonymous" value="1">
								<?= __( 'No, I\'d like to stay anonymous', 'shortpixel-adaptive-images' ); ?>
							</label>
						</section>
						<section class="messages-wrap">
							<p><?= __( 'Please, choose one of the options presented below to decide what we should do with the plugin\'s settings.', 'shortpixel-adaptive-images' ); ?></p>
						</section>
						<section class="options-wrap">
							<label>
								<input type="radio" name="settings" value="keep" checked>
								<?= __( 'Keep plugin data as it is', 'shortpixel-adaptive-images' ); ?>
							</label>
							<label>
								<input type="radio" name="settings" value="remove">
								<?= __( 'Remove all plugin data and settings', 'shortpixel-adaptive-images' ); ?>
							</label>
                            <?php if(false) { ?>
							<label>
								<input type="radio" name="settings" value="revert">
								<?= __( 'Revert settings to the <strong>1.x.x</strong> version', 'shortpixel-adaptive-images' ); ?>
							</label>
                            <?php } ?>
						</section>
						<section class="buttons-wrap clearfix">
							<button class="dark_blue_link" data-action="deactivation"><?= __( 'Deactivate', 'shortpixel-adaptive-images' ); ?></button>
						</section>
					</div>
					<div class="scroll-down hidden">
						<div class="mouse">
							<div class="wheel"></div>
						</div>
					</div>
				</div>
			</div>
			<?php
		}

		/**
		 * Builds the `wordpress` part of the payload (see class docblock).
		 *
		 * @todo `collectWordpressData()` compares `get_bloginfo( 'version' ) >= 4.7` as string vs float; use
		 *       `version_compare()`. WordPress 4.7 is far below the supported floor, so `get_user_locale()` could be
		 *       used directly.
		 *
		 * @param bool $detailed True to add locale, versions, multisite flag, themes and plugins.
		 *
		 * @return array
		 */
		private static function collectWordpressData( $detailed = false ) {
			$current_plugin = get_plugin_data( SHORTPIXEL_AI_PLUGIN_FILE );

			// Plugin data
			$data = [
				'deactivated_plugin' => [
					'slug'    => $current_plugin[ 'TextDomain' ],
					'name'    => $current_plugin[ 'Name' ],
					'version' => $current_plugin[ 'Version' ],
					'author'  => $current_plugin[ 'AuthorName' ],
				],
			];

			if ( !!$detailed ) {
				$data[ 'locale' ]      = ( get_bloginfo( 'version' ) >= 4.7 ) ? get_user_locale() : get_locale();
				$data[ 'wp_version' ]  = get_bloginfo( 'version' );
				$data[ 'multisite' ]   = is_multisite();
				$data[ 'php_version' ] = PHP_VERSION;
				$data[ 'themes' ]      = self::getThemes();
				$data[ 'plugins' ]     = self::getPlugins();
			}

			return $data;
		}

		/**
		 * Returns installed and active plugins as title-cased folder names (`shortpixel-adaptive-images/…` ->
		 * `Shortpixel Adaptive Images`).
		 *
		 * @todo `getPlugins()` skips plugins found in `$active` while `$active` is still empty (it is filled in the next
		 *       loop, and with title-cased names, not plugin ids), so `installed` also lists the active plugins. Build
		 *       `$active` first and compare against `active_plugins` ids.
		 *
		 * @return array{installed: string[], active: string[]}
		 */
		private static function getPlugins() {
			if ( !function_exists( 'get_plugins' ) ) {
				include ABSPATH . '/wp-admin/includes/plugin.php';
			}

			$plugins   = get_plugins();
			$option    = get_option( 'active_plugins', [] );
			$active    = [];
			$installed = [];
			foreach ( $plugins as $id => $info ) {
				if ( in_array( $id, $active ) ) {
					continue;
				}

				$id = explode( '/', $id );
				$id = ucwords( str_replace( '-', ' ', $id[ 0 ] ) );

				$installed[] = $id;
			}

			foreach ( $option as $id ) {
				$id = explode( '/', $id );
				$id = ucwords( str_replace( '-', ' ', $id[ 0 ] ) );

				$active[] = $id;
			}

			return [
				'installed' => $installed,
				'active'    => $active,
			];
		}

		/**
		 * Returns the installed (non-active) themes and the active theme's slug, name, version and author.
		 *
		 * @return array{installed: array, active: array}
		 */
		private static function getThemes() {
			$theme = wp_get_theme();

			return [
				'installed' => self::getInstalledThemes(),
				'active'    => [
					'slug'    => get_stylesheet(),
					'name'    => $theme->get( 'Name' ),
					'version' => $theme->get( 'Version' ),
					'author'  => $theme->get( 'Author' ),
				],
			];
		}

		/**
		 * Returns the installed themes except the active stylesheet, keyed by slug.
		 *
		 * @return array<string, array{slug: string, name: string, version: string, author: string}>
		 */
		private static function getInstalledThemes() {
			$installed = wp_get_themes();
			$theme     = get_stylesheet();
			$data      = [];

			foreach ( $installed as $slug => $info ) {
				if ( $slug === $theme ) {
					continue;
				}

				$data[ $slug ] = [
					'slug'    => $slug,
					'name'    => $info->get( 'Name' ),
					'version' => $info->get( 'Version' ),
					'author'  => $info->get( 'Author' ),
				];
			}

			return $data;
		}

		/**
		 * Returns the current user's e-mail, first/last name and the site domain, or empty strings when anonymous.
		 *
		 * @param bool $anonymous
		 *
		 * @return array{email: string, first_name: string, last_name: string, domain: string}
		 */
		private static function collectUserData( $anonymous ) {
			$user = wp_get_current_user();

			$return = [
				'email'      => '',
				'first_name' => '',
				'last_name'  => '',
				'domain'     => '',
			];

			if ( $user && !$anonymous ) {
				$return[ 'email' ]      = $user->user_email;
				$return[ 'first_name' ] = $user->first_name;
				$return[ 'last_name' ]  = $user->last_name;
				$return[ 'domain' ]     = \ShortPixelDomainTools::get_site_domain();
			}

			return $return;
		}

		/**
		 * Stores the singleton, logger and controller and registers the popup and AJAX hooks (see class docblock).
		 *
		 * @param \ShortPixelAI $controller
		 */
		private function __construct( $controller ) {
			if ( !isset( self::$instance ) || !self::$instance instanceof self ) {
				self::$instance = $this;
			}
            $this->logger = \ShortPixelAILogger::instance();
			$this->controller = $controller;

			add_action( 'admin_footer-plugins.php', [ $this, 'generatePopUp' ] );
			add_action( 'wp_ajax_shortpixel_ai_handle_feedback_action', [ 'ShortPixel\AI\Feedback\Actions', 'handle' ] );

            add_action( 'wp_ajax_shortpixel_ai_send_rating', [ $this, 'handleSurveyAjax' ] );
            add_action( 'wp_ajax_shortpixel_ai_send_feedback', [ $this, 'handleSurveyAjax' ] );
        }

        /**
         * AJAX handler of `shortpixel_ai_send_rating` and `shortpixel_ai_send_feedback`.
         *
         * Reads `data[rating]` (`intval`, must be 1..10, else `wp_send_json_error( 'Invalid rating' )`) and
         * `data[feedback]` (`sanitize_text_field`), sends them with `sendSurvey()`, answers `wp_send_json_error()` on a
         * `WP_Error`, otherwise dismisses `recommend_survey` and answers `wp_send_json_success()`.
         *
         * @todo `handleSurveyAjax()` checks neither a nonce nor a capability, so any logged-in user (or a CSRF page)
         *       can send ratings in that user's name and dismiss the survey for everybody. Add
         *       `Page::checkSpaiNonce()` and a `manage_options` check (and send the nonce from `notice.js`).
         *
         * @return void
         */
        public function handleSurveyAjax() {


            $rating   = isset($_POST['data']['rating']) ? intval($_POST['data']['rating']) : 0;
            $feedback = isset($_POST['data']['feedback']) ? sanitize_text_field($_POST['data']['feedback']) : '';

            if ( ! $rating || $rating < 1 || $rating > 10 ) {
                wp_send_json_error(__('Invalid rating', 'shortpixel-adaptive-images'));
            }
            $response = self::sendSurvey( $rating, $feedback );

            if ( is_wp_error($response) ) {
                wp_send_json_error(__('Feedback could not be sent.', 'shortpixel-adaptive-images'));
            }

            Notice::dismiss( 'recommend_survey' );
            wp_send_json_success();

        }


        /**
         * Sends a survey rating as feedback with `uninstall_reason = 'rating'` and
         * `uninstall_details = 'Rating: N – Feedback: …'`, plus the API key when set and not anonymous.
         *
         * @param int    $rating    1..10; anything else returns false.
         * @param string $feedback  Optional text.
         * @param bool   $anonymous True to omit the user data and key.
         *
         * @return mixed|\WP_Error|false Decoded API response, WP_Error, or false for an invalid rating.
         */
        public  function sendSurvey( $rating, $feedback, $anonymous = false ) {

            if ( ! is_int( $rating ) || $rating < 1 || $rating > 10 ) {
                return false;
            }
            $wordpress = self::collectWordpressData( ShortPixelAI::is_beta() );
            $wordpress['deactivated_plugin']['uninstall_reason']  = 'rating';
            $wordpress['deactivated_plugin']['uninstall_details'] = 'Rating: ' . $rating
                . ( $feedback ? ' – Feedback: ' . $feedback : '' );

            $body = [
                'user'      => self::collectUserData( $anonymous ),
                'wordpress' => $wordpress,
            ];
            $spai_key = Options::_()->settings_general_apiKey;
            if ( $spai_key && ! $anonymous ) {
                $body['key'] = $spai_key;
            }

            return Request::post( 'https://api.shortpixel.com/v2/feedback.php', true, [ 'body' => $body ] );
        }
    }

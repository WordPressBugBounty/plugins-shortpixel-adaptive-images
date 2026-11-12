<?php

	namespace ShortPixel\AI;

	use ShortPixel\AI\Notice\Actions;
    use ShortPixelAI;
	use ShortPixel\AI\Notice\Constants;
	use ShortPixel\AI\Options\Option;

	/**
	 * Admin notices of the plugin: markup, dismissal storage, the list of local notices and the remote feed.
	 *
	 * Markup (`$template`): `<div class="notice notice-<type> [is-dismissible]" data-icon data-causer data-spainonce
	 * data-plugin="short-pixel-ai"><div class="body-wrap"><div class="message-wrap">…</div><div class="buttons-wrap">…
	 * </div></div></div>`. `assets/js/notice.js` binds to `.notice[data-plugin="short-pixel-ai"]` buttons with
	 * `[data-action]` and to `button.notice-dismiss` (the X, which sends action `dismiss`), and posts
	 * `action=shortpixel_ai_handle_notice_action`, `causer` (from `data-causer`), `spainonce` (from `data-spainonce`),
	 * `data[action]`, `data[additional]` to `Notice\Actions::handle()`. A button whose action names a `jQuery.<fn>`
	 * runs that function instead (e.g. `spaiProposeUpgrade`).
	 *
	 * Dismissals: `Options` path `notices.dismissed`, an `Option` whose keys are the snake_cased causers
	 * (`Notice::dismiss( 'wp rocket lazy' )` stores `notices.dismissed.wp_rocket_lazy`; snake-casing happens in
	 * `Options::optionCreator()`) and whose values are `time()` or a given value (the version for `beta`).
	 *
	 * Local notices rendered by `renderNotices()` (`admin_notices`, `manage_options` only). "Key" is the dismissal key
	 * the condition checks; "Handler" is the `Notice\Actions` method reached from `data-causer`:
	 *
	 * | Causer (`data-causer`)   | Shown when                                                                      | Key checked             | Handler / actions |
	 * |--------------------------|---------------------------------------------------------------------------------|-------------------------|-------------------|
	 * | `ao`                     | `ShortPixelAI::is_conflict() === 'ao'`; not dismissible                         | none                    | `handleAo`: `solve conflict` |
	 * | `avadalazy`, `ginger`, `speedoptimizer`, `divitoolbox` | matching `is_conflict()`; not dismissible, link buttons only | none        | none |
	 * | `mbstring`               | `mb_convert_case()` missing; not dismissible                                    | none                    | none |
	 * | `beta`                   | `ShortPixelAI::is_beta()` and dismissed value is not the current version        | `beta`                  | `handleBeta`: `dismiss` (stores version) |
	 * | `on boarding`            | never (`false &&` guard)                                                        | `on_boarding`           | `handleOnBoarding`: `redirect`, `dismiss` |
	 * | `lazy`                   | transient `shortpixelai_thrown_notice['when'] == 'lazy'` (deleted afterwards)   | `lazy`                  | `handleLazy`: `dismiss forever` only |
	 * | `recommend_survey`       | settings page, `shortpixel_ai_installed_time` >= 30 days ago                     | `recommend_survey`      | `handleRecommendSurvey`: `dismiss`; ratings go to `Feedback` |
	 * | `wp rocket defer js`     | WP Rocket `defer-all-js` active                                                 | `wp_rocket_defer_js`    | `handleWpRocketDeferJs`: `solve conflict`, `dismiss` |
	 * | `wp rocket lazy`         | WP Rocket `lazyload` active                                                     | `wp_rocket_lazy`        | `handleWpRocketLazy`: `dismiss` |
	 * | `wprocketcss`            | `parse_css_files > 0`, WP Rocket `minify-css` without `css-filter`              | `wprocketcss`           | `handleWpRocketCss` (case-insensitive match): `dismiss` stores `wp_rocket_css` |
	 * | `key`                    | not on wizard, `flags.all.account` falsy, remote account has a `key`            | `key`                   | `handleKey`: `use account`, `dismiss` |
	 * | `remote_get_error`       | account or domain status call returns no `Status` or `Status == -3`             | none                    | none (no `handleRemoteGetError`) |
	 * | `credits`                | `getCreditsNoticeInfo()` non-empty (domain `Status` not 2 and not -3)           | `credits`               | `handleCredits`: `check`, `dismiss` |
	 * | `twicelossy`             | SPAI `lossy`, SPIO active with `wp-short-pixel-compression == '1'`, not wizard  | `twicelossy`            | `handleTwicelossy`: `dismiss` |
	 * | `missing jquery`         | `tests.front_end.missing_jquery`                                                | `missing_jquery`        | `handleMissingJquery`: `re-check`, `dismiss` |
	 * | `temporary redirect`     | refreshed domain status has `TemporaryRedirectOrigin` true                      | `temporary_redirect`    | `handleTemporaryRedirect`: `re-check`, `dismiss` stores `missing_jquery` |
	 * | `swift performance`      | Swift Performance `has_bug` and `has_conflict`, CSS parsing on                  | `swift_performance`     | `handleSwiftPerformance`: `solve conflict`, `dismiss` |
	 * | `imagify`                | Imagify `has_conflict`                                                          | `imagify`               | `handleImagify`: `solve conflict`, `dismiss` |
	 * | `spio webp`              | SPIO active with `wp-short-pixel-create-webp-markup`                            | `spio_webp`             | `handleSpioWebp`: `solve conflict`, `dismiss` |
	 * | `litespeed js combine`   | option `litespeed.conf.optm-js_comb` non-empty                                  | `litespeed_js_combine`  | `handleLitespeedJsCombine`: `solve conflict`, `dismiss` |
	 * | `wpo merge css`          | WP-Optimize `enable_merging_of_css`                                             | `wpo_merge_css`         | `handleWpoMergeCss`: `solve conflict`, `add exclusions`, `dismiss` |
	 * | `lqip mkdir failed`      | transient `spai_lqip_mkdir_failed`; also sets `settings.behaviour.lqip = false` | `lqip_mkdir_failed`     | none (no `handleLqipMkdirFailed`) |
	 * | `remote_info_notice___<id>` | remote feed, see `display_remote_notices()`                                  | `remote_info_notice___<id>` | `handleRemoteInfoNotice`: `dismiss` |
	 *
	 * Remote feed: `ShortPixelAI::REMOTE_MESSAGE_ENDPOINT` (`https://api.shortpixel.com/v2/notices.php`) with
	 * `version`, `target=2` and `key` (the API key), cached 1 h in the object cache and the `spai_remote_notice`
	 * transient.
	 *
	 * @todo `wprocketcss` is dismissed under `wp_rocket_css` (`Notice\Actions::handleWpRocketCss()`) but
	 *       `renderNotices()` checks `wprocketcss`, so the notice comes back. Dismiss with causer `wprocketcss`.
	 * @todo `temporary redirect` is dismissed under `missing_jquery` (`Notice\Actions::handleTemporaryRedirect()`)
	 *       while `renderNotices()` checks `temporary_redirect`; it also hides the jQuery notice. Dismiss
	 *       `temporary redirect`.
	 * @todo `lazy`: the X button sends `dismiss` but `Notice\Actions::handleLazy()` accepts only `dismiss forever`,
	 *       so the X answers with the "API key is invalid" error. Accept both actions.
	 * @todo `lqip mkdir failed` and `remote_get_error` are rendered dismissible but have no handler in
	 *       `Notice\Actions`, so the X shows the "API key is invalid" error and nothing is stored (and nothing checks
	 *       a `remote_get_error` dismissal). Add handlers (and a dismissal check) or render them non-dismissible.
	 * @todo In `renderNotices()` the Swift Performance condition `!!$this->ctrl->settings->areas->parse_css_files > 0`
	 *       evaluates `(!!x) > 0`, so it is also true for `-1` (explicitly disabled). Use `parse_css_files > 0`.
	 */
	class Notice {
		/**
		 * @var \ShortPixel\AI\Notice Instance of class
		 */
		private static $instance;

		/**
		 * @var string $template Notice markup with the placeholders `{{ NOTICE CLASSES }}`, `{{ NOTICE ICON }}`,
		 *                       `{{ CAUSER }}`, `{{ SPAI NONCE }}`, `{{ MESSAGE }}`, `{{ BUTTONS }}` (see class docblock).
		 */
		private static $template = '<div class="{{ NOTICE CLASSES }}" data-icon="{{ NOTICE ICON }}" data-causer="{{ CAUSER }}" data-spainonce="{{ SPAI NONCE }}" data-plugin="short-pixel-ai"><div class="body-wrap"><div class="message-wrap">{{ MESSAGE }}</div><div class="buttons-wrap">{{ BUTTONS }}</div></div></div>';

		/**
		 * @var string[] $allowed_types Valid notice types, rendered as the `notice-<type>` class; others add no class.
		 */
		private static $allowed_types = [ 'success', 'error', 'warning', 'info' ];

		/**
		 * @var string[] $allowed_icons Valid `data-icon` values; anything else (or empty) renders as `none`.
		 */
		private static $allowed_icons = [ 'scared', 'happy', 'wink', 'cool', 'magnifier', 'notes' ];

		/**
		 * @var string[] $allowed_button_types Button types: `link` renders `<a>`, anything else renders `<button>`.
		 */
		private static $allowed_button_types = [ 'link', 'button' ];

		/**
		 * @var \ShortPixelAI $ctrl ShortPixel AI main controller
		 */
		private $ctrl;

		/**
		 * Returns the singleton, creating it (and registering the hooks) on first call.
		 *
		 * @param \ShortPixelAI|null $controller Required on the first call (hooks read `$this->ctrl`).
		 *
		 * @return \ShortPixel\AI\Notice
		 */
		public static function _( $controller = null ) {
			return self::$instance instanceof self ? self::$instance : new self( $controller );
		}

		/**
		 * Echoes `get( $causer, $data )`.
		 *
		 * @param null|string $causer Value of `data-causer` (see the causer table in the class docblock).
		 * @param null|array  $data   See `get()`.
		 *
		 * @return void
		 */
		public static function render( $causer = null, $data = null ) {
			echo self::get( $causer, $data );
		}

		/**
		 * Builds the notice HTML from `$template`.
		 *
		 * `$data` shape: `[ 'notice' => [ 'type' => success|error|warning|info, 'dismissible' => bool, 'icon' => one of
		 * $allowed_icons ], 'message' => [ 'title' => string, 'body' => string[], 'buttons' => array (see
		 * `renderButtons()`) ] ]`. The title goes into `<h3>`, each body item into `<p>`. Title, body and causer are
		 * inserted WITHOUT escaping (callers pass trusted HTML). A fresh `shortpixel-ai-settings` nonce is put in
		 * `data-spainonce`. Only `$data['message']['buttons']` is rendered.
		 *
		 * @param null|string $causer Value of `data-causer`; null for transient notices returned by AJAX handlers.
		 * @param null|array  $data   Notice description, see above.
		 *
		 * @return string
		 */
		public static function get( $causer = null, $data = null ) {
			$message = '';
			$buttons = '';

			$notice_classes = [ 'notice' ];

			if ( in_array( $data[ 'notice' ][ 'type' ], self::$allowed_types ) ) {
				$notice_classes[] = 'notice-' . strtolower( $data[ 'notice' ][ 'type' ] );
			}

			if ( isset($data[ 'notice' ][ 'dismissible' ]) && !!$data[ 'notice' ][ 'dismissible' ] ) {
				$notice_classes[] = 'is-dismissible';
			}

			if ( !empty( $data[ 'message' ][ 'title' ] ) ) {
				$message .= '<h3>' . $data[ 'message' ][ 'title' ] . '</h3>';
			}

			if ( !empty( $data[ 'message' ][ 'body' ] ) ) {
				foreach ( $data[ 'message' ][ 'body' ] as $paragraph ) {
					$message .= '<p>' . $paragraph . '</p>';
				}
			}

			if ( !empty( $data[ 'message' ][ 'buttons' ] ) ) {
				$buttons = self::renderButtons( $data[ 'message' ][ 'buttons' ] );
//				foreach ( $data[ 'message' ][ 'buttons' ] as $button ) {
//					$button_type    = isset( $button[ 'type' ] ) ? ( in_array( $button[ 'type' ], self::$allowed_button_types ) ? $button[ 'type' ] : 'button' ) : 'button';
//					$button_classes = [ 'button' ];
//
//					if ( isset( $button[ 'primary' ] ) && !!$button[ 'primary' ] ) {
//						$button_classes[] = 'button-primary';
//					}
//					else {
//						$button_classes[] = 'button-secondary';
//					}
//
//					$title      = empty( $button[ 'title' ] ) ? '' : $button[ 'title' ];
//					$action     = empty( $button[ 'action' ] ) ? '' : ' data-action="' . $button[ 'action' ] . '"';
//					$additional = empty( $button[ 'additional' ] ) ? '' : ' data-additional=' . json_encode( $button[ 'additional' ] ) . '';
//
//					if ( $button_type === 'link' ) {
//						$target  = empty( $button[ 'target' ] ) ? '' : ' target="' . $button[ 'target' ] . '"';
//						$url     = empty( $button[ 'url' ] ) ? '#' : $button[ 'url' ];
//						$buttons .= '<a href="' . $url . '" class="' . implode( ' ', $button_classes ) . '"' . $target . '>' . $title . '</a>';
//					}
//					else {
//						$buttons .= '<button type="button" class="' . implode( ' ', $button_classes ) . '"' . $action . $additional . '>' . $title . '</button>';
//					}
//				}
			}

			return str_replace(
				[ '{{ NOTICE CLASSES }}', '{{ NOTICE ICON }}', '{{ MESSAGE }}', '{{ BUTTONS }}', '{{ CAUSER }}', '{{ SPAI NONCE }}' ],
				[
					implode( ' ', $notice_classes ),
					empty( $data[ 'notice' ][ 'icon' ] ) ? 'none' : ( in_array( $data[ 'notice' ][ 'icon' ], self::$allowed_icons ) ? strtolower( $data[ 'notice' ][ 'icon' ] ) : 'none' ),
					$message,
					$buttons,
					$causer,
					wp_create_nonce( 'shortpixel-ai-settings' ),
				],
				self::$template );
		}

		/**
		 * Builds the buttons HTML.
		 *
		 * Each button: `type` (`link` renders `<a href=url target=target>`, anything else a
		 * `<button type="button" data-type data-action data-additional>`), `title`, `action` (the `data[action]` sent by
		 * `notice.js`, or the name of a `jQuery` function), `additional` (JSON in an UNQUOTED `data-additional`
		 * attribute), `primary` (`button-primary` vs `button-secondary`), `url`, `target`. Nothing is escaped.
		 * Also used by the dismissed-notices panel in `settings.tpl.php`.
		 *
		 * @param array[] $buttonsArray Button descriptions.
		 *
		 * @return string
		 */
		public static function renderButtons($buttonsArray) {
			$buttons = '';
			foreach ( $buttonsArray as $button ) {
				$button_type    = isset( $button[ 'type' ] ) ? ( in_array( $button[ 'type' ], self::$allowed_button_types ) ? $button[ 'type' ] : 'button' ) : 'button';
				$button_classes = [ 'button' ];

				if ( isset( $button[ 'primary' ] ) && !!$button[ 'primary' ] ) {
					$button_classes[] = 'button-primary';
				}
				else {
					$button_classes[] = 'button-secondary';
				}

				$title      = empty( $button[ 'title' ] ) ? '' : $button[ 'title' ];
                $type       = empty( $button[ 'type' ] ) ? '' : ' data-type="' . $button[ 'type' ] . '"';
				$action     = empty( $button[ 'action' ] ) ? '' : ' data-action="' . $button[ 'action' ] . '"';
				$additional = empty( $button[ 'additional' ] ) ? '' : ' data-additional=' . json_encode( $button[ 'additional' ] ) . '';

				if ( $button_type === 'link' ) {
					$target  = empty( $button[ 'target' ] ) ? '' : ' target="' . $button[ 'target' ] . '"';
					$url     = empty( $button[ 'url' ] ) ? '#' : $button[ 'url' ];
					$buttons .= '<a href="' . $url . '" class="' . implode( ' ', $button_classes ) . '"' . $target . '>' . $title . '</a>';
				}
				else {
					$buttons .= '<button type="button" class="' . implode( ' ', $button_classes ) . '"' . $type . $action . $additional . '>' . $title . '</button>';
				}
			}
			return $buttons;
		}

		/**
		 * Records a dismissal in `notices.dismissed`.
		 *
		 * The causer is used as given and snake-cased when the Option is saved (`'wp rocket lazy'` ->
		 * `wp_rocket_lazy`), so `renderNotices()` must check the snake_case key.
		 *
		 * @param string $causer Causer name (any case, spaces allowed).
		 * @param mixed  $value  Stored value, e.g. the plugin version to dismiss only for that version; default `time()`.
		 *
		 * @return bool Always true (see the `@todo` on `Options`).
		 */
		public static function dismiss( $causer, $value = null ) {
			$dismissed = Options::_()->get( 'dismissed', 'notices', Option::_() );
			// extra check to make sure that we get right object
			$dismissed = $dismissed instanceof Option ? $dismissed : Option::_();

			$dismissed->{$causer} = isset( $value ) ? $value : time();

			return !!Options::_()->set( $dismissed, 'dismissed', 'notices' );
		}

		/**
		 * Returns the dismissed notices as a plain object `{ <snake_case causer>: time()|value }`.
		 *
		 * @return \stdClass The `notices.dismissed` Option's data, or an empty object.
		 */
        public static function getDismissed() {
            $dismissed = Options::_()->get( 'dismissed', 'notices', Option::_() );

            return $dismissed instanceof Option ? $dismissed->getData() : new \stdClass();
        }

		/**
		 * Is meant to remove one dismissal so the notice can show again (used for `credits` by
		 * `ShortPixelAI::account_check_handler()`).
		 *
		 * @todo `deleteDismissing()` calls `unset( $dismissed->{$causer} )` on an `Option`, which has no `__unset()`;
		 *       the entry lives in the protected `__dyna` bag and stays, so the method is a no-op and the `credits`
		 *       notice never comes back. Use `$dismissed->unsetProperty( $causer )`.
		 *
		 * @param string $causer Causer name, snake-cased with `Converter::toSnakeCase()`.
		 *
		 * @return void
		 */
		public static function deleteDismissing( $causer ) {
			$causer    = Converter::toSnakeCase( $causer );
			$dismissed = Options::_()->get( 'dismissed', 'notices', Option::_() );
			// extra check to make sure that we get right object
			$dismissed = $dismissed instanceof Option ? $dismissed : Option::_();

			unset( $dismissed->{$causer} );

			Options::_()->set( $dismissed, 'dismissed', 'notices' );
		}

		/**
		 * Is meant to clear all dismissals.
		 *
		 * @todo `clearDismissed()` deletes `dismissed` in category `notice` (singular) instead of `notices`, so it
		 *       removes nothing. Use `Options::_()->delete( 'dismissed', 'notices' )`. No caller uses it today.
		 *
		 * @return void
		 */
		public static function clearDismissed() {
			Options::_()->delete( 'dismissed', 'notice' );
		}

		/**
		 * `admin_notices` callback: renders the local notices listed in the class docblock, for `manage_options` only.
		 *
		 * Side effects: deletes the `shortpixelai_thrown_notice` transient (unless `lazy` is dismissed); writes
		 * `shortpixel_ai_installed_time` on the first settings-page visit; sets `settings.behaviour.lqip = false` on
		 * every call while `spai_lqip_mkdir_failed` is set. Remote calls: `get_shortpixel_account()` (key notice),
		 * `get_domain_status()` (credits) and `get_domain_status( true )` (temporary redirect).
		 *
		 * @todo `renderNotices()` calls `ShortPixelDomainTools::get_domain_status( true )` (bypassing the
		 *       `spai_domain_status` transient) on every admin page load until `temporary redirect` is dismissed. Use
		 *       the cached status.
		 *
		 * @return void
		 */
		public function renderNotices() {
			if ( !function_exists( 'current_user_can' ) || !current_user_can( 'manage_options' ) ) {
				return;
			}

			$tests        = $this->ctrl->options->tests;
			$conflict     = $this->ctrl->is_conflict();
			$dismissed    = self::getDismissed();
			$integrations = ActiveIntegrations::_(true);

			// Critical OR conflicting notifications
			if ( $conflict === 'ao' ) {
				self::render( 'ao',
					[
						'notice'  => [
							'type' => 'error',
							'icon' => 'scared',
						],
						'message' => Constants::_()->autoptimize,

					] );
			}
			else if ( $conflict === 'avadalazy' ) {
				self::render( 'avadalazy',
					[
						'notice'  => [
							'type' => 'error',
							'icon' => 'scared',
						],
						'message' => Constants::_()->avadalazy,

					] );
			}
			else if ( $conflict === 'ginger' ) {
				self::render( 'ginger',
					[
						'notice'  => [
							'type' => 'error',
							'icon' => 'scared',
						],
						'message' => Constants::_()->ginger,

					] );
			}
            else if ( $conflict === 'speedoptimizer' ) {
                self::render( 'speedoptimizer',
                    [
                        'notice'  => [
                            'type' => 'error',
                            'icon' => 'scared',
                        ],
                        'message' => Constants::_()->speedoptimizer,

                    ] );
            }
            else if ( $conflict === 'divitoolbox' ) {
				self::render( 'divitoolbox',
					[
						'notice'  => [
							'type' => 'error',
							'icon' => 'scared',
						],
						'message' => Constants::_()->divitoolbox,

					] );
			}
			/* Obsolete because of implemented hook for this
			else if ( $conflict === 'elementorexternal' && !isset( $dismissed->elementorexternal ) ) {
				self::render( 'elementorexternal',
					[
						'notice'  => [
							'type'        => 'error',
							'icon'        => 'scared',
							'dismissible' => true,
						],
						'message' => Constants::_()->elementorexternal,
						'buttons' => [
							[
								'type'    => 'link',
								'title'   => __( 'Change Elementor\'s option', 'shortpixel-adaptive-images' ),
								'url'     => 'themes.php?page=elementor#tab-advanced',
								'primary' => true,
							],
							[
								'type'    => 'link',
								'title'   => __( 'ShortPixel Adaptive Images options', 'shortpixel-adaptive-images' ),
								'url'     => 'options-general.php?page=' . Page::NAMES[ 'settings' ] . '#top#areas',
								'primary' => false,
							],
						],
					] );
			}
			*/

            // Information notifications
            if ( !function_exists('mb_convert_case') ) {
                self::render( 'mbstring',
                    [
                        'notice'  => [
                            'type'        => 'error',
                            'icon'        => 'scared',
                            'dismissible' => false,
                        ],
                        'message' => Constants::_()->mbstring,
                    ] );
            }

			// Information notifications
			if ( ShortPixelAI::is_beta() && ( !isset( $dismissed->beta ) || $dismissed->beta !== SHORTPIXEL_AI_VERSION ) ) {
				self::render( 'beta',
					[
						'notice'  => [
							'type'        => 'info',
							'icon'        => 'notes',
							'dismissible' => true,
						],
						'message' => Constants::_()->beta,

					] );
			}

            //currently deactivating this notice as we have passed to 3.0 a looong time ago.
			if ( false && !$this->ctrl->options->pages_onBoarding_displayAllowed && !$this->ctrl->options->flags_all_firstInstall && !isset( $dismissed->on_boarding ) ) {
				self::render( 'on boarding',
					[
						'notice'  => [
							'type'        => 'info',
							'icon'        => 'wink',
							'dismissible' => true,
						],
						'message' => Constants::_()->on_boarding,

					] );
			}

			// Warnings
			if ( !isset( $dismissed->lazy ) ) {
				$thrown = get_transient( "shortpixelai_thrown_notice" );

				if ( is_array( $thrown ) ) {
					if ( $thrown[ 'when' ] == 'lazy' ) {
						self::render( 'lazy',
							[
								'notice'  => [
									'type'        => 'warning',
									'icon'        => 'scared',
									'dismissible' => true,
								],
								'message' => Constants::_()->lazy,
							] );
					}
				}

				delete_transient( "shortpixelai_thrown_notice" );
			}

            if ( is_admin() && isset( $_GET['page'] ) && $_GET['page'] === 'shortpixel-ai-settings') {
                $installed = get_option( 'shortpixel_ai_installed_time' );
                if ( ! $installed ) {
                    update_option( 'shortpixel_ai_installed_time', time() );
                }
                elseif ( time() - $installed >= 30 * DAY_IN_SECONDS ) {
                    $dismissed = self::getDismissed();
                    if ( !isset( $dismissed->recommend_survey ) ) {
                        Notice::render('recommend_survey', [
                            'notice' => [
                                'type' => 'info',
                                'icon' => 'notes',
                                'dismissible' => true,
                            ],
                            'message' => [
                                'title' => __('How likely are you to recommend ShortPixel AI to a friend or colleague?', 'shortpixel-adaptive-images'),
                                'body' => [
                                    // Closure below: one `.survey-rating-btn[data-rating]` button per rating 1..10.
                                    '<p><span style="margin-right:1em;">' . __('Not likely', 'shortpixel-adaptive-images') . '</span>'
                                    . implode('', array_map(function ($i) {
                                        return '<button class="button survey-rating-btn" '
                                            . 'data-rating="'. $i .'" '
                                            . 'style="margin:0 3px; min-width:2.5em; text-align:center; line-height:1.6;">'
                                            . $i
                                            . '</button>';
                                    }, range(1, 10)))
                                    . '<span style="margin-left:1em;">' . __('Very likely', 'shortpixel-adaptive-images') . '</span></p>',
                                    '<div id="survey-feedback-area" style="display:none;margin-top:10px;">'
                                    . '<p><strong id="survey-feedback-prompt">' . __('Give us your feedback:', 'shortpixel-adaptive-images') . '</strong></p>'
                                    . '<textarea id="survey-feedback" rows="3" style="width:100%;"></textarea><br><br>'
                                    . '<button id="survey-feedback-submit" class="button button-primary">'
                                    . __('Submit', 'shortpixel-adaptive-images')
                                    . '</button>'
                                    . '</div>',
                                ],
                            ],
                        ]);
                    }
                }
            }

			if ( !isset( $dismissed->wp_rocket_defer_js ) && $integrations->has( 'wp-rocket', 'defer-all-js' ) ) {
				self::render( 'wp rocket defer js', [
					'notice'  => [
						'type'        => 'warning',
						'icon'        => 'scared',
						'dismissible' => true,
					],
					'message' => Constants::_()->wp_rocket_defer_js,

				] );
			}

			if ( !isset( $dismissed->wp_rocket_lazy ) && $integrations->has( 'wp-rocket', 'lazyload' ) ) {
				self::render( 'wp rocket lazy',
					[
						'notice'  => [
							'type'        => 'warning',
							'icon'        => 'scared',
							'dismissible' => true,
						],
						'message' => Constants::_()->wp_rocket_lazy,

					] );
			}

			if ( !isset( $dismissed->wprocketcss ) && $this->ctrl->settings->areas->parse_css_files > 0
                 && $integrations->has('wp-rocket', 'minify-css') && !$integrations->has('wp-rocket', 'css-filter') ) {
				self::render( 'wprocketcss',
					[
						'notice'  => [
							'type'        => 'warning',
							'icon'        => 'scared',
							'dismissible' => true,
						],
						'message' => Constants::_()->wprocketcss,

					] );
			}

			if ( !isset( $dismissed->key ) && !Page::isCurrent( 'on-boarding' ) && !$this->ctrl->options->flags_all_account ) {
				$account = \ShortPixelDomainTools::get_shortpixel_account();

                if(!isset($account->Status) || $account->Status == -3) {
                    $this->renderNetworkError($account);
                }
				elseif ( $account->key ) {
					self::render( 'key',
						[
							'notice'  => [
								'type'        => 'warning',
								'icon'        => 'happy',
								'dismissible' => true,
							],
							'message' => [
								'title' => Constants::_()->key['title'],
								'body'  => [
									sprintf( Constants::_()->key['body'][0], $account->email ),
								],
								'buttons' => Constants::_()->key['buttons'],
							],

						] );
				}
			}

			if ( !isset( $dismissed->credits ) ) {
				$message = self::getCreditsNoticeInfo($this->ctrl);
				if(!empty($message)) {
					self::render( 'credits',
						[
							'notice'  => [
								'type'        => 'warning',
								'icon'        => 'notes',
								'dismissible' => true,
							],
							'message' => $message,

						] );
				}
			}

			if (
				!isset( $dismissed->twicelossy ) && $this->ctrl->settings->compression->level === 'lossy' && !Page::isCurrent( 'on-boarding' )
				&& is_plugin_active( 'shortpixel-image-optimiser/wp-shortpixel.php' ) && get_option( 'wp-short-pixel-compression', false ) == '1'
			) {
				self::render( 'twicelossy',
					[
						'notice'  => [
							'type'        => 'warning',
							'icon'        => 'happy',
							'dismissible' => true,
						],
						'message' => Constants::_()->twicelossy,

					] );
			}

			if ( !!$tests->front_end->missing_jquery && !isset( $dismissed->missing_jquery ) ) {
				self::render( 'missing jquery',
					[
						'notice'  => [
							'type'        => 'warning',
							'icon'        => 'scared',
							'dismissible' => true,
						],
						'message' => Constants::_()->missing_jquery,

					]
				);
			}

            if ( !isset( $dismissed->temporary_redirect )) {
                $domainStatus = \ShortPixelDomainTools::get_domain_status(true);
                if($domainStatus && $domainStatus->Status == -3) {
                    $this->renderNetworkError($domainStatus);
                }
                elseif($domainStatus && ($domainStatus->TemporaryRedirectOrigin === true || $domainStatus->TemporaryRedirectOrigin === "true")) {
                    self::render( 'temporary redirect',
                        [
                            'notice'  => [
                                'type'        => 'warning',
                                'icon'        => 'scared',
                                'dismissible' => true,
                            ],
                            'message' => Constants::_()->temporary_redirect,

                        ]
                    );
                }
            }

            if ( $integrations->has('swift-performance') && !!$this->ctrl->settings->areas->parse_css_files > 0 && !isset( $dismissed->swift_performance ) ) {
				if ( $integrations->has('swift-performance', 'has_bug') && $integrations->has('swift-performance', 'has_conflict') ) {
					self::render( 'swift performance',
						[
							'notice'  => [
								'type'        => 'warning',
								'icon'        => 'scared',
								'dismissible' => true,
							],
							'message' => Constants::_()->swift_performance,

						] );
				}
			}

			if ( $integrations->has('imagify', 'has_conflict') && !isset( $dismissed->imagify ) ) {
				self::render( 'imagify',
					[
						'notice'  => [
							'type'        => 'warning',
							'icon'        => 'scared',
							'dismissible' => true,
						],
						'message' => Constants::_()->imagify,

					] );
			}

			if ( !isset( $dismissed->spio_webp ) && is_plugin_active( 'shortpixel-image-optimiser/wp-shortpixel.php' ) && !empty( get_option( 'wp-short-pixel-create-webp-markup', 0 ) ) ) {
				self::render( 'spio webp', [
					'notice'  => [
						'type'        => 'warning',
						'icon'        => 'scared',
						'dismissible' => true,
					],
					'message' => Constants::_()->spio_webp,

				] );
			}

			if ( !empty( get_option( 'litespeed.conf.optm-js_comb', '' ) ) && !isset( $dismissed->litespeed_js_combine ) ) {
				self::render( 'litespeed js combine', [
					'notice'  => [
						'type'        => 'warning',
						'icon'        => 'scared',
						'dismissible' => true,
					],
					'message' => Constants::_()->litespeed_js_combine,

				] );
			}

        if ( $integrations->has( 'wp-optimize', 'enable_merging_of_css' ) && !isset( $dismissed->wpo_merge_css ) ) {
				self::render( 'wpo merge css', [
					'notice'  => [
						'type'        => 'warning',
						'icon'        => 'scared',
						'dismissible' => true,
					],
					'message' => Constants::_()->wpo_merge_css,

				] );
			}
      
			if ( !!get_transient( 'spai_lqip_mkdir_failed' ) && !isset( $dismissed->lqip_mkdir_failed ) ) {
				// Disable the LQIP option
				Options::_()->settings_behaviour_lqip = false;

				self::render( 'lqip mkdir failed', [
					'notice' => [
						'type'        => 'warning',
						'icon'        => 'scared',
						'dismissible' => true,
					],
					'message' => Constants::_()->lqip_mkdir_failed,
				] );
			}
		}

		/**
		 * Builds the `credits` notice message from the cached domain status.
		 *
		 * Empty when the status is missing, `2` (OK) or `-3` (network error). Otherwise: with an API key a
		 * `spaiProposeUpgrade` JS button plus the hidden propose-upgrade modal markup (nonce `ajax_request`); always a
		 * `check` button; a "quota soon exhausted" (`Status 1`) or "quota exceeded" (`-1`/`-2`) text; and either a
		 * Log-in/Top-up link to `DEFAULT_MAIN_DOMAIN/login/<key or _>/…` (account) or a Sign-up link (no account,
		 * affiliate filter `spai_affiliate_link`, `/af/MNCMIUS28044` appended for 4-part URLs). Also used by the
		 * dismissed-notices panel of the settings page.
		 *
		 * @todo `getCreditsNoticeInfo()` appends `' DATA: ' . print_r( $domain_status, true )` to the sign-up message,
		 *       which dumps the raw API response into the admin notice. Remove the debug output.
		 * @todo `getCreditsNoticeInfo()` reads `$_SERVER['HTTPS']` without `isset()` (warning on plain HTTP); use
		 *       `is_ssl()`.
		 *
		 * @param ShortPixelAI $ctrl
		 *
		 * @return array{title?: string, body?: string[], buttons?: array[]} Empty array when no notice is due.
		 */
		public static function getCreditsNoticeInfo(ShortPixelAI $ctrl) {
			$ret = [];
			$domain_status = \ShortPixelDomainTools::get_domain_status();

            //in rare cases because of a network issue $domain_status is null
            //                                                     vv -- temporary network error, ignore
			if ( $domain_status && $domain_status->Status !== 2 && $domain_status->Status !== -3 ) {
                $buttons = $messages = [];
                if($ctrl->options->settings_general_apiKey) {
                    $buttons[] = [
                        'title'   => __( 'Show me the best available options', 'shortpixel-adaptive-images' ),
                        'type'    => 'js',
                        'action'  => 'spaiProposeUpgrade',
                        'primary' => true,
                    ];
                    $messages[] = ' <div id="spaiProposeUpgradeShade" data-ajaxnonce="' . wp_create_nonce( 'ajax_request' )
                        . '" data-ajaxurl="' . admin_url( 'admin-ajax.php' ) . '" class="spai-modal-shade" style="display:none;"></div>
                        <div id="spaiProposeUpgrade" class="spai-modal spai-hide" style="min-width:650px;margin-left:-305px;">
                            <div class="spai-modal-title">
                                <button type="button" class="spai-close-upgrade-button" onclick="jQuery.spaiCloseProposeUpgrade()">&times;</button>
                            </div>
                            <div class="spai-modal-body" style="height:auto;min-height:400px;padding:0;"></div>
                        </div>';
                }
				$buttons[] = [
                    'title'   => __( 'Check credits', 'shortpixel-adaptive-images' ),
                    'action'  => 'check',
                    'primary' => true,
                ];

				if ( $domain_status->Status === 1 ) {
					$messages[] = __( 'Please note that your ShortPixel Adaptive Images quota will be exhausted soon.', 'shortpixel-adaptive-images' );
				}
				else if ( $domain_status->Status === -1 || $domain_status->Status === -2 ) {
					$messages[] = __( 'Your ShortPixel Adaptive Images quota has been exceeded.', 'shortpixel-adaptive-images' );
					$messages[] = __( 'Your images are served from the origin server until you top-up your account.', 'shortpixel-adaptive-images' );
						//__( 'The already optimized images will still be served from the ShortPixel CDN for up to 30 days but the images that weren\'t already optimized and cached via CDN will be served directly from your website.', 'shortpixel-adaptive-images' ),
				}

				if ( !!$domain_status->HasAccount ) {
					if(is_null( $ctrl->options->settings_general_apiKey )) {
						$messages[] = __( 'Please input your API Key in settings in order to get more details.', 'shortpixel-adaptive-images' )  .
						              ' <a href="https://shortpixel.com/knowledge-base/article/94-how-to-associate-a-domain-to-my-account" target="_blank">' .
						              __( 'How do I do this?', 'shortpixel-adaptive-images' ) . '</a>';
					}

					$title = __( 'Log-in', 'shortpixel-adaptive-images' );
                    $redirect = '/dashboard';
					if ( $domain_status->Status === 1 ) {
						$title = __( 'Top-up', 'shortpixel-adaptive-images' );
                        $redirect = '/pricing-adaptive-cdn/http' . urlencode( ($_SERVER['HTTPS'] ? 's' : '') . "://{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}");
					}
                    $key = $ctrl->options->settings_general_apiKey ?: '_';
                    $url = ShortPixelAI::DEFAULT_MAIN_DOMAIN. '/login/'. $key . $redirect;

					$buttons[]  = [
						'type'    => 'link',
						'title'   => $title,
						'url'     => $key === '_' ? apply_filters('spai_affiliate_link', $url) : $url, //apply affiliate filters only if no API key
						'target'  => '_blank',
						'primary' => false,
					];
				}
				else {
					$messages[] = __( 'If you <span>sign-up now</span> with ShortPixel you will receive 5Gb more free traffic and also you\'ll get 50% bonus credits to any purchase that you\'ll choose to make. Image optimization traffic can be purchased with as little as $3.99 for 52.5Gb monthly (including the 50% bonus).',
						'shortpixel-adaptive-images' ) . ' DATA: ' . print_r($domain_status, true);
					$fsuUrl = apply_filters('spai_affiliate_link',ShortPixelAI::DEFAULT_MAIN_DOMAIN. '/fsu');
					if(count(explode('/', $fsuUrl)) == 4) {
						$fsuUrl .= '/af/MNCMIUS28044';
					}
					$buttons[]  = [
						'type'    => 'link',
						'title'   => __( 'Sign-up', 'shortpixel-adaptive-images' ),
						'url'     => $fsuUrl,
						'target'  => '_blank',
						'primary' => false,
					];
				}
				$ret = [
					'title' => __( 'ShortPixel Adaptive Images notice', 'shortpixel-adaptive-images' ),
					'body'  => $messages,
					'buttons' => $buttons,
				];

			}
			return $ret;
		}

		/**
		 * `admin_footer` callback: registers and enqueues `spai-notice` (`assets/js/notice(.min).js`, depends on
		 * jQuery) with the CSP nonce, on every admin page.
		 *
		 * @return void
		 */
		public function enqueueAdminScripts() {
			$scripts = [];
			$min     = ( !!SHORTPIXEL_AI_DEBUG ? '' : '.min' );

			$scripts[ 'notice' ][ 'file' ]    = 'assets/js/notice' . $min . '.js';
			$scripts[ 'notice' ][ 'version' ] = !!SHORTPIXEL_AI_DEBUG ? hash_file( 'crc32', $this->ctrl->plugin_dir . $scripts[ 'notice' ][ 'file' ] ) : SHORTPIXEL_AI_VERSION;

			// Registering scripts
			wp_register_script( 'spai-notice', $this->ctrl->plugin_url . $scripts[ 'notice' ][ 'file' ], [ 'jquery' ], $scripts[ 'notice' ][ 'version' ] );

            \ShortPixelUrlTools::applyNonce('spai-notice');
            // Enqueueing scripts
			wp_enqueue_script( 'spai-notice' );
		}

		/**
		 * Stores the singleton and controller and hooks `renderNotices` and `display_remote_notices` on
		 * `admin_notices`, `enqueueAdminScripts` on `admin_footer` and `Notice\Actions::handle` on
		 * `wp_ajax_shortpixel_ai_handle_notice_action`.
		 *
		 * @param \ShortPixelAI $controller ShortPixel AI main controller
		 */
		private function __construct( $controller ) {
			if ( !isset( self::$instance ) || !self::$instance instanceof self ) {
				self::$instance = $this;
			}

			$this->ctrl = $controller;

			add_action( 'admin_notices', [ $this, 'renderNotices' ] );
			add_action( 'admin_footer', [ $this, 'enqueueAdminScripts' ] );
			add_action( 'wp_ajax_shortpixel_ai_handle_notice_action', [ 'ShortPixel\AI\Notice\Actions', 'handle' ] );
            add_action('admin_notices', [ $this, 'display_remote_notices' ] );
		}

        /**
         * Renders the dismissible `remote_get_error` notice with the error text of a failed API call.
         *
         * This usually is a "connection error: cURL error 28: Operation timed out after 10001 milliseconds with 0 out of 0 bytes received"
         *
         * @param object|mixed $account Account or domain-status response; `->Message` is shown, else "unknown error".
         * @return void
         */
        public function renderNetworkError($account)
        {
        // There is an issue with the wp_safe_remote_get on this host so notify the user
            self::render('remote_get_error', [
                'notice' => [
                    'type' => 'warning',
                    'icon' => 'scared',
                    'dismissible' => true,
                ],
                'message' => [
                    'title' => Constants::_()->remote_get_error['title'],
                    'body' => [
                        sprintf(Constants::_()->remote_get_error['body'][0], isset($account->Message) ? $account->Message : 'unknown error'),
                    ],
                ],
            ]);
        }

        /**
         * Returns the remote notices, fetched from `REMOTE_MESSAGE_ENDPOINT` at most once per hour.
         *
         * Reads `spai_remote_notice` from the object cache, then the transient; on a miss it calls
         * `wp_safe_remote_request( notices.php?version=<SPAI version>&target=2&key=<API key> )` (default timeout),
         * keeps the decoded body only if it is a JSON array and stores the result (or `false`) in both caches for
         * `HOUR_IN_SECONDS`.
         *
         * @todo `get_remote_notices()` caches `false` "to avoid repeated requests", but `false` is also the miss value of
         *       `wp_cache_get()`/`get_transient()`, so after a failed or non-array response the endpoint is called again
         *       on every admin page load. Cache an empty array instead.
         *
         * @return object[]|false Notices `{ id, message, type }`, or false.
         */
        private function get_remote_notices()
        {
            //delete_transient('spai_remote_notice'); //this is only for dev purpose, so it gets new transient value much faster for testing
            $transient_name = 'spai_remote_notice';
            //$transient_duration = 10; //10seconds is temporary for development, to be commented
            $transient_duration = HOUR_IN_SECONDS; // wp constant that means 1 hour

            $spai_key = Options::_()->settings_general_apiKey;

            $notices = wp_cache_get($transient_name);
            if ($notices === false) {
                $notices = get_transient($transient_name);
            }

            $url = ShortPixelAI::REMOTE_MESSAGE_ENDPOINT;
            $url = add_query_arg(array(
                'version' => SHORTPIXEL_AI_VERSION,
                'target' => 2,
                'key' => $spai_key,
            ), $url);

            if ( $notices === false ) {
                $notices_response = wp_safe_remote_request( $url );
                if (! is_wp_error( $notices_response ) ) {
                    $notices = json_decode($notices_response['body']);
                    if (! is_array($notices)) {
                        $notices = false;
                    }
                    /**so, here there are several ways of dealing with these notices in cache to avoid cache delay
                     * if the server has an object caching system  (like Memcached, Redis, etc.),
                     * WordPress transients may be stored in that object cache.
                     * wordpress transients are designed to use the DB as a fallback, but if object caching is available,
                     * the transients will be stored in memory via wp_cache. if you don;t have object cache, it will use
                     * transients only in traditional way.
                     * */
                    wp_cache_set($transient_name, $notices, '', $transient_duration);
                    set_transient($transient_name, $notices, $transient_duration);

                } else {
                     // store false in cache for the duration time to avoid repeated requests
                    wp_cache_set($transient_name, false, '', $transient_duration);
                    set_transient($transient_name, false, $transient_duration);
                }
            }

            return $notices;
        }


        /**
         * `admin_notices` callback: renders each remote notice that has `id` and `message` and is not dismissed.
         *
         * Causer `remote_info_notice___<lower-cased id>`; message passed through `wp_kses_post()`; `type` maps
         * `info` -> info/happy "Information Notice", `notice` -> success/notes "Notice", `alert` -> error/scared
         * "Alert Notice", anything else -> info. Always dismissible. Runs for every admin user who sees
         * `admin_notices` (no capability check, unlike `renderNotices()`).
         *
         * @todo `display_remote_notices()` checks the dismissal under `remote_info_notice___` + `strtolower( id )`, but
         *       `dismiss()` stores `Converter::toSnakeCase()` of it, which also drops non-alphanumerics and turns
         *       spaces into `_`; ids with such characters can never be dismissed. Snake-case the id here too.
         * @todo `display_remote_notices()` has no `manage_options` check, so remote notices (with dismiss buttons that
         *       then fail the capability check) show to every admin-area user. Add the same check as `renderNotices()`.
         *
         * @return void
         */
        public function display_remote_notices()
        {
            $notices = $this->get_remote_notices();

            if ($notices === false || empty($notices)) {
                return;
            }
            $dismissed = Notice::getDismissed();


            foreach ($notices as $remoteNotice) {
                if (!isset($remoteNotice->id) || !isset($remoteNotice->message)) {
                    continue;
                }


                $causer = str_replace(' ', '_', 'remote info notice___') . strtolower($remoteNotice->id);
                    if (isset($dismissed->{$causer})) {
                        continue;
                    }

                $message = wp_kses_post($remoteNotice->message);// sets the notice message

                switch ($remoteNotice->type) {
                    case 'info':
                        $notice_type = 'info';
                        $notice_icon = 'happy';
                        $notice_title = 'Information Notice';
                        break;
                    case 'notice':
                        $notice_type = 'success';
                        $notice_icon = 'notes';
                        $notice_title = 'Notice';
                        break;
                    case 'alert':
                        $notice_type = 'error';
                        $notice_icon = 'scared';
                        $notice_title = 'Alert Notice';
                        break;
                    default:
                        $notice_type = 'info';
                        $notice_icon = 'happy';
                        $notice_title = 'Information Notice';
                        break;
                }

                $this->render($causer , [
                    'notice' => [
                        'type' => $notice_type,
                        'icon' => $notice_icon,
                        'dismissible' => true,
                    ],
                    'message' => [
                        'title' => $notice_title,
                        'body' => [$message],
                    ],
                ]);
            }
        }

    }

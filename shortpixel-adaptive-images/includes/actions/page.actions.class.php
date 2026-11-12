<?php

	namespace ShortPixel\AI\Page;

	use ShortPixel\AI\AccessControlHeaders;
    use ShortPixelAI;
	use ShortPixel\AI\Page;
	use ShortPixel\AI\Notice;
	use ShortPixel\AI\Options;
	use ShortPixel\AI\LQIP;
	use ShortPixel\AI\Converter;

	/**
	 * AJAX handler of the admin pages (`wp_ajax_shortpixel_ai_handle_page_action`, logged-in only).
	 *
	 * Request: `POST action=shortpixel_ai_handle_page_action`, `spainonce`, `page`, `data[...]`. `page` is passed
	 * through `Converter::toTitleCase()` (title-cases words and strips `.`, `-`, `_`, whitespace) and dispatched to
	 * `handle<Page>( $data )`: `settings` -> `handleSettings`, `on-boarding` -> `handleOnBoarding`,
	 * `front-worker`/`frontWorker` -> `handleFrontWorker` (method names are case-insensitive). The returned array
	 * is sent with `wp_send_json()`.
	 *
	 * `data[action]` values handled:
	 * - settings: `save` (`data[options]` = JSON `{ category: { option: value } }`), `save key` (`data[api_key]`),
	 *   `remove key`, `purge_cdn_cache`, `disable advanced`, `enable advanced`, `clear css cache`, `clear lqip cache`.
	 * - on-boarding: `run front worker`, `go to settings`, `save key` (`data[api_key]`), `use same account`; any other
	 *   value (or `save key` without a key) advances the wizard step from `data[step]`.
	 * - front worker: `data[option]` in `lazy-load-backgrounds`, `parse-css`, `parse-js`, `parse-json`,
	 *   `hover-handling` with `data[value]` `'true'|'false'`; plus `data[action]=done`.
	 *
	 * Response shape: `{ success: bool, notice?: string (Notice::get() HTML), reload?: true, message?: string|false,
	 * redirect?: { allowed: true, url: string }, cookie?: 'shortpixel-ai-front-worker' }`.
	 */
	class Actions {
		/**
		 * Entry point of `wp_ajax_shortpixel_ai_handle_page_action`.
		 *
		 * Order of checks: 1) `Page::checkSpaiNonce()`: a missing or bad `spainonce` ends the request with JSON
		 * `{ success: false, message: 'Invalid or expired nonce. ...' }`; 2) `is_admin()` and `manage_options`: on
		 * failure the method just returns, so admin-ajax answers with a silent `0` body (no JSON); 3) a non-empty
		 * string `page` is dispatched to `handle<Page>()`; an empty or non-string `page` sends `{ success: false }`.
		 *
		 * @todo `handle()` dispatches to `handle<Page>` without `method_exists()`, so an unknown `page` value makes
		 *       `call_user_func()` throw a fatal error (HTTP 500) instead of a JSON error; it also reads
		 *       `$_POST['page']`/`$_POST['data']` without `isset()`. Check the method against a list of known pages.
		 * @todo A user without `manage_options` gets the silent `0` from `handle()` while a bad nonce gets JSON; answer
		 *       both with `wp_send_json_error()` (403) so `settings.js` can show a message.
		 *
		 * @return void
		 */
		public static function handle() {
            //verify the nonce first
            Page::checkSpaiNonce();
            $page = $_POST[ 'page' ];
			$data = $_POST[ 'data' ];

			$response = [ 'success' => false ];

            if ( !is_admin() || !\ShortPixelAI::userCan( 'manage_options' ) ) {
                return;
            }

			if ( !empty( $page ) && is_string( $page ) ) {
				$page = Converter::toTitleCase( $page );
				$response = call_user_func( [ Actions::class, 'handle' . $page ], isset( $data ) ? $data : null );
			}

			wp_send_json( $response );
		}

		/**
		 * Settings page actions (see class docblock for the list).
		 *
		 * `save`: `data[options]` is `stripslashes()`ed and JSON-decoded. Sanitising, in order:
		 * - `behaviour.api_url` is trimmed, replaced by the default CDN URL when it has no host, then
		 *   `sanitize_text_field()`; `behaviour.storage_url` and `behaviour.host_removal` are trimmed and
		 *   `sanitize_text_field()`ed;
		 * - `ShortPixelAI::translateSimpleOptions()` maps the simple-mode meta options to real ones;
		 * - when `behaviour.lqip` goes from `true` to `false` the `shortpixel_ai_lqip_state` option is deleted;
		 * - every `{ category: { option: value } }` pair is stored as `settings.<category>.<option>`, EXCEPT values
		 *   containing `<`, which are silently skipped (the response still says success);
		 * - `areas.parse_css_files` is `intval()`ed and stored as `1` when > 0, else `0` if the previous value was
		 *   `0`/unset, else `-1` (explicitly disabled). Turning it on clears the CSS cache and adds the `.htaccess`
		 *   CORS block; turning it off removes it; an `.htaccess` failure makes the response `success: false` with
		 *   an explanatory message, but the options are still saved;
		 * - `behaviour.nojquery` is written only on a transition: stored `> 0` and falsy value -> `-1`; stored `<= 0`
		 *   and truthy value -> `1`; otherwise the stored value (default `2`) is kept.
		 * There is no whitelist of categories or option names.
		 * The response carries a success/error notice HTML.
		 *
		 * `save key`: validates the key with `ShortPixelDomainTools::get_cdn_domain_usage()` (remote); when the
		 * response has a `quota` it stores `settings.general.api_key` and asks for a reload, else an error notice with
		 * the API error. Without `data[api_key]` it returns `{ success: false }`.
		 * `remove key`: deletes `settings.general.api_key`, reload.
		 * `purge_cdn_cache`: calls `ShortPixelDomainTools::purge_cdn_cache()` and sends the JSON itself (success notice
		 * built from `Details[0].Message`, `Details.CDN.Message`, `Details.CDN.Error`, or an error notice).
		 * `disable advanced`: `ShortPixelAI::setSimpleDefaultOptions()`, `flags.all.advanced = 0`, reload, transient
		 * `spaiModeSwitchNotification` (60 s). `enable advanced`: `flags.all.advanced = 1`, reload, transient.
		 * `clear css cache`: `ShortPixelAI::clear_css_cache()` + notice. `clear lqip cache`: `LQIP::clearCache()` + notice.
		 *
		 * @todo `handleSettings()` `save` stores any category/option name under `settings.*`, so it can also write
		 *       keys the UI never sends (e.g. `general.api_key` without the remote validation done by `save key`).
		 *       Whitelist the known option paths and types.
		 * @todo `handleSettings()` `save` calls `strpos( $value, '<' )` on every value; a non-scalar value (nested
		 *       array/object in the JSON) throws a `TypeError` on PHP 8. Skip or reject non-scalar values first.
		 *
		 * @param array|null $data `$_POST['data']`.
		 *
		 * @return array Response for `wp_send_json()`.
		 */
		private static function handleSettings( $data ) {
			// action which should be handled
			$action = isset( $data[ 'action' ] ) ? $data[ 'action' ] : null;

			$success = false;
            $message = false;

			$response = [
				'success' => $success,
			];

			if ( $action === 'save' ) {
				$options         = json_decode( stripslashes( $data[ 'options' ] ) );

				//sanitize
                if (isset($options->behaviour->api_url)) {
                    $url = trim($options->behaviour->api_url);
                    if (parse_url($url, PHP_URL_HOST) === NULL) {
                        $url = ShortPixelAI::DEFAULT_API_AI . ShortPixelAI::DEFAULT_API_AI_PATH;
                    }
                    $url = sanitize_text_field($url);
                    $options->behaviour->api_url = $url;
                }

                if (isset($options->behaviour->storage_url)) {
                    $options->behaviour->storage_url = sanitize_text_field(trim($options->behaviour->storage_url));
                }

                if (isset($options->behaviour->host_removal)) {
                    $options->behaviour->host_removal = sanitize_text_field(trim($options->behaviour->host_removal));
                }



                //translate simple meta options
				$options = ShortPixelAI::translateSimpleOptions( $options );
				$current_options = Options::_()->settings;

                // if user just disabled the LQIP option, delete the LQIP state from DB
                if (($options->behaviour->lqip ?? null) === false && ($current_options->behaviour->lqip ?? null) === true) {
                    delete_option('shortpixel_ai_lqip_state');
                }

                $success = true;
				if ( is_object( $options ) || is_array( $options ) ) {
					foreach ( $options as $category_name => $category ) {
						if ( is_object( $category ) || is_array( $category ) ) {

							foreach ( $category as $option => $value ) {
                                //sanitize the options against XSS, some of them will be included in the <input>'s, <textarea>'s etc.
                                if(strpos($value, '<') !== false) {
                                    //that's an invalid exclude, normally was handled by the JS sanitize, so something is spooky here, just ignore.
                                    continue;
                                }
								if ( $category_name === 'areas' && $option === 'parse_css_files' ) {
								    $failureMessage =  __( 'Options were saved but could not add the CORS header to <i>.htaccess</i>.', 'shortpixel-adaptive-images' );
								    $writableMessage = sprintf(__( 'Please verify that the <strong>%s.htaccess</strong> file is writable.', 'shortpixel-adaptive-images' ), get_home_path());
                                    $value = intval($value); //the value comes as true/false from the page but we use -1/0/1 (0 disabled by default, -1 disabled by user)
                                    $previousValue = $current_options->areas->parse_css_files;
                                    if($value > 0 && $previousValue <= 0 ) {
                                        ShortPixelAI::clear_css_cache();
                                        if(($status = AccessControlHeaders::addHeadersToHtaccess()) < 0) {
                                            $success = false;
                                            $message = $failureMessage . ' ' . ($status == -1 ? $writableMessage :
                                                    __( 'The header is already present but with a different value, please check your <i>.htaccess</i> file.', 'shortpixel-adaptive-images' ));
                                        }
                                    } else if($value <= 0 && $previousValue > 0) {
                                        if(AccessControlHeaders::removeHeadersFromHtaccess() < 0) {
                                            $success = false;
                                            $message = $failureMessage. ' ' . $writableMessage;
                                        }
                                    }
                                    Options::_()->set( $value > 0 ? 1 : ($previousValue == 0 ? 0 : -1), 'parse_css_files', [ 'settings', 'areas' ] );
                                } else {
                                    $behaviour = Options::_()->get( 'behaviour', 'settings', [] );
                                    if($category_name=='behaviour' && $option=='nojquery') {
                                        if( $behaviour->nojquery > 0 && !$value) {
                                            Options::_()->set( -1, $option, [ 'settings', $category_name ] );
                                        } else if( $behaviour->nojquery <= 0 && $value) {
                                            Options::_()->set( 1, $option, [ 'settings', $category_name ] );
                                        }
                                    } else {
                                        Options::_()->set( $value, $option, [ 'settings', $category_name ] );
                                    }
                                }
							}
						}
					}
				}

				$response[ 'success' ] = $success;
				$response[ 'notice' ]  = Notice::get( null, [
					'notice'  => [
						'type'        => $success ? 'success' : 'error',
						'dismissible' => true,
					],
					'message' => [
						'body' => [
							$success
								? __( 'The options have been successfully saved.', 'shortpixel-adaptive-images' )
								: __( 'Something went wrong...', 'shortpixel-adaptive-images' )
                                    . ($message ? $message : __( ' The options have not been saved.',
									'shortpixel-adaptive-images' )),
						],
					],
				] );
			}
			else if ( $action === 'save key' ) {
				if ( !empty( $data[ 'api_key' ] ) ) {
                    $cdn_domain_usage = \ShortPixelDomainTools::get_cdn_domain_usage( null, $data[ 'api_key' ] );
					if ( isset($cdn_domain_usage->quota) ) {
						$response[ 'success' ] = !!Options::_()->set( $data[ 'api_key' ], 'api_key', [ 'settings', 'general' ] );
						$response[ 'reload' ]  = true;
					}
					else {
						$response[ 'success' ] = false;
						$response[ 'notice' ]  = Notice::get( null, [
							'notice'  => [
								'type'        => 'error',
								'dismissible' => true,
                                'icon' => 'scared'
							],
							'message' => [
								'body' => [
									isset($cdn_domain_usage->error)
                                        ? $cdn_domain_usage->error
                                        : __( 'The domain is not associated to this API key.', 'shortpixel-adaptive-images' ),
								],
							],
						] );
					}
				}
			}
			else if ( $action === 'remove key' ) {
				$response[ 'success' ] = !!Options::_()->delete( 'api_key', [ 'settings', 'general' ] );
				$response[ 'reload' ]  = true;
			}

            else if ($action === 'purge_cdn_cache') {
                $api_response = \ShortPixelDomainTools::purge_cdn_cache();//call the purge function()
                $response['success'] = $api_response['success'];

                if ($api_response['success']) {
                    /** here, we get from API an array of 3 messages(for now). first 2 are from local storage
                     * from which only the first message we will extract in order to get number of files purged.
                     * the other 3-rd message is from CDN, and will give us message also (completed or errors:)
                     * $firstMessage is for the message we will get from the api, which says how many files were purged
                     * $generalMessage is the general success mesage we set as an info string
                     * $cdnMessage or $cdnErrorMessage are those transmitted by CDN directly.
                     */
                    $firstMessage = '';
                    $cdnMessage = '';
                    $cdnErrorMessage =  '';
                    $messageData = $api_response['message']['Details'] ?? [];
                    //getting the array of messages
                    if (!empty($messageData['0']['Message']) || !empty($messageData['CDN']['Message']) || !empty($messageData['CDN']['Error']) ) {
                            $firstMessage = $messageData['0']['Message'] ?? '';
                            $cdnMessage = $messageData['CDN']['Message'] ?? '';
                            $cdnErrorMessage = $messageData['CDN']['Error'] ?? '';

                    }

                    // concatenate the first message with CDN message
                    $fullMessage = trim(implode(' ', array_filter([$firstMessage, $cdnMessage, $cdnErrorMessage])));
                    // now get and display the wp_notice with & fullMessage if success or custom string  if not
                    $response['notice'] = Notice::get(null, [
                        'notice' => [
                            'type' => 'success',
                            'dismissible' => true,
                        ],
                        'message' => [
                            'body' => [
                                $fullMessage,
                            ],
                        ],
                    ]);
                } else {

                    $errorMessage = isset($api_response['message']) ? $api_response['message'] : __('An error occurred while purging the CDN cache.', 'shortpixel-adaptive-images');

                    $response['notice'] = Notice::get(null, [
                        'notice' => [
                            'type' => 'error',
                            'dismissible' => true,
                        ],
                        'message' => [
                            'body' => [
                                $errorMessage,
                            ],
                        ],
                    ]);
                }

                wp_send_json($response);
            }

            else if ( $action === 'disable advanced' ) {
				ShortPixelAI::_()->setSimpleDefaultOptions();
				$response[ 'success' ] = !!Options::_()->set( 0, 'advanced', ['flags', 'all'] );
				$response[ 'reload' ] = true;
				set_transient('spaiModeSwitchNotification', __( 'Switched to simple mode', 'shortpixel-adaptive-images' ), 60);
			}
			else if ( $action === 'enable advanced' ) {
				$response[ 'success' ] = !!Options::_()->set( 1, 'advanced', ['flags', 'all'] );
				$response[ 'reload' ] = true;

				set_transient('spaiModeSwitchNotification', __( 'Switched to advanced mode', 'shortpixel-adaptive-images' ), 60);
			}
			else if ( $action === 'clear css cache' ) {
				$success = !!ShortPixelAI::clear_css_cache();

				$response[ 'success' ] = $success;
				$response[ 'notice' ]  = Notice::get( null, [
					'notice'  => [
						'type'        => $success ? 'success' : 'error',
						'dismissible' => true,
					],
					'message' => [
						'body' => [
							$success
								? __( 'The CSS cache has been cleared.', 'shortpixel-adaptive-images' )
								: __( 'Something went wrong...', 'shortpixel-adaptive-images' ) . ' ' . __( 'CSS cache has not been cleared.',
									'shortpixel-adaptive-images' ),
						],
					],
				] );
			}
			else if ( $action === 'clear lqip cache' ) {
				$success = LQIP::clearCache();
				$response[ 'success' ] = $success;
				$response[ 'notice' ]  = Notice::get( null, [
					'notice'  => [
						'type'        => $success ? 'success' : 'error',
						'dismissible' => true,
					],
					'message' => [
						'body' => [
							$success
								? __( 'The LQIP cache has been cleared.', 'shortpixel-adaptive-images' )
								: __( 'Something went wrong...', 'shortpixel-adaptive-images' ) . ' ' . __( 'LQIP cache has not been cleared.',
									'shortpixel-adaptive-images' ),
						],
					],
				] );
			}

			return $response;
		}

		/**
		 * Front-worker actions sent from the front end while the on-boarding wizard's front worker runs.
		 *
		 * `data[option]` toggles one setting (`data[value] == 'true'`): `lazy-load-backgrounds` ->
		 * `settings.areas.backgrounds_lazy` and `.backgrounds_lazy_style`; `parse-css` -> `settings.areas.parse_css_files`
		 * (stored as bool, not `-1/0/1`; clears the CSS cache when enabled; no `.htaccess` update); `parse-js` ->
		 * `settings.areas.parse_js`; `parse-json` -> `settings.areas.parse_json`; `hover-handling` ->
		 * `settings.behaviour.hover_handling`.
		 *
		 * `data[action]=done`: tries to remove the current user's entry from `pages.on_boarding.front_worker`,
		 * switches to advanced mode when `ShortPixelAI::verifySimpleOptions()` says the simple UI cannot show the
		 * resulting settings, saves the front-worker Option and returns the cookie name and a redirect to the wizard.
		 *
		 * @todo In `handleFrontWorker()` `done`, `$opts->get( 1, 'advanced', ['flags', 'all'] )` has its arguments in
		 *       the wrong order (name `1`, category `advanced`, default `['flags','all']`), so it always returns the
		 *       array default and the "already advanced" check never applies. Use `get( 'advanced', [ 'flags', 'all' ], 0 )`.
		 * @todo `handleFrontWorker()` `done` removes the entry keyed by `user_login`, but `handleOnBoarding()`
		 *       `run front worker` stores it keyed by user `ID`, so the entry is never removed. In addition `Option` has
		 *       no `__isset()`/`__unset()`, so `!empty( $front_worker->{$key} )` is always false and `unset()` would
		 *       not reach the `__dyna` bag. Key both by ID and remove with `Option::unsetProperty()`.
		 * @todo `handleFrontWorker()` `parse-css` stores a bool and skips the `.htaccess` CORS block that the
		 *       settings `save` path adds; use the same `-1/0/1` coercion and `AccessControlHeaders` call.
		 *
		 * @param array|null $data `$_POST['data']`.
		 *
		 * @return array Response for `wp_send_json()`.
		 */
		private static function handleFrontWorker( $data ) {
			// action which should be handled
			$action = isset( $data[ 'action' ] ) ? $data[ 'action' ] : null;

			// option to be handled
			$option = isset( $data[ 'option' ] ) ? $data[ 'option' ] : null;

			$response = [
				'success' => false,
			];

            $opts = Options::_();

			switch ( $option ) {
				case 'lazy-load-backgrounds':
					$opts->settings_areas_backgroundsLazy = $data[ 'value' ] == 'true';
                    $opts->settings_areas_backgroundsLazyStyle = $data[ 'value' ] == 'true';
					$response[ 'success' ]                       = true;
					break;
				case 'parse-css':
					$opts->settings_areas_parseCssFiles = $data[ 'value' ] == 'true';
					if ( $data[ 'value' ] == 'true' ) {
						ShortPixelAI::clear_css_cache();
					}

					$response[ 'success' ] = true;
					break;
				case 'parse-js':
					$opts->settings_areas_parseJs = $data[ 'value' ] == 'true';
					$response[ 'success' ]               = true;
					break;
				case 'parse-json':
					$opts->settings_areas_parseJson = $data[ 'value' ] == 'true';
					$response[ 'success' ]                 = true;
					break;
				case 'hover-handling':
					$opts->settings_behaviour_hoverHandling = $data[ 'value' ] == 'true';
					$response[ 'success' ]                         = true;
					break;
			}

			if ( $action === 'done' ) {
				$front_worker = $opts->get( 'front_worker', [ 'pages', 'on_boarding' ], Options\Option::_() );
				$front_worker = $front_worker instanceof Options\Option ? $front_worker : Options\Option::_();

				$current_user_login = wp_get_current_user()->user_login;

				if ( !empty( $front_worker->{$current_user_login} ) ) {
					unset( $front_worker->{$current_user_login} );
				}

                //decide if we need to switch to Advanced mode in Settings, depending on which options were activated during on-boarding.
                if( $opts->get( 1, 'advanced', ['flags', 'all']) != 1
                    && !ShortPixelAI::verifySimpleOptions($opts->settings) ) {
                    //it means that the simple options cannot display the combination of options that were set by onboarding, so we need to switch to advanced mode.
                    $opts->set( 1, 'advanced', ['flags', 'all'] );
                }

				$response[ 'success' ]  = !!$opts->set( $front_worker, 'front_worker', [ 'pages', 'on_boarding' ] );
				$response[ 'cookie' ]   = 'shortpixel-ai-front-worker';
				$response[ 'redirect' ] = [
					'allowed' => true,
					'url'     => admin_url( 'admin.php?page=' . Page::NAMES[ 'on-boarding' ] ),
				];
			}

			return $response;
		}

		/**
		 * On-boarding wizard actions.
		 *
		 * The step count comes from `Page\Constants::_()->onBoarding['messages']`.
		 * - `run front worker`: for the current user ID stores `pages.on_boarding.front_worker.<ID> = { enabled: true,
		 *   token: ShortPixelAI::get_user_token() }` and returns a redirect to `home_url()` plus the cookie name
		 *   `shortpixel-ai-front-worker`; an error notice when the user ID is empty.
		 * - `go to settings`: sets `step` to the last step and `has_been_passed` to true; redirect to the settings page.
		 * - `save key`: validates `data[api_key]` remotely (`get_cdn_domain_usage`, needs `quota`), stores it, success
		 *   or error notice, reload on success.
		 * - `use same account`: `ShortPixelDomainTools::use_shortpixel_account()` (reuses the SPIO key), notice, reload.
		 * - anything else: `step = min( (int) data[step] + 1, last )`; past the last step sets `has_been_passed` and
		 *   redirects to the settings page, otherwise returns the next step's message HTML in `message`.
		 *
		 * @param array|null $data `$_POST['data']`.
		 *
		 * @return array Response for `wp_send_json()`.
		 */
		private static function handleOnBoarding( $data ) {
			// action which should be handled
			$action = isset( $data[ 'action' ] ) ? $data[ 'action' ] : null;

			$response = [
				'success' => false,
			];

			$steps     = Constants::_( ShortPixelAI::_() )->onBoarding;
			$steps_qty = count( isset( $steps[ 'messages' ] ) ? $steps[ 'messages' ] : [] );
			$last_step = $steps_qty > 0 ? $steps_qty - 1 : 0;

			if ( $action === 'run front worker' ) {
				$current_user_login = wp_get_current_user()->ID;

				if ( empty( $current_user_login ) ) {
					return $response = [
						'success' => false,
						'notice'  => Notice::get( null, [
							'notice'  => [
								'type'        => 'error',
								'dismissible' => true,
							],
							'message' => [
								'body' => [
									__( 'It looks like you have logged out. Please <span>log in</span> and try again.', 'shortpixel-adaptive-images' ),
								],
							],
						] ),
					];
				}

				$front_worker = Options::_()->get( 'front_worker', [ 'pages', 'on_boarding' ], Options\Option::_() );
				$front_worker = $front_worker instanceof Options\Option ? $front_worker : Options\Option::_();

				$front_worker->{$current_user_login} = $front_worker->{$current_user_login} instanceof Options\Option ? $front_worker->{$current_user_login} : Options\Option::_();

				$front_worker->{$current_user_login}->enabled = true;
				$front_worker->{$current_user_login}->token   = ShortPixelAI::_()->get_user_token();

				return $response = [
					'success'  => !!Options::_()->set( $front_worker, 'front_worker', [ 'pages', 'on_boarding' ] ),
					'redirect' => [
						'allowed' => true,
						'url'     => home_url(),
					],
					'cookie'   => 'shortpixel-ai-front-worker',
				];
			}
			else if ( $action === 'go to settings' ) {
				return $response = [
					'success'  => !!Options::_()->set( $last_step, 'step', [ 'pages', 'on_boarding' ] ) && !!Options::_()->set( true, 'has_been_passed', [ 'pages', 'on_boarding' ] ),
					'redirect' => [
						'allowed' => true,
						'url'     => admin_url( 'options-general.php?page=' . Page::NAMES[ 'settings' ] ),
					],
				];
			}
			else if ( $action === 'save key' ) {
				if ( !empty( $data[ 'api_key' ] ) ) {
                    $cdn_usage = \ShortPixelDomainTools::get_cdn_domain_usage( null, $data[ 'api_key' ] );
					if ( isset($cdn_usage->quota) ) {
						$response[ 'success' ] = !!Options::_()->set( $data[ 'api_key' ], 'api_key', [ 'settings', 'general' ] );
						$response[ 'notice' ]  = Notice::get( null, [
							'notice'  => [
								'type'        => 'success',
								'dismissible' => true,
							],
							'message' => [
								'body' => [
									__( 'API key has been successfully saved.', 'shortpixel-adaptive-images' ) . ' 😅',
								],
							],
						] );
						$response[ 'reload' ]  = true;
					}
					else {
						$response[ 'success' ] = false;
						$response[ 'notice' ]  = Notice::get( null, [
							'notice'  => [
								'type'        => 'error',
								'dismissible' => true,
							],
							'message' => [
								'body' => [
									sprintf( __( '<strong>%s</strong> is not associated to this API key.', 'shortpixel-adaptive-images' ), \ShortPixelDomainTools::get_site_domain() ),
								],
							],
						] );
					}

					return $response;
				}
			}
			else if ( $action === 'use same account' ) {
				$success = \ShortPixelDomainTools::use_shortpixel_account(ShortPixelAI::_());

				$response[ 'success' ] = $success;
				$response[ 'reload' ]  = $success;
				$response[ 'notice' ]  = $success
					? Notice::get( null, [
						'notice'  => [
							'type'        => 'success',
							'dismissible' => true,
						],
						'message' => [
							'body' => [
								sprintf( __( 'API key has been successfully saved. <strong>ShortPixel Adaptive Images</strong> will use the same account for <strong>%s</strong>.', 'shortpixel-adaptive-images' ), \ShortPixelDomainTools::get_site_domain() ),
							],
						],
					] )
					: Notice::get( null, [
						'notice'  => [
							'type'        => 'error',
							'dismissible' => true,
						],
						'message' => [
							'body' => [
								__( 'Something went wrong... API key has not been added.', 'shortpixel-adaptive-images' ),
							],
						],
					] );

				return $response;
			}

			if ( function_exists( 'get_option' ) && function_exists( 'update_option' ) ) {
				$next_step = (int) $data[ 'step' ] + 1;

				$limited_next_step = $next_step >= $steps_qty ? $steps_qty - 1 : $next_step;

				// updating the current step
				Options::_()->pages_onBoarding_step = $limited_next_step;

				$response[ 'success' ] = true;

				if ( $next_step >= $steps_qty ) {
					// set flag that on-boarding has been passed
					Options::_()->pages_onBoarding_hasBeenPassed = true;

					$response[ 'redirect' ] = [
						'allowed' => true,
						'url'     => admin_url( 'options-general.php?page=' . Page::NAMES[ 'settings' ] ),
					];
				}
				else {
					$response[ 'message' ] = empty( $steps[ 'messages' ][ $limited_next_step ] ) ? false : $steps[ 'messages' ][ $limited_next_step ];
				}
			}

			return $response;
		}
	}
<?php

	namespace ShortPixel\AI\Notice;

	use ShortPixel\AI\Notice;
	use ShortPixel\AI\Converter;
	use ShortPixel\AI\Options;
	use ShortPixel\AI\Page;

	/**
	 * AJAX handler of the notice buttons (`wp_ajax_shortpixel_ai_handle_notice_action`, logged-in only).
	 *
	 * Request (sent by `assets/js/notice.js`): `action=shortpixel_ai_handle_notice_action`, `causer` (the notice's
	 * `data-causer`), `spainonce` (the notice's `data-spainonce`), `data[action]`, optional `data[additional]`.
	 * See the causer table on `\ShortPixel\AI\Notice` for every causer, its condition and its handler.
	 *
	 * Handlers return `null` for an action they do not handle, or an array
	 * `{ success: bool, notice?: string, reload?: { allowed: bool }, redirect?: { allowed: bool, url: string } }`.
	 */
	class Actions {
		/**
		 * Entry point: checks, resolves the handler from the causer and sends the JSON response.
		 *
		 * 1. `Page::checkSpaiNonce()`: bad/missing `spainonce` -> JSON `{ success: false, message }`.
		 * 2. No `manage_options` -> JSON `{ success: false }`.
		 * 3. `causer` is split with `explode( '___', … )`: part 0 is the causer, part 1 (if any) becomes
		 *    `$data['causerId']` (remote notices). The handler is `'handle' . Converter::toTitleCase( causer )`
		 *    (`wp rocket lazy` -> `handleWpRocketLazy`); `method_exists()` is case-insensitive, so `wprocketcss`
		 *    reaches `handleWpRocketCss`. It is called with `( $data, ShortPixelAI::_() )` only when causer and
		 *    `data` are non-empty.
		 * 4. No handler, or a handler returning empty -> `{ success: false, notice: "Something went wrong... API key
		 *    is invalid. …" }`.
		 * The JSON is echoed with `header()` + `die`.
		 *
		 * @todo `handle()` answers every unmatched causer or action with the "API key is invalid" message, which has
		 *       nothing to do with the request (e.g. the `lazy` X, `lqip mkdir failed`, `remote_get_error`). Send a
		 *       generic "action not supported" error.
		 * @todo `handle()` reads `$_POST['data']` and `$_POST['causer']` without `isset()`.
		 *
		 * @return void
		 */
		public static function handle() {
			// Security: this handler is exposed via wp_ajax_ to every logged-in
			// user. Verify the nonce (CSRF) and the capability (authorization)
			// before dispatching, mirroring Page Actions (page.actions.class.php).
			Page::checkSpaiNonce();

			if ( ! \ShortPixelAI::userCan( 'manage_options' ) ) {
				wp_send_json( [ 'success' => false ] );
			}

			$ctrl   = \ShortPixelAI::_();
			$data   = $_POST[ 'data' ];
			$causer = explode("___",$_POST[ 'causer' ]);
            if(isset($causer[1]) ) {
                $data['causerId'] = $causer[1];
            }
            $causer = $causer[0];

            if (!empty($causer) && !empty($data)) {
                $method = 'handle' . Converter::toTitleCase($causer);
                if (method_exists(self::class, $method)) {
                    $response = call_user_func([self::class, $method], $data, $ctrl);
                }
            }

			if ( empty( $response ) ) {
				$response = [];

				$response[ 'success' ] = false;
				$response[ 'notice' ]  = Notice::get( null,
					[
						'notice'  => [
							'type'        => 'error',
							'dismissible' => true,
						],
						'message' => [
							'body' => [ __( 'Something went wrong... API key is invalid. ', 'shortpixel-adaptive-images' ) . __( 'Please check it on your account: <a href="https://shortpixel.com/login" target="_blank"><strong>Login</strong></a>.', 'shortpixel-adaptive-images' ) ],
						],
					] );
			}

			header( 'Content-Type: application/json' );
			echo json_encode( $response );
			die;
		}

        /**
         * `recommend_survey`: `dismiss` stores `notices.dismissed.recommend_survey`.
         *
         * @param array $data
         *
         * @return null|array
         */
        private static function handleRecommendSurvey( $data ) {
            if ( ( $data['action'] ?? '' ) === 'dismiss' ) {
                return [
                    'success' => Notice::dismiss( 'recommend_survey' ),
                ];
            }
            return null;
        }

        /**
         * `remote_info_notice___<id>`: `dismiss` stores `notices.dismissed.remote_info_notice___<id>` (snake-cased).
         *
         * @param array $data `causerId` is set by `handle()` from the part after `___`.
         *
         * @return null|array
         */
        private static function handleRemoteInfoNotice ($data){
            $action = isset($data['action']) ? $data['action'] : null;
            $causerId = $data['causerId'];

            if ($action === 'dismiss') {

                $causer = 'remote info notice___' . $causerId;
                return [
                    'success' => Notice::dismiss($causer)
                ];

            }
            return null;
        }

		/**
		 * `ao` (Autoptimize image optimisation conflict): `solve conflict` removes
		 * `autoptimize_imgopt_checkbox_field_1` from `autoptimize_imgopt_settings`, or (Autoptimize <= 2.4.4)
		 * `autoptimize_extra_checkbox_field_5` from `autoptimize_extra_settings`, and asks for a reload on success.
		 *
		 * @todo `handleAo()` shows "Please check the WP Rocket settings." when the Autoptimize fix fails; it should name
		 *       Autoptimize. It also has no `return null` for other actions (returns void).
		 *
		 * @param array $data
		 *
		 * @return null|array
		 */
		private static function handleAo( $data ) {
			// action which should be handled
			$action = isset( $data[ 'action' ] ) ? $data[ 'action' ] : null;

			if ( $action === 'solve conflict' ) {
				$success            = false;
				$autoptimize_imgopt = get_option( 'autoptimize_imgopt_settings', false );

				if ( $autoptimize_imgopt ) {
					unset( $autoptimize_imgopt[ 'autoptimize_imgopt_checkbox_field_1' ] );
					$success = update_option( 'autoptimize_imgopt_settings', $autoptimize_imgopt );
				}
				else {
					$autoptimize_extra = get_option( 'autoptimize_extra_settings', false ); //this is set by Autoptimize version <= 2.4.4
					unset( $autoptimize_extra[ 'autoptimize_extra_checkbox_field_5' ] );
					$success = update_option( 'autoptimize_extra_settings', $autoptimize_extra );
				}

				return [
					'success' => $success,
					'reload'  => [
						'allowed' => $success,
					],
					'notice'  => Notice::get( null,
						[
							'notice'  => [
								'type'        => $success ? 'success' : 'error',
								'dismissible' => true,
							],
							'message' => [
								'body' => [
									$success
										? __( 'Yay! The conflicting options have been disabled.', 'shortpixel-adaptive-images' )
										: __( 'The conflict has not been resolved.', 'shortpixel-adaptive-images' ) . ' ' . __( 'Please check the WP Rocket settings.',
											'shortpixel-adaptive-images' ),
								],
							],
						] ),
				];
			}

		}

		/**
		 * `wp rocket defer js`: `solve conflict` sets WP Rocket `defer_all_js_safe = 1` (when
		 * `update_rocket_option()` exists); `dismiss` stores `wp_rocket_defer_js`.
		 *
		 * @param array $data
		 *
		 * @return null|array
		 */
		private static function handleWpRocketDeferJs( $data ) {
			// action which should be handled
			$action = isset( $data[ 'action' ] ) ? $data[ 'action' ] : null;

			if ( $action === 'solve conflict' ) {
				$success = false;

				if ( function_exists( 'update_rocket_option' ) ) {
					update_rocket_option( 'defer_all_js_safe', 1 );

					$success = true;
				}

				return [
					'success' => $success,
					'notice'  => Notice::get( null,
						[
							'notice'  => [
								'type'        => $success ? 'success' : 'error',
								'dismissible' => true,
							],
							'message' => [
								'body' => [
									$success
										? __( 'Yay! The safe mode has been enabled.', 'shortpixel-adaptive-images' )
										: __( 'The conflict has not been resolved.', 'shortpixel-adaptive-images' ) . ' ' . __( 'Please check the WP Rocket settings.',
											'shortpixel-adaptive-images' ),
								],
							],
						] ),
				];
			}
			else if ( $action === 'dismiss' ) {
				return [
					'success' => Notice::dismiss( 'wp rocket defer js' ),
				];
			}

			return null;
		}

        /**
         * `lazy` (another lazy loader detected): only `dismiss forever` (the notice's button) stores `lazy`.
         *
         * The X sends `dismiss`, which is not handled (see the `@todo` on `\ShortPixel\AI\Notice`).
         *
         * @param array $data
         *
         * @return null|array
         */
        private static function handleLazy( $data ) {
            // action which should be handled
            return self::justDismiss($data, 'lazy', null, 'dismiss forever');
        }

        /**
		 * `wp rocket lazy`: `dismiss` stores `wp_rocket_lazy`.
		 *
		 * @param array $data
		 *
		 * @return null|array
		 */
		private static function handleWpRocketLazy( $data ) {
			// action which should be handled
            return self::justDismiss($data, 'wp rocket lazy');
		}

		/**
		 * `wprocketcss` (reached case-insensitively): `dismiss` stores `wp_rocket_css`, but `Notice::renderNotices()`
		 * checks `wprocketcss` (see the `@todo` on `\ShortPixel\AI\Notice`).
		 *
		 * @param array $data
		 *
		 * @return null|array
		 */
		private static function handleWpRocketCss( $data ) {
			// action which should be handled
            return self::justDismiss($data, 'wp rocket css');
		}

		/**
		 * `key` (a ShortPixel account exists for the domain): `use account` calls
		 * `ShortPixelDomainTools::use_shortpixel_account()` (reuses the SPIO key) and dismisses `key` on success;
		 * `dismiss` stores `key`. A failed `use account` returns null (generic error).
		 *
		 * @param array         $data
		 * @param \ShortPixelAI $controller
		 *
		 * @return null|array
		 */
		private static function handleKey( $data, $controller ) {
			// action which should be handled
			$action = isset( $data[ 'action' ] ) ? $data[ 'action' ] : null;

			if ( $action === 'use account' ) {
				$success = \ShortPixelDomainTools::use_shortpixel_account($controller);

				if ( $success ) {
					Notice::dismiss( 'key' );

					return [
						'success' => $success,
						'notice'  => Notice::get( null,
							[
								'notice'  => [
									'type'        => 'success',
									'dismissible' => true,
								],
								'message' => [
									'body' => [
										__( 'Yay! <strong>ShortPixel Adaptive Images</strong> will use this account.', 'shortpixel-adaptive-images' ) . ' 😉',
									],
								],
							] ),
					];
				}
			}
			else if ( $action === 'dismiss' ) {
				return [
					'success' => Notice::dismiss( 'key' ),
				];
			}

			return null;
		}

		/**
		 * `credits`: `check` refreshes the domain status (remote) and answers success only for `Status === 2`, with a
		 * success/warning/error notice and a reload; `dismiss` stores `credits`.
		 *
		 * @todo `handleCredits()` reads `$domain_status->Status` without checking the remote result is an object.
		 *
		 * @param array         $data
		 * @param \ShortPixelAI $controller
		 *
		 * @return null|array
		 */
		private static function handleCredits( $data, $controller ) {
			// action which should be handled
			$action = isset( $data[ 'action' ] ) ? $data[ 'action' ] : null;

			if ( $action === 'check' ) {
				$domain_status = \ShortPixelDomainTools::get_domain_status( true );
				$success       = ( $domain_status->Status === 2 );

				$type = $domain_status->Status === 2 ? 'success' : ( $domain_status->Status === 1 ? 'warning' : 'error' );

				$message = $domain_status->Status === 2
					? __( 'Yay! Your new credits are active.', 'shortpixel-adaptive-images' )
					: ( $domain_status->Status === 1
						? __( 'Your account is still about to run out of credits.', 'shortpixel-adaptive-images' )  . __( 'Please top-up.',
							'shortpixel-adaptive-images' )
						: __( 'Still no credits.', 'shortpixel-adaptive-images' ) . ' ' . __( 'Please check your account.',
							'shortpixel-adaptive-images' ) );

				return [
					'success' => $success,
					'reload'  => [
						'allowed' => true,
					],
					'notice'  => Notice::get( null,
						[
							'notice'  => [
								'type'        => $type,
								'dismissible' => true,
							],
							'message' => [
								'body' => [ $message ],
							],
						] ),
				];
			}
			else if ( $action === 'dismiss' ) {
				return [
					'success' => Notice::dismiss( 'credits' ),
				];
			}

			return null;
		}

		/**
		 * Method handles actions for "Elementor external"
		 *
		 * @param array $data
		 *
		 * @return null|array
		 * @deprecated
		 *
		 * private static function handleElementorExternal( $data ) {
		 * // action which should be handled
		 * $action = isset( $data[ 'action' ] ) ? $data[ 'action' ] : null;
		 *
		 * if ( $action === 'dismiss' ) {
		 * return [
		 * 'success' => Notice::dismiss( 'elementor external' ),
		 * ];
		 * }
		 *
		 * return null;
		 * }
		 */

		/**
		 * `on boarding` (notice currently never rendered): `redirect` sets `pages.on_boarding.display_allowed`,
		 * dismisses `on_boarding` and redirects to the wizard; `dismiss` stores `on_boarding`.
		 *
		 * @param array $data
		 *
		 * @return null|array
		 */
		private static function handleOnBoarding( $data ) {
			// action which should be handled
			$action = isset( $data[ 'action' ] ) ? $data[ 'action' ] : null;

			if ( $action === 'redirect' ) {
				Options::_()->set( true, 'display_allowed', [ 'pages', 'on_boarding' ] );

				return [
					'success'  => Notice::dismiss( 'on boarding' ),
					'redirect' => [
						'allowed' => true,
						'url'     => admin_url( 'admin.php?page=' . Page::NAMES[ 'on-boarding' ] ),
					],
				];
			}
			else if ( $action === 'dismiss' ) {
				return ['success' => Notice::dismiss( 'on boarding' )];
			}

			return null;
		}

		/**
		 * `beta`: `dismiss` stores `beta = SHORTPIXEL_AI_VERSION` (the notice returns with the next version).
		 *
		 * @param array $data
		 *
		 * @return null|array
		 */
		private static function handleBeta( $data ) {
			// action which should be handled
            return self::justDismiss($data, 'beta', SHORTPIXEL_AI_VERSION);
		}

		/**
		 * `twicelossy`: `dismiss` stores `twicelossy`.
		 *
		 * @param array $data
		 *
		 * @return null|array
		 */
		private static function handleTwicelossy( $data ) {
			// action which should be handled
			$action = isset( $data[ 'action' ] ) ? $data[ 'action' ] : null;

			if ( $action === 'dismiss' ) {
				return [
					'success' => Notice::dismiss( 'twicelossy' ),
				];
			}

			return null;
		}

		/**
		 * `missing jquery`: `re-check` sets `tests.front_end.enqueued = true`, `missing_jquery = false` and redirects
		 * to `home_url() . '?return_to=' . urlencode( data[additional][return_url] )` so the front-end test runs again;
		 * `dismiss` stores `missing_jquery`.
		 *
		 * @param array $data
		 *
		 * @return null|array
		 */
		private static function handleMissingJquery( $data ) {
			// action which should be handled
			$action = isset( $data[ 'action' ] ) ? $data[ 'action' ] : null;

			if ( $action === 're-check' ) {
				return [
					'success'  => !!Options::_()->set( true, 'enqueued', [ 'tests', 'front_end' ] ) && !!Options::_()->set( false, 'missing_jquery', [ 'tests', 'front_end' ] ),
					'redirect' => [
						'allowed' => true,
						'url'     => home_url() . '?return_to=' . urlencode( $data[ 'additional' ][ 'return_url' ] ),
					],
				];
			}
			else if ( $action === 'dismiss' ) {
				return [
					'success' => Notice::dismiss( 'missing jquery' ),
				];
			}

			return null;
		}

        /**
         * `temporary redirect`: `re-check` only redirects to `data[additional][return_url]` (no server-side recheck
         * exists); `dismiss` stores `missing_jquery` (see the `@todo` on `\ShortPixel\AI\Notice`).
         *
         * @todo `handleTemporaryRedirect()` `re-check` returns the client-supplied `return_url` as redirect target
         *       unvalidated; pass it through `wp_validate_redirect()`.
         *
         * @param array $data
         *
         * @return null|array
         */
        private static function handleTemporaryRedirect( $data ) {
            // action which should be handled
            //TODO, we need an API point server side that removes the domain from the banned list. The point will be recheck-banned-domain
            // For now we will just redirect back.
            $action = isset( $data[ 'action' ] ) ? $data[ 'action' ] : null;

            if ( $action === 're-check' ) {
                return [
                    'success'  => true,
                    'redirect' => [
                        'allowed' => true,
                        'url'     => $data[ 'additional' ][ 'return_url' ],
                    ],
                ];
            }
            else if ( $action === 'dismiss' ) {
                return [
                    'success' => Notice::dismiss( 'missing jquery' ),
                ];
            }

            return null;
        }

        /**
		 * `swift performance`: `solve conflict` empties Swift Performance (Lite and/or Pro) `merge-styles` and
		 * `normalize-static-resources` and clears its cache on success; `dismiss` stores `swift_performance`.
		 *
		 * @param array $data
		 *
		 * @return null|array
		 */
		private static function handleSwiftPerformance( $data ) {
			// action which should be handled
			$action = isset( $data[ 'action' ] ) ? $data[ 'action' ] : null;

			if ( $action === 'solve conflict' ) {
				$success = false;

				if ( class_exists( 'Swift_Performance_Lite' ) ) {
					\Swift_Performance_Lite::update_option( 'merge-styles', '' );
					\Swift_Performance_Lite::update_option( 'normalize-static-resources', '' );

					$success = !\Swift_Performance_Lite::get_option( 'merge-styles' )
					           && !\Swift_Performance_Lite::get_option( 'normalize-static-resources' );
				}

				if ( class_exists( 'Swift_Performance' ) ) {
					\Swift_Performance::update_option( 'merge-styles', '' );
					\Swift_Performance::update_option( 'normalize-static-resources', '' );

					$success = !\Swift_Performance::get_option( 'merge-styles' )
					           && !\Swift_Performance::get_option( 'normalize-static-resources' );
				}

				if ( $success && class_exists( 'Swift_Performance_Cache' ) ) {
					\Swift_Performance_Cache::clear_all_cache();
				}

				return [
					'success' => $success,
					'notice'  => Notice::get( null,
						[
							'notice'  => [
								'type'        => $success ? 'success' : 'error',
								'dismissible' => true,
							],
							'message' => [
								'body' => [
									$success
										? __( 'Yay! The conflicting options have been disabled.', 'shortpixel-adaptive-images' )
										: __( 'The conflict has not been resolved.', 'shortpixel-adaptive-images' ) . ' ' . __( 'Please check the Swift Performance settings.',
											'shortpixel-adaptive-images' ),
								],
							],
						] ),
				];
			}
			else if ( $action === 'dismiss' ) {
				return [
					'success' => Notice::dismiss( 'swift performance' ),
				];
			}

			return null;
		}

		/**
		 * `imagify`: `solve conflict` sets Imagify `display_webp = 0`; `dismiss` stores `imagify`.
		 *
		 * @param array $data
		 *
		 * @return null|array
		 */
		private static function handleImagify( $data ) {
			// action which should be handled
			$action = isset( $data[ 'action' ] ) ? $data[ 'action' ] : null;

			if ( $action === 'solve conflict' ) {
				$success = false;

				if ( function_exists( 'update_imagify_option' ) ) {
					update_imagify_option( 'display_webp', 0 );
					$success = true;
				}

				return [
					'success' => $success,
					'notice'  => Notice::get( null,
						[
							'notice'  => [
								'type'        => $success ? 'success' : 'error',
								'dismissible' => true,
							],
							'message' => [
								'body' => [
									$success
										? __( 'Yay! The conflicting options have been disabled.', 'shortpixel-adaptive-images' )
										: __( 'The conflict has not been resolved.', 'shortpixel-adaptive-images' ) . ' ' . __( 'Please check the Imagify settings.', 'shortpixel-adaptive-images' ),
								],
							],
						] ),
				];
			}
			else if ( $action === 'dismiss' ) {
				return [
					'success' => Notice::dismiss( 'imagify' ),
				];
			}

			return null;
		}

		/**
		 * `spio webp`: `solve conflict` sets the SPIO option `wp-short-pixel-create-webp-markup = 0`; `dismiss` stores
		 * `spio_webp`.
		 *
		 * @param array $data
		 *
		 * @return null|array
		 */
		private static function handleSpioWebp( $data ) {
			// action which should be handled
			$action = isset( $data[ 'action' ] ) ? $data[ 'action' ] : null;

			if ( $action === 'solve conflict' ) {
				$success = false;

				if ( function_exists( 'update_option' ) ) {
					update_option( 'wp-short-pixel-create-webp-markup', 0 );
					$success = true;
				}

				return [
					'success' => $success,
					'notice'  => Notice::get( null,
						[
							'notice'  => [
								'type'        => $success ? 'success' : 'error',
								'dismissible' => true,
							],
							'message' => [
								'body' => [
									$success
										? __( 'Yay! The <b>Deliver next generation images</b> option has been disabled.', 'shortpixel-adaptive-images' )
										: __( 'The <b>Deliver next generation images</b> option has not been disabled.', 'shortpixel-adaptive-images' ) . ' ' . sprintf( __( 'Please check <span>%s\'s</span> <a href="%s" target="_blank">settings</a>.', 'shortpixel-adaptive-images' ),
											'ShortPixel Image Optimizer',
											admin_url( 'options-general.php?page=wp-shortpixel-settings&part=adv-settings' ) ),
								],
							],
						] ),
				];
			}
			else if ( $action === 'dismiss' ) {
				return [
					'success' => Notice::dismiss( 'spio webp' ),
				];
			}

			return null;
		}

		/**
		 * `litespeed js combine`: `solve conflict` empties `litespeed.conf.optm-js_comb`; `dismiss` stores
		 * `litespeed_js_combine`.
		 *
		 * @param array $data
		 *
		 * @return null|array
		 */
		private static function handleLitespeedJsCombine( $data ) {
			// action which should be handled
			$action = isset( $data[ 'action' ] ) ? $data[ 'action' ] : null;

			if ( $action === 'solve conflict' ) {
				$success = false;

				if ( function_exists( 'update_option' ) ) {
					update_option( 'litespeed.conf.optm-js_comb', '' );
					$success = true;
				}

				return [
					'success' => $success,
					'notice'  => Notice::get( null,
						[
							'notice'  => [
								'type'        => $success ? 'success' : 'error',
								'dismissible' => true,
							],
							'message' => [
								'body' => [
									$success
										? __( 'Yay! The conflicting options have been disabled.', 'shortpixel-adaptive-images' )
										: __( 'The conflict has not been resolved.', 'shortpixel-adaptive-images' ) . ' ' . sprintf( __( 'Please check <span>%s\'s</span> <a href="%s" target="_blank">settings</a>.', 'shortpixel-adaptive-images' ),
											'LiteSpeed Cache',
											admin_url( 'admin.php?page=litespeed-page_optm#settings_js' ) ),
								],
							],
						] ),
				];
			}
			else if ( $action === 'dismiss' ) {
				return [
					'success' => Notice::dismiss( 'litespeed js combine' ),
				];
			}

			return null;
		}

		/**
		 * `wpo merge css`: `solve conflict` sets WP-Optimize minify `enable_merging_of_css = false`; `add exclusions`
		 * appends the plugin's `admin(.min).css` and `style-bar(.min).css` paths to its `ignore_list`; `dismiss` stores
		 * `wpo_merge_css`. Both fixes report success whenever the config object has `get`/`update`.
		 *
		 * @param array $data
		 *
		 * @return null|array
		 */
		private static function handleWpoMergeCss( $data ) {
			// action which should be handled
			$action = isset( $data[ 'action' ] ) ? $data[ 'action' ] : null;

			if ( $action === 'solve conflict' ) {
				$success = false;

				if ( function_exists( 'wp_optimize_minify_config' ) ) {
					$config_instance = wp_optimize_minify_config();

					if ( method_exists( $config_instance, 'get' ) && method_exists( $config_instance, 'update' ) ) {
						$config = $config_instance->get();

						if ( is_array( $config ) && isset( $config[ 'enable_merging_of_css' ] ) && !!$config[ 'enable_merging_of_css' ] ) {
							$config[ 'enable_merging_of_css' ] = false;
						}

						$config_instance->update( $config );

						$success = true;
					}
				}

				return [
					'success' => $success,
					'notice'  => Notice::get( null,
						[
							'notice'  => [
								'type'        => $success ? 'success' : 'error',
								'dismissible' => true,
							],
							'message' => [
								'body' => [
									$success
										? __( 'Yay! Looks like conflicting options have been disabled.', 'shortpixel-adaptive-images' )
										: __( 'Conflict has not been resolved.', 'shortpixel-adaptive-images' ) . ' ' . sprintf( __( 'Please check <span>%s\'s</span> <a href="%s" target="_blank">settings</a>.', 'shortpixel-adaptive-images' ),
											'WP Optimize',
											admin_url( 'admin.php?page=wpo_minify&tab=wp_optimize_css' ) ),
								],
							],
						] ),
				];
			}
			else if ( $action === 'add exclusions' ) {
				$success = false;

				if ( function_exists( 'wp_optimize_minify_config' ) ) {
					$config_instance = wp_optimize_minify_config();

					if ( method_exists( $config_instance, 'get' ) && method_exists( $config_instance, 'update' ) ) {
						$config = $config_instance->get();

						if ( is_array( $config ) && isset( $config[ 'ignore_list' ] ) && is_string( $config[ 'ignore_list' ] ) ) {
							$plugin_folder = plugin_basename( SHORTPIXEL_AI_PLUGIN_DIR );

							$conflicting_files = [
								'/' . $plugin_folder . '/assets/css/admin.css',
								'/' . $plugin_folder . '/assets/css/admin.min.css',
								'/' . $plugin_folder . '/assets/css/style-bar.css',
								'/' . $plugin_folder . '/assets/css/style-bar.min.css',
							];

							foreach ( $conflicting_files as $file ) {
								if ( strpos( $config[ 'ignore_list' ], $file ) === false ) {
									$config[ 'ignore_list' ] .= PHP_EOL . $file;
								}
							}
						}

						$config_instance->update( $config );

						$success = true;
					}
				}

				return [
					'success' => $success,
					'notice'  => Notice::get( null,
						[
							'notice'  => [
								'type'        => $success ? 'success' : 'error',
								'dismissible' => true,
							],
							'message' => [
								'body' => [
									$success
										? __( 'Yay! The exclusion rules have been successfully added.', 'shortpixel-adaptive-images' )
										: __( 'Exclusion rules have not been added.', 'shortpixel-adaptive-images' ) . ' ' . sprintf( __( 'Please check <span>%s\'s</span> <a href="%s" target="_blank">settings</a>.', 'shortpixel-adaptive-images' ),
											'WP Optimize',
											admin_url( 'admin.php?page=wpo_minify&tab=wp_optimize_css' ) ),
								],
							],
						] ),
				];
			}
			else if ( $action === 'dismiss' ) {
				return [
					'success' => Notice::dismiss( 'wpo merge css' ),
				];
			}

			return null;
		}

        /**
         * Dismisses `$causer` when `data[action]` equals `$dismissAction`.
         *
         * @param array       $data          Request data.
         * @param string      $causer        Causer to store (snake-cased on save).
         * @param mixed|null  $value         Stored value; null means `time()`.
         * @param string      $dismissAction Action that triggers the dismissal.
         *
         * @return null|array `{ success }` or null when the action does not match.
         */
        private static function justDismiss($data, $causer, $value = null, $dismissAction = 'dismiss') {
            $action = isset( $data[ 'action' ] ) ? $data[ 'action' ] : null;
            if ( $action === $dismissAction ) {
                return [
                    'success' => Notice::dismiss( $causer, $value),
                ];
            }
            return null;
        }

    }

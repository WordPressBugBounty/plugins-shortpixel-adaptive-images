<?php

	namespace ShortPixel\AI\Feedback;

	use ShortPixelAI;
	use ShortPixel\AI\Options;
	use ShortPixel\AI\Feedback;
	use ShortPixel\AI\Converter;
    use ShortPixel\AI\Page;

	/**
	 * AJAX handler of the deactivation popup (`wp_ajax_shortpixel_ai_handle_feedback_action`, logged-in only).
	 *
	 * Request (sent by `assets/js/admin.js` on the plugins screen): `action=shortpixel_ai_handle_feedback_action`,
	 * `spainonce` (`spaiNonce.nonce`), `data[action]=deactivation`, `data[feedback]`, `data[suggestions]`,
	 * `data[anonymous]`, `data[settings]` (`keep` | `remove` | `revert`).
	 */
	class Actions {
		/**
		 * Entry point: runs only when `ShortPixelAI::isAjax()`; checks the nonce (`Page::checkSpaiNonce()`, JSON error on
		 * failure), title-cases `data[action]` and dispatches to `handle<Action>( $data )` with `action` removed; sends
		 * the result, or `{ success: false }`, with `wp_send_json()`. No capability check here (see
		 * `handleDeactivation()`).
		 *
		 * @todo `handle()` dispatches without `method_exists()`, so an unknown `data[action]` makes `call_user_func()`
		 *       throw a fatal error; it also reads `$_POST['data']['action']` without `isset()`. Check the method first.
		 *
		 * @return void
		 */
		public static function handle() {
			if ( ShortPixelAI::isAjax() ) {
                Page::checkSpaiNonce();
				$data   = $_POST[ 'data' ];
				$action = $data[ 'action' ];

				if ( !empty( $action ) && is_string( $action ) ) {
					$action = Converter::toTitleCase( $action );

					// unset action
					unset( $data[ 'action' ] );

					$response = call_user_func( [ Actions::class, 'handle' . $action ], $data );
				}

				if ( !isset( $response ) ) {
					$response = [ 'success' => false ];
				}

				wp_send_json( $response );
			}
		}

		/**
		 * `deactivation`: checks the nonce again and `activate_plugins` (else `{ success: false, message:
		 * 'Insufficient permissions' }`), sends the feedback when `feedback` is set (`Feedback::send()`, result in
		 * `response.feedback`), and when `settings` is set deactivates the plugin and then: `remove` deletes the
		 * transients `shortpixelai_thrown_notice`, `spai_domain_status`, `spai_lqip_mkdir_failed` and the whole
		 * `short_pixel_ai_options` option; `revert` calls `ShortPixelAI::revert_options()` (1.x layout; the radio is
		 * hidden in the popup); anything else keeps the data. Then asks for a reload.
		 *
		 * @todo `handleDeactivation()` `remove` leaves `spai_settings_lazy_ajax_tags`, `shortpixel_ai_lqip_state`,
		 *       `shortpixel_ai_installed_time`, the LQIP files and the other `spai_*` transients behind although the
		 *       popup says "Remove all plugin data and settings". Delete them too (or share the cleanup with uninstall).
		 *
		 * @param array $data Request data without `action`.
		 *
		 * @return array `{ success: bool, feedback?: mixed, reload?: { allowed: true }, message?: string }`
		 */
		private static function handleDeactivation( $data ) {
            Page::checkSpaiNonce();
            if ( ! current_user_can('activate_plugins') ) {
                return [ 'success' => false, 'message' => 'Insufficient permissions' ];
            }
			$response = [ 'success' => false ];

			if ( isset( $data[ 'feedback' ] ) ) {
				$feedback    = $data[ 'feedback' ];
				$suggestions = isset( $data[ 'suggestions' ] ) ? $data[ 'suggestions' ] : null;
				$anonymous   = isset( $data[ 'anonymous' ] ) ? !!$data[ 'anonymous' ] : false;

				$response[ 'feedback' ] = Feedback::send( $feedback, $suggestions, $anonymous );
			}

			if ( isset( $data[ 'settings' ] ) ) {
                deactivate_plugins( ShortPixelAI::_()->basename ); // need to do this before deleting the options (if requested) otherwise the ShortPixelAI::_() will reinit the deleted options.

                switch ( $data[ 'settings' ] ) {
					case 'remove' :
                        delete_transient( "shortpixelai_thrown_notice" );
                        delete_transient( 'spai_domain_status');
                        delete_transient( 'spai_lqip_mkdir_failed');
						$response[ 'success' ] = !!Options::_()->clearCollection();
						break;
					case 'revert' :
						ShortPixelAI::revert_options();
						$response[ 'success' ] = true;

						break;
					default:
						$response[ 'success' ] = true;

						break;
				}

				$response[ 'reload' ] = [
					'allowed' => true,
				];
			}

			return $response;
		}
	}
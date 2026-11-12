<?php

	namespace ShortPixel\AI\Help;

	use ShortPixel\AI\Page;
	use ShortPixel\AI\Options;
	use ShortPixel\AI\Converter;

	/**
	 * AJAX handler of the help tab buttons (`wp_ajax_shortpixel_ai_handle_help_action`, logged-in only).
	 *
	 * Request: `action=shortpixel_ai_handle_help_action`, `data[action]` (only `enable on boarding` exists).
	 * Response: `{ success: bool, redirect?: { url: string } }`.
	 *
	 * @todo `handle()` checks neither a nonce nor a capability, so any logged-in user (or a CSRF page) can reset the
	 *       on-boarding wizard state. Add `Page::checkSpaiNonce()` and a `manage_options` check, and send `spainonce`
	 *       from `admin.js`.
	 */
	class Actions {
		/**
		 * Entry point: title-cases `data[action]` and calls `handle<Action>( $data )` (action removed); sends the result
		 * or `{ success: false }` with `wp_send_json()`.
		 *
		 * @todo `handle()` uses the callable `[ 'self', … ]`, deprecated since PHP 8.2; use `[ self::class, … ]`. It also
		 *       dispatches without `method_exists()` (fatal on an unknown action) and reads `$_POST['data']['action']`
		 *       without `isset()`.
		 *
		 * @return void
		 */
		public static function handle() {
			$data   = $_POST[ 'data' ];
			$action = $data[ 'action' ];

			$response = [ 'success' => false ];

			if ( !empty( $action ) && is_string( $action ) ) {
				$action = Converter::toTitleCase( $action );

				// unset action
				unset( $data[ 'action' ] );

				$response = call_user_func( [ 'self', 'handle' . $action ], isset( $data ) ? $data : null );
			}

			wp_send_json( $response );
		}

		/**
		 * `enable on boarding` (fired by the "Setup Wizard" help-tab button): when the wizard was passed, resets
		 * `pages.on_boarding.step = 0` and `has_been_passed = false`; in every case answers with a redirect to the wizard.
		 *
		 * @return array{success: bool, redirect?: array{url: string}}
		 */
		private static function handleEnableOnBoarding() {
			$return = [
				'success' => false,
			];

			if ( !!Options::_()->get( 'has_been_passed', [ 'pages', 'on_boarding' ] ) ) {
				$return[ 'success' ] = !!Options::_()->set( 0, 'step', [ 'pages', 'on_boarding' ] ) // set current step to first
				                       && !!Options::_()->set( false, 'has_been_passed', [ 'pages', 'on_boarding' ] ); // set flag has not been passed
			}
			else {
				// set this flag to true just to do a redirect without changes in DB
				$return[ 'success' ] = true;
			}

			if ( $return[ 'success' ] ) {
				$return[ 'redirect' ] = [
					'url' => admin_url( 'admin.php?page=' . Page::NAMES[ 'on-boarding' ] ),
				];
			}

			return $return;
		}
	}
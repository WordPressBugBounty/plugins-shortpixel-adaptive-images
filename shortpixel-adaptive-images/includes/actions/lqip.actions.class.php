<?php

	namespace ShortPixel\AI\LQIP;

	use ShortPixelAI;
	use ShortPixel\AI\LQIP;
	use ShortPixel\AI\Page;
	use ShortPixelUrlTools;
	use ShortPixel\AI\Converter;

	/**
	 * AJAX endpoint of the instant LQIP mode (`shortpixel_ai_handle_lqip_action`, also `nopriv`, registered by `LQIP::hooks()`
	 * only when `behaviour.process_way` is `instant`).
	 */
	class Actions {
		/**
		 * Method handles the pages's actions
		 * Works via AJAX
		 *
		 * INSTANT LQIP only: requires  spainonce
		 *
		 * Nonce: action `shortpixel-ai-settings`, field `$_REQUEST['spainonce']` (`Page::checkSpaiNonce()` answers
		 * `{success: false, message}` and dies on failure). No capability check (anonymous visitors post here).
		 * Reads `$_POST['data']` (array); `data.action` is title-cased and dispatched to `handle<Action>( $data )`, so `collect`
		 * goes to `handleCollect()`. Answers `wp_send_json( <result>, 200 )`; without `action` the result is `{success: false}`.
		 * Outside AJAX it returns null.
		 *
		 * @return null
		 * @todo `handle()` dispatches with `call_user_func( [ 'self', ... ] )`, which is deprecated since PHP 8.2; use `[ self::class, ... ]` / `[ __CLASS__, ... ]`.
		 * @todo Any `data.action` other than `collect` makes `handle()` call an undefined method (TypeError on PHP 8); whitelist the actions.
		 */
		public static function handle() {
			if ( ShortPixelAI::isAjax() ) {
				Page::checkSpaiNonce();

				$data   = isset( $_POST[ 'data' ] ) ? wp_unslash( $_POST[ 'data' ] ) : null;
				$action = is_array( $data ) && isset( $data[ 'action' ] ) ? $data[ 'action' ] : null;

				$response = [ 'success' => false ];

				if ( !empty( $action ) && is_string( $action ) ) {
					$action = Converter::toTitleCase( $action );

					unset( $data[ 'action' ] );

					$response = call_user_func( [ 'self', 'handle' . $action ], is_array( $data ) ? $data : null );
				}

				wp_send_json( $response, 200 );
			}

			return null;
		}

		/**
		 * Handles collect action
		 *
		 * Sanitizes client-provided image URLs before they reach LQIP::process():
		 * - caps batch size to BUNDLE_CAPACITY
		 * - derives referer server-side (POST referer is ignored)
		 * - keeps only same-site, non-excluded, processable image URLs
		 *
		 * Rules, in order:
		 * 1. `data.collection` must be a non-empty array, else `{success: false, message: 'Empty collection.'}`;
		 * 2. more than `LQIP::BUNDLE_CAPACITY` (20) items -> `{success: false, message: 'Collection is too large.'}`;
		 * 3. referer = `wp_get_referer()` or `$_SERVER['HTTP_REFERER']` (esc_url_raw), kept only when its host equals
		 *    `ShortPixelDomainTools::get_site_domain()` case-insensitively, else false;
		 * 4. per item: must be an array with non-empty trimmed `url` and `source`, both `ShortPixelUrlTools::isValid()`; both are made
		 *    absolute; skipped when either `urlIsExcluded()`; skipped when the `url` host differs from the site domain (the `source` host
		 *    is not checked); kept as `['url', 'source', 'referer']`;
		 * 5. nothing kept -> `{success: false, message: 'No valid items in collection.'}`;
		 * 6. `LQIP::_()->process()`; answers `{success: true, message, collection: <processed>}`.
		 *
		 * @param array|null $data `$_POST['data']` without `action`
		 *
		 * @return array
		 */
		private static function handleCollect( $data ) {
			$collection = isset( $data[ 'collection' ] ) ? $data[ 'collection' ] : null;

			if ( !is_array( $collection ) || empty( $collection ) ) {
				return [
					'success' => false,
					'message' => __( 'Empty collection.', 'shortpixel-adaptive-images' ),
				];
			}

			// Limit DoS via oversized wp_options writes
			if ( count( $collection ) > LQIP::BUNDLE_CAPACITY ) {
				return [
					'success' => false,
					'message' => __( 'Collection is too large.', 'shortpixel-adaptive-images' ),
				];
			}

			// Referer is used later for cache invalidation; never trust data[referer] from the client
			$site_host = \ShortPixelDomainTools::get_site_domain();
			$referer   = wp_get_referer();

			if ( !$referer && isset( $_SERVER[ 'HTTP_REFERER' ] ) ) {
				$referer = esc_url_raw( wp_unslash( $_SERVER[ 'HTTP_REFERER' ] ) );
			}

			if ( $referer ) {
				$referer_host = wp_parse_url( $referer, PHP_URL_HOST );

				if ( !$site_host || !$referer_host || strcasecmp( $referer_host, $site_host ) !== 0 ) {
					$referer = false;
				}
			}

			// Drop invalid, external, or excluded URLs instead of passing attacker-controlled data to LQIP
			$sanitized = [];

			foreach ( $collection as $item ) {
				if ( !is_array( $item ) ) {
					continue;
				}

				$url    = isset( $item[ 'url' ] ) ? trim( (string) $item[ 'url' ] ) : '';
				$source = isset( $item[ 'source' ] ) ? trim( (string) $item[ 'source' ] ) : '';

				if ( $url === '' || $source === '' || !ShortPixelUrlTools::isValid( $url ) || !ShortPixelUrlTools::isValid( $source ) ) {
					continue;
				}

				$url    = ShortPixelUrlTools::absoluteUrl( $url );
				$source = ShortPixelUrlTools::absoluteUrl( $source );

				if ( ShortPixelAI::_()->urlIsExcluded( $url ) || ShortPixelAI::_()->urlIsExcluded( $source ) ) {
					continue;
				}

				$url_host = wp_parse_url( $url, PHP_URL_HOST );

				if ( !$site_host || !$url_host || strcasecmp( $url_host, $site_host ) !== 0 ) {
					continue;
				}

				$sanitized[] = [
					'url'     => $url,
					'source'  => $source,
					'referer' => $referer,
				];
			}

			if ( empty( $sanitized ) ) {
				return [
					'success' => false,
					'message' => __( 'No valid items in collection.', 'shortpixel-adaptive-images' ),
				];
			}

			$data      = LQIP::_()->process( $sanitized );
			$processed = $data[ 'processed' ];

			return [
				'success'    => true,
				'message'    => $processed ? __( 'Collection has been updated', 'shortpixel-adaptive-images' ) : __( 'Collection has not been updated', 'shortpixel-adaptive-images' ) . ' (' . $data[ 'message' ] . ')',
				'collection' => $processed,
			];
		}
	}

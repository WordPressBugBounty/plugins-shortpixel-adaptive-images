<?php

	namespace ShortPixel\AI\LQIP;

	use ShortPixelAI;
	use ShortPixel\AI\LQIP;
	use ShortPixel\AI\Page;
	use ShortPixelUrlTools;
	use ShortPixel\AI\Converter;

	class Actions {
		/**
		 * Method handles the pages's actions
		 * Works via AJAX
		 *
		 * INSTANT LQIP only: requires  spainonce
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
		 * @param $data
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

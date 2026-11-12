<?php

	namespace ShortPixel\AI;

	/**
	 * Thin static wrapper around wp_safe_remote_request() for the plugin's outgoing HTTP calls.
	 *
	 * Used for the ShortPixel API feedback endpoint, the CDN domain checks
	 * (`ShortPixelDomainTools`), LQIP placeholder downloads and the wordpress.org readme
	 * (`PluginUpdateNotice`). wp_safe_remote_request() rejects URLs that resolve to local or
	 * private addresses; tests can intercept every call with the `pre_http_request` filter.
	 * Holds only the constant `$arguments` defaults; nothing to reset between tests.
	 */
	class Request {
		/**
		 * Default request arguments, merged under the caller's arguments.
		 *
		 * @var array
		 */
		private static $arguments = [ 'timeout' => 15, 'httpversion' => '1.1' ];

		/**
		 * Sends a GET request.
		 *
		 * @param string     $url       Request URL.
		 * @param bool       $json      True to return the json_decode()d body (objects, not
		 *                              arrays); a non-bool value counts as true.
		 * @param array|null $arguments wp_remote_request() arguments; defaults are merged under
		 *                              them and `method` is forced to GET.
		 *
		 * @return mixed|string|\WP_Error See request().
		 */
		public static function get( $url, $json = true, $arguments = null ) {
			$arguments = is_array( $arguments ) && !empty( $arguments ) ? $arguments : self::$arguments;
			$arguments = array_merge( $arguments, [ 'method' => 'GET' ] );
			$json      = is_bool( $json ) ? $json : true;

			return self::request( $url, $json, $arguments );
		}

		/**
		 * Sends a POST request.
		 *
		 * @param string     $url       Request URL.
		 * @param bool       $json      True to return the json_decode()d body; a non-bool value
		 *                              counts as true.
		 * @param array|null $arguments wp_remote_request() arguments (e.g. `['body' => …]`);
		 *                              defaults are merged under them and `method` is forced to POST.
		 *
		 * @return mixed|string|\WP_Error See request().
		 */
		public static function post( $url, $json = true, $arguments = null ) {
			$arguments = is_array( $arguments ) && !empty( $arguments ) ? $arguments : self::$arguments;
			$arguments = array_merge( $arguments, [ 'method' => 'POST' ] );
			$json      = is_bool( $json ) ? $json : true;

			return self::request( $url, $json, $arguments );
		}

		/**
		 * Performs the request and unwraps the body.
		 *
		 * Merges `$arguments` over the defaults, calls wp_safe_remote_request() and logs any
		 * failure through `ShortPixelAILogger` (only when debug is on).
		 *
		 * @param string $url       Request URL.
		 * @param bool   $json      True to json_decode() the body.
		 * @param array  $arguments Request arguments.
		 *
		 * @return mixed|string|\WP_Error The WP_Error on transport failure; `''` when the response
		 *                                has no `response` array or no `body`; otherwise the
		 *                                decoded body (null when it is not valid JSON) or the
		 *                                raw body string.
		 *
		 * @todo request() does not check the HTTP status code, so a 4xx/5xx error page is
		 *       returned (or decoded) as if it were a successful response. Check
		 *       wp_remote_retrieve_response_code() and return a WP_Error for non-2xx codes.
		 */
		private static function request( $url, $json, $arguments ) {
			$arguments = is_array( $arguments ) && !empty( $arguments ) ? array_merge( self::$arguments, $arguments ) : self::$arguments;

			$response = wp_safe_remote_request( $url, $arguments );

			if ( is_wp_error( $response ) ) {
                \ShortPixelAILogger::instance()->log("REQUEST ERR", $response);
				return $response;
			}

			if ( !isset( $response[ 'response' ] ) || !is_array( $response[ 'response' ] ) ) {
                \ShortPixelAILogger::instance()->log("REQUEST UNKNOWN:", $response);
				return '';
			}

			if ( !isset( $response[ 'body' ] ) ) {
                \ShortPixelAILogger::instance()->log("REQUEST PAYLOAD EMPTY: ", $response);
				return '';
			}

			return !!$json ? json_decode( $response[ 'body' ] ) : $response[ 'body' ];
		}
	}
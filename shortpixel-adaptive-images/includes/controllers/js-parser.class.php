<?php
	/**
	 * Inline JavaScript rewriter, plus the bootstrap of the bundled MatthiasMullie Minify library.
	 *
	 * Loading this file (the root plugin file requires it on every request) requires, unless
	 * `MatthiasMullie\Minify\Minify` is already loaded by another plugin: the `Psr\Cache`
	 * interfaces (only when `Psr\Cache\CacheException` is not defined yet), the PathConverter
	 * classes and the Minify `Minify`, `JS`, `CSS` and exception classes from
	 * `includes/libs/`. When another plugin ships its own Minify, that copy (any version) is used.
	 *
	 * @package ShortPixel\AI
	 */
	if ( !class_exists( 'MatthiasMullie\Minify\Minify' ) ) {
		$libs_dir = SHORTPIXEL_AI_PLUGIN_DIR . '/includes/libs';

        if( !class_exists( 'Psr\Cache\CacheException' ) && !interface_exists( 'Psr\Cache\CacheException' ) ) {
            // psr\cache
            require_once $libs_dir . '/psr/cache/src/CacheException.php';
            require_once $libs_dir . '/psr/cache/src/CacheItemInterface.php';
            require_once $libs_dir . '/psr/cache/src/CacheItemPoolInterface.php';
            require_once $libs_dir . '/psr/cache/src/InvalidArgumentException.php';
        }

		// path-converter
		require_once $libs_dir . '/path-converter/src/ConverterInterface.php';
		require_once $libs_dir . '/path-converter/src/NoConverter.php';
		require_once $libs_dir . '/path-converter/src/Converter.php';

		// minify
		require_once $libs_dir . '/minify/src/Minify.php';
		require_once $libs_dir . '/minify/src/JS.php';
		require_once $libs_dir . '/minify/src/CSS.php';
		require_once $libs_dir . '/minify/src/Exception.php';
		require_once $libs_dir . '/minify/src/Exceptions/BasicException.php';
		require_once $libs_dir . '/minify/src/Exceptions/FileImportException.php';
		require_once $libs_dir . '/minify/src/Exceptions/IOException.php';
	}

	use MatthiasMullie\Minify;

	/**
	 * Rewrites `.jpg`/`.jpeg`/`.png`/`.gif` URLs inside inline JavaScript.
	 *
	 * Created per script by `ShortPixelRegexParser::parse()` (inline scripts with a JavaScript or
	 * no `type`, and CDATA blocks via `replace_cdata_js()`). Every parsed script is MINIFIED first
	 * with `MatthiasMullie\Minify\JS`, whether or not a URL is replaced.
	 */
	class ShortPixelJsParser {
		/**
		 * @var ShortPixelAI The plugin controller.
		 */
		protected $ctrl;
		/**
		 * @var bool|mixed True to emit SVG placeholders (lazy) instead of CDN URLs.
		 */
		protected $lazy;
		/**
		 * @var ShortPixelAILogger Shared logger instance.
		 */
		private   $logger;

		/**
		 * @param ShortPixelAI $ctrl The plugin controller.
		 * @param bool|mixed   $lazy false (default) reads `settings.areas.parse_js_lazy`; any other
		 *                           value is used as is.
		 */
		public function __construct(ShortPixelAI $ctrl, $lazy = false ) {
			$this->ctrl   = $ctrl;
			$this->lazy   = $lazy === false ? !!$ctrl->settings->areas->parse_js_lazy : $lazy;
			$this->logger = ShortPixelAILogger::instance();
		}

		/**
		 * Minifies a script and rewrites the image URLs in it.
		 *
		 * Input grammar, two forms:
		 * - a block `<script ...>BODY</script>` (first `<script...>` up to the first `</script>`,
		 *   ungreedy): when BODY is non-empty the result is `<opening tag>` + replaceUrls(minify(BODY))
		 *   + `</script>`; anything after the first `</script>` is dropped. An empty BODY (e.g.
		 *   `<script src="x.js"></script>`) returns the input unchanged.
		 * - bare JS (no `<script>` tag, e.g. a CDATA block): replaceUrls(minify(input)).
		 *
		 * Minification uses `MatthiasMullie\Minify\JS::minify()` (comments and whitespace removed,
		 * statements may be joined). URL rewriting: see {@see replaceUrls()}.
		 *
		 * Output grammar: same form as the input, with each accepted URL replaced by a CDN URL (see
		 * ShortPixelAI::get_api_url()) or, when lazy, by an SVG placeholder data URI.
		 *
		 * Example (not lazy): `<script>var a = "https://example.test/a.jpg"; // c</script>` gives
		 * `<script>` + the minified code (`var a="..."`, comment and spaces gone) with the URL
		 * replaced by `https://cdn.shortpixel.ai/spai/q_lossy+ret_img/example.test/a.jpg` + `</script>`.
		 *
		 * @todo parse() minifies every inline script although only the URLs need changing (see the
		 *       inline TODO); minification can break scripts that rely on formatting and costs time
		 *       on every page. Replace the URLs in the original text and drop the minifier.
		 *
		 * @param string $script Script block or bare JS.
		 * @return string The rewritten script.
		 */
		public function parse( $script ) {

			if ( preg_match( '/(\<script[^>]*\>)(.*)(<\/script>)/sU', $script, $matches ) ) {
				if ( !empty( $matches[ 2 ] ) ) {
                    //TODO ditch minifier and just remove comments
                    $minifier = new Minify\JS( $matches[ 2 ] );
					$minified_js = $minifier->minify();

					$replacedUrls = $this->replaceUrls( $minified_js );

					$this->logger->log( 'JS Parser [ After minify and replace (script with tag) ]: ' . $replacedUrls );

					return $matches[1] . $replacedUrls . '</script>';
				}
			}
			// if just JS content has been provided
			else {
                $this->logger->log( 'JS Parser [ Before minify (script content) ] ' );
                //TODO ditch minifier and just remove comments
                $minifier = new Minify\JS( $script );
				$minified_js = $minifier->minify();

                $this->logger->log( 'JS Parser [ Before replace (script content) ]: ' );
				$replacedUrls = $this->replaceUrls( $minified_js );

				$this->logger->log( 'JS Parser [ After minify and replace (script content) ]: ' . $replacedUrls );

				return $replacedUrls;
			}

			return $script;
		}

		/**
		 * Finds image URLs in JS text and rewrites them with {@see replaceUrl()}.
		 *
		 * Pass 1 (candidate): `(https?:\\?/\\?/ | \\?/\\?/) [^\s'"`]* .(jpe?g|png|gif)` (unicode),
		 * i.e. absolute or protocol-relative URLs, also JSON-escaped (`https:\/\/x\/a.jpg`), up to the
		 * last image extension before a quote or whitespace. A candidate containing a backslash is
		 * passed through `stripslashes()`, which removes EVERY backslash in it.
		 * Pass 2 (inside the candidate): a strict URL regex (scheme or `//`, optional auth, IPv4
		 * excluding private ranges or a host name with a TLD, optional port, path, image extension)
		 * whose matches go to {@see replaceUrl()}.
		 *
		 * When lazy, every non-`img` tag that now carries a `data:image/svg+xml;` value (the text may
		 * be HTML in a JS string) is added to `affectedTags` with `SRC_ATTR | CSS_ATTR`.
		 *
		 * Example: `"https:\/\/example.test\/a.jpg"` gives `"https://cdn.shortpixel.ai/spai/.../example.test/a.jpg"`
		 * (escaping lost).
		 *
		 * @todo replaceUrls() returns escaped URLs (`https:\/\/...`) unescaped, so JSON-in-JS output
		 *       changes form, and stripslashes() also eats legitimate backslashes in the candidate.
		 *       Remember whether the candidate used `\/` and re-escape the replacement with
		 *       `str_replace('/', '\/', ...)`.
		 * @todo replaceUrls() only handles jpg/jpeg/png/gif; webp, avif and svg URLs in scripts stay
		 *       on the origin. Align the extension list with `ShortPixelUrlTools::$PROCESSABLE_EXTENSIONS`.
		 *
		 * @param string $text Minified JS.
		 * @return string The JS with URLs rewritten.
		 */
		protected function replaceUrls( $text ) {
			$this->logger->log( "try replace URLs in $text \n\n" );

			//this expression is better because it works with characters such as three bytes accented letters (é vs. é - notice any difference? the first is on three bytes, second is UTF8...)
			// original pattern
			//			$pattern = '/(?:https?:\/\/)(?:\S+(?::\S*)?@)?(?:(?!10(?:\.\d{1,3}){3})(?!127(?:\.\d{1,3}){3})(?!169\.254(?:\.\d{1,3}){2})(?!192\.168(?:\.\d{1,3}){2})(?!172\.(?:1[6-9]|2\d|3[0-1])(?:\.\d{1,3}){2})(?:[1-9]\d?|1\d\d|2[01]\d|22[0-3])(?:\.(?:1?\d{1,2}|2[0-4]\d|25[0-5])){2}(?:\.(?:[1-9]\d?|1\d\d|2[0-4]\d|25[0-4]))|(?:(?:[a-z\x{00a1}-\x{ffff}0-9]+-?)*[a-z\x{00a1}-\x{ffff}0-9]+)(?:\.(?:[a-z\x{00a1}-\x{ffff}0-9]+-?)*[a-z\x{00a1}-\x{ffff}0-9]+)*(?:\.(?:[a-z\x{00a1}-\x{ffff}]{2,})))(?::\d{2,5})?(?:\/[^\s]*)?\.(?:jpe?g|png|gif)\b/su';

			//			$pattern2 = '/(?:https?:\\\\?\\/\\\\?\\/)(?:\\S+(?::\\S*)?@)?(?:(?!10(?:\\.\\d{1,3}){3})(?!127(?:\\.\\d{1,3}){3})(?!169\\.254(?:\\.\\d{1,3}){2})(?!192\\.168(?:\\.\\d{1,3}){2})(?!172\\.(?:1[6-9]|2\\d|3[0-1])(?:\\.\\d{1,3}){2})(?:[1-9]\\d?|1\\d\\d|2[01]\\d|22[0-3])(?:\\.(?:1?\\d{1,2}|2[0-4]\\d|25[0-5])){2}(?:\\.(?:[1-9]\\d?|1\\d\\d|2[0-4]\\d|25[0-4]))|(?:(?:[a-z\\x{00a1}-\\x{ffff}0-9]+-?)*[a-z\\x{00a1}-\\x{ffff}0-9]+)(?:\\.(?:[a-z\\x{00a1}-\\x{ffff}0-9]+-?)*[a-z\\x{00a1}-\\x{ffff}0-9]+)*(?:\\.(?:[a-z\\x{00a1}-\\x{ffff}]{2,})))(?::\\d{2,5})?(?:\\\\?\\/[^\\s]*)?\\.(?:jpe?g|png|gif)\\b/su';

			//$pattern = '/(?:https?:\\\\?\\/\\\\?\\/|\\\\?\\/\\\\?\\/)(?:\\S+(?::\\S*)?@)?(?:(?!10(?:\\.\\d{1,3}){3})(?!127(?:\\.\\d{1,3}){3})(?!169\\.254(?:\\.\\d{1,3}){2})(?!192\\.168(?:\\.\\d{1,3}){2})(?!172\\.(?:1[6-9]|2\\d|3[0-1])(?:\\.\\d{1,3}){2})(?:[1-9]\\d?|1\\d\\d|2[01]\\d|22[0-3])(?:\\.(?:1?\\d{1,2}|2[0-4]\\d|25[0-5])){2}(?:\\.(?:[1-9]\\d?|1\\d\\d|2[0-4]\\d|25[0-4]))|(?:(?:[a-z\\x{00a1}-\\x{ffff}0-9]+-?)*[a-z\\x{00a1}-\\x{ffff}0-9]+)(?:\\.(?:[a-z\\x{00a1}-\\x{ffff}0-9]+-?)*[a-z\\x{00a1}-\\x{ffff}0-9]+)*(?:\\.(?:[a-z\\x{00a1}-\\x{ffff}]{2,})))(?::\\d{2,5})?(?:\\\\?\\/[^\\s]*)?\\.(?:jpe?g|png|gif)\\b/su';
			//$pattern = "/(https?:|)\/\/(?:www\.)?[-a-zA-Z0-9@:%._\+~#=]{2,256}\.[a-z]{2,4}\b(?:[-a-zA-Z0-9@:%_\+.~#?&\/\/=\(\)]*)\.(jpe?g|png|gif)\b/s";

			//			$simple_pattern = '/(?P<protocol>https?:\\?\/\\?\/|\\?\/\\?\/)[^\s\'\"\`]+\.(?P<extension>jpe?g|png|gif)/us';

			// pattern - /(?:https?:\\?\/\\?\/|\\?\/\\?\/)[^\s\'\"`]*\.(?:jpeg|png|gif)/us
			$ret = preg_replace_callback( '/(?:https?:\\\\?\/\\\\?\/|\\\\?\/\\\\?\/)[^\s\'\"`]*\.(?:jpe?g|png|gif)/us',
				function( $matches ) {
					// strip slashes because it corrupts a functionality
					if ( strpos( $matches[ 0 ], '\\' ) !== false ) {
						$matches[ 0 ] = stripslashes( $matches[ 0 ] );
					}

					$pattern = '/(?:https?:\/\/|\/\/)(?:\S+(?::\S*)?@)?(?:(?!10(?:\.\d{1,3}){3})(?!127(?:\.\d{1,3}){3})(?!169\.254(?:\.\d{1,3}){2})(?!192\.168(?:\.\d{1,3}){2})(?!172\.(?:1[6-9]|2\d|3[0-1])(?:\.\d{1,3}){2})(?:[1-9]\d?|1\d\d|2[01]\d|22[0-3])(?:\.(?:1?\d{1,2}|2[0-4]\d|25[0-5])){2}(?:\.(?:[1-9]\d?|1\d\d|2[0-4]\d|25[0-4]))|(?:(?:[a-z\x{00a1}-\x{ffff}0-9]+-?)*[a-z\x{00a1}-\x{ffff}0-9]+)(?:\.(?:[a-z\x{00a1}-\x{ffff}0-9]+-?)*[a-z\x{00a1}-\x{ffff}0-9]+)*(?:\.(?:[a-z\x{00a1}-\x{ffff}]{2,})))(?::\d{2,5})?(?:\/[^\s]*)?\.(?:jpe?g|png|gif)\b/su';

					return preg_replace_callback( $pattern, [ $this, 'replaceUrl' ], $matches[ 0 ] );
				},
				$text );

			if ( $this->lazy ) {
				//the text might be HTML, we need to mark the possible tags that have lazy replacements
				preg_match_all( '/\<([\w]+)[^\>]+data:image\/svg\+xml;/s', stripslashes( $ret ), $matches );
				if ( isset( $matches[ 1 ] ) ) {
					foreach ( $matches[ 1 ] as $tag ) {
						if ( strtolower( $tag ) !== 'img' ) {
                            $flags = \ShortPixel\AI\AffectedTags::SRC_ATTR | \ShortPixel\AI\AffectedTags::CSS_ATTR;
                            $this->ctrl->affectedTags->add($tag, $flags);
						}
					}
				}
			}
			return $ret;
		}

		/**
		 * Rewrites one URL found by {@see replaceUrls()}.
		 *
		 * Unchanged when it contains `settings.behaviour.api_url`, or (after `absoluteUrl()`) is
		 * `urlIsExcluded()` or not `isValid()`. Otherwise lazy:
		 * `generate_placeholder_svg(<get_image_size()>, $url)`; eager:
		 * `get_api_url($url, false, false, <ext>)` (no `w_` token).
		 *
		 * @param string[] $match [0] the URL.
		 * @return string The replacement.
		 */
		protected function replaceUrl( $match ) {
			$this->logger->log( 'Matches' . json_encode( $match ) . "\n" );
			if ( strpos( $match[ 0 ], $this->ctrl->settings->behaviour->api_url ) === false ) {
				$url = ShortPixelUrlTools::absoluteUrl( $match[ 0 ] );
				if($this->ctrl->urlIsExcluded($url) || !ShortPixelUrlTools::isValid($url)) {
				    return $match[0];
                }
				if ( $this->lazy ) {
					$sizes = ShortPixelUrlTools::get_image_size( $url );
					$ret   = ShortPixelUrlTools::generate_placeholder_svg( isset( $sizes[ 0 ] ) ? $sizes[ 0 ] : false, isset( $sizes[ 1 ] ) ? $sizes[ 1 ] : false, $url );
				}
				else {
					$ret = $this->ctrl->get_api_url($url, false, false, $this->ctrl->get_extension($url) );
				}
				$this->logger->log( "Changing to $ret.\n\n" );

				return $ret;
			}
			else {
				return $match[ 0 ];
			}
		}
	}

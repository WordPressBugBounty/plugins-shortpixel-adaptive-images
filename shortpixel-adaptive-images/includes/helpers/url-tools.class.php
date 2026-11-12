<?php
/**
 * Static URL, path, image-size and placeholder helpers shared by the parsers and the controller.
 *
 * @package ShortPixel\AI
 */

use \ShortPixel\AI\LQIP;
use \ShortPixel\AI\Options;

/**
 * URL helpers: validation, absolutisation, file-system lookup of image sizes, SVG
 * placeholders, CSP nonces.
 *
 * All methods are static. State: `$SIZE_CACHE` (private static) memoises
 * {@see get_image_size()} per URL for the whole request; tests must reset it through reflection
 * (`ShortPixelUrlTools::$SIZE_CACHE = []`). Several methods also touch singletons:
 * `isValid()` calls `ShortPixelAI::_()` (creating the controller if needed) for CSS/JS/font
 * URLs; `generate_placeholder_svg()` reads `Options::_()`, `LQIP::_()` and writes
 * `ShortPixelAI::_()->blankInlinePlaceholders`; `get_image_size()` reads `Options::_()`.
 *
 * The CDN URL grammar itself is built by ShortPixelAI::get_api_url(); this class only decides
 * which URLs qualify and supplies the placeholder that lazy elements carry instead.
 */
class ShortPixelUrlTools {
    /**
     * Base64 of a 1x1 transparent GIF. Only referenced by the commented-out GIF placeholder code.
     * @var string
     */
    const PX_ENCODED = 'R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==';
    /**
     * Base64 of `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1" width="1" height="1" data-bip=""></svg>`:
     * the placeholder {@see generate_placeholder_svg()} returns when width, height and URL are all empty.
     * @var string
     */
    const PX_SVG_ENCODED = 'PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxIDEiIHdpZHRoPSIxIiBoZWlnaHQ9IjEiIGRhdGEtYmlwPSIiPjwvc3ZnPg==';
    /**
     * Old placeholder template without the URL. Only referenced by commented-out code.
     * @var string
     */
    const PX_SVG_TPL = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %WIDTH% %HEIGHT%" width="%WIDTH%" height="%HEIGHT%" data-bip=""></svg>';

    /* New constants */
    /**
     * Placeholder template: `%WIDTH%`, `%HEIGHT%` and `%URL%` (urlencoded original URL) are
     * substituted by {@see generate_placeholder_svg()}. `data-u` is what the front-end JS and
     * {@see url_from_placeholder_svg()} read back; `data-bip` marks a blank inline placeholder.
     * @var string
     */
    const PX_SVG_TEMPLATE = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %WIDTH% %HEIGHT%" width="%WIDTH%" height="%HEIGHT%" data-u="%URL%" data-w="%WIDTH%" data-h="%HEIGHT%" data-bip=""></svg>';

    /**
     * Extensions (lower case, no dot) the CDN can serve: raster/vector images and PDF, CSS/JS,
     * fonts. {@see isValid()} accepts any other URL only if its extension is listed here
     * (css/js/woff/woff2/ttf have extra host rules there); `ShortPixelJsonParser::replaceUrl()`
     * also filters on it.
     * Example: `in_array('jfif', ShortPixelUrlTools::$PROCESSABLE_EXTENSIONS)` is true, `'heic'` is false.
     * @var string[]
     */
    public static $PROCESSABLE_EXTENSIONS = [
        'jpg', 'jpeg', 'jpe', 'jfif', 'gif', 'png', 'pdf', 'svg', 'webp', 'avif', 'bmp', 'tiff',
        'css', 'js',
        'eot', 'woff', 'woff2', 'ttf', 'otf' ];
    /**
     * Types the CDN only stores and serves as is: ShortPixelAI::get_api_url() emits no `w_`,
     * `q_`, `ex_1` or `to_*` token for them (only `ret_*`, `p_h`, `v_N`). Note `css` is not
     * listed (it gets `q_`) while `js` is.
     * Example: for `a.woff2` the URL is `<base>/ret_img/<origin>`.
     * @var string[]
     */
    public static $ONLY_STORE = [ 'svg', 'js', 'eot', 'webp', 'avif', 'woff', 'woff2', 'ttf', 'otf' ];
    /**
     * Types that `ShortPixelRegexParser::_replace_images()` requests with `ret_auto` and the CSS
     * cache version `v_N` when eager (CDN waits for the processed file instead of redirecting,
     * avoiding CORS problems).
     * Example: an eager `<link href="a.css">` gets `.../ret_auto+v_3/...` (other tokens omitted).
     * @var string[]
     */
    public static $EAGER_WAIT = [ 'css', 'js', 'woff2', 'woff', 'otf', 'ttf', 'eot' ];
    /**
     * @var array<string,array> Request-wide memo of {@see get_image_size()} results keyed by the
     *                          URL as passed. Reset it between tests.
     */
    private static $SIZE_CACHE = [];

    /**
     * Decides whether a URL may be sent to the CDN.
     *
     * Input grammar: absolute (`https://h/p`), protocol-relative (`//h/p`) or relative (`/p`, `p`)
     * URL, possibly HTML-entity-encoded and wrapped in whitespace, NBSP bytes or quotes.
     *
     * Steps:
     * 1. Entity-decode, then trim spaces, `\0`, `\x0B`, the bytes `\xC2`/`\xA0` and `'`/`"`.
     * 2. Empty: false. Contains `<`, `>`, `"`, `'` or a backtick: false (attribute break-out guard).
     * 3. Rebuild with each path segment `urlencode()`d (so UTF-8 paths validate) and check with
     *    `FILTER_VALIDATE_URL`; relative URLs are checked as `http://www.example.com/<url>`.
     *    The query/fragment are re-attached by offset, assuming the rebuilt prefix has the
     *    original length (ports and encoded chars shift it; the check usually still passes).
     * 4. No path (e.g. `https://example.test`): false.
     * 5. Extension (lower-cased, from the path) `css`, `js`, `woff2`, `woff` or `ttf`: true for
     *    relative URLs; otherwise true only when the host equals `home_url()`'s host or is listed in
     *    `settings.areas.css_domains` (comma-separated, entries not trimmed).
     * 6. Any other extension: true iff in {@see $PROCESSABLE_EXTENSIONS} (any host; `eot`/`otf`
     *    are not host-restricted).
     *
     * Examples: `https://other.test/a.JPG` true; `https://other.test/a.css` false (not the site);
     * `/a.css` true; `https://example.test/` false; `/a.jpg"onerror=x` false.
     *
     * @todo isValid() does not trim the entries of `css_domains`, so `a.test, b.test` never
     *       matches `b.test`. Apply `array_map('trim', ...)`.
     *
     * @param string $url URL to check.
     * @return bool
     */
    public static function isValid($url) {
		$url = trim( html_entity_decode($url), " \t\n\r\0\x0B\xC2\xA0'\"" );
		if ( strlen( $url ) == 0 ) {
			return false;
		}

		//XSS: real URLs never contain these once decoded, a crafted one would break out of the attribute
		if ( preg_match( '/[<>"\'`]/', $url ) ) {
			return false;
		}

		//handle URLs that contain unencoded UTF8 characters like: https://onlinefox.xyz/wp-content/uploads/2018/10/Kragerup-gods-go-high-klatrepar-åbningstider.jpg"
		$parsed = parse_url( $url );
		$path   = ( isset( $parsed[ 'host' ] ) ? $parsed[ 'host' ] : '' )
		          . ( isset( $parsed[ 'path' ] ) ? implode( '/', array_map( 'urlencode', explode( '/', $parsed[ 'path' ] ) ) ) : '' );

		if ( isset( $parsed[ 'host' ] ) && $parsed[ 'host' ] != '' ) {
			// URL has http/https/...
			$scheme  = isset( $parsed[ 'scheme' ] ) ? $parsed[ 'scheme' ] : self::getCurrentScheme();
			$first   = $scheme . '://' . $parsed[ 'host' ] . $path;
			$url     = $first . substr( $url, strlen( $first ) ); //make sure we keep query or hashtags
			$isValid = !( filter_var( $url, FILTER_VALIDATE_URL ) === false );
		}
		else {
			// PHP filter_var does not support relative urls, so we simulate a full URL
			$url     = $path . substr( $url, strlen( $path ) ); //make sure we keep query or hashtags
			$isValid = !( filter_var( 'http://www.example.com/' . ltrim( $url, '/' ), FILTER_VALIDATE_URL ) === false );
		}
		if ( $isValid && isset( $parsed[ 'path' ] ) ) { //lastly check if is processable by ShortPixel
			$ext = strtolower( pathinfo( $parsed[ 'path' ], PATHINFO_EXTENSION ) );
			//treat the .css, .js and fonts case separately, only for local CSS files
			if ( in_array($ext, [ 'css', 'js', 'woff2', 'woff', 'ttf' ])) {
				if ( !isset( $parsed[ 'host' ] ) || $parsed[ 'host' ] == '' ) {
					return true;
				}
				$parsedHome = parse_url( home_url() );
				//TODO allowed domains unhack
				$cssDomains = ShortPixelAI::_()->settings->areas->css_domains;
				$cssDomains = strlen($cssDomains ?: '') ? explode(',', $cssDomains) : [];
				ShortPixelAILogger::instance()->log( "CSS DOMAIN: {$parsed['host']} EXTRA DOMAINS: ", $cssDomains );

				return isset( $parsedHome[ 'host' ] ) && ( $parsed[ 'host' ] == $parsedHome[ 'host' ] || in_array( $parsed[ 'host' ], $cssDomains ) );
			}
			else {
				return in_array( $ext, self::$PROCESSABLE_EXTENSIONS );
			}
		}

		return false;
	}

	/**
	 * Turns a URL found in a page or CSS file into an absolute URL.
	 *
	 * Input grammar and result, by the first matching form (`proto` = `is_ssl()` ? https : http):
	 * ```
	 * scheme://host/...   -> unchanged
	 * //host/...          -> proto://host/...
	 * /path               -> cssScheme://cssHost/path         when $cssPath is a URL
	 *                     -> homeScheme://homeHost/path       otherwise (home port and sub-path dropped)
	 * rel/path            -> normalizePath($cssPath . rel/path), with get_home_path() swapped for
	 *                        trailingslashit(get_home_url())   when $cssPath is a file-system path under the WP root
	 *                     -> normalizePath(trailingslashit(dirname($cssPath)) . rel/path)  when $cssPath is a URL
	 *                     -> rel/path unchanged                when $cssPath is any other string
	 *                     -> trailingslashit(home_url($wp->request)) . rel/path  without $cssPath
	 * ```
	 * The result always goes through filter `shortpixel/ai/originalUrl`. An empty string yields
	 * the current page URL. Relative URLs need the global `$wp` (front-end request).
	 *
	 * Examples (site `https://example.test`, page `/blog/post/`): `//cdn.test/a.jpg` gives
	 * `https://cdn.test/a.jpg`; `/a.jpg` gives `https://example.test/a.jpg`; `a.jpg` gives
	 * `https://example.test/blog/post/a.jpg`.
	 *
	 * @todo absoluteUrl() with a URL `$cssPath` (as `ShortPixelAI::parse_cached_css_wpo()` passes)
	 *       is wrong twice: `dirname()` of the already-trailing-slashed directory drops its last
	 *       segment, and normalizePath() collapses `https://` to `https:/`. Use the directory as is
	 *       and normalise only the path component.
	 * @todo absoluteUrl() drops the port of `home_url()` for root-relative URLs
	 *       (`http://localhost:8080` gives `http://localhost/a.jpg`). Append `:<port>` when present.
	 *
	 * @param string       $url     URL as found.
	 * @param false|string $cssPath Directory (path or URL, trailing slash) of the CSS file that
	 *                              contains the URL, or false.
	 * @return string The absolute URL (filtered).
	 */
	static function absoluteUrl($url, $cssPath = false) {
        $url = trim($url);
        $URI = parse_url($url);
        $retURL = false;
        $cssUrl = parse_url($cssPath);
        $isCssUrl = isset($cssUrl['host']) && strlen($cssUrl['host']);
        $crtProto = is_ssl() ? 'https' : 'http';
        $cssUrlProto = isset($cssUrl['scheme']) && strlen($cssUrl['scheme']) ? $cssUrl['scheme'] : $crtProto;

        if(isset($URI['host']) && strlen($URI['host'])) {
            if(!isset($URI['scheme']) || !strlen($URI['scheme'])) {
                $url = $crtProto . '://' . ltrim($url, '/');
            }
            $retURL = $url;
        } elseif(substr($url, 0, 1) === '/') {
            if($isCssUrl) {
                $retURL = $cssUrlProto . '://' . $cssUrl['host'] . $url;
            }
            else {
                $home = parse_url(home_url());
                $retURL = $home['scheme'] . '://' . $home['host'] . $url;
            }
        } else {
            if($cssPath) {
                $homePath = self::get_home_path();
                if(strpos($cssPath, $homePath) !== false) {
                    $url = self::normalizePath($cssPath . $url);
                    $retURL = str_replace( $homePath, trailingslashit(get_home_url()), $url);
                } elseif($isCssUrl) {
                    $retURL = self::normalizePath(trailingslashit(dirname($cssPath)) . $url);
                }else {
                    $retURL = $url;
                }
            } else {
                global $wp;
                $retURL =  trailingslashit(home_url($wp->request)) . $url;
            }
        }
        return apply_filters('shortpixel/ai/originalUrl', $retURL);
    }

    /**
     * Copy of `get_home_path()` from `wp-admin/includes/file.php` (not loaded on the front end):
     * the file-system root of the site, with forward slashes and a trailing slash. When home and
     * site URL differ, it is derived from `$_SERVER['SCRIPT_FILENAME']`; otherwise `ABSPATH`.
     *
     * @return string
     */
    static function get_home_path() {
        $home    = set_url_scheme( get_home_url(), 'http' );
        $siteurl = set_url_scheme( get_site_url(), 'http' );
        if ( ! empty( $home ) && 0 !== strcasecmp( $home, $siteurl ) ) {
            $wp_path_rel_to_home = str_ireplace( $home, '', $siteurl ); /* $siteurl - $home */
            $pos = strripos( str_replace( '\\', '/', $_SERVER['SCRIPT_FILENAME'] ), trailingslashit( $wp_path_rel_to_home ) );
            $home_path = substr( $_SERVER['SCRIPT_FILENAME'], 0, $pos );
            $home_path = trailingslashit( $home_path );
        } else {
            $home_path = ABSPATH;
        }

        return str_replace( '\\', '/', $home_path );
    }


    /**
     * Collapses `//` and `/./` to `/`, and `/<segment>/../` to `/`, repeatedly until stable.
     *
     * Grammar: only segments without a dot are collapsed by `..` (`/v1.2/../` stays); a trailing
     * `/..` without slash stays; leading `../` stays. Applied to a URL it also turns `https://` into
     * `https:/` (see the absoluteUrl() todo).
     *
     * Example: `/a/b/../c/./d//e` gives `/a/c/d/e`.
     *
     * @param string $path Path (or URL).
     * @return string The normalised path.
     */
    public static function normalizePath($path) {
        do {
            $path = preg_replace(
                array('#//|/\./#', '#/([^/.]+)/\.\./#'),
                '/', $path, -1, $count
            );
        } while($count > 0);
        return $path;
    }

    /**
     * Removes `$frag` once from the start and once from the end of `$text`, if present there.
     * With an array, does so for each fragment in order. No caller in the plugin.
     *
     * Example: `trimSubstring('"a"', '"')` gives `a`.
     *
     * @todo trimSubstring() has no caller; remove it.
     *
     * @param string          $text
     * @param string|string[] $frag
     * @return string
     */
    public static function trimSubstring($text, $frag) {
        if(is_array($frag)) {
            foreach($frag as $f) {
                $text = self::trimSubstring($text, $f);
            }
            return $text;
        }
        $fragLen = strlen($frag);
        if(0 === strpos($text, $frag)) {
            $text = substr($text, $fragLen).'';
        }
        $textLen = strlen($text);
        if ($textLen - $fragLen === strrpos($text, $frag)) {
            $text = substr($text, 0, $textLen - $fragLen);
        }
        return $text;
    }

    /**
     * Scheme of the current request: `https` when `$_SERVER['HTTPS']` is `on` or `1`, or
     * `HTTP_X_FORWARDED_PROTO` is `https`; else `http`. Used by {@see isValid()} for
     * protocol-relative URLs. Unlike `is_ssl()` it honours the forwarded header.
     *
     * @return string `https` or `http`.
     */
    public static function getCurrentScheme() {
        if (isset($_SERVER['HTTPS']) &&
            ($_SERVER['HTTPS'] == 'on' || $_SERVER['HTTPS'] == 1) ||
            isset($_SERVER['HTTP_X_FORWARDED_PROTO']) &&
            $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https') {
            return 'https';
        }
        else {
            return 'http';
        }
    }

    /**
     * Returns the pixel size of the image a URL points to, cheapest source first, memoised in
     * `$SIZE_CACHE` per URL string.
     *
     * Sources, in order:
     * 1. File name suffix `-<W>x<H>.<ext>` at the end of the URL (optional `?query`, trailing
     *    whitespace), when `<H>` < 10000 and `SPAI_FILENAME_RESOLUTION_UNSAFE` is NOT defined
     *    (defining it disables this heuristic for sites whose names carry other dimensions).
     *    Result `[W, H, 'thumb' => true]` with W and H as strings.
     * 2. The file on disk: {@see url_to_path_to_sizes()} (a `getimagesize()` array, or `[w, h]` for SVG).
     * 3. When that fails and option `settings_behaviour_sizespostmeta` is on: attachment metadata
     *    via {@see url_to_metadata_to_sizes()}.
     * 4. Fallback `[1, 1]`.
     *
     * Output grammar: `[W, H]` | `[W, H, 'thumb' => true]` | `getimagesize()` array (indexes 0..3,
     * `bits`, `mime`, ...). Index 0/1 are always set. `'thumb'` set means "size from file name".
     *
     * Example: `get_image_size('https://example.test/wp-content/uploads/a-300x200.jpg')` gives
     * `['300', '200', 'thumb' => true]` without touching the disk.
     *
     * @param string $url Image URL (absolute or relative).
     * @return array
     */
    public static function get_image_size($url) {
        if(isset(self::$SIZE_CACHE[$url])) {
            return self::$SIZE_CACHE[$url];
        }
        if(defined('SPAI_FILENAME_RESOLUTION_UNSAFE')) { // cases like painting-cm-80x110.jpg - bragetto.it - 80x110 are the original painting size in cm :)
            $matches = array();
        } else {
            //changed to avoid being fooled by filenames like Poster-42x59.4-сm-1.jpeg
            preg_match("/-([0-9]+)x([0-9]+)\.[a-zA-Z0-9]+(?:|\?.*)\s*$/", $url, $matches); //the filename suffix way
        }
        if(isset($matches[1]) && isset($matches[2]) && $matches[2] < 10000) { //encountered cases when the height was set to 99999 in the name, rely on actual image sizes in that case
            //ShortPixelAILogger::instance()->log("Sizes from filename: {$matches[1]} x {$matches[2]}");
            $sizes = array($matches[1], $matches[2], 'thumb' => true); //true means that it was from the name - it's a thumbnail
        } elseif(!($sizes = self::url_to_path_to_sizes($url)) && Options::_()->settings_behaviour_sizespostmeta) { //the file way
            //The postmeta way, as a last resort and only if the option is active
            $sizes = self::url_to_metadata_to_sizes($url);//the DB way
            ShortPixelAILogger::instance()->log("Sizes from DB: {$sizes[0]} x {$sizes[1]}");
        }
        if(!isset($sizes[0])) {
            $sizes = [1,1];
        }
        self::$SIZE_CACHE[$url] = $sizes;
        return $sizes;
    }

    /**
     * Maps an image URL to a local file and returns its size.
     *
     * The query is dropped and relative URLs are made absolute. Then, until a file exists
     * ({@see spai_safe_file_exists()}, which honours `open_basedir`):
     * 1. uploads base URL replaced by uploads base dir (`wp_upload_dir()`, scheme-insensitive);
     * 2. the parent of both (normally `wp-content`);
     * 3. their grandparent mapped to the site root (`get_home_path()` when loaded, else `ABSPATH`);
     * 3bis. multisite: the URL part after the last two segments of the uploads base dir
     *    (`wp-content/uploads`, or `sites/N` on multisite) appended to the base dir;
     * 4./5. root-relative fallbacks (unreachable, see todo);
     * then the path is normalised, tried raw and urldecoded, and finally
     * `ABSPATH/wp-content/uploads/<grandparent>/<parent>/<file>` built from the last three URL
     * segments.
     *
     * @todo url_to_path_to_sizes() guards the TAKE4/TAKE5 fallbacks with
     *       `preg_match('/https?:\/\//', $image_url) === false`, which is true only on a PCRE error,
     *       so those branches never run. Use `!preg_match(...)`.
     *
     * @param string $image_url Image URL.
     * @return array|false `getimagesize()` array, `[w, h]` for SVG, `[1, 1]` when the file is
     *                     outside open_basedir, or false when no file is found or unreadable.
     */
    public static function url_to_path_to_sizes($image_url) {
        //get rid of query if present
        $image_url = explode('?', $image_url)[0];
        $path = '';
        $updir = wp_upload_dir();
        $baseUrl = parse_url($updir['baseurl']);
        if(!isset($baseUrl['host'])) {
            $updir['baseurl'] = home_url() . $updir['baseurl'];
        }
        $urlParsed = parse_url($image_url);
        if(!isset($urlParsed['host'])) {
            $image_url = self::absoluteUrl($image_url);
        }
        $baseUrlPattern = "/" . str_replace("/", "\/", preg_replace("/^http[s]{0,1}:\/\//", "^http[s]{0,1}://", $updir['baseurl'])) . "/";

        SHORTPIXEL_AI_DEBUG && ShortPixelAILogger::instance()->log("MATCH? $image_url PATTERN: $baseUrlPattern UPDIR: " . json_encode($updir), FILE_APPEND);

        if(preg_match($baseUrlPattern, $image_url)) {
            $path = preg_replace($baseUrlPattern, $updir['basedir'], $image_url);
            SHORTPIXEL_AI_DEBUG && ShortPixelAILogger::instance()->log("TAKE1: $path");
        }
        if(!self::spai_safe_file_exists($path)) { //search in the wp-content directory too
            $baseUrlPattern = "/" . str_replace("/", "\/", preg_replace("/^http[s]{0,1}:\/\//", "^http[s]{0,1}://", dirname($updir['baseurl']))) . "/";
            SHORTPIXEL_AI_DEBUG && ShortPixelAILogger::instance()->log("BASE URL PATTERN2: $baseUrlPattern");
            if(preg_match($baseUrlPattern, $image_url)) {
                $path = preg_replace($baseUrlPattern, dirname($updir['basedir']), $image_url);
                ShortPixelAILogger::instance()->log("TAKE2: $path");
            }
            if(!self::spai_safe_file_exists($path)) {
                //that's the root folder, it will match the ABSPATH
                $absPath = untrailingslashit(function_exists('get_home_path') ? get_home_path() : ABSPATH);
                $baseUrlPattern = "/" . str_replace("/", "\/", preg_replace("/^http[s]{0,1}:\/\//", "^http[s]{0,1}://", dirname(dirname($updir['baseurl'])))) . "/";
                if(preg_match($baseUrlPattern, $image_url)) {
                    $path = preg_replace($baseUrlPattern, $absPath, $image_url);
                    SHORTPIXEL_AI_DEBUG && ShortPixelAILogger::instance()->log("TAKE3: $path");
                }
                //cases for multisite when the URL contains an extra element but the local path doesn't (eg food here: path: https://stagegie.wpengine.com/food/wp-content/uploads/sites/5/2020/01/Healthy-Chicken-Sheet-Pan-Fajitas-Get-Inspired-Everyday-5.jpg)
                if(!self::spai_safe_file_exists($path)) {
                    $baseDir = explode(DIRECTORY_SEPARATOR, $updir['basedir']);
                    $contentUploads = implode('/', array_slice($baseDir, -2, 2));
                    $contentSubdir = explode($contentUploads, $image_url);
                    if(isset($contentSubdir[1])) {
                        $path = $updir['basedir'] . $contentSubdir[1];
                        SHORTPIXEL_AI_DEBUG && ShortPixelAILogger::instance()->log("TAKE3BIS: $path");
                        if(!self::spai_safe_file_exists($path) && preg_match('/https?:\/\//', $image_url) === false) {
                            $path = $absPath . ($image_url[0] == '/' ? '' : '/') . $image_url;
                            SHORTPIXEL_AI_DEBUG && ShortPixelAILogger::instance()->log("TAKE4: $path");
                            if(!self::spai_safe_file_exists($path)) {
                                //for cases when the WP directory is a subdir of the root
                                $path = dirname($absPath) . ($image_url[0] == '/' ? '' : '/') . $image_url;
                                SHORTPIXEL_AI_DEBUG && ShortPixelAILogger::instance()->log("TAKE5: $path");
                            }
                        }
                    }
                }
            }
        }

        SHORTPIXEL_AI_DEBUG && ShortPixelAILogger::instance()->log("URL TO PATH TO SIZES, checking: " . $path . ' EXISTS? ' . (self::spai_safe_file_exists($path) ? 'YEE, sizes: ' . json_encode(getimagesize($path)) : 'Nope. UPLOAD url:' . $updir['baseurl'] . ' BaseUrlPattern:' . $baseUrlPattern));

        $abs = self::spai_normalize_fs_path($path);
        if ($abs && self::spai_safe_file_exists($abs)) {
            return self::getimagesizeOrSvg($abs);
        }
        $abs2 = $abs ? urldecode($abs) : self::spai_normalize_fs_path(urldecode($path));
        if ($abs2 && self::spai_safe_file_exists($abs2)) {
            return self::getimagesizeOrSvg($abs2);
        } else {
            //LOG open_basedir warnings
            SHORTPIXEL_AI_DEBUG && !self::check_open_basedir($path) && ShortPixelAILogger::instance()->log('URL TO PATH TO SIZES: OPEN_BASEDIR (' . ini_get('open_basedir') . ') restriction in effect!');

            //try the default location for sites with a wrong baseurl, where the replace above did not work
            $path = trailingslashit(ABSPATH) . 'wp-content/uploads/' . wp_basename(dirname(dirname($image_url))) . '/' . wp_basename(dirname($image_url)) . '/' . wp_basename($image_url);
            if(self::spai_safe_file_exists($path)) {
                return self::getimagesizeOrSvg($path);
            } elseif (self::spai_safe_file_exists(urldecode($path))) {
                return self::getimagesizeOrSvg(urldecode($path));
            }
        }
        return false;
    }

    /**
     * Size of a local file: for `.svg`, the width/height of the `viewBox` (4 non-negative numbers),
     * else the `width`/`height` attributes of `<svg>` (unitless or px), both rounded; else
     * `getimagesize()`.
     *
     * @param string $path File-system path (normalised with {@see spai_normalize_fs_path()}).
     * @return array|false `[w, h]` (floats from round()) for SVG, `[1, 1]` for an unparsable SVG or a
     *                     path refused by normalisation/open_basedir, else the `getimagesize()`
     *                     result (false for non-images).
     */
    static function getimagesizeOrSvg($path) {
        $abs = ShortPixelUrlTools::spai_normalize_fs_path($path);
        if (!$abs || !ShortPixelUrlTools::check_open_basedir($abs)) {
            return [1,1];
        }
        $path = $abs;

        if(strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'svg') {
            $svg = file_get_contents($path);
            preg_match('/viewBox=[\'"]\s*\d+\.?\d*\s+\d+\.?\d*\s+(\d+\.?\d*)\s+(\d+\.?\d*)\s*[\'"]/s', $svg, $matches);
            if(count($matches) == 3) {
                return [round(doubleval($matches[1])), round(doubleval($matches[2]))];
            } else {
                //try width and height
                preg_match('/<svg[^>]*\bwidth=[\'"]\s*(\d+\.?\d*)\s*(?:px|)\s*[\'"]/s', $svg, $matchesWidth);
                preg_match('/<svg[^>]*\bheight=[\'"]\s*(\d+\.?\d*)\s*(?:px|)\s*[\'"]/s', $svg, $matchesHeight);
                if(count($matchesWidth) == 2 && count($matchesHeight) == 2) {
                    return [round(doubleval($matchesWidth[1])), round(doubleval($matchesHeight[1]))];
                }
            }
            return [1,1];
        } else {
            $sz = getimagesize($path);
            if(!is_array($sz)) {
                //ShortPixelAILogger::instance()->logAnyway("getimagesizeOrSvg: getimagesize($path) returned " . var_export($sz, true));
            }
            return $sz;
        }
    }

    /**
     * Returns the URL of the full-size original of a WordPress thumbnail URL (used by LQIP to
     * share placeholders between sizes).
     *
     * The suffix `-<W>x<H>[_c|_tl|_tr|_bl|_br]` before the extension is stripped. With option
     * `settings_behaviour_sizespostmeta` on, the first of (stripped URL, absolute stripped URL,
     * absolute original URL) that `attachment_url_to_postid()` resolves is returned, else `$url`;
     * with it off, `absoluteUrl(<stripped>)` is returned.
     *
     * Example (postmeta off): `/wp-content/uploads/a-300x200.jpg` gives `https://example.test/wp-content/uploads/a.jpg`.
     *
     * @todo get_full_size_image_url() leaves `$no_thumb_url` undefined when
     *       SPAI_FILENAME_RESOLUTION_UNSAFE is defined (warning, then a lookup of null). Initialise
     *       it to `$url` before the `if`.
     *
     * @param string $url Image URL.
     * @return string The parent URL.
     */
    static function get_full_size_image_url($url) {


        if(!defined('SPAI_FILENAME_RESOLUTION_UNSAFE')) {
            //TODO UPDATE ::THUMBNAIL_REGEX and use here too
            $no_thumb_url = preg_replace('/-\d+x\d+(?:_c|_tl|_tr|_bl|_br|)(\.\w+)$/', '$1', $url);
        }
        if(Options::_()->settings_behaviour_sizespostmeta) {
            $parent_url = $url;
            if(attachment_url_to_postid($no_thumb_url)) {
                $parent_url = $no_thumb_url;
            }
            elseif (attachment_url_to_postid($absolute_url = ShortPixelUrlTools::absoluteUrl($no_thumb_url))) {
                $parent_url = $absolute_url;
            }
            elseif ($url != $no_thumb_url && attachment_url_to_postid($absolute_url = ShortPixelUrlTools::absoluteUrl($url))) {
                $parent_url = $absolute_url;
            }
        }
        else {
            $parent_url = ShortPixelUrlTools::absoluteUrl($no_thumb_url);
        }

        return $parent_url;

    }

    /**
     * Rounds a resolution up to a geometric breakpoint `ceil(base * multiplier^k)`.
     *
     * Returns `$base` for resolutions up to `$base`; `ceil($resolution)` when it does not exceed
     * the breakpoint of `k = floor(log(resolution / base, multiplier))`; else the next breakpoint.
     * No caller in the plugin.
     *
     * Example: `get_size_breakpoint(40)` gives 50; `get_size_breakpoint(56)` gives 56.0 (56 does not exceed
     * its breakpoint, so `ceil()` of the resolution is returned).
     *
     * @todo get_size_breakpoint() has no caller; remove it.
     *
     * @param int|float $resolution
     * @param int       $base
     * @param float     $multiplier
     * @return float|int
     */
    static function get_size_breakpoint($resolution, $base = 50, $multiplier = 1.1 ) {
        if($resolution <= $base) return $base;
        $exponent = floor(log(doubleval($resolution) / $base, $multiplier));
        if($resolution <= ceil($base * pow($multiplier, $exponent))) return ceil($resolution);
        return ceil($base * pow($multiplier, $exponent + 1));
    }

    /**
     * True when `open_basedir` is unset or `$path` starts with one of its entries.
     *
     * Entries are split on `:` and `;` (so Windows drive letters such as `C:\` split wrongly) and
     * compared as plain prefixes (`/var/www` also allows `/var/www2`).
     *
     * @param string $path File-system path.
     * @return bool
     */
    static function check_open_basedir($path) {
        if(strlen(ini_get('open_basedir'))) {
            $basedirs = preg_split('/[:;]/', ini_get('open_basedir'));
            $allowed = false;
            foreach($basedirs as $basedir) {
                if (strpos($path, $basedir) === 0) {
                    $allowed = true;
                    break;
                }
            }
            if(!$allowed) {
                return false;
            }
        }
        return true;
    }

    /**
     * Converts a path to an absolute path under the WordPress root (`get_home_path()` when
     * loaded, else `ABSPATH`, without trailing slash, `wp_normalize_path()`-ed).
     *
     * Returns false for empty input or anything with a URL scheme (`x://`). A path already under
     * the root is returned as is; any other path, absolute or relative, is appended to the root
     * (so `/tmp/a.jpg` becomes `<root>/tmp/a.jpg`: lookups never leave the WP root).
     *
     * @param string $candidate Path.
     * @return false|string
     */
    private static function spai_normalize_fs_path($candidate) {
        if (!$candidate || preg_match('#^[a-z][a-z0-9+.\-]*://#i', $candidate)) return false; // e URL, nu cale
        $root = wp_normalize_path( untrailingslashit( function_exists('get_home_path') ? get_home_path() : ABSPATH ) );
        $p    = wp_normalize_path($candidate);

        if ($p === $root || strpos($p, $root . '/') === 0) { // BUg :)) heere i checked if it's already under root, before i didn't and gave double root path
            return $p;
        }

        // if starts with "/" ( /wp-content/...), prefix with ABSPATH
        if (isset($p[0]) && $p[0] === '/') {
            return $root . $p;
        }
        return $root . '/' . ltrim($p, '/');
    }

    /**
     * `file_exists()` (warnings suppressed) on the normalised path, false when normalisation
     * fails or `open_basedir` forbids the path.
     *
     * @param string $candidate Path.
     * @return bool
     */
    private static function spai_safe_file_exists($candidate): bool {
        $abs = self::spai_normalize_fs_path($candidate);
        if (!$abs) return false;
        if (!self::check_open_basedir($abs)) return false;
        return @file_exists($abs);
    }

    /**
     * Returns the raw (serialized) `_wp_attachment_metadata` of the attachment whose URL is
     * `absoluteUrl($image_url)`, resolved with `attachment_url_to_postid()` (not by GUID, despite
     * the name).
     *
     * @param string $image_url Attachment URL.
     * @param bool   $fuzzy     Unused.
     * @return false|string|null The serialized metadata, null when the row is missing, false when no attachment matches.
     */
    static function get_from_meta_by_guid($image_url, $fuzzy = false) {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $url = ShortPixelUrlTools::absoluteUrl($image_url);
        $postId = attachment_url_to_postid($url);

        //ShortPixelAILogger::instance()->log("GET Post FROM META: $sqlPosts $condition");

        $meta = false;
        if(!empty($postId)) {
            $sqlMeta = "SELECT meta_value FROM {$prefix}postmeta WHERE meta_key = '_wp_attachment_metadata' AND post_id =";
            $meta = $wpdb->get_var($wpdb->prepare("$sqlMeta %d;", $postId ));

            //ShortPixelAILogger::instance()->log("GET FROM META: $sqlMeta $postId");
        }
        return $meta;
    }
    /**
     * Size of an image from its attachment metadata (database), used by {@see get_image_size()}
     * when option `settings_behaviour_sizespostmeta` is on and the file is not found on disk.
     *
     * The `-<W>x<H>` suffix (and query/fragment) of jpg/jpeg/png/gif URLs is stripped to find the
     * parent attachment. Lookups, until one returns metadata: stripped URL; for `http://` URLs the
     * `https://` variant; the last three path segments; the original URL. From the metadata:
     * `[width, height]` when `file` is a suffix of the original URL, else the entry of `sizes` whose
     * `file` equals the original basename.
     *
     * @todo url_to_metadata_to_sizes() only tries the other protocol for `http://` URLs (the inner
     *       `https` branch is unreachable), so `https://` URLs stored as `http://` are missed. Test
     *       both prefixes.
     *
     * @param string $image_url Image URL.
     * @return array `[width, height]`, or `[1, 1]` when nothing matches.
     */
    public static function url_to_metadata_to_sizes ( $image_url ) {
        //TODO be smart. If a certain url's domain is not found in the metadata, doesn't make sense to search for the other URLs on the same domain
        // Thx to https://github.com/kylereicks/picturefill.js.wp/blob/master/inc/class-model-picturefill-wp.php

        $original_image_url = $image_url;
        //TODO merge with the regex in get_full_size_image_url
        $image_url = preg_replace('/^(.+?)(-\d+x\d+)?\.(jpg|jpeg?|png|gif)((?:\?|#).+)?$/i', '$1.$3', $image_url);

        $meta = self::get_from_meta_by_guid($image_url);

        //previous joined query - slower in some cases?
        //$sql = "SELECT m.meta_value FROM {$prefix}posts p INNER JOIN {$prefix}postmeta m on p.id = m.post_id WHERE m.meta_key = '_wp_attachment_metadata' AND ";
        //$meta = $wpdb->get_var($wpdb->prepare("$sql p.guid='%s';", $image_url ));

        //try the other proto (https - http) if full urls are used
        if ( empty($meta) && strpos($image_url, 'http://') === 0 ) {
            $image_url_other_proto =  strpos($image_url, 'https') === 0 ?
                str_replace('https://', 'http://', $image_url) :
                str_replace('http://', 'https://', $image_url);
            $meta = self::get_from_meta_by_guid($image_url_other_proto);
        }

        //try using only path
        if (empty($meta) ) {
            $image_path = parse_url($image_url, PHP_URL_PATH); //some sites have different domains in posts guid (site changes, etc.)
            //keep only the last two elements of the path because some CDN's add path elements in front ( Google Cloud adds the project name, etc. )
            $image_path_elements = explode('/', $image_path);
            $image_path_elements = array_slice($image_path_elements, max(0, count($image_path_elements) - 3));
            $meta = self::get_from_meta_by_guid(implode ('/', $image_path_elements), true);
            //$meta = $wpdb->get_var($wpdb->prepare("$sql p.guid like'%%%s';", implode('/', $image_path_elements) ));
        }

        //try using the initial URL
        if ( empty($meta) ) {
            $meta = self::get_from_meta_by_guid($original_image_url);
            //$meta = $wpdb->get_var($wpdb->prepare("$sql p.guid='%s';", $original_image_url ));
        }

        if(!empty($meta)) { //get the sizes from meta
            $meta = unserialize($meta);
            if(strlen(@$meta['file']) && preg_match("/".preg_quote($meta['file'], '/') . "$/", $original_image_url)) {
                return array($meta['width'], $meta['height']);
            }
            if(is_array(@$meta['sizes'])) {
                foreach($meta['sizes'] as $size) {
                    if($size['file'] == wp_basename($original_image_url)) {
                        return array($size['width'], $size['height']);
                    }
                }
            }
        }
        return array(1, 1);
    }

    /**
     * True when the trimmed URL ends in `.<extension>` (case-sensitive; a query string defeats it).
     * With an array, true when any extension matches.
     *
     * Example: `is('a.woff2', ShortPixelUrlTools::$ONLY_STORE)` is true; `is('a.SVG', 'svg')` is false.
     *
     * @param string          $url
     * @param string|string[] $extension Extension(s) without the dot.
     * @return bool
     */
    public static function is($url, $extension) {
        if(is_array($extension)) {
            foreach($extension as $ext) {
                if(self::is($url, $ext)) {
                    return true;
                }
            }
            return false;
        } else {
            return substr(trim($url), - strlen($extension) - 1 ) === '.' . $extension;
        }
    }

	/**
	 * Returns the part after the last `/` of a valid URL (the whole URL when it has no `/`),
	 * including any query string; false for empty or invalid URLs ({@see isValid()}).
	 *
	 * Example: `retrieve_name('https://example.test/a/b.jpg')` gives `b.jpg`.
	 *
	 * @todo retrieve_name() calls `mb_strrpos()`, which WordPress does not polyfill, so it fatals
	 *       without the mbstring extension. Use `strrpos()`/`substr()` (byte-safe for `/`).
	 *
	 * @param string $url
	 *
	 * @return bool|string
	 */
	public static function retrieve_name( $url ) {
		$url = trim( $url );

		$slash_pos = mb_strrpos( $url, '/' );

		return empty( $url ) || !self::isValid( $url ) ? false : ( $slash_pos === false ? $url : mb_substr( $url, $slash_pos + 1 ) );
	}

	/**
	 * Builds the lazy-loading placeholder that replaces an image URL server-side.
	 *
	 * LQIP: when option `settings_behaviour_lqip` is on, `LQIP::_()->get($url)` supplies a cached
	 * low-quality SVG template; when there is none and `$url` is not empty, the URL is queued in
	 * `ShortPixelAI::_()->blankInlinePlaceholders[$url] = ['source' => $url, 'url' => absoluteUrl($url), 'referer' => false]`
	 * for LQIP generation (cron or instant, see `ShortPixelAI::maybe_replace_images_src()`).
	 *
	 * Output grammar:
	 * ```
	 * data:image/svg+xml;base64,<PX_SVG_ENCODED>                       width, height and url all empty
	 * data:image/svg+xml;base64,base64(T with %WIDTH%=W, %HEIGHT%=H, %URL%=urlencode(url))
	 *   T = LQIP template if any, else PX_SVG_TEMPLATE
	 *   W, H = width/height (empty one becomes 1) when width > 1 or height > 1, else 1 and 1
	 * ```
	 * `urlencode()` encodes spaces as `+`. The front-end JS reads `data-u` back to build the CDN URL.
	 *
	 * Example (no LQIP): `generate_placeholder_svg(300, 200, 'https://example.test/a.jpg')` gives
	 * `'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 200" width="300" height="200" data-u="https%3A%2F%2Fexample.test%2Fa.jpg" data-w="300" data-h="200" data-bip=""></svg>')`.
	 *
	 * @param bool|int|string $width  Pixel width (strings from file names accepted), or false.
	 * @param bool|int|string $height Pixel height, or false.
	 * @param bool|string     $url    Original image URL, or false.
	 *
	 * @return string The `data:` URI.
	 */
	public static function generate_placeholder_svg( $width = false, $height = false, $url = false ) {
		$defaults = [
			'width'  => 1,
			'height' => 1,
		];

		$data = 'data:image/svg+xml;base64,';
		$lqip = false;
        $logger = ShortPixelAILogger::instance();

		if ( Options::_()->settings_behaviour_lqip ) {
			$lqip = LQIP::_()->get( $url );

			if(!$lqip && !empty($url) /* && LQIP::_()->process_way === LQIP::USE_CRON */) {
			    $bip = ['source' => $url, 'url' => self::absoluteUrl($url), 'referer' => false];
			    SHORTPIXEL_AI_DEBUG && $logger->log('ADD BIP TO BE LQIP\'d: ', $bip);
                ShortPixelAI::_()->blankInlinePlaceholders[$url] = $bip;
            }

			$logger->log( 'LQIP SOURCE: ', var_export( $url, true ) );
			$logger->log( 'LQIP BODY: ', var_export( $lqip, true ) );
		}

		return empty( $width ) && empty( $height ) && empty( $url ) ?
			$data . self::PX_SVG_ENCODED :
			( $width > 1 || $height > 1 ?
				$data . base64_encode( str_replace( [ '%WIDTH%', '%HEIGHT%', '%URL%' ], [ empty( $width ) ? $defaults[ 'width' ] : $width, empty( $height ) ? $defaults[ 'height' ] : $height, empty( $url ) ? '' : urlencode( $url ) ], ( !$lqip ? self::PX_SVG_TEMPLATE : $lqip ) ) ) :
				$data . base64_encode( str_replace( [ '%WIDTH%', '%HEIGHT%', '%URL%' ], [ $defaults[ 'width' ], $defaults[ 'height' ], empty( $url ) ? '' : urlencode( $url ) ], ( !$lqip ? self::PX_SVG_TEMPLATE : $lqip ) ) ) );
	}

    /**
     * Method generates the SVG placeholder using new template
     * OLD IMPLEMENTATION
     * public static function generate_placeholder_svg($width = false, $height = false, $url = false) {
     * $ret = 'data:image/svg+xml;base64,' . self::PX_SVG_ENCODED;
     * if($width && $height && ($width > 1 || $height > 1)) {
     * $ret = self::_generate_placeholder_svg($width, $height, $url);
     * } elseif ($url) { //external images - we don't know the width...
     * $ret = 'data:image/svg+xml' . ';u=' . base64_encode(urlencode($url)) . ';base64,' . self::PX_SVG_ENCODED;
     * }
     * //self::log('GENERATE for ' . $url . ' : ' . $ret);
     * return $ret;
     * }
     *
     * protected static function _generate_placeholder_svg($width, $height, $url) {
     * return 'data:image/svg+xml;u=' . base64_encode(urlencode($url)) . ";w=$width;h=$height;base64,"
     * . base64_encode(str_replace('%WIDTH%', $width, str_replace('%HEIGHT%', $height, self::PX_SVG_TPL)));
     * }*/

    /* svg pair and placeholder_gif are obsolete TODO remove completely
    public static function generate_placeholder_svg_pair($width = false, $height = false, $url = false) {
        $ret = (object)array('image' => 'data:image/svg+xml;base64,' . self::PX_SVG_ENCODED, 'meta' => false);
        if($width && $height && ($width > 1 || $height > 1)) {
            $ret = self::_generate_placeholder_svg_pair($width, $height, $url);
        } elseif ($url) { //external images - we don't know the width...
            $ret = (object)array('image' => 'data:image/svg+xml;base64,' . self::PX_SVG_ENCODED, 'meta' => 'u=' . base64_encode(urlencode($url)));
        }
        //self::log('GENERATE for ' . $url . ' : ' . $ret);
        return $ret;
    }

    protected static function _generate_placeholder_svg_pair($width, $height, $url) {
        //ShortPixelAILogger::instance()->log("GENERATE SVG PAIR sizes: $width $height");
        return (object)array(
            'image' => 'data:image/svg+xml;base64,' . base64_encode(str_replace('%WIDTH%', $width, str_replace('%HEIGHT%', $height, self::PX_SVG_TPL))),
            'meta' => 'u=' . base64_encode(urlencode($url)) . ";w=$width;h=$height");
    }

    public static function generate_placeholder_gif($width = false, $height = false, $url = false) {
        if($width && $height && ($width > 1 || $height > 1)) {
            return self::_generate_placeholder_gif($width, $height, $url);
        } elseif ($url) { //external images - we don't know the width...
            return 'data:image/gif' . ';u=' . base64_encode(urlencode($url)) . ';base64,' . self::PX_ENCODED;
        }
        return 'data:image/gif;base64,' . self::PX_ENCODED;
    }

    protected static function _generate_placeholder_gif($width, $height, $data = '') {
        $pseudoData = strlen($data) ? ";u=" . base64_encode(urlencode($data)) . ";w=$width;h=$height" : '';
        $pxGif = base64_decode(self::PX_ENCODED);
        $pxGif[6] = chr(intval($width) % 256);
        $pxGif[7] = chr(intval($width) / 256);
        $pxGif[8] = chr(intval($height) % 256);
        $pxGif[9] = chr(intval($height) / 256);
        return 'data:image/gif' . $pseudoData . ';base64' . ',' . base64_encode($pxGif);
    }
    */

    /**
     * OLD IMPLEMENTATION
     *
     */
//    public static function url_from_placeholder_svg($url) {
//        $parts = explode(',', $url);
//        if(count($parts) == 2) {
//            $url = $parts[0];
//            $subparts = explode(';', $url);
//            array_shift($subparts);
//            if(strpos($subparts[0], 'u=') == 0) {
//                $url = urldecode(base64_decode(substr($subparts[0], 2)));
//            }
//        }
//        return $url;
//    }

    /**
     * Reads the original URL back out of a placeholder made by {@see generate_placeholder_svg()}.
     * The same algorithm exists on the CDN side and in `ShortPixelCLI`.
     *
     * Steps: the base64 regex `(?:[A-Za-z0-9+]{4})*(?:[A-Za-z0-9+]{2}==|[A-Za-z0-9+]{3}=)?$`
     * (unanchored at the start) takes the longest aligned run of base64 chars at the END of the
     * input; it always matches, possibly empty, so the `count !== 1` guard never fires. The run is
     * decoded and `data-u="..."` is searched in it; the value is `urldecode()`d.
     *
     * Input grammar: `data:image/svg+xml;base64,<b64>` (also any other string, e.g. a plain URL,
     * which yields false). Output: the URL, or false.
     *
     * Example: for `generate_placeholder_svg(300, 200, 'https://example.test/a.jpg')` the result
     * is `https://example.test/a.jpg` only if no `/` occurs in the base64 text from the `data-u`
     * part onwards; otherwise false (see todo).
     *
     * @todo url_from_placeholder_svg() omits `/` from the base64 alphabet, so the run starts after
     *       the last `/` of the payload and placeholders whose base64 has a `/` at or after the
     *       `data-u` attribute decode to false. With `PX_SVG_TEMPLATE` and the urlencode()d URL the
     *       base64 text after `data-u` contains no `/`, so the failure needs an LQIP template or
     *       another payload. Use
     *       `[A-Za-z0-9+\/]` and take everything after `base64,`. The copy in ShortPixelCLI has
     *       the same defect.
     *
     * @param string $encoded Placeholder `data:` URI.
     *
     * @return false|string The original URL, or false.
     */
    public static function url_from_placeholder_svg( $encoded )
    {
        $reg_exes = array(
            'base64' => '/(?:[A-Za-z0-9+]{4})*(?:[A-Za-z0-9+]{2}==|[A-Za-z0-9+]{3}=)?$/',
            'url' => '/data-u="[^"]+/',
        );

        $matches = array();

        preg_match($reg_exes['base64'], $encoded, $matches);

        if (count($matches) !== 1) {
            return false;
        }

        $decoded = base64_decode($matches[0]);
        preg_match($reg_exes['url'], $decoded, $matches);

        if (count($matches) !== 1) {
            return false;
        }

        return urldecode(str_replace('data-u="', '', $matches[0]));
    }

    /**
     * CSP support: attaches the nonce from filter `spai_script_nonce` (default `''`) to a
     * registered script as extra data `nonce` (`wp_scripts()->add_data($name, 'nonce', $nonce)`).
     * Nothing happens when the filter returns an empty value.
     *
     * Grammar: `add_filter('spai_script_nonce', fn() => '<nonce>')` then, for each handle below,
     * `wp_scripts()->get_data(<handle>, 'nonce') === '<nonce>'`. The plugin itself does not print
     * the stored value; inline scripts (`#spai_js`, localized objects) get no nonce.
     *
     * Called for seven handles: `spai-admin-scripts`, `spai-sniper` (ShortPixelAI), `spai-settings`
     * (Page), `spai-notice` (Notice), `spai-lqip` (LQIP), `spai-front-worker` (JsLoader),
     * `spai-snip-action` (VanillaJsLoader). `docs/csp_nonce_documentation.md` lists only three.
     *
     * @param string $name Script handle.
     * @return void
     */
    public static function applyNonce($name)
    {
        $nonce = apply_filters( 'spai_script_nonce', '' );
        if($nonce) {
            wp_scripts()->add_data( $name, 'nonce',  $nonce);
        }
    }
}
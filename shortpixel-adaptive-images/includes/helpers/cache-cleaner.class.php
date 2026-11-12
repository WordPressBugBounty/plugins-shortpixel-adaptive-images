<?php
/**
 * CacheCleaner: page-cache purge and per-page cache exclusion for third-party cache plugins.
 */

namespace ShortPixel\AI;

/**
 * Singleton that purges third-party page caches and asks them not to cache the current page.
 *
 * clear() is called after selector-list changes in the settings AJAX handlers (whole
 * cache) and by `LQIP` after generating placeholders (only the pages that used them,
 * without wp_cache_flush()). excludeCurrentPage() is called from
 * `ShortPixelAI::maybe_replace_images_src()` when the page still has blank LQIP
 * placeholders.
 *
 * Static state a test must reset: `CacheCleaner::$instance` (set back to false); the
 * cached `$LOGGER_ON` value depends on `SHORTPIXEL_AI_DEBUG` at construction.
 * excludeCurrentPage() also defines the constants `DONOTCACHEPAGE` and `LSCACHE_NO_CACHE`,
 * which cannot be undone, so tests of it belong in an isolated process.
 */
class CacheCleaner
{
    /**
     * The singleton, or false before the first call to _().
     *
     * @var CacheCleaner|false
     */
    private static $instance = false;
    /**
     * @var \ShortPixelAILogger
     */
    private $logger;
    /**
     * Non-zero when `SHORTPIXEL_AI_DEBUG` has the `DEBUG_AREA_CACHE` bit (8).
     *
     * @var int
     */
    private $LOGGER_ON;

    /**
     * Returns the singleton.
     *
     * @return CacheCleaner
     */
    public static function _()
    {
        if (self::$instance === false) {
            self::$instance = new self;
        }
        return self::$instance;
    }

    /**
     * Grabs the logger and caches whether cache-area logging is on.
     */
    protected function __construct()
    {
        $this->logger = \ShortPixelAILogger::instance();
        $this->LOGGER_ON = (SHORTPIXEL_AI_DEBUG & \ShortPixelAILogger::DEBUG_AREA_CACHE);
    }

    /**
     * Purges the page caches of every detected cache plugin and appends a hint to the message.
     *
     * With `$wpFlush` it first calls wp_cache_flush(). A single URL string is wrapped in an
     * array. Then, for each plugin present (detected by class or function, not by
     * `ActiveIntegrations`), with URLs / without URLs:
     * - Comet Cache (class `comet_cache`): `comet_cache::clearUrl( $url )` / `comet_cache::clear()`,
     *   inside a try/catch.
     * - WP Rocket (function `rocket_clean_domain`): `rocket_clean_files( $urls )` / `rocket_clean_domain()`.
     * - W3 Total Cache (function `w3tc_flush_all`): `w3tc_flush_url( $url )` / `w3tc_flush_all()`.
     * - Swift Performance (class `Swift_Performance_Cache`): `clear_permalink_cache( $url )` / `clear_all_cache()`.
     * - Cache Enabler (class `Cache_Enabler`): `clear_page_cache_by_url( $url )` / `clear_complete_cache()`.
     * - WP-Optimize (class `WPO_Page_Cache`): `WPO_Page_Cache::delete_cache_by_url( $url )` /
     *   `WP_Optimize()->get_page_cache()->purge()`.
     * - LiteSpeed (class `LiteSpeed\Purge`): `( new Purge() )->purge_url( $url )` / `Purge::purge_all()`.
     * - WP Super Cache (function `wpsc_delete_url_cache`): `wpsc_delete_url_cache( $url )` /
     *   `wp_cache_clear_cache()`. Does not mark the cache as cleared.
     * - Breeze (class `Breeze_PurgeCache`): `purge_post_on_update( url_to_postid( $url ) )` for
     *   URLs that map to a post / `breeze_cache_flush()`.
     * - WP Fastest Cache: no purge call.
     * Each of the first seven marks the cache as cleared.
     *
     * Filesystem fallback, only when nothing was marked cleared and URLs were given: for each
     * directory under `WP_CONTENT_DIR/cache/` and each URL whose host is the site host
     * (isAllowedCacheClearUrl()) and whose path has no `..`, deletes the `.html`/`.htm` files in
     * `<cache dir>/<url path>` (deleteHtmlFiles()), stopping at the first deletion.
     *
     * @param string               $message Message to return, typically the AJAX result message.
     * @param string|string[]|false $urls   URLs to purge, or false to purge everything.
     * @param bool                 $wpFlush True to call wp_cache_flush() first.
     *
     * @return string `$message` followed by " Please press OK to refresh the page." when a
     *                cache was cleared, otherwise by a newline and a request to clear all
     *                page cache levels manually.
     *
     * @todo clear() catches `Throwable` and `Exception` unqualified inside the `ShortPixel\AI`
     *       namespace, so they resolve to `ShortPixel\AI\Throwable` / `ShortPixel\AI\Exception`
     *       and nothing thrown by Comet Cache is caught. Use `\Throwable`. The second
     *       `catch(Exception)` is a PHP 5.6 fallback for runtimes without `Throwable`; it is
     *       dead now that the plugin floor is PHP 7.4 and can be removed.
     * @todo clear() passes `$cache_cleared` to the Breeze closure by value (`use($cache_cleared)`),
     *       so purging Breeze by URL never marks the cache as cleared and the filesystem fallback
     *       still runs. Use `use(&$cache_cleared)` or a foreach loop.
     * @todo clear() never marks the cache as cleared after the WP Super Cache purge, so the
     *       returned message asks for a manual purge and the filesystem fallback still runs.
     *       Set `$cache_cleared = true` in that branch.
     */
    public function clear($message, $urls = false, $wpFlush = true) {
        $result = $message;
        $cache_cleared = false;

        $LOGGER_ON = $this->LOGGER_ON;
        $LOGGER_ON && $this->logger->log('CLEARING CACHE - urls: ', $urls);

        if($wpFlush) {
            wp_cache_flush();
        }

        if($urls && !is_array($urls)) {
            $urls = [$urls];
        }

        // Comet Cache
        try {
            if(class_exists('\comet_cache')) {
                if($urls) {
                    array_map(function ($item) {
                        \comet_cache::clearUrl($item);
                    }, $urls);
                } else {
                    \comet_cache::clear();
                }
                $LOGGER_ON && $this->logger->log('Comet cache cleared ' . json_encode($urls));
                $cache_cleared = true;
            }
        } catch(Throwable $t) {} catch(Exception $e) {}

        //WPRocket
        if(function_exists('rocket_clean_domain')) {
            $urls ? rocket_clean_files($urls) : rocket_clean_domain();
            $LOGGER_ON && $this->logger->log('WP Rocket cache cleared ' . json_encode($urls));
            $cache_cleared = true;
        }

        //W3 Total Cache
        if(function_exists('w3tc_flush_all')) {
            if($urls) {
                array_map(function ($item) {
                    w3tc_flush_url($item);
                }, $urls);
            } else {
                w3tc_flush_all();
            }
            $LOGGER_ON && $this->logger->log('W3TC cache cleared ' . json_encode($urls));
            $cache_cleared = true;
        }

        //Swift Performance cache
        if(class_exists('\Swift_Performance_Cache')) {
            if($urls) {
                array_map(function ($item) {
                    \Swift_Performance_Cache::clear_permalink_cache($item);
                }, $urls);
            } else {
                \Swift_Performance_Cache::clear_all_cache();
            }
            $LOGGER_ON && $this->logger->log('Swift Perf. cache cleared ' . json_encode($urls));
            $cache_cleared = true;
        }

        //Cache Enabler
        if(class_exists('\Cache_Enabler')) {
            if($urls) {
                array_map(function ($item) {
                    \Cache_Enabler::clear_page_cache_by_url($item);
                }, $urls);
            } else {
                \Cache_Enabler::clear_complete_cache();
            }
            $LOGGER_ON && $this->logger->log('Cache Enabler cache cleared ' . json_encode($urls));
            $cache_cleared = true;
        }

        //WP Optimize
        if(class_exists('\WPO_Page_Cache')) {
            if($urls) {
                array_map(function ($item) {
                    \WPO_Page_Cache::delete_cache_by_url($item);
                }, $urls);
            } else {
                \WP_Optimize()->get_page_cache()->purge();
            }
            $LOGGER_ON && $this->logger->log('WPOptimize cache cleared ' . json_encode($urls));
            $cache_cleared = true;
        }

        //LiteSpeed cache
        if(class_exists('\LiteSpeed\Purge')) {
            if($urls) {
                array_map(function ($item) {
                    (new \LiteSpeed\Purge())->purge_url($item);
                }, $urls);
            } else {
                \LiteSpeed\Purge::purge_all();
            }
            $LOGGER_ON && $this->logger->log('LiteSpeed cache cleared ' . json_encode($urls));
            $cache_cleared = true;
        }

        //WP Super Cache
        if(function_exists('wpsc_delete_url_cache')) {
            if($urls) {
                array_map(function ($item) {
                    wpsc_delete_url_cache($item);
                }, $urls);
            } else {
                wp_cache_clear_cache();
            }
        }

        //Breeze cache
        if(class_exists('Breeze_PurgeCache')) {
            if($urls) {
                array_map(function ($item) use($cache_cleared) {
                    $postId = url_to_postid($item);
                    if($postId) {
                        \Breeze_PurgeCache::purge_post_on_update($postId);
                        $cache_cleared = true;
                    }
                }, $urls);
            } else {
                \Breeze_PurgeCache::breeze_cache_flush();
                $cache_cleared = true;
            }
        }

        //WP Fastest Cache

        //Generic cache, search for cache folders in wp-content/cache - only if we have a list of URLs otherwise we could delete too many things...
        // URLs and paths are validated to block traversal and off-site referer abuse
        if(!$cache_cleared && $urls) {
            $cache_parent = WP_CONTENT_DIR . '/cache/';
            $caches = @scandir($cache_parent);
            $LOGGER_ON && $this->logger->log('Generic caches: ' . json_encode($caches));
            if($caches) foreach($caches as $cache) {
                if ($cache == '.' || $cache == '..') continue;
                if(is_dir($cache_parent . $cache)) {
                    foreach($urls as $url) {
                        if(!$this->isAllowedCacheClearUrl($url)) {
                            $LOGGER_ON && $this->logger->log('Skipping disallowed cache URL: ' . $url);
                            continue;
                        }

                        $path = wp_parse_url($url, PHP_URL_PATH);
                        if(!is_string($path) || $path === '' || strpos($path, '..') !== false) {
                            continue;
                        }

                        $relative_path = ltrim(wp_normalize_path($path), '/');
                        $cache_dir = $cache_parent . $cache . DIRECTORY_SEPARATOR . $relative_path;
                        if(($cache_cleared = $this->deleteHtmlFiles($cache_dir))) {
                            break 2;
                        }
                    }
                }
            }
        }

        if($cache_cleared) {
            $result .= ' ' . __( 'Please press OK to refresh the page.', 'shortpixel-adaptive-images' );
        }
        else {
            $result .= "\n" . __( 'Please clear all page cache levels from the WP admin and CDN (if you have one), then press REFRESH to reload the page.', 'shortpixel-adaptive-images' );
        }
        return $result;
    }

    /**
     * Delete .html/.htm files from a cache directory after path validation
     *
     * @param string $cache_path Candidate directory under wp-content/cache
     * @return int Number of deleted files
     */
    protected function deleteHtmlFiles($cache_path) {
        // Never unlink before resolveCacheDirectory confirms the target stays inside wp-content/cache
        $safe_path = $this->resolveCacheDirectory($cache_path);
        if($safe_path === false) {
            $this->LOGGER_ON && $this->logger->log('Rejected unsafe cache path: ' . $cache_path);
            return 0;
        }

        $counter = 0;
        $cached_pages = @scandir($safe_path);
        $this->LOGGER_ON && $this->logger->log('PATH to clear cache: ' . $safe_path . ' contains: ' . json_encode($cached_pages));
        if($cached_pages) foreach ($cached_pages as $cp) {
            if ($cp == '.' || $cp == '..') continue;
            if(preg_match('/\.html?$/', $cp)) {
                $counter += @unlink(trailingslashit($safe_path) . $cp );
            }
        }
        return $counter;
    }

    /**
     * Resolve and validate a cache directory path before any filesystem delete
     *
     * Rejects traversal sequences, paths outside wp-content/cache, and non-directories
     *
     * @param string $cache_path Raw path built from a page URL
     * @return string|false Real path when safe, false otherwise
     */
    protected function resolveCacheDirectory($cache_path) {
        if(!is_string($cache_path) || $cache_path === '' || strpos($cache_path, "\0") !== false) {
            return false;
        }

        $cache_root = realpath(WP_CONTENT_DIR . '/cache');
        if($cache_root === false || !is_dir($cache_root)) {
            return false;
        }

        $normalized = wp_normalize_path($cache_path);
        if(preg_match('#(^|/)\.\.(/|$)#', $normalized)) {
            return false;
        }

        $root_prefix = wp_normalize_path(WP_CONTENT_DIR . '/cache');
        if(strpos($normalized, $root_prefix) !== 0) {
            return false;
        }

        $real = realpath($normalized);
        if($real === false || !is_dir($real)) {
            return false;
        }

        $real = wp_normalize_path($real);
        if(strpos($real, wp_normalize_path($cache_root)) !== 0) {
            return false;
        }

        return $real;
    }

    /**
     * Allow cache clearing only for URLs that belong to the current site host
     *
     * @param string $url Page URL used to locate cached HTML files
     * @return bool
     */
    protected function isAllowedCacheClearUrl($url) {
        if(!is_string($url) || $url === '') {
            return false;
        }

        $host = wp_parse_url($url, PHP_URL_HOST);
        $site_host = \ShortPixelDomainTools::get_site_domain();

        return $host && $site_host && strcasecmp($host, $site_host) === 0;
    }

    /**
     * Asks every supported cache plugin not to cache the page being rendered.
     *
     * Current URL: `home_url( add_query_arg( array(), $wp->request ) )`. Calls made:
     * - WP Rocket: filter `rocket_cache_reject_uri` returning `[ $currentUrl ]`.
     * - W3 Total Cache, WP-Optimize, WP Super Cache: defines `DONOTCACHEPAGE` (if not defined).
     * - Swift Performance: filter `swift_performance_is_cacheable` returning false.
     * - Cache Enabler: filter `bypass_cache` returning false.
     * - LiteSpeed: defines `LSCACHE_NO_CACHE` true.
     * - WP Fastest Cache: `wpfc_exclude_current_page()` when the function exists.
     *
     * @return void
     *
     * @todo excludeCurrentPage() returns false from the Cache Enabler `bypass_cache` filter,
     *       which means "do not bypass", so Cache Enabler still caches the page. Return true
     *       (and also hook `cache_enabler_bypass_cache`, the filter name current Cache Enabler
     *       versions use).
     * @todo excludeCurrentPage() defines `LSCACHE_NO_CACHE` without a `defined()` guard, so a
     *       second call in the same request, or LiteSpeed having defined it, raises a
     *       "Constant already defined" warning. Guard it like `DONOTCACHEPAGE`.
     * @todo excludeCurrentPage() replaces the whole `rocket_cache_reject_uri` list with an
     *       absolute URL, discarding the rules WP Rocket and other plugins put there, while WP
     *       Rocket expects URI path patterns. Accept the existing array and append the path of
     *       the current URL.
     */
    public function excludeCurrentPage()
    {
        global $wp;
        $currentUrl = home_url( add_query_arg( array(), $wp->request ) );

        //WPRocket
        add_filter( 'rocket_cache_reject_uri', function() use ($currentUrl){
            return [$currentUrl];
        });

        //W3 Total Cache, WP Optimize and WP Super Cache
        if(!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        //Swift Performance cache
        add_filter('swift_performance_is_cacheable', function() {
            return false;
        });

        //Cache Enabler
        add_filter('bypass_cache', function() {
            return false;
        });

        //LiteSpeed cache
        define('LSCACHE_NO_CACHE', true);

        //WPFC
        if(function_exists('wpfc_exclude_current_page')) {
            wpfc_exclude_current_page();
        }
    }
}

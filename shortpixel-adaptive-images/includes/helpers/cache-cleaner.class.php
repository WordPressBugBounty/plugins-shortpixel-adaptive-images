<?php
/**
 * User: simon
 * Date: 20.11.2020
 */

namespace ShortPixel\AI;

class CacheCleaner
{
    private static $instance = false;
    private $logger;
    private $LOGGER_ON;

    /**
     * @param bool $refresh
     * @return CacheCleaner
     */
    public static function _()
    {
        if (self::$instance === false) {
            self::$instance = new self;
        }
        return self::$instance;
    }

    protected function __construct()
    {
        $this->logger = \ShortPixelAILogger::instance();
        $this->LOGGER_ON = (SHORTPIXEL_AI_DEBUG & \ShortPixelAILogger::DEBUG_AREA_CACHE);
    }

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

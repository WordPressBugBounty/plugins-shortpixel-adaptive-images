<?php
/**
 * ActiveIntegrations detector.
 */

namespace ShortPixel\AI;

/**
 * Singleton that detects, once per construction, which third-party plugins, themes and
 * theme modules SPAI has special handling for.
 *
 * Read by `TagRules` (per-integration rules), `ShortPixelAI` (conflicts, lazy/CSS
 * behaviour), `Notice` and the settings view. Static state a test must reset:
 * `ActiveIntegrations::$instance` (set back to false), or call `_( true )`. Detection
 * reads the `active_plugins` option through the `active_plugins` filter, so tests can
 * drive it with `pre_option_active_plugins`.
 *
 * Results are not cached across requests. is_conflict() in `ShortPixelAI` adds further
 * conflict checks (Autoptimize, Divi Toolbox, and others) that are not in this class.
 */
class ActiveIntegrations {
    /**
     * The singleton, or false before the first call to _().
     *
     * @var ActiveIntegrations|false
     */
    private static $instance = false;
    /**
     * Detected integrations: key => bool, or key => array for the cache/optimisation
     * plugins, plus `theme` => theme name. See __construct() for every key.
     *
     * @var array<string,mixed>
     */
    private $integrations = [];

    /**
     * Returns the singleton, building it on the first call or when `$refresh` is true.
     *
     * `TagRules` calls it with true, so building the tag rules always re-detects.
     *
     * @param bool $refresh True to discard the current instance and detect again.
     * @return ActiveIntegrations
     */
    public static function _($refresh = false) {
        if(self::$instance === false || $refresh) {
            \ShortPixelAILogger::instance()->log("INTEGRATIONS - constructing ");
            self::$instance = new ActiveIntegrations();
        }
        return self::$instance;
    }

    /**
     * Detects every integration and fills `$integrations`.
     *
     * Active plugin list: `apply_filters( 'active_plugins', get_option( 'active_plugins' ) )`,
     * merged on multisite with the keys of the `active_sitewide_plugins` site option.
     * "slug" below means `in_array( '<slug>', $activePlugins )`.
     *
     * Array-valued keys:
     * - `wp-rocket` (slug `wp-rocket/wp-rocket.php`; version read from the plugin header
     *   under `SHORTPIXEL_AI_WP_PLUGINS_DIR`, settings from option `wp_rocket_settings`):
     *   `lazyload` (version < 2.5 and `lazyload` == 1), `css-filter` (version >= 3.4.3),
     *   `minify-css` (`minify_css` == 1), `defer-all-js` (version < 3.8, `defer_all_js` set
     *   and `defer_all_js_safe` not set), `video-placeholder` (`lazyload_youtube` == 1).
     *   All false when inactive.
     * - `swift-performance` (slugs `swift-performance-lite/performance.php` and
     *   `swift-performance/performance.php`): the entry of the active edition with
     *   `active`, `path`, `version` (`SWIFT_PERFORMANCE_VER` or plugin header), `has_bug`
     *   (lite <= 2.1.2, pro < 2.1.6.2), `has_conflict` (options `normalize-static-resources`
     *   and `merge-styles` both on, via `Swift_Performance_Lite::get_option` /
     *   `Swift_Performance::get_option` when the class exists), `merge_styles`, `plugin`
     *   (`lite`|`pro`). False when neither is active.
     * - `imagify`: `['active' => slug `imagify/imagify.php`]`, plus `has_conflict` from
     *   `get_imagify_option( 'display_webp' )` when that function exists. Always an array
     *   (never false), so callers test `has( 'imagify', 'has_conflict' )`.
     * - `wp-optimize` (slug `wp-optimize/wp-optimize.php`): `active`, `enable_css` and
     *   `enable_merging_of_css` from `wp_optimize_minify_config()->get()`; merging is true
     *   only when CSS minify and merging are on, an `ignore_list` exists and it does not
     *   contain all four SPAI admin/bar stylesheets.
     *
     * Boolean keys from plugin slugs:
     * `nextgen` (nextgen-gallery/nggallery.php), `modula` (modula-best-grid-gallery/Modula.php),
     * `elementor` (elementor/elementor.php), `elementor-addons` (essential-addons-for-elementor
     * or essential-addons-for-elementor-lite, /essential_adons_elementor.php), `viba-portfolio`
     * (viba-portfolio/viba-portfolio.php), `envira` (envira-gallery/envira-gallery.php or
     * envira-gallery-lite/envira-gallery-lite.php), `everest` (everest-gallery/everest-gallery.php
     * or everest-gallery-lite/everest-gallery-lite.php), `wp-bakery` (js_composer/js_composer.php),
     * `woocommerce` (woocommerce/woocommerce.php), `wpc-variations`
     * (wpc-variations-radio-buttons/wpc-variations-radio-buttons.php), `foo`
     * (foogallery/foogallery.php), `global-gallery` (global-gallery/global-gallery.php),
     * `essential-grid` (essential-gridv1-9/essential-grid.php), `oxygen` (oxygen/functions.php),
     * `slider-revolution` (revslider/revslider.php), `custom-facebook-feed`
     * (custom-facebook-feed-pro/custom-facebook-feed.php), `smart-slider`
     * (smart-slider-3/smart-slider-3.php or nextend-smart-slider3-pro/nextend-smart-slider3-pro.php),
     * `real3d-flipbook` (real3d-flipbook/real3d-flipbook.php), `wp-grid-builder`
     * (wp-grid-builder/wp-grid-builder.php), `beaver-builder` (bb-plugin/fl-builder.php or
     * beaver-builder-lite-version/fl-builder.php), `the-grid` (the-grid/the-grid.php),
     * `social-pug` (social-pug/index.php), `instagram-feed` (instagram-feed/instagram-feed.php),
     * `insta-gallery` (insta-gallery/insta-gallery.php), `content-views`
     * (content-views-query-and-display-post-page/content-views.php), `featherlight`
     * (wp-featherlight/wp-featherlight.php), `lightbox-photoswipe`
     * (lightbox-photoswipe/lightbox-photoswipe.php), `acf` (advanced-custom-fields-pro/acf.php or
     * advanced-custom-fields/acf.php), `soliloquy` (soliloquy/soliloquy.php), `jetpack`
     * (jetpack/jetpack.php), `perfmatters` (perfmatters/perfmatters.php), `wp-super-cache`
     * (wp-super-cache/wp-cache.php), `breeze` (breeze/breeze.php), `smart-cookie-kit`
     * (smart-cookie-kit/plugin.php).
     *
     * Boolean keys with extra checks:
     * - `wp-fastest-cache` (slug `wp-fastest-cache/wpFastestCache.php`, version >= 0.9.0.7 read
     *   from `WP_PLUGIN_DIR`): true when `WpFastestCacheCreateCache` options have the cache
     *   status on and CSS minify or combine on; also true when the class does not exist yet
     *   (before `init`/`admin_init`).
     * - `w3-total-cache` (slug `w3-total-cache/w3-total-cache.php`): true when version >= 0.14.0.
     * - `litespeed-cache` (slug `litespeed-cache/litespeed-cache.php`): true when option
     *   `litespeed.conf.optm-css_min` or `litespeed.conf.optm-css_comb` is truthy.
     * - `wpzoom-theme`: `class_exists( 'WPZOOM' )`.
     * - `avia-gallery-module`: `class_exists( 'avia_sc_gallery' )` (Enfold and other themes).
     * - `theme`: `wp_get_theme()->Name` (string, matched by prefix in `TagRules` and themeIs()).
     *
     * Debug override: when `SHORTPIXEL_AI_DEBUG` is truthy and the current user has
     * `manage_options`, each `$_REQUEST['spai_force_<key>']` forces that key to true
     * (array-valued keys become a plain true).
     *
     * @todo __construct() calls `array_keys( get_site_option( 'active_sitewide_plugins' ) )`
     *       without a default; when the site option is missing it returns false and
     *       array_keys() throws a TypeError on PHP 8. Pass `array()` as the default and cast.
     * @todo __construct() reads `$wpRocketSettings['defer_all_js']` and `['defer_all_js_safe']`
     *       without isset() for WP Rocket < 3.8, raising undefined index warnings when the keys
     *       are absent. Guard them with isset()/empty().
     * @todo __construct() flattens `$swift_performance` to the active edition inside the loop,
     *       so when both Swift Performance Lite and Pro are active the second iteration writes
     *       into the flattened array and the result has no `active` key, making
     *       `swift-performance` false. Build the result in a separate variable and break after
     *       the first active edition.
     * @todo __construct() contains an empty `if` block for WP Rocket `lazyload_youtube`; the
     *       value is already exposed as `video-placeholder`. Remove the empty block.
     */
    protected function __construct() {
        $activePlugins = (array) apply_filters('active_plugins', get_option( 'active_plugins', array()));
        if ( is_multisite() ) {
            $activePlugins = array_merge($activePlugins, array_keys(get_site_option( 'active_sitewide_plugins')));
        }
        //test WPRocket
        if (in_array('wp-rocket/wp-rocket.php', $activePlugins)) {
            $path = SHORTPIXEL_AI_WP_PLUGINS_DIR . '/wp-rocket/wp-rocket.php';
            $pluginVersion = self::readPluginVersion($path);
            $wpRocketSettings = get_option('wp_rocket_settings', array());

            $hasCssFilter = (version_compare($pluginVersion, '3.4.3') >= 0);
            $hasLazyFilter = version_compare( $pluginVersion, '2.5', '>=' ); // does WP Rocket have the hook for disabling lazy loading?
            $hasSafeMode = version_compare( $pluginVersion, '3.8', '<' ); // does WP Rocket have the option to enable safe mode for jQuery? Removed in 3.8

            //if the lazy-load videos is active, do not optimize this placeholder: https://i.ytimg.com/vi/ID/hqdefault.jpg
            if(isset($wpRocketSettings['lazyload_youtube']) && $wpRocketSettings['lazyload_youtube'] == 1) {

            }

            $rocket = array(
                'lazyload'   => !$hasLazyFilter && ( isset( $wpRocketSettings[ 'lazyload' ] ) && $wpRocketSettings[ 'lazyload' ] == 1 ),
                'css-filter' => $hasCssFilter,
                'minify-css' => isset( $wpRocketSettings[ 'minify_css' ] ) && $wpRocketSettings[ 'minify_css' ] == 1,
                'defer-all-js' => $hasSafeMode && !!$wpRocketSettings[ 'defer_all_js' ] && !$wpRocketSettings[ 'defer_all_js_safe' ],
                'video-placeholder' => (isset($wpRocketSettings['lazyload_youtube']) && $wpRocketSettings['lazyload_youtube'] == 1),
            );

        } else {
            $rocket = array('lazyload' => false, 'css-filter' => false, 'minify-css' => false, 'defer-all-js' => false, 'video-placeholder' => false);
        }

        // Swift Performance
        $swift_performance = array(
            'lite' => array(
                'active' => in_array( 'swift-performance-lite/performance.php', $activePlugins ),
                'path'   => WP_CONTENT_DIR . '/plugins/swift-performance-lite/performance.php',
            ),
            'pro'  => array(
                'active' => in_array( 'swift-performance/performance.php', $activePlugins ),
                'path'   => WP_CONTENT_DIR . '/plugins/swift-performance/performance.php',
            ),
        );

        foreach ( $swift_performance as $plugin => $info ) {
            if ( !$info[ 'active' ] ) {
                continue;
            }

            $swift_performance[ $plugin ][ 'version' ] = defined( 'SWIFT_PERFORMANCE_VER' ) ? SWIFT_PERFORMANCE_VER : self::readPluginVersion( $info[ 'path' ] );
            $swift_performance[ $plugin ][ 'has_bug' ] = version_compare( $swift_performance[ $plugin ][ 'version' ], $plugin === 'lite' ? '2.1.2' : '2.1.6.2', $plugin === 'lite' ? '<=' : '<' );

            switch ( $plugin ) {
                case 'lite' :
                    if ( class_exists( 'Swift_Performance_Lite' ) ) {
                        $swift_performance[ $plugin ][ 'has_conflict' ] = !!\Swift_Performance_Lite::get_option( 'normalize-static-resources' )
                            && !!\Swift_Performance_Lite::get_option( 'merge-styles' );

                        $swift_performance[ $plugin ][ 'merge_styles' ] = !!\Swift_Performance_Lite::get_option( 'merge-styles' );
                    }
                    break;

                case 'pro' :
                    if ( class_exists( 'Swift_Performance' ) ) {
                        $swift_performance[ $plugin ][ 'has_conflict' ] = !!\Swift_Performance::get_option( 'normalize-static-resources' )
                            && !!\Swift_Performance::get_option( 'merge-styles' );

                        $swift_performance[ $plugin ][ 'merge_styles' ] = !!\Swift_Performance::get_option( 'merge-styles' );
                    }
                    break;
            }

            $swift_performance = $swift_performance[ $plugin ];

            $swift_performance[ 'plugin' ] = $plugin;
        }

        // Imagify
        $imagify = [
            'active' => in_array( 'imagify/imagify.php', $activePlugins ),
        ];

        if ( function_exists( 'get_imagify_option' ) ) {
            $imagify[ 'has_conflict' ] = !!get_imagify_option( 'display_webp' );
        }

        // WP Fastest Cache
        $wp_fastest_cache = false;

        if ( in_array( 'wp-fastest-cache/wpFastestCache.php', $activePlugins ) ) {
            $path    = WP_PLUGIN_DIR . '/wp-fastest-cache/wpFastestCache.php';
            $version = self::readPluginVersion( $path );

            if ( version_compare( $version, '0.9.0.7', '>=' ) ) {
                if ( class_exists( '\WpFastestCacheCreateCache' ) ) {
                    $wpfc = new \WpFastestCacheCreateCache;

                    if ( property_exists( $wpfc, 'options' ) && is_object( $wpfc->options ) ) {
                        if (
                            ( property_exists( $wpfc->options, 'wpFastestCacheStatus' ) && !!$wpfc->options->wpFastestCacheStatus )
                            && ( ( property_exists( $wpfc->options, 'wpFastestCacheMinifyCss' ) && !!$wpfc->options->wpFastestCacheMinifyCss )
                                || ( property_exists( $wpfc->options, 'wpFastestCacheCombineCss' ) && !!$wpfc->options->wpFastestCacheCombineCss ) )
                        ) {
                            $wp_fastest_cache = true;
                        }
                        else {
                            $wp_fastest_cache = false;
                        }
                    }
                }
                // condition for pre-init or admin pages because class WpFastestCacheCreateCache exists after "admin_init" and "init" hooks
                else {
                    $wp_fastest_cache = true;
                }
            }
        }

        // W3 Total Cache
        $w3_total_cache = false;

        if ( in_array( 'w3-total-cache/w3-total-cache.php', $activePlugins ) ) {
            $path    = WP_PLUGIN_DIR . '/w3-total-cache/w3-total-cache.php';
            $version = self::readPluginVersion( $path );

            if ( version_compare( $version, '0.14.0', '>=' ) ) {
                $w3_total_cache = true;
            }
        }

        // WP Optimize
        $wp_optimize = ['active' => false, 'enable_css' => false, 'enable_merging_of_css' => false];

        if ( in_array( 'wp-optimize/wp-optimize.php', $activePlugins ) ) {
            $wp_optimize['active'] = true;
            if ( function_exists( 'wp_optimize_minify_config' ) ) {
                $config = wp_optimize_minify_config();

                if ( method_exists( $config, 'get' ) ) {
                    $config = $config->get();

                    if ( is_array( $config ) ) {
                        $has_ignore_list = false;
                        $has_exclusion   = true;

                        if ( isset( $config[ 'ignore_list' ] ) && is_string( $config[ 'ignore_list' ] ) ) {
                            $has_ignore_list = true;

                            $plugin_folder = plugin_basename( SHORTPIXEL_AI_PLUGIN_DIR );

                            $conflicting_files = [
                                '/' . $plugin_folder . '/assets/css/admin.css',
                                '/' . $plugin_folder . '/assets/css/admin.min.css',
                                '/' . $plugin_folder . '/assets/css/style-bar.css',
                                '/' . $plugin_folder . '/assets/css/style-bar.min.css',
                            ];

                            foreach ( $conflicting_files as $file ) {
                                if ( strpos( $config[ 'ignore_list' ], $file ) === false ) {
                                    $has_exclusion = false;
                                    break;
                                }
                            }
                        }
                        if ( isset( $config[ 'enable_css' ] ) && !!$config[ 'enable_css' ] ){
                            $wp_optimize['enable_css'] = true;
                        }
                        if (
                            isset( $config[ 'enable_css' ] ) && isset( $config[ 'enable_merging_of_css' ] )
                            && ( !!$config[ 'enable_css' ] && !!$config[ 'enable_merging_of_css' ] )
                            && ( $has_ignore_list && !$has_exclusion )
                        ) {
                            $wp_optimize[ 'enable_merging_of_css' ] = true;
                        }
                    }
                }
            }
        }

        $this->integrations = array(
            'nextgen' => in_array('nextgen-gallery/nggallery.php', $activePlugins),
            'modula' => in_array('modula-best-grid-gallery/Modula.php', $activePlugins),
            'elementor' => in_array('elementor/elementor.php', $activePlugins),
            'elementor-addons' => in_array('essential-addons-for-elementor/essential_adons_elementor.php', $activePlugins)
                               || in_array('essential-addons-for-elementor-lite/essential_adons_elementor.php', $activePlugins),
            'viba-portfolio' => in_array('viba-portfolio/viba-portfolio.php', $activePlugins),
            'envira' => in_array('envira-gallery/envira-gallery.php', $activePlugins) || in_array('envira-gallery-lite/envira-gallery-lite.php', $activePlugins),
            'everest' => in_array('everest-gallery/everest-gallery.php', $activePlugins) || in_array('everest-gallery-lite/everest-gallery-lite.php', $activePlugins),
            'wp-bakery' => in_array('js_composer/js_composer.php', $activePlugins), //WP Bakery (testimonials)
            'woocommerce' => in_array('woocommerce/woocommerce.php' , $activePlugins),
            'wpc-variations' => in_array('wpc-variations-radio-buttons/wpc-variations-radio-buttons.php', $activePlugins),
            'foo' => in_array('foogallery/foogallery.php', $activePlugins),
            'global-gallery' => in_array('global-gallery/global-gallery.php', $activePlugins),
            'essential-grid' => in_array('essential-gridv1-9/essential-grid.php', $activePlugins),
            'oxygen' => in_array( 'oxygen/functions.php', $activePlugins),
            'slider-revolution' => in_array('revslider/revslider.php', $activePlugins),
            'custom-facebook-feed' => in_array('custom-facebook-feed-pro/custom-facebook-feed.php', $activePlugins),
            'smart-slider' => in_array('smart-slider-3/smart-slider-3.php', $activePlugins) || in_array('nextend-smart-slider3-pro/nextend-smart-slider3-pro.php', $activePlugins),
            'real3d-flipbook' => in_array('real3d-flipbook/real3d-flipbook.php', $activePlugins),
            'wp-grid-builder' => in_array('wp-grid-builder/wp-grid-builder.php', $activePlugins),
            'beaver-builder' => in_array('bb-plugin/fl-builder.php', $activePlugins) || in_array('beaver-builder-lite-version/fl-builder.php', $activePlugins),
            'the-grid' => in_array('the-grid/the-grid.php', $activePlugins),
            'social-pug' => in_array('social-pug/index.php', $activePlugins), //Mediavine Grow
            'instagram-feed' => in_array('instagram-feed/instagram-feed.php', $activePlugins),
            'insta-gallery' => in_array('insta-gallery/insta-gallery.php', $activePlugins), //Social Feed Gallery
            'content-views' => in_array( 'content-views-query-and-display-post-page/content-views.php', $activePlugins),
            'featherlight' => in_array('wp-featherlight/wp-featherlight.php', $activePlugins), //Featherlight lightbox
            'lightbox-photoswipe' => in_array('lightbox-photoswipe/lightbox-photoswipe.php', $activePlugins),
            'acf' => in_array('advanced-custom-fields-pro/acf.php', $activePlugins) || in_array('advanced-custom-fields/acf.php', $activePlugins),
            'soliloquy' => in_array('soliloquy/soliloquy.php', $activePlugins),
            'jetpack' => in_array('jetpack/jetpack.php', $activePlugins),
            'wp-rocket' => $rocket,
            'perfmatters' => in_array('perfmatters/perfmatters.php', $activePlugins),
            'swift-performance' => isset( $swift_performance[ 'active' ] ) ? $swift_performance : false,
            'imagify' => isset( $imagify[ 'active' ] ) ? $imagify : false,
            'wp-fastest-cache' => $wp_fastest_cache,
            'litespeed-cache' => in_array('litespeed-cache/litespeed-cache.php', $activePlugins) && (get_option('litespeed.conf.optm-css_min', false) || get_option('litespeed.conf.optm-css_comb', false)),
            'w3-total-cache' => $w3_total_cache,
            'wp-super-cache' => in_array('wp-super-cache/wp-cache.php', $activePlugins),
            'wp-optimize' => $wp_optimize,
            'breeze' => in_array( 'breeze/breeze.php', $activePlugins),
            'smart-cookie-kit' => in_array( 'smart-cookie-kit/plugin.php', $activePlugins),
            'wpzoom-theme' => class_exists('WPZOOM'),
            //modules that are included in themes for example, not standalone plugins
            'avia-gallery-module' => class_exists( 'avia_sc_gallery' ),
            //Deactivated, because the product is no longer added to chart excludedif the URL is changed. Keeping it here in case some other customer appears...
            //'visual-product-configurator' => in_array( 'visual-product-configurator/vpc.php', $activePlugins),
        );
        //test theme. 'Jupiter' 'CROWD 2'
        $theme = wp_get_theme();
        $this->integrations['theme'] = $theme->Name;
        //admins only
        if(SHORTPIXEL_AI_DEBUG && function_exists('current_user_can') && current_user_can('manage_options')) {
            //integration forced from the request parameters
            foreach($this->integrations as $key => $val) {
                if(isset($_REQUEST['spai_force_' . $key])) {
                    $this->integrations[$key] = true;
                }
            }
        }
    }

    /**
     * Tells whether the active theme name starts with `$name` (case-sensitive).
     *
     * @param string $name Theme name prefix, e.g. `'Jupiter'`.
     *
     * @return bool
     */
    public function themeIs($name) {
        return strpos($this->integrations['theme'], $name) === 0;
    }

    /**
     * Tells whether an integration (or one attribute of an array-valued integration) is truthy.
     *
     * @param string       $plugin Integration key, see __construct().
     * @param string|false $attr   Attribute of an array-valued integration, or false.
     *
     * @return bool
     */
    public function has($plugin, $attr = false) {
        return isset( $this->integrations[ $plugin ] )
            && ($attr ? isset($this->integrations[$plugin][$attr]) && !! $this->integrations[$plugin][$attr]
                      : !!$this->integrations[ $plugin ]);
    }

    /**
     * Returns the raw value of an integration, or of one of its attributes.
     *
     * When `$attr` is given but not set, the whole integration value is returned instead.
     *
     * @param string       $plugin Integration key, see __construct().
     * @param string|false $attr   Attribute of an array-valued integration, or false.
     *
     * @return mixed The value, or false when the key is unknown.
     */
    public function get($plugin, $attr = false) {
        return
            isset( $this->integrations[ $plugin ] )
            ? ($attr && isset($this->integrations[ $plugin ][$attr])
                ? $this->integrations[ $plugin ][$attr]
                : $this->integrations[ $plugin ])
            : false;
    }

    /**
     * Returns every detected integration.
     *
     * @return array<string,mixed> See __construct() for the keys.
     */
    public function getAll() {
        return $this->integrations;
    }

    /**
     * Returns the selectors whose images the front-end JS sizes like the first match
     * (`use_first_sizes` in the jQuery loader settings).
     *
     * @return array<string,array> With WooCommerce:
     *                             `['div.woocommerce-product-gallery__image > a > img' => ['width' => 0, 'height' => 0]]`;
     *                             otherwise an empty array.
     */
    public function getUseFirstSizes() {
        //if woocommerce is active, the following elements have to be forced to the size of the first case (because the first is displayed as larger image and the others get to that same size when selected)
        return $this->integrations['woocommerce'] ? ['div.woocommerce-product-gallery__image > a > img' => ['width' => 0, 'height' => 0]] : [];
    }

    /**
     * Reads the `Version:` header from the first 100 lines of a plugin file.
     *
     * @param string $path Absolute path of the plugin main file.
     *
     * @return string The version (text after the last `Version:` on the first matching
     *                line, trimmed), or `'0.0'` when the file is missing or has no header.
     */
    public static function readPluginVersion( $path ) {
        $ver = '0.0';

        if ( file_exists( $path ) ) {
            $fp = fopen( $path, 'r' );
            for ( $i = 0; $i < 100 && !feof( $fp ); $i++ ) {
                $line = trim( fgets( $fp ) );
                if ( strpos( $line, 'Version:' ) !== false ) {
                    $version = explode( 'Version:', $line );
                    $ver     = trim( end( $version ) );
                    break;
                }
            }
            fclose( $fp );
        }

        return $ver;
    }
}

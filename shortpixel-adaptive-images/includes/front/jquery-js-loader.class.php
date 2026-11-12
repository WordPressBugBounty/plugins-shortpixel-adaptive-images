<?php
/**
 * Legacy jQuery front-end engine loader.
 */

namespace ShortPixel\AI;

/**
 * jQuery mode (`settings.behaviour.nojquery <= 0`): enqueues `assets/js/ai-2.0[.min].js` (handle `spai-scripts`, depends on
 * `jquery`) with the `spai_settings` object, and injects the affected tags recorded during AJAX.
 */
class JqueryJsLoader extends JsLoader {

    /**
     * Only through `JsLoader::_()`.
     */
    protected function __construct() {
        parent::__construct();
    }

    /**
     * Enqueues the jQuery engine.
     *
     * For `manage_options` users with `tests.front_end.enqueued` it first enqueues `spai-tests` (`ai.tests.js`, deferred).
     * Then registers `spai-scripts` and localizes `spai_settings` with these keys:
     * - `api_domain`: host of `get_cdn_url()`;
     * - `api_url`: `get_api_url( false, '%WIDTH%', '%HEIGHT%', 'noauto' )` (prefix with `w_%WIDTH%`, no `to_` token: the JS adds it per extension);
     * - `api_short_url`: `get_api_url( false, false, false, 'svg' )` (only-store prefix: `ret_img` [+ `p_h`]);
     * - `method`: `behaviour.replace_method` (`src` | `srcset` | `both`);
     * - `crop`: bool `behaviour.crop`;
     * - `size_breakpoints`: `{on, base, rate}` from `behaviour.size_breakpoints`, `_base`, `_rate`;
     * - `lqip`: bool `behaviour.lqip`;
     * - `lazy_threshold`: `behaviour.lazy_threshold` when an int >= 0, else 500;
     * - `hover_handling`: bool `behaviour.hover_handling`;
     * - `native_lazy`: bool `areas.native_lazy`;
     * - `serve_svg`: always true;
     * - `debug`: `SHORTPIXEL_AI_DEBUG`;
     * - `site_url`: `home_url()` through filter `shortpixel/ai/originalUrl`;
     * - `plugin_url`: `SHORTPIXEL_AI_PLUGIN_BASEURL`; `version`: `SHORTPIXEL_AI_VERSION`;
     * - `excluded_selectors`, `eager_selectors`, `noresize_selectors`: arrays split on `,` (Modula adds `.modula-creative-gallery img.pic` to eager and noresize);
     * - `alter2wh`: bool `behaviour.alter2wh`;
     * - `use_first_sizes`: `ActiveIntegrations::getUseFirstSizes()` (WooCommerce gallery selector map or []);
     * - `lazy_bg_style`: bool `areas.backgrounds_lazy_style`;
     * - `active_integrations`: `ActiveIntegrations::getAll()`;
     * - `parse_css_files`: `areas.parse_css_files > 0`;
     * - `backgrounds_max_width`: `areas.backgrounds_max_width` when an int >= 0, else 1920;
     * - `sep`: `ShortPixelAI::SEP`;
     * - `webp`, `avif`: bools from `compression`;
     * - `webp_detect`: `!ShortPixelAI::$varyCacheSupport`;
     * - `extensions_to_nextgenimg`: `{png, jpg, gif}` = (webp || avif) && `<ext>_to_webp`;
     * - `sniper`: URL of `assets/img/target.cur`;
     * - `affected_tags`: the literal `{{SPAI-AFFECTED-TAGS}}`, replaced by `addTagData()`;
     * - `ajax_url`: admin-ajax URL; `ajax_nonce`: nonce `shortpixel-ai-settings`;
     * - `excluded_paths`, `eager_paths` (kept last for a Swift Performance HTML fix): lines split on PHP_EOL, each base64-encoded.
     * With WP Rocket active adds filter `rocket_defer_inline_exclusions` -> `wp_rocket_no_defer_spai_settings()`. Then calls `parent::enqueue()`.
     *
     * @return void
     * @todo `native_lazy` in `JqueryJsLoader::enqueue()` reads `areas.native_lazy`; the setting lives in `behaviour.native_lazy`
     *       (settings page, regex parser, vanilla loader), so jQuery mode always gets the stale/missing value. Read `behaviour->native_lazy`.
     * @todo In `lazy_threshold` and `backgrounds_max_width` the `(int)` cast applies to `is_int()`, not to the value, so a numeric-string
     *       setting (e.g. `'300'`) falls back to the default. Use `is_numeric()` and cast the value.
     */
    public function enqueue() {
        $testFE = false;
        if ( current_user_can( 'manage_options' ) && !!$this->ctrl->options->tests_frontEnd_enqueued ) {
            $this->ctrl->register_js('spai-tests', 'ai.tests', true, false, true, [], true);
        }

        $noresize_selectors = $this->ctrl->splitSelectors( $this->settings->exclusions->noresize_selectors, ',' );
        $eager_selectors = $this->ctrl->splitSelectors( $this->settings->exclusions->eager_selectors, ',' );
        if(ActiveIntegrations::_()->has('modula')) {
            //This is for the creative gallery, because it sets the images positions outside of the view and they are never replaced
            $noresize_selectors[] = '.modula-creative-gallery img.pic';
            $eager_selectors[] = '.modula-creative-gallery img.pic';
        }

        $this->ctrl->register_js('spai-scripts', 'ai-' . \ShortPixelAI::AI_JS_VERSION, false, false, false, ['jquery']);
        $nextgen = (!!$this->settings->compression->webp || !!$this->settings->compression->avif);
        wp_localize_script( 'spai-scripts', 'spai_settings', [
            'api_domain'            =>  parse_url($this->ctrl->get_cdn_url(), PHP_URL_HOST),
            'api_url'               => $this->ctrl->get_api_url(false, '%WIDTH%', '%HEIGHT%', 'noauto'), //the noauto type gets rid of the to_auto - the jQuery JS handles it based on extensions_to_nextgenimg
            'api_short_url'         => $this->ctrl->get_api_url(false, false, false, 'svg' ),
            'method'                => $this->settings->behaviour->replace_method,
            'crop'                  => !!$this->settings->behaviour->crop,
            'size_breakpoints'      => (object)['on' => $this->settings->behaviour->size_breakpoints, 'base' => $this->settings->behaviour->size_breakpoints_base, 'rate' => $this->settings->behaviour->size_breakpoints_rate],
            'lqip'                  => !!$this->settings->behaviour->lqip,
            'lazy_threshold'        => (int) is_int( $this->settings->behaviour->lazy_threshold ) && $this->settings->behaviour->lazy_threshold >= 0 ? $this->settings->behaviour->lazy_threshold : 500,
            'hover_handling'        => !!$this->settings->behaviour->hover_handling,
            'native_lazy'           => !!$this->settings->areas->native_lazy,
            'serve_svg'             => true, //!!$this->settings->areas->serve_svg,
            'debug'                 => SHORTPIXEL_AI_DEBUG,
            'site_url'              => apply_filters('shortpixel/ai/originalUrl', home_url()),
            'plugin_url'            => SHORTPIXEL_AI_PLUGIN_BASEURL,
            'version'               => SHORTPIXEL_AI_VERSION,
            'excluded_selectors'    => $this->ctrl->splitSelectors( $this->settings->exclusions->excluded_selectors, ',' ),
            'eager_selectors'       => $eager_selectors,
            'noresize_selectors'    => $noresize_selectors,
            'alter2wh'               => !!$this->settings->behaviour->alter2wh,
            'use_first_sizes'       => ActiveIntegrations::_()->getUseFirstSizes(),
            'lazy_bg_style'         => !!$this->settings->areas->backgrounds_lazy_style,
            'active_integrations'   => ActiveIntegrations::_()->getAll(),
            'parse_css_files'       => $this->settings->areas->parse_css_files > 0,
            'backgrounds_max_width' => (int) is_int( $this->settings->areas->backgrounds_max_width ) && $this->settings->areas->backgrounds_max_width >= 0 ? $this->settings->areas->backgrounds_max_width : 1920,
            'sep'                   => \ShortPixelAI::SEP, //separator
            'webp'                  => !!$this->settings->compression->webp,
            'avif'                  => !!$this->settings->compression->avif,
            'webp_detect'           => !$this->ctrl->varyCacheSupport,
            'extensions_to_nextgenimg'    => [
                'png' => $nextgen  && !!$this->settings->compression->png_to_webp,
                'jpg' => $nextgen && !!$this->settings->compression->jpg_to_webp,
                'gif' => $nextgen && !!$this->settings->compression->gif_to_webp,
            ],
            'sniper'                => $this->ctrl->plugin_url . 'assets/img/target.cur',
            'affected_tags'         => '{{SPAI-AFFECTED-TAGS}}',
            'ajax_url'              => admin_url( 'admin-ajax.php' ),
            'ajax_nonce'            => wp_create_nonce('shortpixel-ai-settings'),
            //**** LET THIS ONE BE LAST - SWIFT Performance HTML optimize bug when their Fix Invalid HTML option is on
            //the excluded_paths can contain URLs so we base64 encode them in order to pass our own JS parser :)
            'excluded_paths'        => array_map( 'base64_encode', $this->ctrl->splitSelectors( $this->settings->exclusions->excluded_paths, PHP_EOL ) ),
            'eager_paths'        => array_map( 'base64_encode', $this->ctrl->splitSelectors( $this->settings->exclusions->eager_paths, PHP_EOL ) ),
        ] );

        wp_enqueue_script( 'spai-scripts' );

        if(ActiveIntegrations::_()->has('wp-rocket')) {
            add_filter('rocket_defer_inline_exclusions', [$this, 'wp_rocket_no_defer_spai_settings']);
        }

        parent::enqueue();
    }

    /**
     * Filter `rocket_defer_inline_exclusions`: keeps the inline `spai_settings` script out of WP Rocket's deferral.
     *
     * @param string|string[] $regex Regex string (`|`-joined) or list
     * @return string|string[]
     */
    public function wp_rocket_no_defer_spai_settings($regex) {
        if( is_string( $regex ) ){
            return $regex . '|spai_settings';
        }
        $regex[] = 'spai_settings';
        return $regex;
    }

    /**
     * Replaces `{{SPAI-AFFECTED-TAGS}}` in the localized `spai_settings` with `addslashes( json_encode( AffectedTags::getAll() ) )`;
     * when the placeholder is absent, inserts `<script>var spai_affectedTags = "<same>";</script>` before `</body>`.
     *
     * @param string $content
     * @return string
     */
    public function addTagData($content)
    {
        if ( strpos( $content, '{{SPAI-AFFECTED-TAGS}}' ) ) {
            $this->logger->log( "AFFECTED TAGS PLACEHOLDER FOUND: ", $this->ctrl->affectedTags->getAll() );
            $content = str_replace( '{{SPAI-AFFECTED-TAGS}}', addslashes( json_encode( $this->ctrl->affectedTags->getAll() ) ), $content );
        }
        else {
            $this->logger->log( "AFFECTED TAGS PLACEHOLDER NOT FOUND: ", $this->ctrl->affectedTags->getAll() );
            $content = str_replace( '</body>', '<script>var spai_affectedTags = "' . addslashes( json_encode( $this->ctrl->affectedTags->getAll() ) ) . '";</script></body>', $content );
        }
        return $content;
    }

    /**
     * True while `spai-scripts` is still enqueued (`wp_script_is()`); `$content` is not inspected.
     *
     * @param string $content Unused
     * @return bool
     */
    public function check($content)
    {
        return wp_script_is('spai-scripts');
    }

    /**
     * Adds the inline style `spai-fadein`: `img[data-spai]` starts at opacity 0 (except inside `div.woocommerce-product-gallery`),
     * and `img[data-spai-egr]`, `img[data-spai-lazy-loaded]`, `img[data-spai-upd]` fade to opacity 1 (.5s linear, .2s delay).
     *
     * @return void
     */
    public function fadeInCss()
    {
        wp_register_style( 'spai-fadein', false );
        wp_enqueue_style( 'spai-fadein' );
        //Exclude the .zoomImg's as it conflicts with rules of WooCommerce.
        wp_add_inline_style( 'spai-fadein',
            'img[data-spai]{'
            . 'opacity: 0;'
            . '} '
            . 'div.woocommerce-product-gallery img[data-spai]{' //exclusions
            . 'opacity: 1;'
            . '} '
            . 'img[data-spai-egr],'
            . 'img[data-spai-lazy-loaded],'
            . 'img[data-spai-upd] {'
            . 'transition: opacity .5s linear .2s;'
            . '-webkit-transition: opacity .5s linear .2s;'
            . '-moz-transition: opacity .5s linear .2s;'
            . '-o-transition: opacity .5s linear .2s;'
            . ' opacity: 1;'
            . '}');
    }
}
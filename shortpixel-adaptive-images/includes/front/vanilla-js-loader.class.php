<?php
/**
 * Vanilla JS (default) front-end engine loader.
 */

namespace ShortPixel\AI;

/**
 * Vanilla mode (`settings.behaviour.nojquery > 0`, default 2): the engine is the CDN bundle
 * `https://<host>/assets/js/bundles/spai-lib-bg[-webp][-compat].<ver>[.dev].min.js?v=<plugin version>`, loaded async by an
 * inline script printed on `wp_head`. The bundle is not part of this repository.
 *
 * Page markers: `html.spai_has_js` (added by the inline script), inline `<script id="spai_js" data-cfasync="false">`,
 * `<style id="spai_bg_lazr">` (see `addTagData()`).
 */
class VanillaJsLoader extends JsLoader {

    /**
     * Only through `JsLoader::_()`.
     */
    protected function __construct() {
        parent::__construct();
    }

    /**
     * Registers the inline loader script, the admin sniper helpers and the WP Rocket exclusions.
     *
     * `wp_head` closure prints `<script type="text/javascript" id="spai_js" data-cfasync="false">` which
     * adds class ` spai_has_js` to `<html>`, sets `window.spaiDomain` (host of `get_cdn_url()`) and `window.spaiData`:
     * - `version`: `SHORTPIXEL_AI_VERSION`;
     * - `key`: last path segment of `get_cdn_url()` (e.g. `spai`);
     * - `customKeys`: `{<behaviour.host_removal>: <last segment of choose_api_base()>}` when `amazon_s3` and both are set, else `[]`;
     * - `quality`: `compression.level`;
     * - `convert`: `none` (no webp/avif), `detect` (webp/avif on without `$varyCacheSupport`), `auto` (webp+avif), `webp` or `avif`;
     * - `lqip`: bool `behaviour.lqip`;
     * - `converts`: `{png, jpg, gif}` each `<convert>` or `none` per `<ext>_to_webp`; printed only when webp is on and at least one toggle is off;
     * - `rootMargin`: `<behaviour.lazy_threshold>px` (500 when not an int >= 0);
     * - `crop`: bool `behaviour.crop`;
     * - `sizeBreakpoints`: `{on, base, rate}`;
     * - `backgroundsMaxWidth`: `areas.backgrounds_max_width` (1920 when not an int >= 0);
     * - `resizeStyleBackgrounds`: bool `areas.backgrounds_lazy_style`;
     * - `nativeLazyLoad`: bool `behaviour.native_lazy`;
     * - `safeLazyBackgrounds`: `backgrounds_lazy_style || backgrounds_lazy`;
     * - `asyncInitialParsing`: `!behaviour.sync_initial_parsing`;
     * - `debug`: bool `SHORTPIXEL_AI_DEBUG`;
     * - `doSelectors`: placeholder `"__SPAI_DO_SELECTORS__"`, `exclusions`: placeholder `"__SPAI_EXCLUSIONS__"` (replaced by `addTagData()`);
     * - `sizeFromImageSuffix`: false when `SPAI_FILENAME_RESOLUTION_UNSAFE` is defined;
     * - `ajax_url`: admin-ajax URL.
     * Bundle URL: host `dev.shortpixel.ai` and suffix `.dev` when debugging (`SHORTPIXEL_AI_DEBUG` or `?SPAI_VJS` present), else the CDN host;
     * `-webp` when `convert === 'detect'`; `-compat` added client-side when `IntersectionObserver` is missing; version
     * `?SPAI_VJS=<[.0-9a-zA-Z]+>` or `SHORTPIXEL_AI_VANILLAJS_VER`.
     *
     * For `manage_options` users: empty-source handle `spai-snip-action` (footer) localized as `spai_settings` with
     * `api_domain`, `ajax_url`, `excluded_selectors`, `eager_selectors`, `noresize_selectors`, `excluded_paths`, `eager_paths`
     * (paths base64-encoded) plus inline `spaiSniperClick()`, which defines a minimal `ShortPixelAI.is()` (NORESIZE 1, EXCLUDED 2,
     * EAGER 4) and calls `SpaiSniper(1)`.
     * With WP Rocket active: `rocket_defer_inline_exclusions` -> `wp_rocket_no_defer_spai_settings()`, `rocket_delay_js_exclusions`
     * -> `wp_rocket_no_defer_spai_lib()`. Then `parent::enqueue()`.
     *
     * @return void
     * @todo `?SPAI_VJS` in `VanillaJsLoader::enqueue()` switches any visitor (no capability check) to the `dev.shortpixel.ai` host and an
     *       arbitrary bundle version, and a page cache can then serve that page to everyone. Honour it only for `manage_options` users or when `SHORTPIXEL_AI_DEBUG` is set.
     * @todo `converts` is printed only when WebP is on; with AVIF alone the per-extension `<ext>_to_webp` toggles are ignored by the bundle while
     *       `get_api_url()` honours them. Use `$convert !== 'none'` as the condition.
     * @todo The inline `#spai_js` script gets no CSP nonce (`spai_script_nonce` is applied only to registered handles).
     */
    public function enqueue()
    {
        /**
         * Prints the inline `#spai_js` loader script (see the method docblock for `window.spaiData`).
         * @return void
         */
        add_action( 'wp_head', function() {
            $apiUrlParts = explode('/', rtrim($this->ctrl->get_cdn_url(), '/'));
            $customApiUrlParts = explode('/', rtrim($this->ctrl->choose_api_base(), '/'));
            $customKeys = [];
            if ($this->settings->behaviour->amazon_s3) {
                $hostRemoval = $this->settings->behaviour->host_removal;
                $customKey   = end($customApiUrlParts);
                if ($hostRemoval && $customKey) {
                    $customKeys[$hostRemoval] = $customKey;
                }
            }

            $convert = 'none';
            if(!!$this->settings->compression->webp || !!$this->settings->compression->avif) {
                if (!$this->ctrl->varyCacheSupport) {
                    $convert = 'detect';
                } elseif(!!$this->settings->compression->webp && !!$this->settings->compression->avif) {
                    $convert = 'auto';
                } elseif (!!$this->settings->compression->webp) {
                    $convert = 'webp';
                } else {
                    $convert = 'avif';
                }
            }
            $dbg = (SHORTPIXEL_AI_DEBUG || isset($_GET['SPAI_VJS']));
            $vjsVer = isset($_GET['SPAI_VJS']) && preg_match('/^[\.0-9a-zA-Z]+$/', $_GET['SPAI_VJS']) ? esc_js($_GET['SPAI_VJS']) : SHORTPIXEL_AI_VANILLAJS_VER;
            $spaiDomain = parse_url($this->ctrl->get_cdn_url(), PHP_URL_HOST); // this one changes the picture's URL when filtered
            $scriptDomain = ($dbg ? 'dev.shortpixel.ai' : $spaiDomain);
            ?>
            <script type="text/javascript" id="spai_js" data-cfasync="false">
                document.documentElement.className += " spai_has_js";
                (function(w, d){
                    var b = d.getElementsByTagName('head')[0];
                    var s = d.createElement("script");
                    var v = ("IntersectionObserver" in w) ? "" : "-compat";
                    s.async = true; // This includes the script as async.
                    s.src = "https://<?= $scriptDomain ?>/assets/js/bundles/spai-lib-bg<?= $convert === 'detect' ? '-webp' : '' ?>" + v
                        + ".<?=$vjsVer?><?=($dbg ? '.dev' : '')?>.min.js?v=<?= SHORTPIXEL_AI_VERSION ?>";
                    w.spaiDomain = "<?= esc_js($spaiDomain) ?>";
                    w.spaiData = {
                        version: "<?= esc_js(SHORTPIXEL_AI_VERSION) ?>",
                        key: "<?= esc_js(end($apiUrlParts)) ?>",
                        customKeys: <?= wp_json_encode($customKeys) ?>,
                        quality: "<?= esc_js($this->settings->compression->level) ?>",
                        convert: "<?= esc_js($convert) ?>",
                        lqip: <?= $this->settings->behaviour->lqip ? 'true' : 'false' ?>,
                        <?php
                            if(!!$this->settings->compression->webp && !(!!$this->settings->compression->png_to_webp && !!$this->settings->compression->jpg_to_webp && !!$this->settings->compression->gif_to_webp)) {
                                //means at least one of the three is deactivated for conversion
                                ?>
                        converts: {
                            png: "<?= !!$this->settings->compression->png_to_webp ? $convert : 'none' ?>",
                            jpg: "<?= !!$this->settings->compression->jpg_to_webp ? $convert : 'none' ?>",
                            gif: "<?= !!$this->settings->compression->gif_to_webp ? $convert : 'none' ?>",
                        },
                                <?php
                            }
                        ?>
                        rootMargin: "<?= (int) is_int( $this->settings->behaviour->lazy_threshold ) && $this->settings->behaviour->lazy_threshold >= 0 ? $this->settings->behaviour->lazy_threshold : 500 ?>px",
                        crop: <?= !!$this->settings->behaviour->crop ? 'true' : 'false' ?>,
                        sizeBreakpoints: <?= json_encode((object)['on' => $this->settings->behaviour->size_breakpoints, 'base' => $this->settings->behaviour->size_breakpoints_base, 'rate' => $this->settings->behaviour->size_breakpoints_rate]); ?>,
                        backgroundsMaxWidth: <?= (int) is_int( $this->settings->areas->backgrounds_max_width ) && $this->settings->areas->backgrounds_max_width >= 0 ? $this->settings->areas->backgrounds_max_width : 1920 ?>,
                        resizeStyleBackgrounds: <?= $this->settings->areas->backgrounds_lazy_style ? 'true' : 'false' ?>,
                        nativeLazyLoad: <?= $this->settings->behaviour->native_lazy ? 'true' : 'false' ?>,
                        safeLazyBackgrounds: <?= $this->settings->areas->backgrounds_lazy_style || $this->settings->areas->backgrounds_lazy ? 'true' : 'false' ?>,
                        asyncInitialParsing: <?= $this->settings->behaviour->sync_initial_parsing ? 'false' : 'true' ?>,
                        debug: <?= SHORTPIXEL_AI_DEBUG ? 'true' : 'false' ?>,
                        doSelectors: "__SPAI_DO_SELECTORS__",
                        exclusions: "__SPAI_EXCLUSIONS__",
                        sizeFromImageSuffix: <?php echo(defined('SPAI_FILENAME_RESOLUTION_UNSAFE') ? 'false' : 'true'); ?>,
                        ajax_url: "<?= esc_js(admin_url('admin-ajax.php')) ?>"
                    };
                    b.appendChild(s);
                }(window, document));
            </script>
            <?php
        } );

        if(\ShortPixelAI::userCan( 'manage_options' )) {
            wp_register_script( 'spai-snip-action', '', [], '', true );
            wp_localize_script( 'spai-snip-action', 'spai_settings', [
                'api_domain'            =>  parse_url($this->ctrl->get_cdn_url(), PHP_URL_HOST),
                'ajax_url'              => admin_url( 'admin-ajax.php' ),
                'excluded_selectors'    => $this->ctrl->splitSelectors( $this->settings->exclusions->excluded_selectors, ',' ),
                'eager_selectors'       => $this->ctrl->splitSelectors( $this->settings->exclusions->eager_selectors, ',' ),
                'noresize_selectors'    => $this->ctrl->splitSelectors( $this->settings->exclusions->noresize_selectors, ',' ),
                'excluded_paths'        => array_map( 'base64_encode', $this->ctrl->splitSelectors( $this->settings->exclusions->excluded_paths, PHP_EOL ) ),
                'eager_paths'           => array_map( 'base64_encode', $this->ctrl->splitSelectors( $this->settings->exclusions->eager_paths, PHP_EOL ) ),
            ]);
            \ShortPixelUrlTools::applyNonce('spai-snip-action');
            wp_enqueue_script( 'spai-snip-action'  );
            wp_add_inline_script('spai-snip-action',
            "function spaiSniperClick() {
                if(typeof ShortPixelAI === 'undefined') {
                    window.ShortPixelAI = {};
                }
                if(typeof ShortPixelAI.NORESIZE === 'undefined') {
                    ShortPixelAI.NORESIZE = 1;
                    ShortPixelAI.EXCLUDED = 2;
                    ShortPixelAI.EAGER = 4;
                    ShortPixelAI.is = function(elm, types) {
                        var excluded = 0;
                        if(types & ShortPixelAI.EAGER) {
                            for(var i = 0; i < spai_settings.eager_selectors.length; i++) { //.elementor-section-stretched img.size-full
                                var selector = spai_settings.eager_selectors[i];
                                try {if(elm.is(selector)) excluded |= ShortPixelAI.EAGER;} catch (xc){} //we don't bother about wrong selectors at this stage
                            }
                        }
                    
                        if(types & ShortPixelAI.EXCLUDED) {
                            for(var i = 0; i < spai_settings.excluded_selectors.length; i++) { //.elementor-section-stretched img.size-full
                                var selector = spai_settings.excluded_selectors[i];
                                try {if(elm.is(selector)) excluded |= ShortPixelAI.EXCLUDED;} catch (xc){}
                            }
                        }
                    
                        if(types & ShortPixelAI.NORESIZE) {
                            for(var i = 0; i < spai_settings.noresize_selectors.length; i++) { //.elementor-section-stretched img.size-full
                                var selector = spai_settings.noresize_selectors[i];
                                try {if(elm.is(selector)) excluded |= ShortPixelAI.NORESIZE;} catch (xc){}
                            }
                        }
                        return excluded;
                    };
                }
                SpaiSniper(1);
            }
            ");
        }

        if(ActiveIntegrations::_()->has('wp-rocket')) {
            add_filter('rocket_defer_inline_exclusions', [$this, 'wp_rocket_no_defer_spai_settings']);
            add_filter('rocket_delay_js_exclusions', [$this, 'wp_rocket_no_defer_spai_lib']);
        }

        parent::enqueue();
    }

    /**
     * Filter `rocket_delay_js_exclusions`: keeps `spai_js` out of WP Rocket's "delay JS".
     *
     * @param string[] $regex
     * @return string[]
     */
    public function wp_rocket_no_defer_spai_lib($regex) {
        $regex[] = 'spai_js';
        $this->logger->log("WP ROCKET rocket_delay_js_exclusions: ");
        return $regex;
    }

    /**
     * Filter `rocket_defer_inline_exclusions`: keeps the inline `spai_js` script out of WP Rocket's inline deferral.
     *
     * @param string|string[] $regex Regex string (`|`-joined) or list
     * @return string|string[]
     */
    public function wp_rocket_no_defer_spai_settings($regex) {
        if( is_string( $regex ) ){
            $this->logger->log("WP ROCKET ADDED SPAI as string");
            return $regex . '|spai_js';
        }
        $regex[] = 'spai_js';
        $this->logger->log("WP ROCKET ADDED SPAI");
        return $regex;
    }

    /**
     * Fills the `spaiData` placeholders and adds the background-hiding style.
     *
     * When `"__SPAI_DO_SELECTORS__"` (with quotes) is present, it is replaced by `json_encode( getDoSelectors() )` and
     * `"__SPAI_EXCLUSIONS__"` by `getExclusions()`. Otherwise (inline script moved to a file by WP Rocket or similar) it inserts
     * `<script id="spai_js">var spai_doSelectors=<json>;var spai_exclusions=<json>;</script>` before `</body>`.
     * Then, before `</head>`, `<style id="spai_bg_lazr"><selector>{background-image: none !important;}</style>` with selector
     * `html.spai_has_js :not([data-spai-bg-prepared])` when a `__stylesheet` do-selector exists, else
     * `html.spai_has_js [data-spai-bg-on]:not([data-spai-bg-prepared])` when `areas.backgrounds_lazy`; no style otherwise.
     *
     * @param string $content
     * @return string
     */
    public function addTagData($content)
    {
        //$affectedTags = $this->ctrl->affectedTags->getAll();
        //$content = str_replace( '"__SPAI_BACKGROUND_REPLACE_CLASSES__"', '', $content );
        //$content = str_replace( '__SPAI_BACKGROUND_LAZY_SELECTORS__', implode(',', array_keys($affectedTags)), $content );
        $doSelectors = $this->getDoSelectors();
        $this->logger->log("DO SELECTORS: ", $doSelectors);
        if(strpos($content, '"__SPAI_DO_SELECTORS__"') === false) {
            //inline JS was already extracted in a JS file by WPRocket or another page speed tool
            $content = str_replace( '</body>', '<script id="spai_js">var spai_doSelectors=' .  json_encode( $doSelectors )
                . ';var spai_exclusions=' . $this->getExclusions() . ';</script></body>', $content );
        } else {
            $content = str_replace( '"__SPAI_DO_SELECTORS__"', json_encode($doSelectors), $content );
            $content = str_replace( '"__SPAI_EXCLUSIONS__"', $this->getExclusions(), $content );
        }

        $bgCss = '';
        foreach($doSelectors as $rule) {
            $rule = (object)$rule;
            $this->logger->log("DO SELECTORS RULE: ", $rule);
            if($rule->type == '__stylesheet') {
                (SHORTPIXEL_AI_DEBUG & \ShortPixelAILogger::DEBUG_AREA_CSS) && $this->logger->log("CSS FILES ON");
                $bgCss = 'html.spai_has_js :not([data-spai-bg-prepared])';
                break;
            }
        }
        if(!strlen($bgCss) && $this->settings->areas->backgrounds_lazy) {
            $bgCss .= 'html.spai_has_js [data-spai-bg-on]:not([data-spai-bg-prepared])';
        }
        (SHORTPIXEL_AI_DEBUG & \ShortPixelAILogger::DEBUG_AREA_CSS) && $this->logger->log("BACKGROUND CSS RULES: " . $bgCss);

        if(strlen($bgCss)) {
            $content = str_replace( '</head>', '<style id="spai_bg_lazr">' . $bgCss . '{background-image: none !important;}</style></head>', $content);
        }
        //$content = str_replace( '__SPAI_BACKGROUND_LAZY_TAGS__', implode(',', array_keys($affectedBkTags)), $content );
        return $content;
    }

    /**
     * Builds `spaiData.doSelectors`: the list of element groups the bundle must process.
     *
     * Items, in order:
     * - when AffectedTags has `SRC_ATTR` entries, for each `TagRules::usedLazy()` rule of type `url`:
     *   `{selectors: "<used tags joined by ,>", type: "attr", targetAttr: <attr>, attrType: "json"|"url" (json for
     *   `replace_custom_json_attr`), lazy: !frontEager, resize: frontResize}`;
     * - when AffectedTags has `CSS_ATTR` entries and `areas.backgrounds_lazy`:
     *   `{selectors: "[data-spai-bg-on]", type: "attr", targetAttr: "style", attrType: "style"}`;
     * - for each `TagRules::frontEndItems()` rule: `{selectors: <tag>, type: "attr", targetAttr: <attr>, attrType: "url", lazy, resize}`
     *   plus `filter: {attrName, attrValue}` when the rule has `attrFilter`;
     * - `areas.backgrounds_lazy_style`: `{selectors: "style", type: "inner", attrType: "style"}`;
     * - `backgrounds_lazy_style` and `parse_css_files > 0`: `{type: "__stylesheet"}`.
     *
     * @return array[]
     */
    protected function getDoSelectors() {
        //$lazyRules = TagRules::_()->usedLazy();
        (SHORTPIXEL_AI_DEBUG & \ShortPixelAILogger::DEBUG_AREA_CSS) && $this->logger->log("AFFECTED TAGS: ", $this->ctrl->affectedTags->getAll());
        $doSelectors = [];
        if(count($this->ctrl->affectedTags->filter(AffectedTags::SRC_ATTR))) {
            //add other monkeys like video poster here, if they were found when parsing the page.
            foreach(TagRules::_()->usedLazy() as $rule) {
                (SHORTPIXEL_AI_DEBUG & \ShortPixelAILogger::DEBUG_AREA_HTML) && $this->logger->log("RULE: ", $rule);
                if($rule->type == 'url') {
                    $selector = [
                        'selectors' => implode(',', array_keys($rule->used)),
                        'type' => 'attr',
                        'targetAttr' => $rule->attr,
                        'attrType' => $rule->callback == 'replace_custom_json_attr' ? 'json': 'url',
                        'lazy' => !$rule->frontEager,
                        'resize' => $rule->frontResize
                    ];
                    $doSelectors[] = $selector;
                }
            }
        }
        if(count($this->ctrl->affectedTags->filter(AffectedTags::CSS_ATTR)) && $this->settings->areas->backgrounds_lazy) {
            (SHORTPIXEL_AI_DEBUG & \ShortPixelAILogger::DEBUG_AREA_HTML) && $this->logger->log("IN TAG STYLE RULE: .spai-bg-on");
            $doSelectors[] = [
                'selectors' => '[data-spai-bg-on]',
                'type' => 'attr',
                'targetAttr' => 'style',
                'attrType' => 'style',
            ];
        }
        foreach(TagRules::_()->frontEndItems() as $rule) {
            $doSelector = [
                'selectors' => $rule->tag,
                'type' => 'attr',
                'targetAttr' => $rule->attr,
                'attrType' => 'url',
                'lazy' => !$rule->frontEager,
                'resize' => $rule->frontResize
            ];
            if($rule->attrFilter) {
                $doSelector['filter'] = ['attrName' => $rule->attrFilter, 'attrValue' => $rule->attrValFilter];
            }
            $doSelectors[] = $doSelector;
        }
        if($this->settings->areas->backgrounds_lazy_style) {
            (SHORTPIXEL_AI_DEBUG & \ShortPixelAILogger::DEBUG_AREA_HTML) && $this->logger->log("INLINE STYLE RULE");
            $doSelectors[] = [
                'selectors' => 'style',
                'type' => 'inner',
                'attrType' => 'style'
            ];
        }
        if($this->settings->areas->backgrounds_lazy_style && $this->settings->areas->parse_css_files > 0) {
            (SHORTPIXEL_AI_DEBUG & \ShortPixelAILogger::DEBUG_AREA_HTML) && $this->logger->log("STYLESHEET RULE");
            $doSelectors[] = ['type' => '__stylesheet'];
        }
        return $doSelectors;
    }

    /**
     * Builds `spaiData.exclusions` as a JSON string `{"selectors": {<sel>: props}, "urls": {<rule>: props}}`.
     *
     * Each entry starts from `{lazy: 1, cdn: 1, resize: 1, lqip: 0, crop: 0}` and is overridden by, in order:
     * `excluded_paths` lines -> urls `{lazy: 0, cdn: 0, resize: 0, crop: -1}`; `eager_paths` lines -> urls `{lazy: 0}`;
     * `excluded_selectors` -> selectors `{lazy: 0, cdn: 0, resize: 0, crop: -1}`; `noresize_selectors` (+ Modula
     * `.modula-creative-gallery img.pic`, WooCommerce `img.zoomImg`) -> `{resize: 0, crop: -1}`; `eager_selectors`
     * (+ Modula) -> `{lazy: 0}`. URL keys are the raw rule lines (`regex:...`, `path:...`, `domain:...`). Empty lists encode as `[]`.
     *
     * @return string JSON
     */
    protected function getExclusions() {
        //"div.excluded-lazy-lqip" : { lazy: 1, cdn:0, resize:0, lqip: 0, crop:-1},
        $exclusions = ['selectors' => [], 'urls' => []];
        $ai = ActiveIntegrations::_();
        $noresizeSelectors = $this->ctrl->splitSelectors( $this->settings->exclusions->noresize_selectors, ',');
        $eagerSelectors = $this->ctrl->splitSelectors( $this->settings->exclusions->eager_selectors, ',');

        if($ai->has('modula')) {
            //This is for the creative gallery, because it sets the images positions outside of the view and they are never replaced
            $noresizeSelectors[] = '.modula-creative-gallery img.pic';
            $eagerSelectors[] = '.modula-creative-gallery img.pic';
        }
        if($ai->has('woocommerce')) {
            $noresizeSelectors[] = 'img.zoomImg';
        }

        foreach($this->ctrl->splitSelectors( $this->settings->exclusions->excluded_paths, PHP_EOL) as $excludedPath) {
            $this->alterExclusion($exclusions, 'urls', $excludedPath,
                ['lazy' => 0, 'cdn' => 0, 'resize' => 0, 'crop' => -1]);
        }

        foreach($this->ctrl->splitSelectors( $this->settings->exclusions->eager_paths, PHP_EOL) as $eagerPath) {
            $this->alterExclusion($exclusions, 'urls', $eagerPath, ['lazy' => 0]);
        }

        foreach($this->ctrl->splitSelectors( $this->settings->exclusions->excluded_selectors, ',') as $excludedSel) {
            $this->alterExclusion($exclusions, 'selectors', $excludedSel,
                ['lazy' => 0, 'cdn' => 0, 'resize' => 0, 'crop' => -1]);
        }
        foreach($noresizeSelectors as $noresizeSel) {
            $this->alterExclusion($exclusions, 'selectors', $noresizeSel, ['resize' => 0, 'crop' => -1]);
        }
        foreach($eagerSelectors as $eagerSel) {
            $this->alterExclusion($exclusions, 'selectors', $eagerSel, ['lazy' => 0]);
        }

        return json_encode($exclusions);
    }

    /**
     * Creates `$exclusion[$type][$key]` with the defaults `{lazy: 1, cdn: 1, resize: 1, lqip: 0, crop: 0}` when missing, then merges `$props`.
     *
     * @param array  $exclusion Exclusions array, modified by reference
     * @param string $type      `selectors` or `urls`
     * @param string $key       Selector or URL rule
     * @param array  $props     Properties to override
     * @return void
     */
    protected function alterExclusion(&$exclusion, $type, $key, $props) {
        if(!isset($exclusion[$type][$key])) {
            $exclusion[$type][$key] =  ['lazy' => 1, 'cdn' => 1, 'resize' => 1, 'lqip' => 0, 'crop' => 0];
        }
        $exclusion[$type][$key] = array_merge($exclusion[$type][$key], $props);
    }

    /**
     * True when the buffered page contains `/assets/js/bundles/spai-lib-bg` (the inline loader's bundle URL).
     *
     * @param string $content Buffered HTML
     * @return bool
     */
    public function check($content)
    {
        return strpos($content, '/assets/js/bundles/spai-lib-bg') !== false;
    }

    /**
     * No-op in vanilla mode (the former inline style is commented out).
     *
     * @return void
     */
    public function fadeInCss()
    {
        /*
        wp_register_style( 'spai-fadein', false );
        wp_enqueue_style( 'spai-fadein' );
        //Exclude the .zoomImg's as it conflicts with rules of WooCommerce.
        wp_add_inline_style( 'spai-fadein',
            'html.DATA_SPAI_PLACEHOLDER_CLASS{background-image: none !important;}');
        */
    }
}
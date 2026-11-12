<?php
/**
 * TagRules registry.
 */

namespace ShortPixel\AI;

/**
 * Singleton holding the list of `TagRule`s that `ShortPixelRegexParser::parse()` applies
 * (items()) and the extra rules sent only to the front-end JS (frontEndItems()).
 *
 * The list is built once, in the constructor, from `ShortPixelAI::_()->settings` and a
 * freshly rebuilt `ActiveIntegrations::_( true )`; see __construct() for the full list.
 * Settings or active plugins changed later in the same request are not picked up.
 *
 * Static state a test must reset (via reflection) before building a new rule set:
 * `TagRules::$instance` (set back to false). Building also replaces the
 * `ActiveIntegrations::$instance` singleton and may add tags to
 * `ShortPixelAI::_()->affectedTags`.
 */
class TagRules
{
    /**
     * The singleton, or false before the first call to _().
     *
     * @var TagRules|false
     */
    private static $instance = false;

    /**
     * The main plugin controller.
     *
     * @var \ShortPixelAI
     */
    private $ctrl;
    /**
     * Rules for the regex parser, after the `shortpixel/ai/customRules` filter.
     *
     * @var TagRule[]
     */
    private $items;
    /**
     * Rules sent only to the front-end JS, after the `shortpixel/ai/customFrontendRules` filter.
     *
     * @var TagRule[]
     */
    private $frontEndItems;

    /**
     * Returns the singleton, building the rule list on the first call.
     *
     * @return TagRules
     */
    public static function _()
    {
        if (self::$instance === false) {
            self::$instance = new TagRules();
        }
        return self::$instance;
    }

    /**
     * Builds the regex-parser rule list and the front-end rule list.
     *
     * Notation: `tag attr` [class=`classFilter`] [attr `attrFilter`[=`attrValFilter`]]
     * {eager} {frontEager} (type/callback when not `url`/`replace_images`). Rules are
     * appended in this order; the parser applies them in array order.
     *
     * Base rules (always):
     * 1. `img|div data-src` {frontEager}. Placed first for images that have both src and data-src.
     * 2. `img data-srcset` {eager} (srcset / replace_custom_srcset).
     * 3. Only when `behaviour.replace_method` is not `srcset`: `img|amp-img src`, with
     *    mergeAttr `srcset` when replace_method is `src`, and {eager} when it is `both`.
     * 4. `img data-large-image`.
     * 5. `a href` class=`media-gallery-link`.
     * 6. `link href` attr `rel`=`icon` {eager}.
     * 7. `input src` attr `type`=`image`.
     * 8. `video|source poster`.
     * 9. `source src` {eager}.
     * 10. `img srcset` {eager} (srcset / replace_custom_srcset), for images with only srcset.
     * 11. `source srcset` (srcset / replace_custom_srcset).
     * 12. `div|figure|li data-thumb(?:nail|)` (data-thumb / replace_wc_gallery_thumbs).
     *
     * Per-integration rules (key from ActiveIntegrations):
     * - `nextgen`: rule 1 becomes `img|div|a data-src`; adds `a data-thumbnail` and
     *   `a href` (ahref / replace_link_href), {eager} when `behaviour.nojquery > 0`.
     * - `oxygen`: adds `a href` class=`oxy-gallery-item` {eager}; prepends `img data-original-src` {eager}.
     * - `modula`: `a href` attr `data-lightbox`, `a href` attr `data-fancybox`,
     *   `a href` class=`modula-item-link`, `img data-full` {eager}.
     * - `elementor`: `a href` attr `data-elementor-open-lightbox` {eager} with noFront=true,
     *   `a href` class=`viba-portfolio-media-link`, `header|section|div data-settings|data-options`
     *   (srcset / replace_custom_json_attr).
     * - `elementor-addons`: `a href` class=`eael-magnific-link`.
     * - `viba-portfolio` without `elementor`: `a href` class=`viba-portfolio-media-link`.
     * - `slider-revolution`: `img data-lazyload` {eager}; also adds `rs-bg-elem` to the background tag list.
     * - `envira`: `img data-envira-src`, `img data-safe-src`, `a href` class=`envira-gallery-link`,
     *   `img data-envira-srcset` (srcset / replace_custom_srcset).
     * - `everest`: `a href` attr `data-lightbox-type`.
     * - `wp-bakery`: `span data-element-bg` class=`dima-testimonial-image`; later also
     *   `'' data-ultimate-bg` with an empty tag (bg-attr / replace_bg_attr).
     * - `foo`: `img data-src-fg` class=`fg-image` {eager}; `a href` class=`fg-thumb` attr `data-attachment-id`.
     * - `global-gallery`: removes `a href` rules from the list built so far, then adds `a href`
     *   (constructed with mergeAttr=true, see the @todo).
     * - `essential-grid`: `img data-lazythumb` {eager}, `img data-lazysrc` {eager}.
     * - `smart-slider`: `div data-desktop` class=`n2-ss-slide-background-image`.
     * - `wp-grid-builder`: `div data-wpgb-src`.
     * - `content-views`: `img data-cvpsrc`.
     * - `the-grid`: `a data-tolb-src`.
     * - `acf`: `header data-background`; also adds `figure` to AffectedTags as CSS_ATTR.
     * - `wpc-variations`: `option data-imagesrc` (eager is the literal `false && true`, i.e. false).
     * - `soliloquy`: `img data-soliloquy-src` {eager}, `img data-soliloquy-src-mobile` {eager}.
     * - `jetpack`: `img data-orig-file` {eager}.
     * - `woocommerce`: `form|div data-product_variations` (srcset / replace_product_variations)
     *   with quickMatch `/\<(form|div)\b[^>]+\bdata-product_variations=/is`.
     * - `real3d-flipbook`: `div data-flipbook-options` {eager} (srcset / replace_custom_json_attr).
     * - `beaver-builder`: `div data-parallax-image`.
     * - `featherlight`: front-end only rule `a href` attr `data-featherlight`=`image` {eager}
     *   {frontEager}, frontResize=false.
     * - `lightbox-photoswipe`: `a href` attr `data-lbwps-srcsmall`, `a data-lbwps-srcsmall`.
     *
     * Background rule (always): `<bgTags> background-image|background` (bg-style /
     * replace_background_image_from_tag), where bgTags is
     * `div|span|body|article|section|i|p|h[1-6]|form|img|figure|a|header|li|lite-youtube|aside`
     * (plus `|rs-bg-elem` with `slider-revolution`). The list is explicit because `[\w]+`
     * triggers catastrophic PCRE backtracking.
     *
     * Special settings:
     * - `behaviour.replace_method` not `src`: another `img srcset` (srcset / replace_custom_srcset).
     * - `areas.parse_css_files > 0`: three eager link rules: `link href` attr `rel`=`stylesheet`,
     *   `link href` attr `rel`=`preload`, `link imagesrcset` attr `rel`=`preload`
     *   (srcset / replace_custom_srcset). Their compression is set to `orig` unless URLs inside
     *   the CSS are replaced, i.e. unless (`nojquery <= 0` or not `backgrounds_lazy_style`) and
     *   none of WP Rocket minify+css-filter, WP Fastest Cache, W3TC, WP-Optimize enable_css
     *   is active. With `nojquery > 0` each gets the addCrossOrigin() post-processor.
     * - `areas.lity`: `a href` attr `data-lity`, `a data-lity-target` attr `data-lity`.
     * - `wpzoom-theme`: `div|li data-bigimg` {eager}, `div|li data-smallimg` {eager}.
     *
     * Theme rules (by `ActiveIntegrations` `theme` name prefix, first match wins):
     * - `Blocksy*`: `a href` class=`ct-image-container` {eager}.
     * - `Uncode*`: `a href` attr `data-lbox` {eager}.
     * - exactly `CROWD 2` or `Lovely 2`: `div|a|span style` (srcset / replace_crowd2_img_styles).
     * - `Jupiter*`: `img data-mk-image-src-set` and `div data-mk-img-set` (srcset / replace_custom_json_attr).
     * - `Divi*`: adds `div` to AffectedTags as CSS_ATTR; `img data-et-multi-view` {eager}
     *   (srcset / replace_custom_json_attr).
     * - `Stack*`: adds `div` to AffectedTags as CSS_ATTR (no rule).
     *
     * Theme modules: `avia-gallery-module` adds `a href`, `a data-prev-img` and `a data-srcset`
     * (srcset / replace_custom_srcset), all class=`lightbox` {eager}.
     *
     * Finally the regex rules go through `shortpixel/ai/customRules` and the front-end rules
     * through `shortpixel/ai/customFrontendRules`, see applyFilterAndVerify().
     *
     * @todo __construct() removes the `a href` rules for `global-gallery` with a `for` loop whose
     *       condition re-evaluates `count($regexItems)` after each unset(), so the bound shrinks
     *       and trailing `a href` rules are never examined. Collect the keys first or use
     *       array_filter() and array_values().
     * @todo __construct() builds the `global-gallery` replacement rule as
     *       `new TagRule('a', 'href', false, false, false, true)`, which sets mergeAttr to true
     *       (the parser then builds `\s1=` regexes) instead of eager. Pass true as the seventh
     *       (eager) argument and false as mergeAttr.
     */
    protected function __construct() {
        $integrations = ActiveIntegrations::_( true )->getAll();
        $this->ctrl = \ShortPixelAI::_();
        $settings = $this->ctrl->settings;
        $logger = \ShortPixelAILogger::instance();
        $logger->log("BUILDING TAG RULES ....");
        if($settings->behaviour->replace_method !== 'srcset') {
            $imgTagRule = new TagRule('img|amp-img', 'src', false, false, false,
                /*mergeAttr: */($settings->behaviour->replace_method === 'src' ? 'srcset' : false), $settings->behaviour->replace_method === 'both');
        }

        $frontEndItems = array();
        $regexItems = array_merge( [
            new TagRule('img|div', 'data-src', false, false, false, false, false, 'url', false, false, true), //CHANGED ORDER for images which have both src and data-src - TODO better solution
            new TagRule('img', 'data-srcset', false, false, false, false, true,
                'srcset', 'replace_custom_srcset'),
        ],
        (isset($imgTagRule) ? [$imgTagRule] : []),
        [
            new TagRule('img', 'data-large-image'),
            new TagRule('a', 'href', 'media-gallery-link'), //this one seems generally related to sliders
            new TagRule('link', 'href', false, 'rel', 'icon', false, true),
            new TagRule('input', 'src', false, 'type', 'image', false), // only known case: an image-type input
            new TagRule('video|source', 'poster'),
            new TagRule('source', 'src', false, false, false, false, true),
            //for images that have only the srcset attribute present
            new TagRule('img', 'srcset', false, false, false, false, true,
                'srcset', 'replace_custom_srcset'),
            new TagRule('source', 'srcset', false, false, false, false, false,
                'srcset', 'replace_custom_srcset'),
            new TagRule('div|figure|li', 'data-thumb(?:nail|)', false, false, false, false, false,
                'data-thumb', 'replace_wc_gallery_thumbs')
        ] );
        if ($integrations['nextgen']) {
            //add |a to the img|div rule
            foreach($regexItems as $item) {
                if($item->tag === 'img|div') {
                    $item->tag = 'img|div|a';
                    break;
                }
            }
            $regexItems[] = new TagRule('a', 'data-thumbnail');
            //moved from RegexParser - Nextgen - check for <a href's>
            $regexItems[] = new TagRule('a', 'href', false, false, false, false, $settings->behaviour->nojquery > 0,
                'ahref', 'replace_link_href');
        }
        if ($integrations['oxygen']) {
            $regexItems[] = new TagRule('a', 'href', 'oxy-gallery-item', false, false, false, true);
            array_unshift($regexItems, new TagRule('img', 'data-original-src', false, false, false, false, true));
        }
        if ($integrations['modula']) {
            $regexItems[] = new TagRule('a', 'href', false, 'data-lightbox'); //fourth param filters by attribute
            $regexItems[] = new TagRule('a', 'href', false, 'data-fancybox');
            $regexItems[] = new TagRule('a', 'href', 'modula-item-link');
            $regexItems[] = new TagRule('img', 'data-full', false, false, false, false, true);
        }
        if ($integrations['elementor']) {
            // For the data-elementor-open-lightbox, we need to use eager as it conflicts with elementor's lazy-loading, if it's a lazy placeholder, their JS will break
            $openLB = new TagRule('a', 'href', false, 'data-elementor-open-lightbox', false, false,  true); //fourth param filters by attribute
            $openLB->noFront = true;
            $regexItems[] = $openLB;
            $regexItems[] = new TagRule('a', 'href', 'viba-portfolio-media-link'); //third param filters by class
            $regexItems[] = new TagRule('header|section|div', 'data-settings|data-options', false, false, false, false, false,
                'srcset', 'replace_custom_json_attr');
        }
        if ($integrations['elementor-addons']) {
            $regexItems[] = new TagRule('a', 'href', 'eael-magnific-link'); //fourth param filters by attribute
        }
        if ($integrations['viba-portfolio'] && !$integrations['elementor']) {
            $regexItems[] = new TagRule('a', 'href', 'viba-portfolio-media-link'); //third param filters by class
        }
        if ($integrations['slider-revolution']) {
            $regexItems[] = new TagRule('img', 'data-lazyload', false, false, false, false, true);
        }
        if ($integrations['envira']) {
            $regexItems[] = new TagRule('img', 'data-envira-src');
            $regexItems[] = new TagRule('img', 'data-safe-src');
            $regexItems[] = new TagRule('a', 'href', 'envira-gallery-link'); //third param filters by class
            $regexItems[] = new TagRule('img', 'data-envira-srcset', false, false, false, false, false,
                'srcset', 'replace_custom_srcset');
        }
        if ($integrations['everest']) {
            $regexItems[] = new TagRule('a', 'href', false, 'data-lightbox-type'); //fourth param filters by attribute
        }
        if ($integrations['wp-bakery']) {
            $regexItems[] = new TagRule('span', 'data-element-bg', 'dima-testimonial-image');  //third param filters by class
        }
        if ($integrations['foo']) {
            $regexItems[] = new TagRule('img', 'data-src-fg', 'fg-image', false, false, false, true);
            $regexItems[] = new TagRule('a', 'href', 'fg-thumb', 'data-attachment-id');
        }
        if ($integrations['global-gallery']) {
            for($i = 0; $i < count($regexItems); $i++) {
                if($regexItems[$i]->tag === 'a' && $regexItems[$i]->attr === 'href') {
                    unset($regexItems[$i]);
                }
            }
            $regexItems[] = new TagRule('a', 'href', false, false, false, true);
        }
        if ($integrations['essential-grid']) {
            $regexItems[] = new TagRule('img', 'data-lazythumb', false, false, false, false, true);
            $regexItems[] = new TagRule('img', 'data-lazysrc', false, false, false, false, true);
        }
        if ($integrations['smart-slider']) {
            $regexItems[] = new TagRule('div', 'data-desktop', 'n2-ss-slide-background-image');  //third param filters by class
        }
        if ($integrations['wp-grid-builder']) {
            $regexItems[] = new TagRule('div', 'data-wpgb-src');
        }
        if ($integrations['content-views']) {
            $regexItems[] = new TagRule('img', 'data-cvpsrc');
        }
        if ($integrations['the-grid']) {
            $regexItems[] = new TagRule('a', 'data-tolb-src');
        }
        /*
        if ($integrations['visual-product-configurator']) {
            $regexItems[] = new TagRule('input', 'data-img');
        }*/
        if ($integrations['acf']) {
            $regexItems[] = new TagRule('header', 'data-background');
        }
        if ($integrations['wpc-variations']) {
            $regexItems[] = new TagRule('option', 'data-imagesrc', false, false, false, false, false && true);
        }
        if ($integrations['soliloquy']) {
            $regexItems[] = new TagRule('img', 'data-soliloquy-src', false, false, false, false, true);
            $regexItems[] = new TagRule('img', 'data-soliloquy-src-mobile', false, false, false, false, true);
        }
        if ($integrations['jetpack']) {
            $regexItems[] = new TagRule('img', 'data-orig-file', false, false, false, false, true);
        }
        if($integrations['wp-bakery']) {
            SHORTPIXEL_AI_DEBUG && $logger->log("WP BAKERY data-ultimate-bg");
            $regexItems[] = new TagRule('', 'data-ultimate-bg', false, false, false, false, false,
                'bg-attr', 'replace_bg_attr');
        }

        if($integrations['woocommerce']) {
            $variationTags = 'form|div';
            $quickMatch = '/\<(' . $variationTags . ')\b[^>]+\bdata-product_variations=/is';
            //there are product variations
            $regexItems[] = new TagRule($variationTags, 'data-product_variations', false, false, false, false, false,
                'srcset', 'replace_product_variations', $quickMatch);
        }

        if($integrations['real3d-flipbook']) {
            $logger->log( 'REAL 3D FLIPBOOK: ENABLED' );
            $regexItems[] = new TagRule('div', 'data-flipbook-options', false, false, false, false, true,
                'srcset', 'replace_custom_json_attr');
        }

        if($integrations['acf']) {
            //ACF moves src's from hidden images to FIGURE backgrounds
            $this->ctrl->affectedTags->add('figure', AffectedTags::CSS_ATTR);
        }

        if($integrations['beaver-builder']) {
            $logger->log( 'BEAVER BUILDER: ENABLED' );
            $regexItems[] = new TagRule('div', 'data-parallax-image');
        }

        if($integrations['featherlight']) {
            $logger->log( 'FeatherLight: FRONT RULE ENABLED' );
            //{selectors: ['a'], type: 'attr', lazy: false, resize: false, origAttr: 'href', targetAttr: 'href', attrType: 'url', filter: [{attrName: 'data-featherlight', attrValue:'image'}]}
            $frontEndItems[] = new TagRule('a', 'href', false, 'data-featherlight', 'image', false, true,
                'url', false, false, true, false, false);
        }

        if($integrations['lightbox-photoswipe']) {
            $logger->log( 'Photoswipe: RULES ENABLED' );
            //{selectors: ['a'], type: 'attr', lazy: false, resize: false, origAttr: 'href', targetAttr: 'href', attrType: 'url', filter: [{attrName: 'data-featherlight', attrValue:'image'}]}
            $regexItems[] = new TagRule('a', 'href', false, 'data-lbwps-srcsmall');
            $regexItems[] = new TagRule('a', 'data-lbwps-srcsmall');
        }

        //BACKGROUND IMAGES
        //we need to specify the list of tags because on some situations PHP regex implementation throws catastrophic backtracking if we use [\w]+ instead...
        $bgTagsList = 'div|span|body|article|section|i|p|h[1-6]|form|img|figure|a|header|li|lite-youtube|aside';
        if($integrations['slider-revolution']) {
            $bgTagsList .= '|rs-bg-elem';
        }
        $regexItems[] = new TagRule($bgTagsList, 'background-image|background', false, false, false, false, false,
            'bg-style', 'replace_background_image_from_tag');

        //SPECIAL SETTINGS:
        if($settings->behaviour->replace_method !== 'src') { //srcset has to be checked too, in some cases the srcset wp hook isn't called...
            $regexItems[] = new TagRule('img', 'srcset', false, false, false, false, false,
                'srcset', 'replace_custom_srcset');
        }
        if ( $settings->areas->parse_css_files > 0)
        {
            $replaceUrls = ($settings->behaviour->nojquery <= 0 || !$settings->areas->backgrounds_lazy_style)
                && !( $integrations[ 'wp-rocket' ][ 'minify-css' ] && $integrations[ 'wp-rocket' ][ 'css-filter' ] )
                && !$integrations[ 'wp-fastest-cache' ] && !$integrations[ 'w3-total-cache' ]  && !$integrations[ 'wp-optimize']['enable_css'];

            $logger->log("CSS FILES TO CDN" . ($replaceUrls ? ' AND REPLACE URLs' : ''));

            $ssRule = new TagRule('link', 'href', false, 'rel', 'stylesheet',false,  true);
            if(!$replaceUrls) $ssRule->setCustomCompression('orig'); //only works with eager true
            if($settings->behaviour->nojquery > 0) $ssRule->setPostProcessor([$this, 'addCrossOrigin']);
            $regexItems[] = $ssRule;

            $preloadHrefRule = new TagRule('link', 'href', false, 'rel', 'preload', false, true);
            $preloadSetRule = new TagRule('link', 'imagesrcset', false, 'rel', 'preload', false, true,
                'srcset', 'replace_custom_srcset');
            if(!$replaceUrls) {
                $preloadHrefRule->setCustomCompression('orig');
                $preloadSetRule->setCustomCompression('orig');
            }
            if($settings->behaviour->nojquery > 0) {
                $preloadHrefRule->setPostProcessor([$this, 'addCrossOrigin']);
                $preloadSetRule->setPostProcessor([$this, 'addCrossOrigin']);
            }
            $regexItems[] = $preloadHrefRule;
            $regexItems[] = $preloadSetRule;
        }

        if ( !!$settings->areas->lity ) {
            $logger->log( 'LITY LIBRARY INTEGRATION: ENABLED' );
            $regexItems[] = new TagRule( 'a', 'href', false, 'data-lity' );
            $regexItems[] = new TagRule( 'a', 'data-lity-target', false, 'data-lity' );
        }

        //WPZOOM themes
        if($integrations['wpzoom-theme']) {
            $logger->log( 'WPZoom theme: ENABLED' );
            $regexItems[] = new TagRule('div|li', 'data-bigimg', false, false, false, false, true);
            $regexItems[] = new TagRule('div|li', 'data-smallimg', false, false, false, false, true);
        }

        //THEMES:
        $theme = $integrations['theme'];
        if(strpos($theme, 'Blocksy') === 0) {
            $logger->log("BLOCKSY ON");
            $regexItems[] = new TagRule('a', 'href', 'ct-image-container', false, false, false, true);
        }
        elseif(strpos($theme, 'Uncode') === 0) {
            $logger->log("Uncode ON");
            $regexItems[] = new TagRule('a', 'href', false, 'data-lbox', false, false, true);
        }
        elseif($theme == 'CROWD 2' || $theme == 'Lovely 2') {
            $regexItems[] = new TagRule('div|a|span', 'style', false, false, false, false, false,
                'srcset', 'replace_crowd2_img_styles');
        }
        elseif(strpos($theme, 'Jupiter') === 0) {
            //Jupiter has the mk slider in it, which uses srcsets encoded as JSON in data-mk-image-src-set atributes. How cool is that.
            $regexItems[] = new TagRule('img', 'data-mk-image-src-set', false, false, false, false, false,
                'srcset', 'replace_custom_json_attr');
            $regexItems[] = new TagRule('div', 'data-mk-img-set', false, false, false, false, false,
                'srcset', 'replace_custom_json_attr');
        }
        elseif(strpos($theme, 'Divi') === 0) {
            $this->ctrl->affectedTags->add('div', AffectedTags::CSS_ATTR);
            //Divi - Elegant Themes' custom attribute
            // changed to eager because in newer Divi versions the JS is adding a srcset that is not being replaced by the vanilla JS
            $regexItems[] = new TagRule('img', 'data-et-multi-view', false, false, false, false, true,
                'srcset', 'replace_custom_json_attr');
        }
        elseif(strpos($theme, 'Stack') === 0) {
            // Stack moves srcs from images to background-images of divs...
            $this->ctrl->affectedTags->add('div', AffectedTags::CSS_ATTR);
        }

        //Various modules that are included in themes
        if($integrations['avia-gallery-module']) { //the Avia gallery is included for example in the Enfold theme
            //it also has lightbox class - $regexItems[] = new TagRule('a', 'href', 'avia-gallery-big', false, false, false, true);
            $regexItems[] = new TagRule('a', 'href', 'lightbox', false, false, false, true);
            $regexItems[] = new TagRule('a', 'data-prev-img', 'lightbox', false, false, false, true);
            $regexItems[] = new TagRule('a', 'data-srcset', 'lightbox', false, false, false, true,
                'srcset', 'replace_custom_srcset');
        }

        $logger->log("FILTER TAG RULES");
        $this->items = $this->applyFilterAndVerify($regexItems, 'shortpixel/ai/customRules');
        $logger->log("TAG RULES: ", $this->items);
        $this->frontEndItems = $this->applyFilterAndVerify($frontEndItems, 'shortpixel/ai/customFrontendRules');
        $logger->log("FRONTEND TAG RULES: ", $this->frontEndItems);
    }

    /**
     * Runs a rule list through an extension filter and validates the result.
     *
     * Used for `shortpixel/ai/customRules` (regex-parser rules) and
     * `shortpixel/ai/customFrontendRules` (front-end only rules). The callback receives
     * the built `TagRule[]` and may return:
     * - a single `TagRule`: it is appended to the original list;
     * - an array: it replaces the list, so callbacks must return the rules they received
     *   plus their own. Each element must be a `TagRule`, or a legacy positional array of at
     *   least two entries `[0 tag, 1 attr, 2 classFilter, 3 attrFilter, 4 mergeAttr,
     *   5 attrValFilter, 6 eager]`, converted to `new TagRule(tag, attr, classFilter|false,
     *   attrFilter|false, attrValFilter|false, false, eager|true)` (missing eager means eager);
     * - anything else: ignored, the original list is kept.
     * If any element of a returned array is neither form, the whole filter result is
     * discarded and the original list is returned.
     *
     * Example: `add_filter( 'shortpixel/ai/customRules', function( $rules ) {
     *     $rules[] = [ 'div', 'data-bg', 'hero' ]; return $rules; } );`
     *
     * @param TagRule[] $items  Rules built by the constructor.
     * @param string    $filter Filter hook name.
     *
     * @return TagRule[]
     *
     * @todo applyFilterAndVerify() ignores index 4 (mergeAttr) of the legacy array shape and
     *       always passes false; pass `isset($extItem[4]) ? $extItem[4] : false` as the sixth
     *       TagRule argument.
     */
    protected static function applyFilterAndVerify($items, $filter) {
        $filteredItems = apply_filters( $filter, $items);
        if($filteredItems instanceof TagRule) {
            $items[] = $filteredItems;
            return $items;
        }
        if(!is_array($filteredItems)) {
            return $items;
        }

        $validatedItems = [];
        foreach($filteredItems as $extItem)
        {
            if($extItem instanceof TagRule) {
                $validatedItems[] = $extItem;
            }
            elseif(is_array($extItem) && count($extItem) >= 2) {
                //valid item
                $validatedItems[] = new TagRule($extItem[0], $extItem[1], isset($extItem[2]) ? $extItem[2] : false, isset($extItem[3]) ? $extItem[3] : false,
                    isset($extItem[5]) ? $extItem[5] : false, false, isset($extItem[6]) ? $extItem[6] : true);
            }
            else return $items;
        }
        return $validatedItems;
    }

    /**
     * Returns the regex-parser rules.
     *
     * @return TagRule[]
     */
    public function items(){
        return $this->items;
    }

    /**
     * Returns the rules sent only to the front-end JS (`VanillaJsLoader` doSelectors).
     *
     * @return TagRule[]
     */
    public function frontEndItems() {
        return $this->frontEndItems;
    }

    /**
     * Post-processor for the CSS link rules: adds `crossorigin="anonymous"` to the tag.
     *
     * Only when the tag has no `crossorigin=` yet and its `href` starts with the configured
     * CDN `behaviour.api_url`, so external stylesheets are left alone. The attribute is
     * inserted right after the tag name.
     *
     * @param string $tag Full rewritten tag, e.g. `<link rel="stylesheet" href="...">`.
     *
     * @return string The tag, possibly with the crossorigin attribute.
     */
    public function addCrossOrigin($tag) {
        //add the crossorigin attribute but only if it's from our CDN domain, otherwise it's an external CSS that we don't want to mess with.
        if(   strpos($tag, 'crossorigin=') === false
           && preg_match('/href=[\'"]?' . preg_quote($this->ctrl->settings->behaviour->api_url, '/') . '/', $tag)) {
            $tag = preg_replace('/^\s*<(\w+)/', '<$1 crossorigin="anonymous"', $tag);
        }
        return $tag;
    }

    /**
     * Returns the rules as a tree keyed by single tag name, plus a `*` entry for inline
     * style backgrounds handled by `ShortPixelCssParser::replace_in_tag_style_backgrounds`.
     *
     * Only the dead `ShortPixelSimpleDomParser` calls it.
     *
     * @return array<string,object[]> Tag => list of {attr, classFilter, attrFilter,
     *                                attrValFilter, mergeAttr, lazy[, customReplacer]}.
     *
     * @todo map() reads each rule with array offsets (`$rule[0]` … `$rule[6]`), but items() holds
     *       `TagRule` objects, so the first iteration is a fatal "Cannot use object of type
     *       TagRule as array" error. Read the TagRule properties (`tag`, `attr`, `classFilter`,
     *       `attrFilter`, `attrValFilter`, `mergeAttr`, `eager`), or delete map() together with
     *       the SimpleDom parser.
     */
    public function map() {
        $rules = $this->items;
        $tree = array();
        foreach($rules as $rule) {
            $tags = explode("|", $rule[0]);
            foreach($tags as $tag) {
                if(!isset($tree[$tag])) {
                    $tree[$tag] = array();
                }
                $ruleNode = array('attr' => $rule[1]);
                $ruleNode['classFilter'] = isset($rule[2]) ? $rule[2] : false;
                $ruleNode['attrFilter'] = isset($rule[3]) ? $rule[3] : false;
                $ruleNode['attrValFilter'] = isset($rule[5]) ? $rule[5] : false;
                $ruleNode['mergeAttr'] = isset($rule[4]) ? $rule[4] : false;
                $ruleNode['lazy'] = !isset($rule[6]) || ! $rule[6]? true : false;
                $tree[$tag][] = (object)$ruleNode;
            }
        }
        //add also the rule for background image
        $cssParser = $this->ctrl->getCssParser();
        $tree['*'] = array((object)array('attr' => 'style', 'lazy' => false, 'customReplacer' => array($cssParser, 'replace_in_tag_style_backgrounds')));
        return $tree;
    }

    /**
     * Returns the regex-parser rules that matched in this request and are lazy and sent to
     * the front end (non-empty `used`, not `eager`, not `noFront`). `VanillaJsLoader` turns
     * them into JS selectors.
     *
     * @return TagRule[]
     */
    public function usedLazy() {
        $used = [];
        foreach($this->items as $item) {
            if(count($item->used) && !$item->eager && !$item->noFront) {
                $used[] = $item;
            }
        }
        return $used;
    }

    /**
     * Marks the first rule whose `attr` equals `$attr` (string comparison, not regex) and
     * whose tag alternation contains `$tag` as used for that tag.
     *
     * No caller in the plugin uses it; the parsers write `TagRule::$used` directly.
     *
     * @param string $tag   Single tag name.
     * @param string $attr  Attribute exactly as stored in the rule.
     * @param int    $flags `AffectedTags` flag bits.
     *
     * @return void
     */
    public function setUsed($tag, $attr, $flags) {
        foreach($this->items as $item)  {
            if($item->attr == $attr && in_array($tag, explode('|', $item->tag))) {
                $item->used[$tag] = $flags;
                return;
            }
        }
    }
}
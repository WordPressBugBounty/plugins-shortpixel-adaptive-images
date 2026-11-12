<?php
/**
 * Regex-based HTML rewriter: the only parser the plugin uses on front-end output.
 *
 * `ShortPixelAI::__construct()` instantiates it (the parser is hardcoded, see the
 * dead `ShortPixelSimpleDomParser`) and `ShortPixelAI::maybe_replace_images_src()`
 * calls {@see ShortPixelRegexParser::parse()} on every HTML output buffer. The JSON
 * parser and the `text/template` branch create further instances for nested HTML.
 *
 * @package ShortPixel\AI
 */

use ShortPixel\AI\ActiveIntegrations;
use ShortPixel\AI\AffectedTags;
use \ShortPixel\AI\TagRule;
use ShortPixel\AI\Options;

/**
 * Rewrites image, background, font, CSS and JS URLs in an HTML string.
 *
 * The rules (which tag/attribute pairs to rewrite, and how) come from the
 * `\ShortPixel\AI\TagRules` singleton. Each rule's `type` selects one of the
 * regex templates in {@see ShortPixelRegexParser::$regexes}, and its `callback`
 * names the method of this class that rewrites each match
 * (default {@see ShortPixelRegexParser::replace_images()}).
 *
 * Collaborators: `ShortPixelAI` (settings, options, `get_api_url()`, `urlIsApi()`,
 * `urlIsExcluded()`, `urlIsEager()`, `tagIs()`, `affectedTags`), `ShortPixelCssParser`
 * (`<style>` blocks and inline backgrounds), `ShortPixelJsParser` (inline scripts),
 * `ShortPixelJsonParser` (`application/json` scripts, JSON attributes),
 * `ShortPixelUrlTools` (validation, sizes, placeholders), `\ShortPixel\AI\JsLoader`
 * (`addTagData()` at the end of `parse()`).
 *
 * State: every property below is per instance and is reset by `parse()` except
 * `$isTemplate`, `$forceEager` and `$tagRule`. Tests that reuse
 * `ShortPixelAI::_()->getRegexParser()` must also reset the `TagRules` singleton and
 * `ShortPixelUrlTools::$SIZE_CACHE`, and note that `parse()` raises
 * `pcre.backtrack_limit` to 2000000 for the rest of the request.
 *
 * Markers this class emits (consumed by the front-end JS):
 * - `data-spai="1"`: lazy element; the attribute holds an SVG placeholder
 *   (`data:image/svg+xml;base64,...` carrying `data-u`, see
 *   `ShortPixelUrlTools::generate_placeholder_svg()`), the JS builds the CDN URL.
 * - `data-spai-egr="1"`: eager element; the attribute already holds the CDN URL.
 * - `data-spai-excluded="true"`: element skipped because of a skip attribute.
 * - `data-spai-target="src" data-spai-orig="<url>" data-spai-exclude="nocdn"`:
 *   `<img>` whose URL matches `excluded_paths`.
 * - `<noscript data-spai="1">`: eager copy of a lazy `<img>`.
 * - `loading="lazy"`: native lazy loading (see `_replace_images()` and
 *   `replace_custom_srcset()`).
 * - `data-lity-target="<cdn url>"`: Lity lightbox target for lazy `<img data-lity>`.
 * Background markers `data-spai-bg-on` / `data-spai-bg-prepared` come from
 * `ShortPixelCssParser`.
 */
class ShortPixelRegexParser {
    /**
     * @var ShortPixelAI The plugin controller (settings, options, URL helpers).
     */
    protected $ctrl;
    /**
     * @var ShortPixelAILogger Shared logger instance.
     */
    private $logger;
    /**
     * @var ShortPixelCssParser Own CSS parser instance (not the controller's), created in the constructor.
     */
    private $cssParser;

    /**
     * @var string[] `<script>...</script>` blocks extracted by `parse()`; index N matches the
     *               placeholder `<script>__sp_script_plAc3h0ldR_N</script>`.
     */
    private $scripts;
    /**
     * @var string[] `<style>...</style>` blocks; placeholder `<style>__sp_style_plAc3h0ldR_N</style>`.
     */
    private $styles;
    /**
     * @var string[] `<![CDATA[ ... ]]>` blocks; placeholder `<![CDATA[\n__sp_cdata_plAc3h0ldR_N\n]]>`.
     *               `replace_cdata_js()` replaces entries in place with the JS-parsed version.
     */
    private $CDATAs;
    /**
     * @var string[] `<noscript>...</noscript>` blocks; placeholder `<noscript>__sp_noscript_plAc3h0ldR_N</noscript>`.
     */
    private $noscripts;
	/**
	 * @var string[] Bodies of quoted attribute values that contain `>`; index N matches the
	 *               placeholder `__sp_attribute_plAc3h0ldR_N_` (see `isolate_attributes()`).
	 */
	private $attributes;

	/**
	 * @var TagRule The rule being applied by the current `preg_replace_callback()` pass.
	 *              Not initialised before the first rule; set to `new TagRule(false, false)`
	 *              (no filters, lazy) after the rule loop and for the noscript pass.
	 */
	private $tagRule;

    /**
     * @var bool True when the current rule (or `$forceEager`) makes every match eager.
     *           Also forced to true temporarily while a `<noscript>` copy is generated.
     */
    private $isEager = false;
    /**
     * @var bool Forces every rule eager and disables native lazy. Set only on the nested
     *           parser created for `text/x-jsrender` templates.
     */
    private $forceEager = false;
    /**
     * @var bool True on the nested parser for `text/x-jsrender` templates: URLs that contain
     *           `{{:` are accepted although invalid, size detection is skipped, and a URL that
     *           starts with `{{:` is emitted unescaped after the CDN prefix.
     */
    private $isTemplate = false;
    /**
     * @var string The content after block extraction and attribute isolation, before the rule
     *             loop. Read by `_replace_images()` to choose the `,` separator for preload links.
     */
    private $content;


    /**
     * Regex templates keyed by `TagRule::$type`. `{{TAG}}` and `{{ATTR}}` are replaced
     * verbatim (not quoted) with `TagRule::$tag` / `TagRule::$attr`, so both may be regex
     * alternations such as `img|amp-img` or `data-thumb(?:nail|)`.
     *
     * Capture groups per type (what the callbacks receive):
     * - `url`: `<TAG ... ATTR = "v"|'v'|v ...>`. 1 tag, 2 attribute name, 3 quote (`"`, `'`
     *   or empty), 4 quoted value, 5 unquoted value (set only when unquoted). Values must not
     *   contain `<`, `>`, `'` or `"`. Example: `<img class="a" src="/a.jpg">` gives
     *   [1]=`img`, [2]=`src`, [3]=`"`, [4]=`/a.jpg`.
     * - `data-thumb`: `<TAG ... ATTR="v" ...>`. 1 tag, 2 value (any char, lazy, up to the next quote).
     * - `srcset`: `<TAG ... ATTR="v"|'v' ...>`. 1 tag, 2 attribute, 3 `"`, 4 value, or
     *   5 `'`, 6 value. Used by every callback that receives a whole attribute value
     *   (srcset, JSON attributes, CROWD 2 styles, product variations).
     * - `bg-attr`: `<tag ... ATTR=...url(v)` or `...-gradient(v)` (any tag; `{{TAG}}` unused).
     *   1 tag, 2 attribute, 3 `=` plus opening quote and any text, 4 `url` or gradient name,
     *   5 value, 6 closing quote. The match ends at the `)`, not at `>`.
     * - `bg-style`: `<TAG ... ATTR: ... url(v) ...>` inside an inline style. 1 tag, 2 text
     *   before the property, 3 property, 4 `:` and text before `url(`, 5 `url` or gradient
     *   name, 6 value, 7 quote, 8 rest of the tag.
     * - `ahref`: `<a ... href=v ...>` (NextGen). 1 quote (may be empty), 2 value.
     *
     * @var array<string,string>
     */
    private $regexes = [
        //this one is used for attributes having regular URL inside (img src, etc)
        'url'      => '/\<({{TAG}})(?:\s|\s[^\<\>]*?\s)({{ATTR}})\s*\=\s*(?:(\"|\')([^\>\'\"]+)(?:\'|\")|([^\>\'\"\s]+))(?:.*?)\>/s',
        'data-thumb' => '/\<({{TAG}})[^\<\>]*?\b{{ATTR}}\=(?:\"|\')(.+?)(?:\"|\')(?:.+?)\>/s',
        //this one is used for srcsets
        'srcset'   => '/\<({{TAG}})(?:\s|\s[^\<\>]*?\s)({{ATTR}})\s*\=\s*(?:(\")([^\"\>]+?)\"|(\')([^\'\>]+?)\')(?:.*?)\>/s',
        //this one is used for any tag that has a specific attribute with css background format
        'bg-attr'  => '/\<([\w]+)(?:[^\<\>]*?)\b({{ATTR}})(=(?:"|\'|)[^"\']*?)(url|[\w-]+-gradient)\((?:\'|")?([^\'"\)]*)(\'|")?\s*\)/s',
        //elements with style="background ...."
        'bg-style' => '/\<({{TAG}})(?![\w-])([^\<\>]*?)\b({{ATTR}})(\s*:(?:[^;]*?[,\s]|\s*))(url|[\w-]+-gradient)\((?:\'|")?([^\'"\)]*)(\'|")?\s*\)(.*?)\>/s', //original
        //used for NextGen
        'ahref' => '/\<a[^\<\>]*?\shref\=(\"|\'?)(.+?)(?:\"|\')(?:.+?)\>/s',
    ];


    /**
     * Flags an `<img>` whose URL matches `excluded_paths` so the front-end JS leaves it alone.
     *
     * Appends ` data-spai-target="src" data-spai-orig="<esc_attr($origUrl)>" data-spai-exclude="nocdn"`
     * before the `>` of the FIRST `<img` tag in `$tagHtml` (case-insensitive). Any other tag
     * (`<a href>`, `<div data-src>`, ...) is returned unchanged. The URL itself is not touched.
     *
     * Example: `<img src="/x.jpg">` with `/x.jpg` gives
     * `<img src="/x.jpg" data-spai-target="src" data-spai-orig="/x.jpg" data-spai-exclude="nocdn">`.
     *
     * @todo markTotalExclude() writes `data-spai-target="src"` whatever the rule attribute is
     *       (srcset, data-src, ...), and appends after a self-closing `/` (`<img ... / data-spai-...>`).
     *       Pass the attribute name in and insert before an optional `/`.
     *
     * @param string $tagHtml The matched tag text.
     * @param string $origUrl The URL as found in the attribute (not entity-decoded).
     * @return string The tag with the exclusion markers, or `$tagHtml` unchanged.
     */
    protected function markTotalExclude(string $tagHtml, string $origUrl): string
    {
        $attrs  = ' data-spai-target="src"';
        $attrs .= ' data-spai-orig="' . esc_attr($origUrl) . '"';
        $attrs .= ' data-spai-exclude="nocdn"';
        return preg_replace(
            '/<img\b([^>]*)>/i',
            '<img$1' . $attrs . '>',
            $tagHtml,
            1
        );
    }

    /**
     * @param ShortPixelAI $ctrl The plugin controller. Its settings are read on every call, not cached.
     */
    public function __construct(ShortPixelAI $ctrl)
    {
        $this->ctrl = $ctrl;
        $this->logger = ShortPixelAILogger::instance();
        $this->cssParser = new ShortPixelCssParser($ctrl);
    }

    /**
     * Builds the regex that detects a tag the user asked SPAI to skip.
     *
     * Grammar of the returned pattern (case-insensitive, matched against the start of the text):
     * ```
     * /^<[^>]*( SKIP_NAME [ \s*=\s*["']?(true|1|yes)?["']? ]
     *         | \b(data-optimized|data-cfasync)\b\s*=\s*["']?(false|0|no)["']?
     *         [ | type\s*=\s*["']module["'] ]          (only when $includeModuleType)
     *        )/i
     * SKIP_NAME = \bdata-no-optimize\b | \bdata-noptimize\b | \bdata-no-ptimize\b
     *           | \bdata-no-minify\b   | \bdata-spai-excluded\b
     * ```
     * Only the first tag of the text is inspected (`[^>]*` stops at the first `>`). The
     * value part after a SKIP_NAME is optional and the pattern is not anchored after it, so
     * the mere presence of a SKIP_NAME skips the tag whatever its value
     * (`data-no-optimize="false"` skips too). `data-optimized` / `data-cfasync` skip only
     * with `false`, `0` or `no`. Names also match inside attribute values (`alt="data-noptimize"`).
     *
     * Example: `<script data-cfasync="false" src="a.js">` matches; `<img data-cfasync="true">` does not.
     *
     * @todo skipAttributesRegex() skips on any value of a SKIP_NAME although the inline comment
     *       says only true/1/yes/empty; `data-spai-excluded="false"` therefore excludes. Anchor the
     *       end of the attribute after the optional value group (append `(?=[\s\/>])`) or drop
     *       the claim from the comment.
     *
     * @param bool $includeModuleType Also match `type="module"` (used for `<script>` tags).
     * @return string The regex pattern.
     */
    private static function skipAttributesRegex($includeModuleType = false) {

        $skipAttributesPattern =
            // qttributes that cause skipping when present, optionally with value 'true', '1', 'yes', or empty
            '(\bdata-no-optimize\b|\bdata-noptimize\b|\bdata-no-ptimize\b|\bdata-no-minify\b|\bdata-spai-excluded\b)'
            . '(?:\s*=\s*["\']?(true|1|yes)?["\']?)?'
            // attributes that cause skipping when value is 'false', '0', or 'no'
            . '|\b(?:data-optimized|data-cfasync)\b\s*=\s*["\']?(false|0|no)["\']?';

        $skipModulePattern = '|type\s*=\s*["\']module["\']';
        // the common regex pattern
        $skipRegex = '/^<[^>]*(' . $skipAttributesPattern;
        //  regex for type="module"
        if ($includeModuleType) {
            $skipRegex .= $skipModulePattern;
        }
        $skipRegex .= ')/i';
        return $skipRegex;
    }

    /**
     * Checks the first tag of `$tagText` against {@see skipAttributesRegex()} and marks it.
     *
     * When the skip regex matches and the text has no `data-spai-excluded="true"` (or `'true'`)
     * yet, ` data-spai-excluded="true"` is inserted before the first `>` of the first tag
     * (`/<(\w+)([^>]*)>/i`, once). An existing `data-spai-excluded` with another value gets a
     * second attribute. For a self-closing tag the marker lands after the `/`
     * (`<img src="a" / data-spai-excluded="true">`), which HTML parsers tolerate.
     *
     * Used by `parse()` on `<style>` blocks (`$includeModuleType` false) and `<script>` blocks
     * (true), and by `_replace_images()` on each matched tag (true only for `script` tags).
     *
     * @todo addSpaiExcludedIfNotPresent() inserts the marker after a self-closing `/`; capture
     *       an optional `\s*\/?` before `>` and insert in front of it.
     *
     * @param string &$tagText          The tag or block text; modified in place when skipped.
     * @param bool   $includeModuleType Also treat `type="module"` as a skip attribute.
     * @return bool True when the text must be left unprocessed.
     */
    private function addSpaiExcludedIfNotPresent(&$tagText, $includeModuleType): bool {
        if (preg_match($this->skipAttributesRegex($includeModuleType), $tagText)) {
            if (!preg_match('/data-spai-excluded\s*=\s*["\']true["\']/', $tagText)) {
                $tagText = preg_replace(
                    '/<(\w+)([^>]*)>/i',
                    '<$1$2 data-spai-excluded="true">',
                    $tagText,1
                );
            }
            return true; // tag excluded, we continue with next step
        }
        return false; // tag is not excluded
    }

    /**
     * Rewrites every URL the active tag rules cover in an HTML document or fragment.
     *
     * Input grammar: any string; `ShortPixelAI::maybe_replace_images_src()` passes only content
     * that its HTML sniff regex accepts, nested callers pass fragments (template bodies, JSON
     * leaves, urldecoded `data-thumb` values). Matching is case-sensitive for the block markers
     * (`<script`, `<style`, `<noscript`, `</head>`).
     *
     * Pipeline, in this order:
     * 1. Preconnect: every `</head>` becomes
     *    `<link href="<scheme|https>://<host of settings.behaviour.api_url>" rel="preconnect" crossorigin></head>`.
     *    Runs before extraction, so a `</head>` inside a script string gets one too.
     * 2. CDATA: `// <![CDATA[` is rewritten to `/* <![CDATA[ *\/` and `//]]>` to `/* ]]> *\/`, then
     *    {@see extract_blocks()} moves each `<![CDATA[ ... ]]>` into `$CDATAs`, leaving
     *    `<![CDATA[\n__sp_cdata_plAc3h0ldR_N\n]]>`. A second `<![CDATA[` before `]]>` drops the open block.
     * 3. `<noscript ...>...</noscript>` into `$noscripts`, leaving `<noscript>__sp_noscript_plAc3h0ldR_N</noscript>`
     *    (scripts and styles inside a noscript stay in it).
     * 4. `<script ...>...</script>` into `$scripts`, leaving `<script>__sp_script_plAc3h0ldR_N</script>`.
     * 5. `<style ...>...</style>` into `$styles`, leaving `<style>__sp_style_plAc3h0ldR_N</style>`.
     * 6. {@see isolate_attributes()}: quoted attribute values containing `>` become
     *    `__sp_attribute_plAc3h0ldR_N_` (always, whatever the inline comment says about noscript).
     * 7. `ini_set('pcre.backtrack_limit', 2000000)`, never restored.
     * 8. Rule loop over `\ShortPixel\AI\TagRules::_()->items()`, in order: a rule with a
     *    `quickMatch` regex that does not match the current content is skipped; otherwise the
     *    template `$regexes[$rule->type]` gets `{{TAG}}`/`{{ATTR}}` substituted, `$tagRule` and
     *    `$isEager = $forceEager || $rule->eager` are set, and `preg_replace_callback()` runs with
     *    `$rule->callback` (default `replace_images`). A PCRE failure (null/empty result, e.g.
     *    backtrack limit on huge attributes) keeps the content from before that rule.
     * 9. `$tagRule` is reset to `new TagRule(false, false)`, then {@see revert_attributes()}.
     * 10. Styles: unless skipped by {@see addSpaiExcludedIfNotPresent()} (no module check),
     *     `ShortPixelCssParser::replace_inline_style_backgrounds()` then
     *     `replace_inline_style_fonts()`; the block replaces its placeholder.
     * 11. Noscripts: `$tagRule` reset, then the `url` regex for `img src` with
     *     {@see replace_images()} and the `srcset` regex for `img srcset` with
     *     {@see replace_custom_srcset()}; the block replaces its placeholder.
     * 12. Scripts, unless skipped (skip attributes or `type="module"`):
     *     a. `settings.areas.js2cdn`: a `src` URL whose host equals
     *        `ShortPixelDomainTools::get_site_domain()` and that is not already on the CDN is
     *        replaced (every occurrence in the block) by
     *        `get_api_url(absoluteUrl($url), false, false, <ext>, false, true, cssCacheVer)`
     *        (`ret_auto`, `v_N`). Relative `src` values have no host and are left alone.
     *     b. `settings.areas.parse_json` and opening tag `type="application/json"` or
     *        `"application/ld+json"`: {@see replace_application_json_script()}.
     *     c. else `settings.areas.parse_js`:
     *        - block contains a CDATA placeholder: {@see replace_cdata_js()} parses the stored
     *          CDATA (the block text itself is not changed);
     *        - opening tag has `type="(application|text)/javascript` or no `type=`:
     *          `ShortPixelJsParser::parse()` (minifies, then rewrites image URLs);
     *        - `type="text/template`: body JSON-decoded; non-JSON is entity-decoded when it starts
     *          with `&lt;`, parsed by a new `ShortPixelRegexParser` and re-encoded with
     *          `htmlentities()`; JSON arrays/objects go through `ShortPixelJsonParser`; JSON
     *          scalars through a new regex parser; result is `<opening tag><parsed></script>`;
     *        - `type="text/x-jsrender`: body parsed by a new regex parser with
     *          `$isTemplate = $forceEager = true`.
     *     The block replaces its placeholder.
     * 13. CDATA placeholders (with and without the `//` before `]]>`) are restored.
     * 14. When `settings.behaviour.nojquery <= 0` (jQuery mode) `img` is removed from `affectedTags`.
     * 15. `\ShortPixel\AI\JsLoader::_($ctrl)->addTagData()` injects the selectors, exclusions and
     *     background CSS (vanilla) or `spai_affectedTags` (jQuery) at `</body>` or into their placeholders.
     *
     * Output grammar: the input with, per rewritten attribute, either a lazy SVG placeholder plus
     * `data-spai="1"` or an eager CDN URL plus `data-spai-egr="1"` (CDN URL grammar: see
     * ShortPixelAI::get_api_url()), the other markers listed on the class, the preconnect link,
     * and the `addTagData()` additions. Fragments without `</head>`/`</body>` get neither injection.
     *
     * Example (lazy, replace_method `src`, vanilla mode, no LQIP):
     * `<head></head><img src="https://example.test/wp-content/uploads/a-300x200.jpg">` gives
     * `<head><link href="https://cdn.shortpixel.ai" rel="preconnect" crossorigin></head><img src="data:image/svg+xml;base64,B64" data-spai="1">`
     * where B64 is `base64_encode()` of `ShortPixelUrlTools::PX_SVG_TEMPLATE` with
     * `%WIDTH%`=300, `%HEIGHT%`=200 (from the `-300x200` suffix) and `%URL%`=`urlencode()` of the URL.
     *
     * @todo parse() comments that noscript blocks are replaced eagerly but the noscript loop never
     *       sets `$isEager`; it keeps the value of the last rule in the loop, so `<noscript>` images
     *       get lazy SVG placeholders (and, with generate_noscript on, a nested `<noscript>`) unless
     *       that last rule happens to be eager. Set `$this->isEager = true` before the noscript loop
     *       and restore it after.
     * @todo parse() reads `$apiUrl['host']` without checking it exists; an `api_url` without a host
     *       emits a warning and `href="https://"`. Skip the preconnect when the host is missing.
     * @todo parse() on nested parsers (templates, JSON leaves, `data-thumb` HTML) also runs the
     *       preconnect and `addTagData()` steps; harmless while fragments lack `</head>`/`</body>`.
     *
     * @param string $content HTML to rewrite.
     * @return string The rewritten HTML.
     */
    public function parse($content) {
        $this->logger->log("******** REGEX PARSER BB ********* ");

        //add the preconnect header for faster loading of the images
        $apiUrl = parse_url($this->ctrl->settings->behaviour->api_url);
        $content = str_replace('</head>', '<link href="' . (isset($apiUrl['scheme'])?$apiUrl['scheme']: 'https') . '://' . $apiUrl['host'] . '" rel="preconnect" crossorigin></head>',
            $content);
        // EXTRACT all CDATA and inline script to be reinserted after the replaces
        // -----------------------------------------------------------------------

        $this->CDATAs = array();
        $content = preg_replace('/\/\/\s*<!\[CDATA\[/s', '/* <![CDATA[ */', $content);
        $content = preg_replace('/\/\/\]\]>/s', '/* ]]> */', $content);
        $content = $this->extract_blocks($content, '__sp_cdata', '<![CDATA[', ']]>', $this->CDATAs, true, '<![CDATA[');

        //<noscript> blocks will have URLs replaced but eagerly
        $this->noscripts = array();
        $content = $this->extract_blocks($content, '__sp_noscript', '<noscript.', '</noscript>', $this->noscripts);

        $this->scripts = array();
        $content = $this->extract_blocks($content, '__sp_script', '<script.', '</script>', $this->scripts);
        //$content = preg_replace_callback(
        ////     this part matches the scripts of the page, we don't replace inside JS
        //    '/\<script(.*)\<\/script\>/sU', // U flag - UNGREEDY
        //    array($this, 'replace_scripts'),
        //    $content
        //);
        $this->styles = array();
        $content = $this->extract_blocks($content, '__sp_style', '<style.', '</style>', $this->styles);
/*        $content = preg_replace_callback(
        //     this part matches the styles of the page, we replace inside CSS afterwards.
            '/\<style(.*)\<\/style\>/sU', // U flag - UNGREEDY
            array($this, 'replace_styles'),
            $content
        );
*/
	    /**
	     * Isolate the attributes which contain the ">" sign
	     * to avoid the parse conflicts and corrupt the DOM
	     * if "Generate Noscript" options is enabled
	     */
	    $this->attributes = [];
	    $content = $this->isolate_attributes( $content );
	    /*
	     * Old implementation using RegEx
	    $content = preg_replace_callback( '/=(["\'`])(?!\1)((?:(?![^\\\\]\1).)*?(?:>|>.*?(?:[^\\\\])))\1/s', [ $this, 'isolate_attributes' ], $content );
	    */

	    SHORTPIXEL_AI_DEBUG && $this->logger->log( "CHECK 1: " . strlen( $content ) );

        // Replace different cases of image URL usages
        // -------------------------------------------

        /* $content = preg_replace_callback(
        //     this part matches URLs without quotes
            '/\<img[^\<\>]*?\ssrc\=([^\s\'"][^\s>]*)(?:.+?)\>/s',
            array( $this, 'replace_images_no_quotes' ),
            $content
        ); */

        //In some cases the regex fails with this limit reached (default is 1000000) so make it larger
        ini_set('pcre.backtrack_limit', 2000000);

        $this->content = $content;

        foreach (\ShortPixel\AI\TagRules::_()->items() as $tagRule) {
            SHORTPIXEL_AI_DEBUG && $this->logger->log("******** TAG RULE IS : ", $tagRule);
            if($tagRule->quickMatch && !preg_match($tagRule->quickMatch, $content)) continue;
            $regex = str_replace(array('{{TAG}}', '{{ATTR}}'), array($tagRule->tag, $tagRule->attr), $this->regexes[$tagRule->type]);
            //$this->logger->log("REGEX: $regex");
            $this->tagRule = $tagRule;
            $this->isEager = $this->forceEager || $tagRule->eager;

    	    SHORTPIXEL_AI_DEBUG && $this->logger->log("******** REGEX IS : " . $regex
                . ($this->tagRule->classFilter ? " CLASS FILTER: " . $this->tagRule->classFilter : '')
                . ($this->tagRule->attrFilter ? " ATTR FILTER: " . $this->tagRule->attrFilter . ($this->tagRule->attrValFilter ? '=' . $this->tagRule->attrValFilter : '') : '')
                . " Content len: " . strlen($content));

    	    $callback = $tagRule->callback ? $tagRule->callback : 'replace_images';
    	    $contentReplaced = preg_replace_callback($regex,
                array($this, $callback),
                $content
            );

            if(strlen($contentReplaced)) { // better safe than sorry
                $content = $contentReplaced;
            } else {
                //this can happen if there are HUGE tags attributes like for example a <form with data-product_variations having a large number of variations
                SHORTPIXEL_AI_DEBUG && $this->logger->log("******** REGEX PARSER FAILED using regex: $regex, PREG ERROR:"
                    . preg_last_error() . " bkt.limit:" . ini_get('pcre.backtrack_limit') . " rec.limit:" . ini_get('pcre.recursion_limit')
                    . " TAG RULE: ", $tagRule);
            }
        }

        //reset the current tag rule
        $this->tagRule = new TagRule(false, false);

        SHORTPIXEL_AI_DEBUG && $this->logger->log("******** REGEX PARSER put back the styles, (no)scripts and CDATAs. Content len: " . strlen($content));

        // put the isolated attributes back
        $content = $this->revert_attributes( $content );

        //put back the styles, scripts and CDATAs.
        for ($i = 0; $i < count($this->styles); $i++) {
            $style = $this->styles[$i];
            //$this->logger->log("STYLE $i: $style");

            // this must skip replacing css with data-no-optimize or data-no-minify code in it
            if (!$this->addSpaiExcludedIfNotPresent($style, false)) {
                //replace all the background-images
                $style = $this->cssParser->replace_inline_style_backgrounds($style);
                //replace inline fonts
                $style = $this->cssParser->replace_inline_style_fonts($style);

                SHORTPIXEL_AI_DEBUG && $this->logger->log("STYLE $i REPLACED:  Content len: " . strlen($content));
                (SHORTPIXEL_AI_DEBUG & ShortPixelAILogger::DEBUG_AREA_CSS)
                && $this->logger->log("STYLE: " . $style);

            }
            $content = str_replace("<style>__sp_style_plAc3h0ldR_$i</style>", $style, $content);
        }
        //handle the noscripts in a simpler (only <img>) and eager way.
        for ($i = 0; $i < count($this->noscripts); $i++) {
            $noscript = $this->noscripts[$i];
            $regex = str_replace(array('{{TAG}}', '{{ATTR}}'), array('img', 'src'), $this->regexes['url']);
            $this->logger->log("NOSCRIPT: $noscript");
            $this->tagRule = new TagRule(false, false);
            $noscript = preg_replace_callback($regex,
                array($this, 'replace_images'),
                $noscript
            );
            SHORTPIXEL_AI_DEBUG && $this->logger->log("NOSCRIPT SRC: $noscript Content len: " . strlen($content));
            $regex = str_replace(array('{{TAG}}', '{{ATTR}}'), array('img', 'srcset'), $this->regexes['srcset']);
            $noscript = preg_replace_callback($regex,
                array($this, 'replace_custom_srcset'),
                $noscript
            );
            SHORTPIXEL_AI_DEBUG && $this->logger->log("NOSCRIPT SRCSET: $noscript Content len: " . strlen($content));
            $content = str_replace("<noscript>__sp_noscript_plAc3h0ldR_$i</noscript>", $noscript, $content);
        }

        for ($i = 0; $i < count($this->scripts); $i++) {
            SHORTPIXEL_AI_DEBUG && $this->logger->log("SCRIPT $i Content len: " . strlen($content));
            $script = $this->scripts[$i];

            // must skip from replacing when scripts use data-no-optimize, data-no-minify, type="module"
            if (!$this->addSpaiExcludedIfNotPresent($script, true)) {


                if ($this->ctrl->settings->areas->js2cdn) {
                    //the URL if it's not an inline script
                    $regex = str_replace(array('{{TAG}}', '{{ATTR}}'), array('script', 'src'), $this->regexes['url']);
                    if (preg_match($regex, $script, $matches)) {
                        (SHORTPIXEL_AI_DEBUG & ShortPixelAILogger::DEBUG_AREA_JSON) && $this->logger->log("IS EXT JS:", $matches);
                        $url = isset($matches[5]) ? $matches[5] : $matches[4];
                        if (strpos($url, parse_url($this->ctrl->settings->behaviour->api_url, PHP_URL_HOST)) === false
                            && strpos($url, ShortPixelAI::DEFAULT_API_AI) === false
                            && parse_url($url, PHP_URL_HOST) === ShortPixelDomainTools::get_site_domain()) {
                            //we set any CSS or JS to ret_wait because of the many CORS issues we've seen with these.
                            $absoluteUrl = ShortPixelUrlTools::absoluteUrl($url);
                            $cdnUrl = $this->ctrl->get_api_url($absoluteUrl, false, false, $this->ctrl->get_extension($url), false, true, $this->ctrl->cssCacheVer);
                            SHORTPIXEL_AI_DEBUG && $this->logger->log("API URL: " . $this->ctrl->settings->behaviour->api_url . "JS URL: " . $url . " Replacing with: " . $cdnUrl);
                            $script = str_replace($url, $cdnUrl, $script);
                        }
                    }
                }
                //now the content, if it's an inline script
                if ($this->ctrl->settings->areas->parse_json && preg_match('/^(\<script[^>]*(?:\btype=(?:"|\')application\/(?:ld\+|)json(?:"|\'))[^>]*\>)/sU', $script)) {
                    (SHORTPIXEL_AI_DEBUG & ShortPixelAILogger::DEBUG_AREA_JSON) && $this->logger->log("IS APP LD JSON.");
                    $script = preg_replace_callback('/^(\<script[^>]*(?:\btype=(?:"|\')application\/(?:ld\+|)json(?:"|\'))[^>]*\>)(.*)\<\/script\>/sU',
                        array($this, 'replace_application_json_script'),
                        $script
                    );
                }
                else if ($this->ctrl->settings->areas->parse_js) {
                    if (strpos($script, '__sp_cdata_plAc3h0ldR_')) {
                        (SHORTPIXEL_AI_DEBUG & ShortPixelAILogger::DEBUG_AREA_JSON) && $this->logger->log("IS JS CDATA PLACEHOLDER.", $script);
                        $script = preg_replace_callback('/__sp_cdata_plAc3h0ldR_([0-9]+)/s',
                            array($this, 'replace_cdata_js'),
                            $script
                        );
                    } else {
                        $script = trim($script);
                        $openingTagEnd = strpos($script, '>') + 1;
                        $openingTag = substr($script, 0, $openingTagEnd);
                        if (preg_match('/\btype=["\'](application|text)\/javascript/', $openingTag) || !preg_match('/\btype=/', $openingTag)) { // either it's type text/javascript or type not defined. Outstanding example that should not be parsed as JS:  type=text/template
                            (SHORTPIXEL_AI_DEBUG & ShortPixelAILogger::DEBUG_AREA_JSON) && $this->logger->log("IS app/text JS: ", $script);
                            $jsParser = new ShortPixelJsParser($this->ctrl);

                            $parsed_script = $jsParser->parse($script);

                            $script = empty($parsed_script) ? $script : $parsed_script;
                        }
                        else if (preg_match('/\btype=["\']text\/template/', $openingTag)) {
                            (SHORTPIXEL_AI_DEBUG & ShortPixelAILogger::DEBUG_AREA_JSON) && $this->logger->log("IS TEXT/TEMPLATE: ", $script);
                            $endTagStart = strrpos($script, "</script");
                            if ($endTagStart > $openingTagEnd) {
                                $tpl = trim(substr($script, $openingTagEnd, $endTagStart - $openingTagEnd));
                                (SHORTPIXEL_AI_DEBUG & ShortPixelAILogger::DEBUG_AREA_JSON) && $this->logger->log("ZE TEMPLATE: " . $tpl);
                                $parser = new ShortPixelRegexParser($this->ctrl);
                                $tplDec = json_decode($tpl);
                                if ($tplDec === NULL) {
                                    //IF the template is not a json, in most cases it's html_entity_encoded, so we decode it.
                                    $isEncoded = preg_match('/^\s*&lt;/', $tpl);
                                    $tpl = $isEncoded ? html_entity_decode($tpl) : $tpl;
                                    (SHORTPIXEL_AI_DEBUG & ShortPixelAILogger::DEBUG_AREA_JSON) && $this->logger->log("DECODED TEMPLATE: " . $tpl);
                                    $parsed_script = $parser->parse($tpl);
                                    (SHORTPIXEL_AI_DEBUG & ShortPixelAILogger::DEBUG_AREA_JSON) && $this->logger->log("PARSED TEMPLATE: " . $parsed_script);
                                    $parsed_script = $isEncoded ? htmlentities($parsed_script) : $parsed_script;
                                } elseif (is_array($tplDec) || is_object($tplDec)) {
                                    $parserJson = new ShortPixelJsonParser($this->ctrl);
                                    $parsed_script = json_encode($parserJson->parse($tplDec));
                                } else {
                                    $parsed_script = json_encode($parser->parse($tplDec));
                                }
                                (SHORTPIXEL_AI_DEBUG & ShortPixelAILogger::DEBUG_AREA_JSON) && $this->logger->log("TEXT TEMPLATE BY REGEX: " . $parsed_script);
                                $script = empty($parsed_script) ? $script : $openingTag . $parsed_script . '</script>';
                            }
                        }
                        else if (preg_match('/\btype=["\']text\/x-jsrender/', $openingTag)) {
                            (SHORTPIXEL_AI_DEBUG & ShortPixelAILogger::DEBUG_AREA_JSON) && $this->logger->log("TEXT X-JSRENDER TEMPLATE: " . $openingTag);
                            $endTagStart = strrpos($script, "</script");
                            if ($endTagStart > $openingTagEnd) {
                                $tpl = trim(substr($script, $openingTagEnd, $endTagStart - $openingTagEnd));
                                $parser = new ShortPixelRegexParser($this->ctrl);
                                $parser->isTemplate = true;
                                $parser->forceEager = true;
                                $parsed_script = $parser->parse($tpl);
                                (SHORTPIXEL_AI_DEBUG & ShortPixelAILogger::DEBUG_AREA_JSON) && $this->logger->log("TEXT X-JSRENDER TEMPLATE BY REGEX: " . $parsed_script);
                                $script = empty($parsed_script) ? $script : $openingTag . $parsed_script . '</script>';
                            }
                        }
                    }
                }
            }
            $content = str_replace("<script>__sp_script_plAc3h0ldR_$i</script>", $script, $content);
        }

	    for ( $i = 0; $i < count( $this->CDATAs ); $i++ ) {
		    $content = str_replace( "<![CDATA[\n__sp_cdata_plAc3h0ldR_$i\n//]]>", $this->CDATAs[ $i ], $content );
            $content = str_replace( "<![CDATA[\n__sp_cdata_plAc3h0ldR_$i\n]]>", $this->CDATAs[ $i ], $content );
	    }

	    // $content = str_replace('{{SPAI-AFFECTED-TAGS}}', implode(',', array_keys($this->ctrl->affectedTags)), $content);

	    if($this->ctrl->settings->behaviour->nojquery <= 0) $this->ctrl->affectedTags->remove( 'img' );

        $content = \ShortPixel\AI\JsLoader::_($this->ctrl)->addTagData($content);

        SHORTPIXEL_AI_DEBUG && $this->logger->log("******** REGEX PARSER RETURN ********* " . strlen($content) . (SHORTPIXEL_AI_DEBUG & ShortPixelAILogger::DEBUG_INCLUDE_CONTENT ? $content : ''));

        return $content;
    }

    /**
     * Callback of the `bg-style` rule: delegates the matched tag (with its inline style) to
     * {@see ShortPixelCssParser::replace_background_image_from_tag()} with the current rule.
     *
     * @param string[] $matches Groups of the `bg-style` regex.
     * @return string The tag with background URLs rewritten and `data-spai-bg-*` markers.
     */
    protected function replace_background_image_from_tag($matches) {
        return $this->cssParser->replace_background_image_from_tag($matches, $this->tagRule);
    }

    /**
     * Callback of the `bg-attr` rule (WPBakery `data-ultimate-bg`): delegates to
     * {@see ShortPixelCssParser::replace_wp_bakery_data_ultimate_bg()}.
     *
     * @param string[] $matches Groups of the `bg-attr` regex; the match ends at the `)` of `url(...)`.
     * @return string The matched text with the background URL rewritten.
     */
    protected function replace_bg_attr($matches) {
        return $this->cssParser->replace_wp_bakery_data_ultimate_bg($matches, $this->tagRule);
    }

	/**
	 * Callback for `__sp_cdata_plAc3h0ldR_N` inside an extracted script block.
	 *
	 * Runs `ShortPixelJsParser::parse()` on `$CDATAs[N]` (the whole `<![CDATA[ ... ]]>` text,
	 * which the JS parser treats as bare JS and minifies) and stores the result back in
	 * `$CDATAs[N]` when non-empty. Blocks containing `var spai_settings` (the plugin's own
	 * jQuery-mode settings) are left alone. Returns the placeholder unchanged; `parse()` restores
	 * the CDATA later.
	 *
	 * @param string[] $matches [0] the placeholder, [1] the index N.
	 * @return string `$matches[0]`.
	 */
	protected function replace_cdata_js( $matches ) {
		$index    = (int) $matches[ 1 ];
		$script   = $this->CDATAs[ $index ];

		if ( strpos( $script, 'var spai_settings' ) === false ) { //don't parse our own JS block...
            $jsParser = new ShortPixelJsParser( $this->ctrl );
			$parsed_script = $jsParser->parse( $script );

			$this->CDATAs[ $index ] = empty( $parsed_script ) ? $script : $parsed_script;
		}

		return $matches[ 0 ];
	}

//    protected function replace_cdata_js($matches) {
//        $jsParser = new ShortPixelJsParser($this->ctrl);
//        $key = '__sp_cdata_plAc3h0ldR_' . $matches[1];
//        $script = $this->CDATAs[$key];
//        if(strpos($script, 'var spai_settings') === false) { //don't parse our own JS block...
//            $this->CDATAs[$key] = $jsParser->parseJsonBlocks($script, 'cdata');
//        }
//        return $matches[0];
//    }

    /**
     * Callback of the CROWD 2 theme rule (type `srcset` over a `style` attribute): rewrites the
     * `--img-small` / `--img-medium` / `--img-large` CSS variables to eager CDN URLs.
     *
     * Groups are normalised so that [3] is the quote and [4] the value. A value without
     * `--img-` is returned unchanged. `ATTR=<q>value<q>` is located with `strpos()` and replaced
     * by ` ATTR=<q>` + `ShortPixelCssParser::replace_crowd2_img_styles(value)` + `<q>` (note the
     * leading space, which doubles the existing one).
     *
     * @param string[] $matches Groups of the `srcset` regex.
     * @return string The tag with the variables rewritten, or unchanged.
     */
    public function replace_crowd2_img_styles($matches) {
        $text = $matches[0];
        if(isset($matches[5]) && $matches[5] == '\'') {
            $matches[3] = $matches[5];
            $matches[4] = $matches[6];
        }
        $style = $matches[4];
        if(strpos($style, '--img-') === false) return $text;
        $qm = strlen($matches[3]) ? $matches[3] : '"';

        $pattern = $matches[2] . '=' . $matches[3] . $matches[4] . $matches[3];
        $pos = strpos($text, $pattern);
        if($pos === false) return $text;

        $replacedStyle = $this->cssParser->replace_crowd2_img_styles($matches[4]);
        $replacement = ' ' . $matches[2] . '=' . $qm . $replacedStyle . $qm;

        $this->logger->log("CROWD2 - style found, string $pattern is replaced by $replacement");

        $str = substr($text, 0, $pos) . $replacement . substr($text, $pos + strlen($pattern));
        return $str;
    }

    /**
     * Moves every `start ... end` block out of `$content` into `$store` and leaves a placeholder.
     *
     * A linear scan replaces an ungreedy `preg_replace_callback()`, which hits catastrophic
     * backtracking on large blocks.
     *
     * Input grammar: `$content` is any string. Matching is exact and case-sensitive. When
     * `$startMarker` ends in `.`, the `.` is dropped for searching (so `<script.` finds `<script`
     * followed by anything, including `<scripts`) and the placeholder opens with the marker
     * plus `>` (`<script>`). A block runs from the first char of the start marker to the last char
     * of the FIRST following end marker (no nesting). If `$endSafeguard` occurs inside an open
     * block, that block is dropped and the scan restarts at the safeguard (which is usually the
     * start marker itself). A block still open at the end of the string is not extracted.
     *
     * Output grammar, per block N (0-based within this call):
     * ```
     * <startMarkerRepl>[\n]<id>_plAc3h0ldR_<N>[\n]<endMarker>
     * ```
     * and `$store[]` receives the original block text, start and end markers included. N counts
     * from 0 on every call, so `$store` must be empty when called (callers reset it).
     *
     * Example: `extract_blocks('a<script src="x"></script>b', '__sp_script', '<script.', '</script>', $s)`
     * returns `a<script>__sp_script_plAc3h0ldR_0</script>b` and sets `$s = ['<script src="x"></script>']`.
     *
     * @param string      $content      HTML to scan.
     * @param string      $id           Placeholder prefix, e.g. `__sp_script`.
     * @param string      $startMarker  E.g. `<![CDATA[`, `<noscript.`, `<script.`, `<style.`.
     * @param string      $endMarker    E.g. `]]>`, `</noscript>`.
     * @param string[]    &$store       Receives the extracted blocks, appended in order.
     * @param bool        $newLine      Put `\n` around the placeholder id (used for CDATA).
     * @param bool|string $endSafeguard String that aborts an open block, e.g. `<![CDATA[`.
     * @return string The content with placeholders.
     */
	public function extract_blocks( $content, $id, $startMarker, $endMarker, &$store, $newLine = false, $endSafeguard = false ) {
        $matches = array();
        $startMarkerRepl = $startMarker;
        if(substr($startMarker, -1) == '.') {
            $startMarker = substr($startMarker, 0, -1);
            $startMarkerRepl = $startMarker . '>';
        }
        $startMarkerLen = strlen($startMarker);
        $endMarkerLen = strlen($endMarker);
        $endSafeguardLen = $endSafeguard ? strlen($endSafeguard) : 0;
        for($idx = 0, $match = false, $len = strlen($content); $idx < $len - $endMarkerLen + 1; $idx++) {
            if($match) {
                if(substr( $content, $idx, $endMarkerLen) == $endMarker) {
                    //end of CDATA block
                    $matches[] = (object)array('start' => $match ? $match : 0, 'end' => $idx + $endMarkerLen - 1);
                    $idx += $endMarkerLen - 1;
                    $match = false;
                }
                elseif($endSafeguard && substr( $content, $idx, $endSafeguardLen) == $endSafeguard) {
                    $this->logger->log(" OOPS, $endSafeguard ENCOUNTERED, dropping current block.");
                    $match = false;
                    $idx--;
                }
            } else {
                if(substr($content, $idx, $startMarkerLen) == $startMarker) {
                    $match = $idx;
                    $idx += $startMarkerLen - 1;
                }
            }
        }
        SHORTPIXEL_AI_DEBUG && $this->logger->log(" MATCHED $startMarker BLOCKS: " . json_encode($matches));
        $replacedContent = '';
        $nl = ($newLine ? "\n" : '');
        for($idx = 0; $idx < count($matches); $idx++) {
            $start = isset($matches[$idx - 1]) ? $matches[$idx - 1]->end + 1 : 0;
            $placeholder = $startMarkerRepl . $nl . $id . '_plAc3h0ldR_' .$idx . $nl . $endMarker;
            $replacedContent .= substr($content, $start, $matches[$idx]->start - $start) . $placeholder;
            $cdata = substr($content, $matches[$idx]->start, $matches[$idx]->end - $matches[$idx]->start + 1);
            SHORTPIXEL_AI_DEBUG && $this->logger->log(" MATCHED AND EXTRACTED $idx: " . $cdata . " WITH: " . $placeholder);
            $store[] = $cdata;
        }
        $replacedContent .= substr($content, isset($matches[$idx - 1]) ? $matches[$idx - 1]->end + 1 : 0);
        return $replacedContent;
    }

	/*
	 * Old implementation
	 *
	private function isolate_attributes( $matches ) {
		$index = count( $this->attributes );

		$this->logger->log( 'ISOLATED ATTRIBUTE BODY:', $matches[ 2 ] ); // second group is a body of the affected attribute

		$this->attributes[] = $matches[ 2 ]; // second group is a body of the affected attribute

		return str_replace( $matches[ 2 ], '__sp_attribute_plAc3h0ldR_' . $index, $matches[ 0 ] );
	}
	*/

	/**
	 * Replaces quoted attribute values that contain `>` with placeholders so the rule regexes,
	 * which stop at `>`, do not cut tags in half (typical: `data-*` attributes holding HTML).
	 *
	 * Character scan: outside a tag, `<` enters a tag (any `<`, also `a < b` in text). Inside a
	 * tag, `>` leaves it, and a `"` or `'` whose previous non-whitespace char is `=` opens a value.
	 * Inside a value, the same quote char not preceded by `\` closes it; if any `>` was seen in
	 * the value, the value body (without quotes) is extracted.
	 *
	 * Output: each extracted body becomes `__sp_attribute_plAc3h0ldR_<N>_` (trailing `_` keeps
	 * `_1_` distinct from `_10_`) and `$this->attributes[N]` holds the body. `$this->attributes`
	 * is overwritten only when at least one value is extracted.
	 *
	 * Example: `<a title="x>y" href="/">` gives `<a title="__sp_attribute_plAc3h0ldR_0_" href="/">`
	 * and `$attributes = ['x>y']`.
	 *
	 * @param string $content HTML content (blocks already extracted).
	 * @return string The content with placeholders.
	 */
	private function isolate_attributes( $content ) {
        //first mark down each attribute that contains >
        for ($attributes = [], $attributesContent = [],
             $inTag = false, $attrStartMarker = 0, $inAttr = false, $toBeExtracted = false, $attrDelim = false,
             $len = strlen($content),
             $index = 0; $index < $len; $index++) {
            if(!$inTag ) {
                if( $content[$index] == '<') {
                    //start of a tag
                    $inTag = true;
                } // else we just increment no matter what
            }
            else {
                if(!$inAttr ) {
                    if($content[$index] == '>') {
                        //end of a tag
                        $inTag = false;
                    }
                    elseif(($content[$index] == '"' | $content[$index] == '\'') && self::previousNonWhite($content, $index) == '=' ) {
                        $attrStartMarker = $index;
                        $attrDelim = $content[$index];
                        $inAttr = true;
                    }
                }
                else {
                    if($content[$index] == '>') {
                        //that's our attribute, this one will need to be replaced
                        $toBeExtracted = true;
                    } elseif($content[$index] == $attrDelim && $content[$index - 1] != '\\') {
                        //the attribute just ended
                        $inAttr = false;
                        if($toBeExtracted) {
                            $toBeExtracted = false;
                            $attributes[] = (object)['start' => $attrStartMarker + 1, 'end' => $index];
                            $attributesContent[] =  substr($content, $attrStartMarker + 1, $index - $attrStartMarker - 1);
                        }
                    }

                }
            }
        }
        //now replace them
        if(count($attributes)) {
            for ($parsedContent = '', $index = 0, $prev = 0; $index < count($attributes); $index++) {
                $parsedContent .= substr($content, $prev, $attributes[$index]->start - $prev) . '__sp_attribute_plAc3h0ldR_' . $index . '_';
                $prev = $attributes[$index]->end;
            }
            $parsedContent .= substr($content, $prev);
            $this->attributes = $attributesContent;
            return $parsedContent;
        }

		return $content;
	}

    /**
     * Returns the closest char before `$index` that is not whitespace (`ctype_space()`).
     *
     * @param string $content The string to scan.
     * @param int    $index   Position to scan back from (exclusive).
     * @return false|string The char, or false when only whitespace precedes `$index`.
     */
    public static function previousNonWhite($content, $index) {
        for($i = $index - 1; $i >= 0; $i--) {
            if(!ctype_space($content[$i])) return $content[$i];
        }
        return false;
    }

	/**
	 * Puts back the attribute bodies saved by {@see isolate_attributes()}.
	 *
	 * Every occurrence of `__sp_attribute_plAc3h0ldR_<N>_` is replaced (so copies made by
	 * callbacks, e.g. the noscript duplicate, are restored too). URLs inside isolated values are
	 * never rewritten by the rule loop.
	 *
	 * @param string $content HTML after the rule loop.
	 * @return string The content with the original attribute values.
	 */
	private function revert_attributes( $content ) {
		if ( !empty( $this->attributes ) ) {
			foreach ( $this->attributes as $index => $attribute ) {
				SHORTPIXEL_AI_DEBUG && $this->logger->log( 'REVERTED ATTRIBUTE VALUE: ' . $attribute );

				$content = str_replace( '__sp_attribute_plAc3h0ldR_' . $index . '_', $attribute, $content );
			}
		}

		return $content;
	}

    /**
     * Legacy `preg_replace_callback()` extractor for `<script>` blocks; unused since
     * {@see extract_blocks()} replaced it (only referenced from commented-out code in `parse()`).
     *
     * @todo replace_scripts() is dead code; remove it.
     *
     * @param string[] $matches [0] the whole script block.
     * @return string `<script>__sp_script_plAc3h0ldR_N</script>`.
     */
    public function replace_scripts($matches)
    {
        $index = count($this->scripts);
        $this->scripts[] = $matches[0];
        return "<script>__sp_script_plAc3h0ldR_$index</script>";
    }

    /**
     * Legacy `preg_replace_callback()` extractor for `<style>` blocks; unused since
     * {@see extract_blocks()} replaced it (only referenced from commented-out code in `parse()`).
     *
     * @todo replace_styles() is dead code; remove it.
     *
     * @param string[] $matches [0] the whole style block.
     * @return string `<style>__sp_style_plAc3h0ldR_N</style>`.
     */
    public function replace_styles($matches)
    {
        //$this->logger->log("STYLE: " . $matches[0]);
        $index = count($this->styles);
        $this->styles[] = $matches[0];
        return "<style>__sp_style_plAc3h0ldR_$index</style>";
    }

    /**
     * Default rule callback (used when `TagRule::$callback` is false, i.e. with the `url` regex):
     * filters the match by the
     * rule's class/attribute filters, rewrites it with {@see _replace_images()}, applies the
     * rule's post-processor and optionally appends an eager `<noscript>` copy.
     *
     * Decision steps:
     * 1. URL = `$matches[5]` (unquoted) or `$matches[4]` (quoted); quote = `$matches[3]`.
     * 2. Unchanged when fewer than 5 groups, or the attribute already holds `data:image/svg+xml;`.
     * 3. `classFilter`: unchanged unless `class="..."` contains the word (regex, unescaped).
     * 4. `attrFilter` with `attrValFilter`: unchanged unless `attrFilter=` is followed by a value
     *    containing `attrValFilter` at a word start; `attrFilter` alone: unchanged unless an
     *    attribute of that name exists (quoted values are blanked before the test).
     * 5. {@see _replace_images()} rewrites the tag and reports, by reference, whether it was eager.
     * 6. `TagRule::getPostProcessor()` (a `[object, method]` pair, e.g.
     *    `TagRules::addCrossOrigin`) is applied to the rewritten tag only.
     * 7. Noscript: for `img` tags, when `options.settings_behaviour_replaceMethod === 'src'`,
     *    `settings_behaviour_generateNoscript` is on and the tag was rewritten lazily, appends
     *    `<noscript data-spai="1">` + `_replace_images()` of the ORIGINAL tag with `$isEager`
     *    forced true (CDN URL, `data-spai-egr`, srcset as CDN URLs) + `</noscript>`.
     *
     * Example (lazy): `<img src="/a.jpg">` gives `<img src="data:image/svg+xml;base64,..." data-spai="1">`
     * and, with generate_noscript, `<noscript data-spai="1"><img src="https://cdn.../a.jpg" data-spai-egr="1"></noscript>` after it.
     *
     * @param string[] $matches Groups of the `url` regex (see `$regexes`).
     * @return string The rewritten tag (plus optional noscript), or the original text.
     */
    public function replace_images($matches)
    {
        $text = $matches[0];
        $tag = $matches[ 1 ];
        $attr = $matches[ 2 ];
        $url = isset( $matches[ 5 ] ) ? $matches[ 5 ] : $matches[ 4 ];
        $q = $matches[ 3 ]; //$matches[3] will be either " or '

        if (count($matches) < 5 ||  preg_match('/\s' . $attr . '=["\']{0,1}data\:image\/svg\+xml;/s', $text)) {
            //avoid duplicated replaces due to filters interference
            return $text;
        }
        if ($this->tagRule->classFilter && !preg_match('/\bclass=(\"|\').*?\b' . $this->tagRule->classFilter . '\b.*?(\"|\')/s', $text)) {
            return $text;
        }

	    if ( $this->tagRule->attrFilter ) {
		    if ( $this->tagRule->attrValFilter ) {
			    if ( !preg_match( '/\b' . $this->tagRule->attrFilter . '=((\"|\'|\`)[^\"|\'|\`]*\b|)' . preg_quote( $this->tagRule->attrValFilter, '/' ) . '/s', $text ) ) {
				    return $text;
			    }
		    }
		    else {
			    $stripped = preg_replace( '/(\"|\'|\`).*?(\"|\'|\`)/s', ' ', $text ); //keep only the attribute's names
			    if ( !preg_match( '/\b' . $this->tagRule->attrFilter . '(?=\s|=|\/?>)/s', $stripped ) ) {
				    return $text;
			    }
		    }
	    }

	    $noresize = true; //it's a return param actually, if not modified, don't add the noscript tag
	    $processed_tag = $this->_replace_images( $tag, $attr, $text, $url, $q, $noresize );

        $postProcessor = $this->tagRule->getPostProcessor();
        if($postProcessor && is_array($postProcessor) && count($postProcessor) == 2 && method_exists($postProcessor[0], $postProcessor[1])) {
            $processed_tag = call_user_func($postProcessor, $processed_tag);
        }

	    // ADD noscript
	    if (   $tag == 'img' //noscript makes sense only for img tags
	        && $this->ctrl->options->settings_behaviour_replaceMethod === 'src'
            && !!$this->ctrl->options->settings_behaviour_generateNoscript
            && !$noresize) { // don't add noscript if the image is eager.
	        $eager = $this->isEager;
            $this->isEager = true; //temporarily force is Eager for noscript
            SHORTPIXEL_AI_DEBUG && $this->logger->log("NOSCRIPT TAG FOR " . $url);
            //replace the URLs with eager URLs inside noscript.
		    $processed_tag .= '<noscript data-spai="1">' . $this->_replace_images($tag, $attr, $text, $url, $q) . '</noscript>';
		    $this->isEager = $eager;
	    }

	    return $processed_tag;
    }

    /**
     * Rewrites one URL attribute of one tag; the core of the lazy/eager decision.
     *
     * Input grammar: `$text` is a single opening tag `<tag ... attr=<q>url<q> ...>` as matched by a
     * rule regex; `$url` is the raw attribute value (entities not decoded); `$q` is `"`, `'` or
     * `''` (unquoted).
     *
     * Decision steps, in order (the first "return" wins):
     * 1. Skip attributes ({@see addSpaiExcludedIfNotPresent()}, `type="module"` only for
     *    `script`): return the tag with `data-spai-excluded="true"`.
     * 2. Lazy-plugin detection (no return): if no notice was raised yet, the URL starts with
     *    `data:image` and the tag has `data-lazy-src=`, `data-lazy=`, `data-layzr=`, `data-src=`,
     *    or `data-orig-src=` plus `lazyload`, sets transient `shortpixelai_thrown_notice`
     *    (`['when' => 'lazy', 'extra' => false, 'causer' => 'regex parser', 'text' => $text]`,
     *    86400 s) and `$ctrl->lazyNoticeThrown = true`.
     * 3. `$ctrl->urlIsApi($url)` (host equals the CDN host): return unchanged.
     * 4. `!ShortPixelUrlTools::isValid($url)`, except a `{{:` URL in a jsrender template: return unchanged.
     * 5. `$ctrl->urlIsExcluded($url)` (`excluded_paths`): return {@see markTotalExclude()}.
     * 6. Slider Revolution active and URL ends in `plugins/revslider/.../dummy.png`: return unchanged.
     * 7. `$url = trim(html_entity_decode($url))`; the raw value is kept as `$pristineUrl`.
     * 8. `$ctrl->tagIs('excluded', $text)` (`excluded_selectors`, or `data-spai-excluded`): return unchanged.
     * 9. Tag already contains `data-spai` (after position 0): if this attribute already holds
     *    `data:image/svg+xml;` return unchanged; otherwise strip `data-spai="N"` / `data-spai-upd="N"`
     *    (copied markup) and continue.
     * 10. Eager = `$isEager` (rule eager or forced) || `tagIs('eager')` (`eager_selectors`,
     *     `data-spai-eager`) || `urlIsEager()` (`eager_paths`). Native lazy = tag `img`, attribute
     *     `src`, not `$forceEager`, `settings.behaviour.native_lazy` on, not eager by selector or
     *     path, no `fetchpriority="high"`. Native lazy also makes the tag eager.
     * 11. Glow Pro slider class `attachment-glow-featured-slider-img`: `figure` is added to `affectedTags`.
     * 12. Size: `[1, 1]` for `.css`/`.js` URLs and templates, else
     *     `ShortPixelUrlTools::get_image_size($url)`; aspect ratio from it.
     * 13. Merge attribute (`TagRule::$mergeAttr`, `srcset` for `img src` when replace_method is
     *     `src`), only when `$sizes['thumb']` is set (size from a `-WxH` file name, or the
     *     CSS/JS/template default), or eager, or the tag contains `@2x.`:
     *     - eager: each srcset item becomes `get_api_url(absoluteUrl(item), false, false, false, customCompression)`
     *       (already-CDN items kept), descriptors kept, giving ` srcset="u1 300w, u2 600w"`;
     *     - lazy: the widest candidate whose aspect ratio is within 3% replaces `$url` (and sizes);
     *       the attribute becomes ` srcset=" "` (kept non-empty for WooCommerce variations).
     *     When the merge attribute is not processed it is removed later, together with `sizes=`
     *     when it is `srcset`.
     * 14. `$url` goes through filter `shortpixel/ai/originalUrl`.
     * 15. Every `loading=lazy` (any quoting) is removed from the tag.
     * 16. Eager: `get_api_url(absoluteUrl, false, false, <ext>, customCompression, ret_auto for
     *     `ShortPixelUrlTools::$EAGER_WAIT` types, cssCacheVer for those, separator)` where the
     *     separator is `,` for a `preload` rule (`attrValFilter`) whose URL occurs in the page,
     *     else `ShortPixelAI::SEP`; template URLs starting with `{{:` get `get_api_url(false, ...) . '/' . $url`.
     *     Marker ` data-spai-egr=<q>1<q>`, plus ` loading="lazy"` for native lazy when the tag has no `loading=`.
     *     Lazy: placeholder `ShortPixelUrlTools::generate_placeholder_svg(w, h, $url)`, marker
     *     ` data-spai=<q>1<q>`, plus ` data-lity-target="<cdn url>"` for `img src` with `data-lity`
     *     when `settings.areas.lity` is on.
     * 17. The value is `esc_attr()`-ed (except `{{:` template URLs) and
     *     `/\s<attr>\s*=\s*<q><pristineUrl><q>/` is replaced by ` <attr>=<q|"><value><q|"><marker>[ data-lity-target]`.
     * 18. The merge attribute is replaced by the step-13 result (or removed); `sizes=` is removed
     *     when the srcset was removed.
     * 19. `affectedTags->add($tag, SRC_ATTR)` and `$tagRule->used[$tag] = SRC_ATTR`.
     *
     * Output grammar:
     * ```
     * lazy:  <tag ... attr=<q>data:image/svg+xml;base64,<b64><q> data-spai=<q>1<q>[ data-lity-target="..."] ...>
     * eager: <tag ... attr=<q><cdn url, see ShortPixelAI::get_api_url()><q> data-spai-egr=<q>1<q>[ loading="lazy"] ...>
     * ```
     * Example (eager by rule; lossy, remove_exif on, WebP on): `<source src="https://example.test/a.jpg">` gives
     * `<source src="https://cdn.shortpixel.ai/spai/q_lossy+ret_img+to_webp/example.test/a.jpg" data-spai-egr="1">`.
     *
     * @todo _replace_images() strips copied `data-spai` / `data-spai-upd` markers but not
     *       `data-spai-egr`, so copied eager markup gets a second marker. Extend the regex to
     *       `data-spai(-upd|-egr|)`.
     *
     * @param string    $tag    Tag name as matched (may be upper case).
     * @param string    $attr   Attribute name as matched.
     * @param string    $text   The opening tag.
     * @param string    $url    Raw attribute value.
     * @param string    $q      Quote char of the attribute, or `''`.
     * @param bool      &$eager Out: set at step 10 to true when the tag is rewritten eagerly (also
     *                          native lazy), false when lazily. Untouched on the early returns of
     *                          steps 1-9 (`replace_images()` passes true, so no noscript follows).
     * @return string The rewritten tag, or the input (possibly with exclusion markers).
     */
    protected function _replace_images($tag, $attr, $text, $url, $q = '', &$eager = false) {
        SHORTPIXEL_AI_DEBUG && $this->logger->log("******** REPLACE IMAGE IN $tag ATTRIBUTE $attr: '" . $url . "'"
            . ($this->tagRule->mergeAttr ? ' AND MERGE ' . $this->tagRule->mergeAttr : ''));


        // looking for 'data-no-optimize', 'data-no-minify', or 'type="module"' attributes to avoid replacing urls
        $includeModuleType = ($tag === 'script');
        if ($this->addSpaiExcludedIfNotPresent($text, $includeModuleType)) {
            return $text;
        }

	    if (
		    !$this->ctrl->lazyNoticeThrown && substr( $url, 0, 10 ) == 'data:image'
		    && ( strpos( $text, 'data-lazy-src=' ) !== false
                 || strpos( $text, 'data-lazy=' ) !== false
		         || strpos( $text, 'data-layzr=' ) !== false
		         || strpos( $text, 'data-src=' ) !== false
		         || ( strpos( $text, 'data-orig-src=' ) !== false && strpos( $text, 'lazyload' ) ) //found for Avada theme with Fusion Builder
		    )
	    ) {
		    set_transient( "shortpixelai_thrown_notice", [ 'when' => 'lazy', 'extra' => false, 'causer' => 'regex parser', 'text' => $text ], 86400 );
		    $this->ctrl->lazyNoticeThrown = true;
	    }
	    //if($this->ctrl->lazyNoticeThrown) {
        //    $this->logger->log("Lazy notice thrown");
        //    return $text;
        //}

        if($this->ctrl->urlIsApi($url)) {$this->logger->log('IS API');return $text;}
        if(   !ShortPixelUrlTools::isValid($url)
           && (!$this->isTemplate || strpos($url, '{{:') === false)) {$this->logger->log('NOT VALID');return $text;}
        if ($this->ctrl->urlIsExcluded($url)) {
            $this->logger->log('EXCLUDED TOTALLY: ' . $url);
            return $this->markTotalExclude($text, $url);
        }

        //custom exclusion for SliderRevolution TODO unhack
        if(ActiveIntegrations::_()->get('slider-revolution') && preg_match('/plugins\/revslider\/.*\/dummy.png$/', $url )) {
            return $text;
        }

        $pristineUrl = $url;
        //WP is encoding some characters, like & ( to &#038; ), and oh, some URLs end in spaces...
        $url = trim(html_entity_decode($url));

        //early check for the excluded selectors - only the basic cases when the selector is img.class img#id or img[attr]
        if($this->ctrl->tagIs('excluded', $text)) {
            $this->logger->log("Excluding: " . $text);
            return $text;
        }
        //prevent cases when html code including data-spai attributes gets copied into new articles
        if(strpos($text, 'data-spai') > 0) {
            //  this way it works if we have two rules for the same tag, eg: img:src and img:data-src etc. - fix for
            if(preg_match('/\s' . $attr . '=["\']{0,1}data\:image\/svg\+xml;/s', $text)) {
                //for cases when the src is pseudo
                //Seems that Thrive Architect is doing something like this under the hood?
                $this->logger->log("Ignoring $tag - $attr because it's already data:img: " . $url);
                return $text;
            }
            //for cases when it's normal URL, just get rid of data-spai's
            $text = preg_replace('/data-spai(-upd|)=["\'][0-9]*["\']/s', '', $text);
        }

        $eager = $this->isEager || $this->ctrl->tagIs('eager', $text) || $this->ctrl->urlIsEager($url);
        //with native lazy-loading on, img src is replaced eagerly (whatever the replace method) and the browser lazy-loads it
        //instead of our JS, unless the user marked it as eager or WP flagged it as the LCP image (fetchpriority="high").
        $nativeLazy = strtolower($tag) === 'img' && $attr === 'src' && !$this->forceEager
            && !!$this->ctrl->settings->behaviour->native_lazy
            && !$this->ctrl->tagIs('eager', $text) && !$this->ctrl->urlIsEager($url)
            && !preg_match('/\bfetchpriority=["\']?high\b/i', $text);
        $eager = $eager || $nativeLazy;

        SHORTPIXEL_AI_DEBUG && $this->logger->log("Including: " . $url . ($eager ? ' EAGER' : ' LAZY'));

	    //some particular cases are hardcoded here...
	    // 1. Revolution Slider (rev-slidebg) Glow Pro's Swiper slider (attachment-glow-featured-slider-img) and Optimizer PRO's frontpage slider (stat_bg_img)
	    if ( strpos( $text, 'attachment-glow-featured-slider-img' ) ) {
		    $this->ctrl->affectedTags->add( 'figure', AffectedTags::SRC_ATTR); //the Glow Pro moves the image in <figure> tags
            $this->tagRule->used['figure'] = AffectedTags::SRC_ATTR;
	    }

        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        //Get current image size
        if($ext === 'css' || $ext === 'js' | $this->isTemplate) { //no need to calculate the image size if it's CSS
            $sizes = [1, 1, 'thumb' => false];
            $AR = 1;
        } else {
            $sizes = ShortPixelUrlTools::get_image_size($url);
            $AR = (isset($sizes[0]) && $sizes[0] && $sizes[1]) ? $sizes[0] / $sizes[1] : 1; //it appears that in some circumstances sizes[0] could be 0 and sizes[1] > 0
            $this->logger->log('Got Sizes: ', $sizes);
        }
        $qex = strlen($q) ? '' : '"';
        $qm = strlen($q) ? $q : '"';
        $atirReplacement = '';
        $absoluteUrl = ShortPixelUrlTools::absoluteUrl($url);

        if($this->tagRule->mergeAttr && (isset($sizes['thumb']) || $eager || preg_match('/@2x\./', $text))) {
            $this->logger->log('Integrate and remove: ' . $this->tagRule->mergeAttr . '. Sizes: ', $sizes);
            $matches = false;
            $this->logger->log('TEXT: ' . $text);
            if(preg_match('/\s' . $this->tagRule->mergeAttr . '=(\"|\')(.*?)(?:\"|\')/s', $text, $matches) && isset($matches[2]) && strlen(trim($matches[2]))){
                $this->logger->log('Matched mergeAttr:', $matches);
                $items = explode(',', $matches[2]);
                foreach($items as $item) {
                    $parts = explode(' ', trim($item));

                    if($eager) {
                        //we keep the srcset, but we replace the URLs inside with the API URLs
                        $apiUrl = $this->ctrl->get_api_url(ShortPixelUrlTools::absoluteUrl($parts[0]), false, false, false, $this->tagRule->getCustomCompression());
                        $atirReplacement .= (strlen($atirReplacement) ? ', ': ' ' . $this->tagRule->mergeAttr . '="')
                            . esc_attr($this->ctrl->urlIsApi($parts[0]) ? $parts[0] : $apiUrl)
                            . (isset($parts[1]) ? ' ' . esc_attr($parts[1]) : '');
                    }
                    elseif(isset($sizes['thumb'])
                        //also look for a @2x and no sizes inside the URL, this image could be larger than the original
                        ||     preg_match('/@2x\.\w+(\?|$)/', $parts[0])
                           && !preg_match('/-([0-9]+)x([0-9]+)@2x\.\w+(\?|$)/', $parts[0]))
                    {
                        $partSizes = ShortPixelUrlTools::get_image_size($parts[0]);
                        if(isset($partSizes[0]) && isset($partSizes[1]) && $partSizes [0] > 0 && $partSizes [1] > 0) {
                            $partAR = $partSizes[0] / $partSizes[1];
                            if($partSizes[0] > $sizes[0]
                                && abs(($partAR - $AR) / $AR) < 0.03) {
                                $sizes = $partSizes;
                                $url = $parts[0];
                            }
                        }
                    }
                }
                if(strlen($atirReplacement)) {
                    $atirReplacement .= '"';
                }
                else {
                    //we need to keep the attribute empty because some plugins as woocommerce are checking for it when doing variations
                    $atirReplacement = ' ' . $this->tagRule->mergeAttr . '=" "';
                }
            }
        }

        $spaiMeta = ''; $spaiMarker = ' data-spai=' . $qm . '1' . $qm; $dataLityTarget = '';

        $url = apply_filters('shortpixel/ai/originalUrl', $url);

        //remove loading="lazy" if present (either we do our own JS lazy loading or the $eager flag is set).
        $text = preg_replace('/\s\bloading=["\']?lazy["\']?/s', '', $text);

        if($eager) {
            //we set any CSS or JS or FONTS to ret_wait because of the many CORS issues we've seen with these.
            $wait = in_array($ext, ShortPixelUrlTools::$EAGER_WAIT);
            $cacheVer = $wait ? $this->ctrl->cssCacheVer : false;
            if($this->isTemplate && strpos($url, '{{:') === 0) {
                $inlinePlaceholder = $this->ctrl->get_api_url( false, false, false, false, $this->tagRule->getCustomCompression(), $wait, $cacheVer)
                    . '/' . $url;
            } else {
                $separator = strpos($this->content, $absoluteUrl) > 0 && $this->tagRule->attrValFilter == "preload" ? "," : ShortPixelAI::SEP;
                $inlinePlaceholder = $this->ctrl->get_api_url($absoluteUrl, false, false, $this->ctrl->get_extension($url),  $this->tagRule->getCustomCompression(), $wait, $cacheVer, $separator);
            }
            $spaiMarker = ' data-spai-egr=' . $qm . '1' . $qm;
            if ($nativeLazy && strpos($text, 'loading=') === false) {
                $spaiMarker .= ' loading="lazy"';
            }
        } else {
            //lazy
            //handle the data-lity TODO add this as a tag rule instead...
            if ( $tag == 'img' && $attr == 'src' && !!$this->ctrl->settings->areas->lity && preg_match('/\bdata-lity\b/', $text) ) {
                $dataLityTarget = ' data-lity-target="' . esc_attr($this->ctrl->get_api_url( $absoluteUrl,false, false, $this->ctrl->get_extension( $url ))) . '"';
                SHORTPIXEL_AI_DEBUG && $this->logger->log("LITY TARGET " . $dataLityTarget);
            }
            $inlinePlaceholder = isset($sizes[0]) ? ShortPixelUrlTools::generate_placeholder_svg($sizes[0], $sizes[1], /*$this->absoluteUrl(*/$url) : ShortPixelUrlTools::generate_placeholder_svg(false, false, $url);
        }
        //XSS: $url was entity-decoded above, escape it back. Not jsrender templates: escaping breaks their {{:...}} JS expressions
        //and that code comes from the theme's own <script>, which user content can't reach.
        $attrValue = $this->isTemplate && strpos($url, '{{:') === 0 ? $inlinePlaceholder : esc_attr($inlinePlaceholder);
        $pattern = '/\s' . $attr . '\s*=\s*' . preg_quote($q . $pristineUrl . $q, '/') . '/';
        $replacement = ' '. $attr . '=' . $qm . $attrValue . $qm . $spaiMarker . $spaiMeta . $dataLityTarget;

        SHORTPIXEL_AI_DEBUG && $this->logger->log("REPLACING: Pattern " . $pattern . " with " . $replacement . " in " . $text);

        $str = preg_replace($pattern, $replacement, $text);

        if($this->tagRule->mergeAttr) {
            $str = preg_replace('/\s' . $this->tagRule->mergeAttr . '=(\"|\').*?(\"|\')/s', $atirReplacement, $str);
            if($this->tagRule->mergeAttr == 'srcset' && $atirReplacement == '') {
                //also make sure we remove the sizes if srcset was removed
                $str = preg_replace('/\bsizes=(\"|\').*?(\"|\')/s', '', $str);
            }
        }
        $this->ctrl->affectedTags->add($tag, AffectedTags::SRC_ATTR);
        $this->tagRule->used[$tag] = AffectedTags::SRC_ATTR;
        SHORTPIXEL_AI_DEBUG && $this->logger->log("Replaced pattern: $pattern with $replacement. RESULTED TAG: $str\n\n");
        return $str;// . "<!-- original url: $url -->";
    }

    /**
     * Callback of the `data-thumb` rule (`div|figure|li` `data-thumb` / `data-thumbnail`, used by
     * WooCommerce galleries): rewrites the value eagerly.
     *
     * - Value starting with `%3C` (urlencoded HTML): `urldecode()`, parse with a new
     *   `ShortPixelRegexParser`, `rawurlencode()` back, and replace the value in the tag.
     * - Value not valid per `ShortPixelUrlTools::isValid()`: unchanged.
     * - Otherwise every occurrence of the value in the tag becomes
     *   `get_api_url(absoluteUrl(value), false, false, <ext>)` (no `w_` token); when the tag
     *   changed, the tag is added to `affectedTags` with `SRC_ATTR`.
     *
     * Example: `<div data-thumb="https://example.test/t.jpg">` gives
     * `<div data-thumb="https://cdn.shortpixel.ai/spai/q_lossy+ret_img/example.test/t.jpg">`.
     *
     * @param string[] $matches [0] the tag, [1] tag name, [2] the attribute value.
     * @return string The rewritten tag.
     */
    public  function replace_wc_gallery_thumbs( $matches ) {
        SHORTPIXEL_AI_DEBUG && $this->logger->log("replace_wc_gallery_thumbs: received ", $matches);
        $tag = $matches[1];
        if(strpos($matches[2], '%3C') === 0) { //it's HTML urlencoded
            $parser = new ShortPixelRegexParser($this->ctrl);
            $ret = str_replace($matches[2], rawurlencode($parser->parse(urldecode($matches[2]))), $matches[0]);
            SHORTPIXEL_AI_DEBUG && $this->logger->log("replace_wc_gallery_thumbs: found HTML urlencoded, RETURN ", $ret);
            return $ret;
        }
        if(!ShortPixelUrlTools::isValid($matches[2])) {
            SHORTPIXEL_AI_DEBUG && $this->logger->log("replace_wc_gallery_thumbs: INVALID URL " . $matches[2] . ", do nothing");
            return $matches[0];
        }
        $url = ShortPixelUrlTools::absoluteUrl($matches[2]);
        $str = str_replace($matches[2], $this->ctrl->get_api_url( $url, false, false, $this->ctrl->get_extension( $url )), $matches[0]);
        if($str != $matches[0]) {
            $this->ctrl->affectedTags->add($tag, AffectedTags::SRC_ATTR);
            $this->tagRule->used[$tag] = AffectedTags::SRC_ATTR;
        }
        return $str;
    }

    /**
     * Callback of the `srcset`-type rules that rewrite a whole srcset attribute: `img srcset`
     * (eager rule), `img data-srcset` (eager), `source srcset`, `data-envira-srcset`, theme rules,
     * and the `img srcset` pass over noscript blocks.
     *
     * Steps:
     * 1. Groups are normalised: single-quoted values move from [5]/[6] to [3]/[4].
     * 2. A blank value (`srcset=" "`, which SPAI itself writes for WooCommerce) is left unchanged.
     * 3. Unchanged when `tagIs('excluded')` or the FIRST srcset URL is `urlIsExcluded()`.
     * 4. `ATTR=<q>value<q>` is replaced (first occurrence, via `strpos()`) by
     *    ` ATTR=<q|">` + {@see replace_srcset()}`(value)` + `<q|">` (leading space doubles the existing one).
     * 5. ` loading="lazy"` is appended to the replacement when `settings.behaviour.replace_method`
     *    is `src`, the tag has no `loading=`, the rule is not eager (`$isEager`) and the tag is
     *    not `tagIs('eager')`.
     * No `data-spai*` marker is added and `affectedTags` is not updated.
     *
     * Example (rule `source srcset`, replace_method `src`):
     * `<source srcset="https://example.test/a.jpg 1x">` gives
     * `<source  srcset="https://cdn.shortpixel.ai/spai/q_lossy+ret_img/example.test/a.jpg 1x" loading="lazy">`.
     *
     * @todo replace_custom_srcset() adds `loading="lazy"` whatever `settings.behaviour.native_lazy`
     *       says, also to `<source>` tags (where the attribute has no effect) and to
     *       `fetchpriority="high"` images, unlike `_replace_images()`. Gate it on `native_lazy`,
     *       on `img` tags and on the absence of `fetchpriority="high"`.
     * @todo replace_custom_srcset() tests `tagIs('eager', $text)` twice in the same condition; drop the duplicate.
     *
     * @param string[] $matches Groups of the `srcset` regex.
     * @return string The rewritten tag, or the original text.
     */
    public function replace_custom_srcset($matches)
    {
        $this->logger->log("REPLACE CUSTOM SRCSET ", $matches);
        if(isset($matches[5]) && $matches[5] == '\'') {
            $matches[3] = $matches[5];
            $matches[4] = $matches[6];
        }
        $qm = strlen($matches[3]) ? $matches[3] : '"';
        $text = $matches[0];
        if(strlen(trim($matches[4])) == 0) return $text; //it's an empty srcset attribute: srcset=" " - this is used by SPAI too for woocommerce variations JS in some cases, that would break otherwise

        $srcset_urls = explode(',', $matches[4]);
        $image_src = isset($srcset_urls[0]) ? trim(explode(' ', $srcset_urls[0])[0]) : '';

        if ($this->ctrl->tagIs('excluded', $text) || $this->ctrl->urlIsExcluded($image_src)) {
            $this->logger->log("IMAGE WITH SRC {$image_src} IS EXCLUDED. NO SRCSET REPLACED.");
            return $text;
        }

        $pattern = $matches[2] . '=' . $matches[3] . $matches[4] . $matches[3];
        $replacement_srcset = $this->replace_srcset($matches[4]);
        $replacement = ' ' . $matches[2] . '=' . $qm . $replacement_srcset . $qm;

        if($this->ctrl->settings->behaviour->replace_method === 'src'
            && strpos($text, 'loading=') === false
            && !$this->isEager && !$this->ctrl->tagIs('eager', $text)
            && !$this->ctrl->tagIs('eager', $text)) {
            $replacement .= ' loading="lazy"';
        }

        $pos = strpos($text, $pattern);
        if($pos === false) return $text;
        $str = substr($text, 0, $pos) . $replacement . substr($text, $pos + strlen($pattern));
        return $str;// . "<!-- original url: $url -->";
    }

    /**
     * Rewrites the body of a `<script type="application/json">` or `"application/ld+json">` block.
     *
     * The body has `&quot;` turned into `"` and is `json_decode()`d (objects, not arrays). On
     * `JSON_ERROR_SYNTAX` the block is returned unchanged. Otherwise a `ShortPixelJsonParser`
     * (current rule; lazy = `settings.areas.parse_json_lazy`, or 0 for `ld+json`, which is
     * structured data and never lazy) parses the tree, and the block becomes
     * `<opening tag>` + `json_encode()` (default flags: `\/` and `\uXXXX` escaping) + `</script>`.
     *
     * Example: `<script type="application/json">{"img":"https://example.test/a.jpg"}</script>` gives
     * `<script type="application/json">{"img":"https:\/\/cdn.shortpixel.ai\/spai\/...\/example.test\/a.jpg"}</script>`
     * (lazy off; with lazy on the value is an SVG placeholder).
     *
     * @todo replace_application_json_script() checks only JSON_ERROR_SYNTAX; other decode errors
     *       (invalid UTF-8, depth) give null and the body becomes `null`. Return `$matches[0]`
     *       unless `json_last_error() === JSON_ERROR_NONE`.
     *
     * @param string[] $matches [0] whole block, [1] opening tag, [2] body.
     * @return string The rewritten block.
     */
    public function replace_application_json_script($matches) {
        $lazy = strpos($matches[1], 'application/ld+json') === false ? $this->ctrl->settings->areas->parse_json_lazy : 0; //ld+json is structured data, don't lazy-load (0 marks not lazy-load
        $jsonParser = new ShortPixelJsonParser($this->ctrl, $this->tagRule, $lazy);
        $dataObj = json_decode(str_replace('&quot;', '"', $matches[2]));
        if(json_last_error() === JSON_ERROR_SYNTAX) {
            $this->logger->log("APPLICATION JSON syntax error", $matches);
            return $matches[0];
        }
        $this->logger->log("APPLICATION / JSON", $dataObj);
        return $matches[1] . json_encode($jsonParser->parse($dataObj)) . '</script>';
    }

    /**
     * Callback of the rules whose attribute holds JSON (Elementor `data-settings|data-options`
     * on `header|section|div`, and the other `replace_custom_json_attr` rules in `TagRules`; the
     * vanilla loader also sends these rules to the JS as `attrType: 'json'`).
     *
     * The quote `$qm` is taken from [3] BEFORE the single-quote normalisation, so a single-quoted
     * attribute is rewritten double-quoted. The value has `&quot;` turned into `"` and is decoded;
     * on `JSON_ERROR_SYNTAX` the tag is unchanged. A `ShortPixelJsonParser` (current rule, lazy
     * from `parse_json_lazy` unless the rule is eager) parses it; the result is encoded with
     * `JSON_UNESCAPED_SLASHES` and `"` turned back into `&quot;`. Unchanged when equal to the
     * input; otherwise `ATTR=<q>value<q>` is replaced by ` ATTR="<json>" data-spai-egr="1"`.
     * For a `section` tag, `div` is added to `affectedTags` with `SRC_ATTR | CSS_ATTR`.
     *
     * Example: `<div data-settings="{&quot;bg&quot;:&quot;https://example.test/a.jpg&quot;}">` gives
     * `<div  data-settings="{&quot;bg&quot;:&quot;https://cdn.shortpixel.ai/spai/.../example.test/a.jpg&quot;}" data-spai-egr="1">` (lazy off).
     *
     * @todo replace_custom_json_attr() checks only JSON_ERROR_SYNTAX; other decode errors give
     *       null and the attribute becomes `null`. Require `JSON_ERROR_NONE`.
     *
     * @param string[] $matches Groups of the `srcset` regex.
     * @return string The rewritten tag, or the original text.
     */
    public function replace_custom_json_attr($matches)
    {
        $qm = strlen($matches[3]) ? $matches[3] : '"';
        $text = $matches[0];
        $tag = $matches[1];
        $attr = $matches[2];
        if(isset($matches[5]) && $matches[5] == '\'') {
            $matches[3] = $matches[5];
            $matches[4] = $matches[6];
        }
        $jsonParser = new ShortPixelJsonParser($this->ctrl, $this->tagRule);
        $parsed = json_decode(str_replace('&quot;', '"', $matches[4]));
        if(json_last_error() === JSON_ERROR_SYNTAX) return $text;
        $replaced = str_replace('"', '&quot;',json_encode($jsonParser->parse($parsed), JSON_UNESCAPED_SLASHES));
        if($matches[4] == $replaced) return $text;
        $pattern = $attr . '=' . $matches[3] . $matches[4] . $matches[3];
        $replacement = ' ' . $attr . '=' . $qm . $replaced . $qm . ' data-spai-egr='. $qm . '1' . $qm;
        $pos = strpos($text, $pattern);
        if($pos === false) return $text;
        $str = substr($text, 0, $pos) . $replacement . substr($text, $pos + strlen($pattern));
        if(strtolower($tag) == 'section') {
            SHORTPIXEL_AI_DEBUG && $this->logger->log("JSON affected tag: DIV");
            $flags = AffectedTags::SRC_ATTR | AffectedTags::CSS_ATTR;
            $this->ctrl->affectedTags->add('div', $flags);
            $this->tagRule->used['div'] = $flags;
        }
        return $str;// . "<!-- original url: $url -->";
    }

    /**
     * Callback of the WooCommerce rule for `data-product_variations` (on `form`, with a
     * `quickMatch`): rewrites the image URLs inside the variations JSON eagerly.
     *
     * Input grammar: `ATTR=<q>JSON<q>` where JSON (with `&quot;` for `"`) is an array of
     * variation objects; each may carry `image` (object) and the lists `jck_additional_images`
     * and `variation_gallery_images` (arrays of image objects).
     *
     * Steps: normalise quotes; decode; unchanged on `JSON_ERROR_SYNTAX` or when the result is not
     * an array (WooCommerce writes `"false"` when variations load by AJAX). Then, with
     * `$aiUrl = get_api_url(false, false)` (`<base>/<tokens>`, no `w_`, see ShortPixelAI::get_api_url()),
     * {@see replace_product_variation_attrs()} runs on every image object. The tree is re-encoded
     * with `JSON_UNESCAPED_SLASHES`, `"` becomes `&quot;`; unchanged when equal, else
     * `ATTR=<q>value<q>` is replaced by `ATTR=<q><json><q> data-spai-egr=<q>1<q>`.
     *
     * Output grammar per URL field (keeps the scheme, unlike get_api_url()):
     * ```
     * <aiUrl>/<absoluteUrl(trim(value))>      e.g. https://cdn.shortpixel.ai/spai/q_lossy+ret_img/https://example.test/a.jpg
     * ```
     * srcset fields: see {@see replace_product_variation_set()}.
     *
     * Example: `data-product_variations="[{&quot;image&quot;:{&quot;src&quot;:&quot;https://example.test/a.jpg&quot;}}]"`
     * gives `data-product_variations="[{&quot;image&quot;:{&quot;src&quot;:&quot;https://cdn.shortpixel.ai/spai/q_lossy+ret_img/https://example.test/a.jpg&quot;}}]" data-spai-egr="1"`.
     *
     * @todo replace_product_variations() logs `get_class($parsed)` when the JSON is not an array;
     *       for `false` (the usual non-array value) that throws a TypeError on PHP 8 whenever
     *       SHORTPIXEL_AI_DEBUG is on. Log `gettype($parsed)` instead.
     * @todo replace_product_variations() guards a debug log with `function_exists('json_last_error')`,
     *       a legacy shim that is always true on the PHP 7.4 floor; drop the guard.
     * @todo replace_product_variations() reads `$parsed[$i]->image` without `isset()`; a variation
     *       without `image` raises a warning. Guard it.
     *
     * @param string[] $matches Groups of the `srcset` regex.
     * @return string The rewritten tag, or the original text.
     */
    public function replace_product_variations($matches)
    {
        SHORTPIXEL_AI_DEBUG && $this->logger->log("PRODUCT VARIATION", $matches);
        if(isset($matches[5]) && $matches[5] == '\'') {
            $matches[3] = $matches[5];
            $matches[4] = $matches[6];
        }
        $qm = strlen($matches[3]) ? $matches[3] : '"';
        SHORTPIXEL_AI_DEBUG && $this->logger->log("PRODUCT VARIATION QM ", $qm);
        $parsed = json_decode(str_replace('&quot;', '"', $matches[4]));
        SHORTPIXEL_AI_DEBUG && $this->logger->log("PRODUCT VARIATION PARSED: ", $parsed);
        $text = $matches[0];
        SHORTPIXEL_AI_DEBUG && $this->logger->log("PRODUCT VARIATION JSON LAST ERR: ", function_exists('json_last_error') ? json_last_error() : 'function missing');
        if(($err = json_last_error()) === JSON_ERROR_SYNTAX) {
            SHORTPIXEL_AI_DEBUG && $this->logger->log("PRODUCT VARIATIONS - JSON PARSE ERROR " . $err);
            return $text;
        }
        if(!is_array($parsed)) {
            SHORTPIXEL_AI_DEBUG && $this->logger->log("PRODUCT VARIATIONS - NOT AN ARRAY " . get_class($parsed));
            return $text;
        }
        $this->logger->log('PRODUCT VARIATIONS - replacing');
        $aiUrl = $this->ctrl->get_api_url(false, false);
        for($i = 0; $i < count($parsed); $i++)
        {
            SHORTPIXEL_AI_DEBUG && $this->logger->log("VARIATIONS - image:");
            $this->replace_product_variation_attrs($parsed[$i]->image, $aiUrl);

            if(isset($parsed[$i]->jck_additional_images) && is_array($parsed[$i]->jck_additional_images)) {
                $this->replace_product_variation_list_attrs($parsed[$i]->jck_additional_images, $aiUrl);
            }

            if(isset($parsed[$i]->variation_gallery_images) && is_array($parsed[$i]->variation_gallery_images)) {
                $this->replace_product_variation_list_attrs($parsed[$i]->variation_gallery_images, $aiUrl);
            }
        }
        $replaced = str_replace('"', '&quot;',json_encode($parsed, JSON_UNESCAPED_SLASHES));
        if($matches[4] == $replaced) return $text;
        $pattern = $matches[2] . '=' . $matches[3] . $matches[4] . $matches[3];
        $replacement = $matches[2] . '=' . $qm . $replaced . $qm . ' data-spai-egr=' . $qm . '1' . $qm;
        $pos = strpos($text, $pattern);
        if($pos === false) return $text;
        $str = substr($text, 0, $pos) . $replacement . substr($text, $pos + strlen($pattern));
        return $str;// . "<!-- original url: $url -->";
    }

    /**
     * Runs {@see replace_product_variation_attrs()} on each image object of a list
     * (`jck_additional_images`, `variation_gallery_images`). Objects are modified in place.
     *
     * @param object[] $list  Image objects.
     * @param string   $aiUrl CDN prefix `<base>/<tokens>`.
     * @return void
     */
    public function replace_product_variation_list_attrs($list, $aiUrl) {
        foreach ($list as $var) {
            $this->replace_product_variation_attrs($var, $aiUrl);
        }
    }

    /**
     * Rewrites the URL fields `src`, `full_src`, `large_src`, `thumb_src`, `archive_src`,
     * `gallery_thumbnail_src`, `url` and the srcset fields `srcset`, `large_srcset`,
     * `gallery_thumbnail_srcset` of one WooCommerce variation image object, in place.
     *
     * @param object|null $var   Image object (null when the variation has no image: nothing happens).
     * @param string      $aiUrl CDN prefix `<base>/<tokens>`.
     * @return void
     */
    protected function replace_product_variation_attrs($var, $aiUrl) {
        $this->replace_product_variation_attr($var, 'src', $aiUrl);
        $this->replace_product_variation_attr($var, 'full_src', $aiUrl);
        $this->replace_product_variation_attr($var, 'large_src', $aiUrl);
        $this->replace_product_variation_attr($var, 'thumb_src', $aiUrl);
        $this->replace_product_variation_attr($var, 'archive_src', $aiUrl);
        $this->replace_product_variation_attr($var, 'gallery_thumbnail_src', $aiUrl);
        $this->replace_product_variation_attr($var, 'url', $aiUrl);
        $this->replace_product_variation_set($var, 'srcset', $aiUrl);
        $this->replace_product_variation_set($var, 'large_srcset', $aiUrl);
        $this->replace_product_variation_set($var, 'gallery_thumbnail_srcset', $aiUrl);
    }

    /**
     * Prefixes one non-empty URL field: `$var->$attr = $aiUrl . '/' . absoluteUrl(trim(value))`.
     *
     * The origin keeps its scheme (`.../ret_img/https://example.test/a.jpg`), and there is no
     * validity, exclusion or already-on-CDN check.
     *
     * @todo replace_product_variation_attr() keeps the origin scheme (one of the three code paths
     *       emitting `/https://host/...` instead of the scheme-less form of get_api_url()), skips
     *       `urlIsExcluded()`/`isValid()`, and prefixes URLs already on the CDN again. Build the URL
     *       with `get_api_url(absoluteUrl($v), false, false, get_extension($v))` and skip CDN URLs.
     *
     * @param object $var   Image object.
     * @param string $attr  Field name.
     * @param string $aiUrl CDN prefix.
     * @return void
     */
    protected function replace_product_variation_attr($var, $attr, $aiUrl)
    {
        if(!empty($var->$attr)) {
            SHORTPIXEL_AI_DEBUG && $this->logger->log(" -- ATTR - $attr " . $var->$attr);
            $var->$attr = $aiUrl . '/' . ShortPixelUrlTools::absoluteUrl(trim($var->$attr));
        }
    }

    /**
     * Rewrites one non-empty srcset field of a variation image object, in place.
     *
     * First every `data:image/svg+xml;base64...` placeholder (up to the next whitespace) is
     * turned back into a CDN URL by {@see pseudo_url_to_api_url()}, then the whole value goes
     * through {@see replace_srcset()}, which returns it unchanged as soon as one item is already
     * on the CDN, so mixed srcsets keep their non-placeholder items on the origin.
     *
     * @param object $var   Image object.
     * @param string $set   Field name.
     * @param string $aiUrl CDN prefix (unused here; replace_srcset() builds its own).
     * @return void
     */
    protected function replace_product_variation_set($var, $set, $aiUrl)
    {
        if(!empty($var->$set)) {
            SHORTPIXEL_AI_DEBUG && $this->logger->log(" -- ATTR - $set " . $var->$set);
            // $var->srcset = preg_replace_callback('/data:image\/svg\+xml;u=[^\s]*/s', // old
            $var->$set = preg_replace_callback('/data:image\/svg\+xml;base64[^\s]*/s', // new
                array($this, 'pseudo_url_to_api_url'),
                $var->$set
            );
            SHORTPIXEL_AI_DEBUG && $this->logger->log(" -- ATTR - $set REPLACED placeholders? " . $var->$set);
            $var->$set = $this->replace_srcset( $var->$set );
        }
    }

    /**
     * Turns an SVG placeholder back into an eager CDN URL:
     * `get_api_url(absoluteUrl(url_from_placeholder_svg($match[0])), false, false, <ext>)`.
     *
     * @todo pseudo_url_to_api_url() does not check for `url_from_placeholder_svg()` returning
     *       false (which its base64 regex makes likely, see there); `absoluteUrl(false)` then
     *       yields the current page URL and the CDN URL points at it. Return `$match[0]` on false.
     *
     * @param string[] $match [0] the placeholder.
     * @return string The CDN URL.
     */
    public function pseudo_url_to_api_url($match){
        $this->logger->log("VARIATIONS SRCSET ITEM", $match);
        $url = ShortPixelUrlTools::url_from_placeholder_svg($match[0]);
        $this->logger->log("VARIATIONS SRCSET URL", $url);
        return $this->ctrl->get_api_url(ShortPixelUrlTools::absoluteUrl($url), false, false, $this->ctrl->get_extension( $url ));
    }

    /**
     * Rewrites every URL of a srcset value to an eager CDN URL.
     *
     * Input grammar: `item ("," item)*`, item = `url [descriptor]` separated by the first space
     * (the value is split on every `,`, so URLs containing commas break).
     *
     * All-or-nothing: the ORIGINAL string is returned as soon as one item's URL is
     * `urlIsExcluded()`, or one item contains `settings.behaviour.api_url`, or (when set)
     * `settings.behaviour.storage_url`, or starts with `data:image/`.
     *
     * Output grammar:
     * ```
     * [http:]<aiUrl>/<host><path>[?<query>][ <descriptor>] (", " ...)
     * aiUrl = get_api_url(false, false)   (no w_; p_h from the current page's scheme, not the item's;
     *                                       see ShortPixelAI::get_api_url())
     * ```
     * `http:` is prepended only when `$aiUrl` does not start with `http` (protocol-relative CDN).
     * Each URL goes through `ShortPixelUrlTools::absoluteUrl()`, then only host, path and query
     * are kept: scheme, port, user/password and fragment are dropped. The origin is not URL-encoded.
     *
     * Example: `"/a-300x200.jpg 300w, https://example.test/a.jpg 1024w"` on site `https://example.test` gives
     * `"https://cdn.shortpixel.ai/spai/q_lossy+ret_img/example.test/a-300x200.jpg 300w, https://cdn.shortpixel.ai/spai/q_lossy+ret_img/example.test/a.jpg 1024w"`.
     *
     * @todo replace_srcset() drops the port, user info and fragment of each URL
     *       (`https://example.test:8080/a.jpg` becomes `.../example.test/a.jpg`). Append
     *       `:<port>` to the host when `parse_url()` returns one.
     * @todo replace_srcset() turns an empty item (trailing comma, `"a.jpg 1x, "`) into a CDN URL
     *       of the current page, because `absoluteUrl('')` returns the page URL. Skip empty items.
     * @todo replace_srcset() decides `p_h` from the current page's scheme (`get_api_url(false)`),
     *       so `http://` items on an HTTPS page get no `p_h` token. Build the URL per item with
     *       `get_api_url($abs, false)`.
     *
     * @param string $srcset The srcset value.
     * @return string The rewritten srcset, or `$srcset` unchanged.
     */
    public function replace_srcset($srcset) {
        $aiSrcsetItems = array();
        $aiUrl = $this->ctrl->get_api_url(false, false);
        $aiUrlBase = $this->ctrl->settings->behaviour->api_url; //ex: https://cdn.shortpixel.ai/spai
        $aiUrlBaseAmazon = $this->ctrl->settings->behaviour->storage_url; //ex: https://cdn.shortpixel.ai/x9
        $srcsetItems = explode(',', $srcset);
        foreach($srcsetItems as $srcsetItem) {
            $srcsetItem = trim($srcsetItem);
            $srcsetItemParts = explode(' ', $srcsetItem);
            if($this->ctrl->urlIsExcluded($srcsetItemParts[0])) {
                //if any of the items are excluded, don't replace
                SHORTPIXEL_AI_DEBUG && $this->logger->log("REPLACE SRCSET abort - excluded: " . $srcsetItem);
                return $srcset;
            }
            if(
                strpos($srcsetItem, $aiUrlBase) !== false
                || (!empty($aiUrlBaseAmazon) && strpos($srcsetItem, $aiUrlBaseAmazon) !== false)
                || strpos($srcsetItem, 'data:image/') === 0
            ) {
                SHORTPIXEL_AI_DEBUG && $this->logger->log("REPLACE SRCSET abort - AI url: " . $srcsetItem);
                return $srcset; //already parsed by the hook.
            }
// now we get ugly cdn url like this:
// srcset="https://cdn.shortpixel.ai/spai/q_lossy+ret_img+to_webp/https://calin.shortpixel.com/wp-content/uploads/2025/02/image00031-1024x576.jpg 1024w
// so we need to strip scheme from middle of url(origin) https://cdn.shortpixel.ai/spai/.../https://....
            $parts = explode(' ', $srcsetItem, 2);
            $url   = trim($parts[0]);
            $desc  = isset($parts[1]) ? ' ' . trim($parts[1]) : '';

            $abs = ShortPixelUrlTools::absoluteUrl($url);

            $u = parse_url($abs);
            $host  = isset($u['host']) ? $u['host'] : '';
            $path  = isset($u['path']) ? $u['path'] : '';
            $query = isset($u['query']) ? ('?' . $u['query']) : '';

            $absNoScheme = $host . $path . $query;

            $prefix = (strpos($aiUrl, 'http') === 0) ? '' : 'http:';
            $aiSrcsetItems[] = $prefix . $aiUrl . '/' . $absNoScheme . $desc;
        }
        return implode(', ', $aiSrcsetItems);
    }

    //NextGen specific
    //TODO make gallery specific
    /**
     * Callback of the NextGen `ahref` rule: rewrites `<a href>` lightbox links of NextGen galleries.
     *
     * Unchanged when fewer than 3 groups, when `href=<q>data:image/svg+xml;` is already present,
     * or when the tag contains neither `ngg-fancybox` nor `ngg-simplelightbox`. Otherwise
     * {@see _replace_images()} with tag `a`, attribute `href` (lazy unless the rule is eager,
     * which it is in vanilla mode).
     *
     * @param string[] $matches [0] the tag, [1] quote (may be empty), [2] URL.
     * @return string The rewritten tag, or the original text.
     */
    public function replace_link_href($matches)
    {
        if (count($matches) < 3 || strpos($matches[0], 'href=' . $matches[1] . 'data:image/svg+xml;') // I think "u=" can be removed too
            || strpos($matches[0], 'ngg-fancybox') === false && strpos($matches[0], 'ngg-simplelightbox') === false) { //this is to limit replacing the href to NextGen's fancybox links
            //avoid duplicated replaces due to filters interference
            return $matches[0];
        }
        //$matches[1] will be either " or '
        return $this->_replace_images('a', 'href', $matches[0], $matches[2], $matches[1]);
    }
}

<?php
/**
 * CSS rewriter for background images, masks and `@font-face` URLs.
 *
 * @package ShortPixel\AI
 */

/**
 * Rewrites `url(...)` references in CSS text and in tags with inline styles.
 *
 * Two instances exist per request: the controller's own (`ShortPixelAI::getCssParser()`, used
 * for CSS files and by `TagRules::map()`) and the one each `ShortPixelRegexParser` creates.
 * The controller is untyped so tests can pass a stub that offers `settings`, `options`,
 * `affectedTags`, `lazyNoticeThrown`, `tagIs()`, `urlIsApi()`, `urlIsExcluded()`,
 * `get_api_url()` and `get_extension()`.
 *
 * Markers emitted on tags (see {@see replace_background_image_from_tag()}):
 * - `data-spai-bg-on="1"`: the tag's background was replaced lazily (SVG placeholder).
 * - `data-spai-bg-prepared="1"`: vanilla mode; the background is final (CDN URL, gradient, or
 *   nothing replaceable) and the `#spai_bg_lazr` CSS must not hide it.
 * Lazy URLs become `ShortPixelUrlTools::generate_placeholder_svg()` data URIs; eager URLs become
 * CDN URLs (see ShortPixelAI::get_api_url()).
 */
class ShortPixelCssParser {
    //                          v- changed to also parse --background-image CSS variable used by Blocksy builder
    /**
     * CSS properties whose `url()` is rewritten (regex alternation). `REGEX_CSS` accepts a `-`
     * before the property, so custom properties such as `--background-image` match too.
     * @var string
     */
    const BG_ATTRS = 'mask|-webkit-mask|mask-image|-webkit-mask-image|background-image|background|content';
    /**
     * Declaration with a `url()` inside a CSS rule body.
     *
     * Grammar: `(start|[\s{;-]) () (BG_ATTRS) (\s*: [anything up to ; or } ending in , or space]) (url) ( [quote] value [quote] )`.
     * Groups: 1 the char before the property, 2 always empty, 3 property, 4 `:` and the text up
     * to `url(`, 5 `url`, 6 value (no quotes or `)`), 7 closing quote. Only the first `url()` of
     * a declaration is captured; `replace_background_image()` re-scans the whole match with `REGEX_URL`.
     * Example: `{background: #fff url('/a.png') no-repeat}` matches with [3]=`background`, [6]=`/a.png`.
     * @var string
     */
    const REGEX_CSS = '/([\s{;-]|^)()(' . self::BG_ATTRS . ')(\s*:(?:[^;}]*[,\s]|\s*))(url)\(\s*(?:\'|")?([^\'"\)]+)(\'|"|)?\s*\)/s';
    // tried (url|[\w-]+-gradient) instead of (url) but it breaks when the gradient is before a url() so reverted it. To be seen if the gradient problem for which it was added in the first place reappears.
    /**
     * Any `url(...)` or `var(...)` call. Groups: 1 `url` or ` var`, 2 `url`, 3 `var`, 4 value
     * (without quotes), 5 closing quote or empty. Used to rewrite every `url()` of a declaration
     * (also inside `image-set()`). `var(...)` matches go through the same callback but are never
     * changed (the substitution targets `url(`); a `var()` whose content passes the checks still
     * sets the "replaced" flag.
     * Example: `url("a.png")` gives [4]=`a.png`, [5]=`"`.
     * @var string
     */
    const REGEX_URL = '/((url)|\s*(var))\s*\([\'"]?([^\'"\)]*?)([\'"]?)\)/s';

	//extract content of @font-face
	/**
	 * `@font-face ... { body }` (ungreedy). Group 1 the rule body.
	 * @var string
	 */
	const REGEX_FONT_FACE = '/@font-face.*{([^}]+)[}]/sU';
	//extract src of @font-face (may be multiple)
	/**
	 * `src: value;` inside a `@font-face` body. Group 1 the value (may hold several `url()`).
	 * @var string
	 */
	const REGEX_FONT_FACE_SRC = '/src\s*:([^;}]+)[;}]/s';
	//extract url part of src
	/**
	 * `url(value)` inside a font `src` (ungreedy). Group 1 the value, quotes included.
	 * @var string
	 */
	const REGEX_FONT_FACE_SRC_URL = '/url\((.*)\)/sU';


	/**
	 * `<tag ... BG_ATTRS: ... url(value)` inside a tag. Same groups as `REGEX_CSS` with 1 the tag
	 * name and 2 the text before the property. Only used by {@see replace_in_tag_style_backgrounds()},
	 * which only the dead `ShortPixelSimpleDomParser` reaches through `TagRules::map()`; the
	 * regex parser uses its own `bg-style` regex instead.
	 * @var string
	 */
	const REGEX_IN_TAG = '/\<([\w]+)([^\<\>]*?)(' . self::BG_ATTRS . ')(\s*:(?:[^;}]*[,\s]|\s*))(url)\(\s*(?:\'|")?([^\'"\)]+)(\'|"|)?\s*\)/s';
    /**** BEWARE, THE ABOVE IS NOT USED ANY MORE, the bg-attr regex from RegexParser is used instead  ****/

    /**
     * Token kind for the unimplemented {@see get_all_tokens()}; unused.
     * @var int
     */
    const NESTED_RULE = 1;
    /**
     * Token kind for the unimplemented {@see get_all_tokens()}; unused.
     * @var int
     */
    const NESTED_RULE_END = 0;
	/**
	 * @var null|ShortPixelAI The plugin controller (or a test stub with the same members).
	 */
    private $ctrl;
    /**
     * @var ShortPixelAILogger Shared logger instance.
     */
    private $logger;

    /**
     * @var bool|null Set to true when {@see replace_crowd2_img_style()} rewrites a CROWD 2
     *                variable; never read.
     */
    private $crowd2replaced;
    /**
     * @var bool True while the rule being processed by {@see replace_inline_style_backgrounds()}
     *           has a `:before`/`:after` selector; such backgrounds are always replaced eagerly.
     */
    private $isPseudoSelector = false;

    /**
     * @var false|string Directory (with trailing slash) of the CSS file being parsed, a path or a
     *                   URL. Set on the controller's instance by `ShortPixelAI::parse_cached_css()`
     *                   and `parse_cached_css_wpo()` around the call, so relative `url()` values
     *                   resolve against it (see `ShortPixelUrlTools::absoluteUrl()`); false otherwise.
     */
    public $cssFilePath = false;

    /**
     * @param ShortPixelAI|object $controller The plugin controller (untyped on purpose).
     */
    public function __construct($controller) {
        $this->ctrl = $controller;
        $this->logger = ShortPixelAILogger::instance();
    }


    /**
     * Rewrites background/mask/content `url()` values in CSS text (a `<style>` block or a whole
     * CSS file from a cache plugin).
     *
     * Input grammar: CSS text. Each innermost rule `selector { body }` (body without braces;
     * regex `/([^{};>]+\s*){([^{}]+\s*)}/s`) is processed; text outside braces (a bare
     * declaration list such as a `style` attribute value) is not. Nested blocks (`@media`) work
     * because only the innermost braces match. The selector part stops at `>`, so for
     * `.a > .b` only ` .b ` is inspected for pseudo elements.
     *
     * Per rule: `isPseudoSelector` = selector contains `:before` or `:after` (such rules are
     * eager); every `REGEX_CSS` declaration goes through {@see replace_background_image_from_style()}
     * (lazy when `settings.areas.backgrounds_lazy_style` is on and not a pseudo element); with
     * the `CROWD 2` theme active, `--img-*` variables go through {@see replace_crowd2_img_styles()}.
     *
     * Output grammar: the same CSS with each accepted URL replaced by either
     * `url(<q><cdn url><q>)` (eager, see ShortPixelAI::get_api_url(), `w_<backgrounds_max_width>`
     * when set) or `url(<q>data:image/svg+xml;base64,...<q>)` (lazy placeholder).
     *
     * Example (eager): `.a{background:#fff url('/a.png') no-repeat}` on `https://example.test` gives
     * `.a{background:#fff url('https://cdn.shortpixel.ai/spai/q_lossy+ret_img/example.test/a.png') no-repeat}`.
     *
     * @param string $style CSS text.
     * @return string The rewritten CSS.
     */
    public function replace_inline_style_backgrounds($style) {
        //$tokens = $this->get_all_tokens($style);

        $style = preg_replace_callback('/([^{};>]+\s*){([^{}]+\s*)}/s', function($matches) {
            preg_match('/:(before|after)\b/s', $matches[1], $pseudo);
            $this->isPseudoSelector = isset($pseudo[1]);
            $rules = preg_replace_callback(self::REGEX_CSS,
                array(&$this, 'replace_background_image_from_style'), $matches[2]);
            if (\ShortPixel\AI\ActiveIntegrations::_()->get('theme') == 'CROWD 2' && strpos($rules, '--img-') !== false) {
                $this->logger->log("CROWD2 - inline stile block has --img-");
                $rules = $this->replace_crowd2_img_styles($rules);
            }
            $this->isPseudoSelector = false;
            return $matches[1] . '{' . $rules . '}';
        }, $style);
        return $style;
    }

	/**
	 * Rewrites the `url()` values of `@font-face` `src` declarations to CDN URLs.
	 *
	 * Does nothing unless `settings.areas.parse_css_files > 0`. Grammar:
	 * `@font-face ... { ... src: url(v) [format(...)] (, url(v) ...)* ; ... }`; nested via
	 * `REGEX_FONT_FACE` > `REGEX_FONT_FACE_SRC` > `REGEX_FONT_FACE_SRC_URL`. Each value is
	 * stripped of quotes and made absolute (`absoluteUrl()` without `$cssFilePath`), skipped when
	 * `urlIsApi()`, not `isValid()` (woff/woff2/ttf only on the site host or `css_domains`) or
	 * `urlIsExcluded()`, else replaced by `"<get_api_url(url, false, false, <ext>, false)>"`
	 * (always double-quoted, `ret_img`, no `v_` token).
	 *
	 * Example (`parse_css_files` on, HTTPS site):
	 * `@font-face{src:url('/f.woff2') format("woff2")}` gives
	 * `@font-face{src:url("https://cdn.shortpixel.ai/spai/ret_img/example.test/f.woff2") format("woff2")}`.
	 *
	 * @param string $style CSS text.
	 * @return string The rewritten CSS.
	 */
	public function replace_inline_style_fonts($style) {

        $this->logger->log("REPLACE INLINE FONTS:", substr($style, 0, 1000));

		if($this->ctrl->settings->areas->parse_css_files <= 0) {
			$this->logger->log("REPLACE INLINE FONT - PARSE CSS OPTION IS OFF");
			return $style;
		}
		$style = preg_replace_callback(self::REGEX_FONT_FACE, function($matches) {
			$srcs = preg_replace_callback(self::REGEX_FONT_FACE_SRC, function($matches1) {
				$urls = preg_replace_callback(self::REGEX_FONT_FACE_SRC_URL, function($matches2) {
					$url = trim($matches2[1],'"\'');

					$url = ShortPixelUrlTools::absoluteUrl($url);
					if(   $this->ctrl->urlIsApi($url)
					      || !ShortPixelUrlTools::isValid($url)
					      || $this->ctrl->urlIsExcluded($url)) {
						$this->logger->log("REPLACE INLINE FONT - IS INVALID OR EXCLUDED");
						return $matches2[0];
					}
					$apiUrl = $this->ctrl->get_api_url($url, false, false, $this->ctrl->get_extension($url), false);
					$retUrl = '"' . $apiUrl . '"';

					return str_replace($matches2[1],$retUrl, $matches2[0]);
				},$matches1[1]);
				return  str_replace($matches1[1], $urls, $matches1[0]);
			},$matches[1]);

			return  str_replace($matches[1], $srcs, $matches[0]);
		}, $style);

		return $style;
	}

    /**
     * Rewrites backgrounds inside a tag's inline style with `REGEX_IN_TAG`. Returns the input
     * unchanged when it does not contain `background` (masks alone are ignored).
     *
     * Only reachable through `TagRules::map()` (`customReplacer` of the `*` rule) from the dead
     * `ShortPixelSimpleDomParser`. `preg_replace_callback()` calls
     * {@see replace_background_image_from_tag()} with no rule, so `$tagRule->eager` is read on false.
     *
     * @todo replace_in_tag_style_backgrounds() is dead code (SimpleDom only) and would warn on
     *       `$tagRule->eager`; remove it with `REGEX_IN_TAG` and `TagRules::map()`.
     *
     * @param string $style Tag text or style value.
     * @return string The rewritten text.
     */
    public function replace_in_tag_style_backgrounds($style) {
        if(strpos($style, 'background') === false) return $style;
        return preg_replace_callback(
            self::REGEX_IN_TAG,
            array(&$this, 'replace_background_image_from_tag'),
            $style);
    }

    /**
     * Appends `$spaiClass` to the tag's `class` attribute (no separator is added, so the caller
     * must pass a leading space), or inserts `class="<spaiClass>"` after the tag name when there
     * is none. A class already containing `spai-bg-on` is left alone. Quotes are normalised to `"`.
     *
     * @todo add_class() has no caller; remove it.
     *
     * @param object $ret       `{text, tag}` as returned by {@see replace_background_image()}.
     * @param string $spaiClass Class to add.
     * @return string The tag text (also stored in `$ret->text`).
     */
    public function add_class($ret, $spaiClass) {
        if(preg_match('/\sclass=("[^"]+"|\'[^\']+\'|[^\s\'""]+)/s', $ret->text, $classes)) {
            $this->logger->log("REPLACE BK CLASS: ", $classes);
        }
        if(count($classes)) {
            $full = $classes[0];
            $cls = $classes[1];
            $this->logger->log("REPLACE BK BITS: full: $full cls: $cls");
            if(strpos( $cls, 'spai-bg-on') === false) {
                $hasSep = ($cls[0] === '"' || $cls[0] === "'");
                $cls = ($hasSep ? substr($cls, 1, strlen($cls) - 2) : $cls) . $spaiClass;
                $this->logger->log("REPLACE BK BITS2: cls: $cls");
                $ret->text = str_replace($full, ' class="' . $cls . '"', $ret->text);
            }
        }
        else {
            $ret->text = '<' . $ret->tag . ' class="'. $spaiClass . '"' . substr($ret->text, strlen($ret->tag) + 1);
        }
        return $ret->text;
    }

    /**
     * Adds `<attr>="1"` right after the tag name unless `/\b<attr>=/` already matches.
     *
     * Every `<tag` occurrence in `$ret->text` gets it (the text is normally one tag). The output
     * is `'<' . tag . ' ' . attr . '="1"'`, so callers passing `' data-spai-bg-prepared'` (with
     * a leading space) produce a double space, and the existence test for that value
     * (`\b data-spai-bg-prepared=`) needs a word char right before the space, which never
     * happens after a quote or after its own double space: re-parsed markup gets the attribute
     * again.
     *
     * Example: `add_spai_attr({text:'<div style="...">', tag:'div'}, 'data-spai-bg-on')` gives
     * `<div data-spai-bg-on="1" style="...">`.
     *
     * @todo add_spai_attr() is called with a leading space for `data-spai-bg-prepared`, which
     *       doubles the space and defeats the "already present" check, so the marker is
     *       duplicated on re-parse. Trim `$attr` (or pass it without the space) and quote the tag
     *       with `preg_quote()`.
     *
     * @param object $ret  `{text, tag}` as returned by {@see replace_background_image()}.
     * @param string $attr Attribute name.
     * @return string The tag text (also stored in `$ret->text`).
     */
    public function add_spai_attr($ret, $attr) {
        if(!preg_match('/\b' . $attr . '=/s', $ret->text)) {
            $ret->text = preg_replace('/<' . $ret->tag . '\b/s', '<' . $ret->tag . ' ' . $attr . '="1"', $ret->text);
        }
        return $ret->text;
    }

    /**
     * Rewrites the background URLs of one tag (inline `style`, or a `bg-attr` attribute) and
     * flags the tag for the front-end JS.
     *
     * Lazy when `settings.areas.backgrounds_lazy` is on and the rule is not eager
     * (see {@see replace_background_image()} for the per-URL steps; called with `$inAttr` true,
     * because the text is an attribute value, so every new URL is written through `esc_attr()`). Then:
     * - replaced, and (`backgrounds_lazy` on or vanilla mode `nojquery > 0`): the tag is added to
     *   `affectedTags` and `$tagRule->used` with `CSS_ATTR`, and
     *   - `backgrounds_lazy` on, rule not eager and the last URL not eager:
     *     `data-spai-bg-on="1"` (JS swaps the placeholder);
     *   - else in vanilla mode: `data-spai-bg-prepared="1"` (background final, keep visible);
     * - not replaced in vanilla mode (gradient-only, invalid, excluded, already CDN...):
     *   `data-spai-bg-prepared="1"` so the `#spai_bg_lazr` CSS does not hide it;
     * - otherwise (jQuery mode, eager or nothing replaced) no marker.
     * Both markers are added through {@see add_spai_attr()}.
     *
     * Output grammar: `<tag[ data-spai-bg-on="1"|  data-spai-bg-prepared="1"] ...style="...url(<placeholder|cdn url>)...">`.
     * Example (lazy, vanilla): `<div style="background:url(/a.jpg)">` gives
     * `<div data-spai-bg-on="1" style="background:url(data:image/svg+xml;base64,...)">`.
     *
     * @param string[]           $matches          Groups of the `bg-style` regex ([1] tag name).
     * @param false|\ShortPixel\AI\TagRule &$tagRule The current rule; `->used` is updated.
     *                                              Reading `->eager` on false warns.
     * @param bool               $ignoreLazyNotice Skip the lazy-plugin detection and stop rule.
     * @return string The rewritten tag text.
     */
    public function replace_background_image_from_tag($matches, &$tagRule = false, $ignoreLazyNotice = false) {
        $this->logger->log("REPLACE TAG BK RECEIVES: ", $matches);
        $ret = $this->replace_background_image($matches, $this->ctrl->settings->areas->backgrounds_lazy && !$tagRule->eager, $ignoreLazyNotice, true);

        if(($this->ctrl->settings->areas->backgrounds_lazy || $this->ctrl->settings->behaviour->nojquery > 0) && $ret->replaced) {
            SHORTPIXEL_AI_DEBUG && $this->logger->log("CSS in-tag affected tag: " . $ret->tag);
            $this->ctrl->affectedTags->add($ret->tag, \ShortPixel\AI\AffectedTags::CSS_ATTR);
            if($tagRule) $tagRule->used[$ret->tag] = \ShortPixel\AI\AffectedTags::CSS_ATTR;

            if($this->ctrl->settings->areas->backgrounds_lazy && !$tagRule->eager && !$ret->eager) {
                $ret->text = $this->add_spai_attr($ret, 'data-spai-bg-on');
            }
            elseif($this->ctrl->settings->behaviour->nojquery > 0) {
                $ret->text = $this->add_spai_attr($ret, ' data-spai-bg-prepared');
            }
        }
        elseif (!$ret->replaced && $this->ctrl->settings->behaviour->nojquery > 0) {
            $this->logger->log("SOME DIFFERENT KIND OF BG - gradient-image?");
            $ret->text = $this->add_spai_attr($ret, ' data-spai-bg-prepared'); //we still need to flag it to be visible
        }
        $this->logger->log("REPLACE TAG BG RETURNS: ", $ret);
        return $ret->text;
    }

    /**
     * `REGEX_CSS` callback for declarations inside CSS rules: lazy when not a `:before`/`:after`
     * rule and `settings.areas.backgrounds_lazy_style` is on. When anything was replaced,
     * `script` is added to `affectedTags` with `SCRIPT_ATTR`.
     *
     * @param string[] $matches Groups of `REGEX_CSS`.
     * @return string The rewritten declaration.
     */
    public function replace_background_image_from_style($matches) {
        $this->logger->log("REPLACE STYLE BK RECEIVES: ", $matches);
        //if($this->cssFilePath) {
        //    $this->logger->log('URL is ' . $matches[4] . ' (homepath: ' . get_home_path() . ' , css File path: ' . $this->cssFilePath . ') and will be converted to ' . ShortPixelUrlTools::absoluteUrl($matches[4], $this->cssFilePath));
        //}
        //doesn't make sense to replace lazily in <style> blocks
        //actually it does because otherwise we don't have WebP
        $ret = $this->replace_background_image($matches, !$this->isPseudoSelector && $this->ctrl->settings->areas->backgrounds_lazy_style/*false*/);
        if($ret->replaced) {
            $this->ctrl->affectedTags->add('script', \ShortPixel\AI\AffectedTags::SCRIPT_ATTR);
        }
        return $ret->text;
    }

    /**
     * WPBakery `data-ultimate-bg` (rule type `bg-attr`): maps the `bg-attr` groups onto the
     * 8-slot shape {@see replace_background_image_from_tag()} expects
     * (`[0, tag, attr, '', '=...', 'url', <url|gradient name>, <value>]`; only [0], [1] and the
     * presence of [5] matter, URLs are re-read from [0]) and calls it with the lazy-plugin
     * detection disabled.
     *
     * @param string[]                     $matches Groups of the `bg-attr` regex.
     * @param false|\ShortPixel\AI\TagRule $tagRule The current rule.
     * @return string The rewritten text (up to the `)` of the `url()`).
     */
    public function replace_wp_bakery_data_ultimate_bg($matches, $tagRule = false) {
        $this->logger->log("REPLACE BAKERY BK RECEIVES: ", $matches);
        $ret = $this->replace_background_image_from_tag([$matches[0], $matches[1], $matches[2], '', $matches[3], 'url', $matches[4], $matches[5]], $tagRule, true);
        $this->logger->log("REPLACE BAKERY BK RETURNS: ", $ret);
        return $ret;
    }

    /**
     * Rewrites every `url()` of a matched declaration or tag, lazily or eagerly.
     *
     * Returns `{text: <unchanged>, replaced: false}` when `$matches[5]` is missing. Otherwise
     * every `REGEX_URL` match in `$matches[0]` goes through these steps (first "unchanged" wins):
     * 1. URL = trimmed value, entity-decoded (`ENT_QUOTES`); surrounding `"` (from `&quot;`) and
     *    `'` (from `&#039;`) are stripped from it and from the raw value.
     * 2. Eager for this URL = `!$lazy` or `tagIs('eager', $matches[0])`.
     * 3. An SVG placeholder (`ShortPixelUrlTools::url_from_placeholder_svg()` succeeds): unchanged
     *    when lazy; when eager the decoded original URL is used (collected CSS made eager).
     * 4. URL contains `settings.behaviour.api_url`: unchanged.
     * 5. Lazy-plugin detection unless `$ignoreLazyNotice`: a `data-bg=` in the text sets transient
     *    `shortpixelai_thrown_notice` (`causer` `css parser`, 86400 s) and
     *    `$ctrl->lazyNoticeThrown`. From then on (also when the regex parser raised it) every
     *    background is left unchanged for the rest of the request, unless `$ignoreLazyNotice`.
     * 6. `tagIs('excluded')`, `urlIsApi()`, `!isValid()` or `urlIsExcluded()`: unchanged.
     * 7. Absolute URL via `ShortPixelUrlTools::absoluteUrl($url, $cssFilePath)`.
     * 8. Eager: `get_api_url($abs, <backgrounds_max_width or false>, false, <ext>)`.
     *    Lazy: `generate_placeholder_svg(<get_image_size($url)>, $abs)`.
     * 9. `url(<q><raw value><q>` is replaced by `url(<q><new value><q>` (the closing quote char is
     *    assumed for the opening one). `replaced` becomes true, `eager` takes this URL's value.
     *    With `$inAttr` the new value goes through `esc_attr()`: step 1 decoded the entities of the
     *    original, so a decoded `"`, `'`, `<`, `>` or `&` of the URL would otherwise be written raw
     *    into the attribute and could close it. In CSS text (`<style>`, CSS files) entities mean
     *    nothing, so there the value is written as is.
     *
     * Example (eager): `background:url(/a.png)` gives `background:url(https://cdn.shortpixel.ai/spai/q_lossy+ret_img/example.test/a.png)`.
     * Example (eager, `$inAttr`): `url(/a.png?x=1&#038;y=&quot;z)` gives `url(https://cdn.shortpixel.ai/.../a.png?x=1&amp;y=&quot;z)`.
     *
     * @param string[] $matches          Groups of `REGEX_CSS`, `REGEX_IN_TAG` or `bg-style` ([0] text, [1] tag or prefix char).
     * @param bool     $lazy             Use placeholders instead of CDN URLs.
     * @param bool     $ignoreLazyNotice Skip step 5.
     * @param bool     $inAttr           The text is an HTML attribute value (inline `style`, `bg-attr`): escape the
     *                                   new URL for an attribute. False for CSS text.
     * @return object `{text: string, replaced: bool, eager: bool, tag: string}`; `eager` reflects
     *                the last URL replaced; `tag` is trimmed `$matches[1]` (empty or a punctuation
     *                char for `REGEX_CSS`).
     */
    public function replace_background_image($matches, $lazy = true, $ignoreLazyNotice = false, $inAttr = false) {
        $text = $matches[0];
        //The URL matches are not always correct, for example when it's an *-image-set and there are
        // several url()'s only the first is matched, so we use a regex that matches only the url() part
        $replaced = false;
        if(!isset($matches[5])) {
            $this->logger->log("REPLACE BG - NO URL", $matches);
            return (object)array('text' => $text, 'replaced' => false);
        }
        $tag = trim($matches[1]);
        $eager = false;
        $changed = preg_replace_callback(self::REGEX_URL, function($matches) use ($text, $lazy, $ignoreLazyNotice, $inAttr, &$replaced, &$eager) {
            $unchanged = $matches[0];
            $url = trim($matches[4]);
            $q = isset($matches[5]) ? $matches[5] : '';

            $pristineUrl = $url;
            //WP is encoding some characters, like & ( to &#038; )
            $url = trim(html_entity_decode($url, ENT_QUOTES));
            //some URLs in css are delimited by &quot; which becomes " after html_entity_decode
            $urlUnquot = trim($url, '"');
            if($urlUnquot !== $url) {
                $this->logger->log('Removed double quote ' . $urlUnquot);
                $url = $urlUnquot;
                $pristineUrl = trim($pristineUrl, '"');
            }
            //Other URLs are delimited by &#039; which decodes to '
            $urlUnquot = trim($url, '\'');
            if($urlUnquot !== $url) {
                $this->logger->log('Removed quote ' . $urlUnquot);
                $url = $urlUnquot;
                $pristineUrl = trim($pristineUrl, '\'');
            }

            $tagEager = !$lazy || $this->ctrl->tagIs('eager', $text);
            //        if(strpos($url, 'data:image/svg+xml;u=') !== false) { // old implementation
            if(($decodedUrl = ShortPixelUrlTools::url_from_placeholder_svg($url)) !== false) {
                if(!$tagEager) {
                    $this->logger->log("REPLACE BG - IS PLACEHOLDER");
                    return $unchanged;
                } else {
                    //this is collected CSS, need to change it back and make it eager
                    $url = $decodedUrl;

                }
            }
            if(strpos($url, $this->ctrl->settings->behaviour->api_url) !== false) {
                $this->logger->log("REPLACE BG - IS PLACEHOLDER");
                return $unchanged;
            }
            if ( !$ignoreLazyNotice && !$this->ctrl->lazyNoticeThrown && ( strpos( $text, 'data-bg=' ) !== false ) ) {
                set_transient( "shortpixelai_thrown_notice", [ 'when' => 'lazy', 'extra' => false, 'causer' => 'css parser', 'text' => $text ], 86400 );
                $this->ctrl->lazyNoticeThrown = true;
            }
            if(!$ignoreLazyNotice && $this->ctrl->lazyNoticeThrown) {
                $this->logger->log("REPLACE BG - IS LAZYNOTICE");
                return $unchanged;
            }
            if($this->ctrl->tagIs('excluded', $text)) {
                $this->logger->log("REPLACE BG - IS EXCLUDED");
                return $unchanged;
            }

            $this->logger->log('******** REPLACE BACKGROUND IMAGE ' . ($lazy ? 'LAZILY ' : 'EAGERLY ') . $url);

            if(   $this->ctrl->urlIsApi($url)
                || !ShortPixelUrlTools::isValid($url)
                || $this->ctrl->urlIsExcluded($url)) {
                $this->logger->log("REPLACE BG - IS INVALID OR EXCLUDED", $url);
                return $unchanged;
            }

            $absoluteUrl = ShortPixelUrlTools::absoluteUrl($url, $this->cssFilePath ? $this->cssFilePath : false);
            if($tagEager) {
                $width = $this->ctrl->settings->areas->backgrounds_max_width ? $this->ctrl->settings->areas->backgrounds_max_width : false;
                //cssFilePath present means that's a CSS file from the cache plugin (WP Rocket)
                $inlinePlaceholder = $this->ctrl->get_api_url($absoluteUrl, $width, false, $this->ctrl->get_extension( $url ));
                $this->logger->log("API URL: " . $inlinePlaceholder);
            } else {
                $sizes = ShortPixelUrlTools::get_image_size($url);
                $inlinePlaceholder = isset($sizes[0]) ? ShortPixelUrlTools::generate_placeholder_svg($sizes[0], $sizes[1], $absoluteUrl) : ShortPixelUrlTools::generate_placeholder_svg(false, false, $absoluteUrl);
            }

//        $this->logger->log("REPLACE REGEX: " . '/' . $attr . '\s*:' . preg_quote($extra, '/') . 'url\(\s*' . preg_quote($q . $pristineUrl . $q, '/') . '/'
//              . " WITH: " . ' '. $attr . ':' . $extra . 'url(' . $q . $inlinePlaceholder . $q);
            //removed the ' ' . in front because it did not work with --background-images
            $replacement =  'url(' . $q . ($inAttr ? esc_attr($inlinePlaceholder) : $inlinePlaceholder) . $q;
            $str = preg_replace('/url\(\s*' . preg_quote($q . $pristineUrl . $q, '/') . '/',
                $replacement, $unchanged);

            $this->logger->log('******** WITH ', $replacement);
            $replaced = true;
            $eager = $tagEager;
            return $str;
        }, $text);

        return (object)array('text' => $changed, 'replaced' => $replaced, 'eager' => $eager, 'tag' => $tag);// . "<!-- original url: $url -->";
    }

    /**
     * CROWD 2 theme: rewrites `--img-small`, `--img-medium` and `--img-large` custom properties
     * (`--img-small: url(v)`) through {@see replace_crowd2_img_style()} (eager CDN URLs).
     *
     * @param string $style CSS declarations or a style attribute value.
     * @return string The rewritten CSS.
     */
    public function replace_crowd2_img_styles($style) {
        // CROWD2 uses --img-small --img-medium and --img-large styles
        return preg_replace_callback(
            '/(--img-small|--img-medium|--img-large)(\s*:(?:[^;]*?[,\s]|\s*))url\((?:\'|")?([^\'"\)]+)(\'|"|)?/s',
            array(&$this, 'replace_crowd2_img_style'),
            $style);
    }

    /**
     * Intended to tokenise CSS into rules and nested blocks (`NESTED_RULE` / `NESTED_RULE_END`).
     * Not implemented: the body is empty and it returns null. Only referenced from a comment.
     *
     * @todo get_all_tokens() is an empty stub; remove it with the NESTED_RULE constants or implement it.
     *
     * @param string $style CSS text.
     * @return null Always null.
     */
    public function get_all_tokens($style) {
        //TODO
    }

    /**
     * Callback of {@see replace_crowd2_img_styles()}: one `--img-*: url(v)` occurrence.
     *
     * URLs already on the CDN are unchanged. Otherwise the CDN URL is
     * `get_api_url(absoluteUrl(v, $cssFilePath), false, false, <ext>)` (get_api_url() returns
     * the original URL when it is excluded; no `isValid()` check). The text is rebuilt as
     * ` <type><trimmed extra>url(<q><cdn url><q>` through a regex built from the TRIMMED
     * property and separator.
     *
     * @todo replace_crowd2_img_style() builds its search regex from `trim($matches[2])`, so
     *       `--img-small: url(x)` (space after the colon) never matches and stays on the origin.
     *       Use the untrimmed `$matches[1]` / `$matches[2]` in the pattern.
     *
     * @param string[] $matches [0] text, [1] property, [2] `:` and separator, [3] URL, [4] closing quote.
     * @return string The rewritten text.
     */
    protected function replace_crowd2_img_style($matches) {
        $text = $matches[0];
        $type = trim($matches[1]);
        $extra = trim($matches[2]);
        $url = trim($matches[3]);
        $q = isset($matches[4]) ? $matches[4] : '';
        if($this->ctrl->urlIsApi($url)) {
            return $text;
        }
        $this->crowd2replaced = true;
        $absoluteUrl = ShortPixelUrlTools::absoluteUrl($url, $this->cssFilePath ? $this->cssFilePath : false);
        $inlinePlaceholder = $this->ctrl->get_api_url($absoluteUrl, false, false, $this->ctrl->get_extension( $url ));
        return preg_replace('/' . $type . preg_quote($extra, '/') . 'url\(\s*' . preg_quote($q . $url . $q, '/') . '/',
            ' '. $type . $extra . 'url(' . $q . $inlinePlaceholder . $q, $text);

    }
}
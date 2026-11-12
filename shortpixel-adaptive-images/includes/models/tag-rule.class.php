<?php
/**
 * TagRule value object.
 */

namespace ShortPixel\AI;

/**
 * One rewriting rule: which tag(s) and attribute(s) `ShortPixelRegexParser::parse()`
 * looks for, how the match is filtered, and how the URL is replaced.
 *
 * Instances are built by `TagRules` (and by `shortpixel/ai/customRules` /
 * `customFrontendRules` filter callbacks). The regex parser reads every property;
 * `VanillaJsLoader` turns the used lazy rules and the front-end rules into the JS
 * `doSelectors` list using `tag`, `attr`, `type`, `callback`, `frontEager`,
 * `frontResize`, `attrFilter` and `attrValFilter`. Mutable, no static state.
 */
class TagRule
{
    /**
     * Tag name(s) as a regex alternation inserted into the parser regex, e.g. `'img|amp-img'`.
     * An empty string is used for the attribute-only `data-ultimate-bg` rule.
     *
     * @var string
     */
    public $tag;
    /**
     * Attribute name(s) as a regex fragment, e.g. `'src'`, `'data-thumb(?:nail|)'`,
     * `'background-image|background'`.
     *
     * @var string
     */
    public $attr;
    /**
     * Class name the tag must have in its `class` attribute for the rule to apply, or false.
     *
     * @var string|false
     */
    public $classFilter;
    /**
     * Attribute name the tag must have for the rule to apply, or false.
     *
     * @var string|false
     */
    public $attrFilter;
    /**
     * Value the `attrFilter` attribute must contain, or false to require only its presence.
     *
     * @var string|false
     */
    public $attrValFilter;
    /**
     * Second attribute (e.g. `srcset`) whose URLs are merged into the CDN replacement of
     * the main attribute and then removed or rewritten, or false.
     *
     * @var string|false
     */
    public $mergeAttr;
    /**
     * True to always emit the final CDN URL (`data-spai-egr`) instead of a lazy placeholder.
     *
     * @var bool
     */
    public $eager;
    /**
     * Regex template key in `ShortPixelRegexParser::$regexes`: `url`, `srcset`,
     * `data-thumb`, `bg-attr`, `bg-style` or `ahref`.
     *
     * @var string
     */
    public $type;
    /**
     * Name of the `ShortPixelRegexParser` replacement method (e.g. `replace_custom_srcset`),
     * or false for the default `replace_images`.
     *
     * @var string|false
     */
    public $callback;
    /**
     * Cheap regex tested against the whole content first; when it does not match, the
     * rule is skipped. False to always run the rule.
     *
     * @var string|false
     */
    public $quickMatch; //this is a faster regex that checks if we go further for that particular
    /**
     * True to tell the front-end JS to replace the attribute eagerly (`lazy: false`).
     *
     * @var bool
     */
    public $frontEager;
    /**
     * True to keep the rule out of the front-end JS selector list (used for lightbox
     * hrefs that need no replacement until they become an img).
     *
     * @var bool
     */
    public $noFront; // this indicates not to send the rule to the (Vanilla)JS as it should remain as it is. For example a lazy img href that is used for a lightbox, it doesn't need to be replaced until it's in the final img.
    /**
     * Passed to the front-end JS as `resize`: whether the JS may resize the image.
     *
     * @var bool
     */
    public $frontResize;

    /**
     * Compression override passed to `ShortPixelAI::get_api_url()` (e.g. `'orig'`);
     * empty string for the configured compression. Only works with eager true.
     *
     * @var string
     */
    private $customCompression = '';
    /**
     * Callable (array form) that, if set, is called with the tag contents after
     * rewriting, e.g. `[TagRules, 'addCrossOrigin']`; false for none.
     *
     * @var callable|false
     */
    private $postProcessor = false;

    /**
     * Tags in which the rule matched during this request: tag name => `AffectedTags`
     * flag. Filled by the parsers and `TagRules::setUsed()`; read by `TagRules::usedLazy()`.
     *
     * @var array<string,int>
     */
    public $used = [];

    /**
     * Stores every argument in the property of the same name.
     *
     * @param string       $tag           Tag alternation, see `$tag`.
     * @param string       $attr          Attribute fragment, see `$attr`.
     * @param string|false $classFilter   Required class, see `$classFilter`.
     * @param string|false $attrFilter    Required attribute, see `$attrFilter`.
     * @param string|false $attrValFilter Required attribute value, see `$attrValFilter`.
     * @param string|false $mergeAttr     Attribute merged into the replacement, see `$mergeAttr`.
     * @param bool         $eager         Eager CDN URL instead of a lazy placeholder.
     * @param string       $type          Regex template key, see `$type`.
     * @param string|false $callback      Parser replacement method, see `$callback`.
     * @param string|false $quickMatch    Pre-check regex, see `$quickMatch`.
     * @param bool         $frontEager    Eager replacement in the front-end JS.
     * @param bool         $noFront       Keep out of the front-end JS.
     * @param bool         $frontResize   Allow front-end resizing.
     */
    public function __construct($tag, $attr, $classFilter = false, $attrFilter = false, $attrValFilter = false, $mergeAttr = false, $eager = false,
                                $type = 'url', $callback = false, $quickMatch = false, $frontEager = false, $noFront = false, $frontResize = true) {
        $this->tag = $tag;
        $this->attr = $attr;
        $this->classFilter = $classFilter;
        $this->attrFilter = $attrFilter;
        $this->attrValFilter = $attrValFilter;
        $this->mergeAttr = $mergeAttr;
        $this->eager = $eager;
        $this->type = $type;
        $this->callback = $callback;
        $this->quickMatch = $quickMatch;
        $this->frontEager = $frontEager;
        $this->noFront = $noFront;
        $this->frontResize = $frontResize;
    }

    /**
     * Returns the compression override.
     *
     * @return string The custom compression (e.g. `'orig'`), or an empty string when not set.
     */
    public function getCustomCompression()
    {
        return $this->customCompression;
    }

    /**
     * Sets the compression override; it only takes effect on eager rules.
     *
     * @param string $customCompression Compression token, e.g. `'orig'`.
     *
     * @return void
     */
    public function setCustomCompression($customCompression)
    {
        $this->customCompression = $customCompression;
    }

    /**
     * Sets the callable applied to the rewritten tag string.
     *
     * @param array $func Callable in array form, e.g. `[$tagRules, 'addCrossOrigin']`.
     *
     * @return void
     */
    public function setPostProcessor(array $func)
    {
        $this->postProcessor = $func;
    }

    /**
     * Returns the post-processor callable.
     *
     * @return array|false The callable array, or false when none is set.
     */
    public function getPostProcessor() {
        return $this->postProcessor;
    }
}
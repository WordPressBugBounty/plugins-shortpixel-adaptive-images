<?php
/**
 * JSON rewriter for AJAX/REST responses, `application/json` scripts and JSON attributes.
 *
 * @package ShortPixel\AI
 */

/**
 * Walks a decoded JSON tree and rewrites the image URLs in its string leaves.
 *
 * Callers: `ShortPixelAI::maybe_replace_images_src()` for whole JSON responses when
 * `settings.areas.parse_json` is on; `ShortPixelRegexParser::replace_application_json_script()`,
 * `replace_custom_json_attr()` and the `text/template` branch of `parse()`.
 */
class ShortPixelJsonParser {

    /**
     * @var ShortPixelAI The plugin controller.
     */
    protected $ctrl;
    /**
     * @var bool|mixed True to emit SVG placeholders (lazy) instead of CDN URLs.
     */
    protected $lazy;
    /**
     * @var ShortPixelAILogger Shared logger instance.
     */
    private $logger;
    /**
     * @var false|\ShortPixel\AI\TagRule The regex parser's current rule; its `used` map gets the
     *                                  tags that received lazy placeholders.
     */
    private $tagRule;

    /**
     * @param ShortPixelAI                 $ctrl           The plugin controller.
     * @param false|\ShortPixel\AI\TagRule $currentTagRule Rule of the calling regex parser, or false.
     * @param bool|mixed                   $lazy           false (default): lazy when
     *                                                     `settings.areas.parse_json_lazy` is on and
     *                                                     the rule (if any) is not eager; any other
     *                                                     value (including 0) is used as is.
     */
    function __construct(ShortPixelAI $ctrl, $currentTagRule = false, $lazy = false) {
        $this->ctrl = $ctrl;
        $this->lazy = $lazy === false ? ($ctrl->settings->areas->parse_json_lazy && (!$currentTagRule || !$currentTagRule->eager)) : $lazy;
        $this->logger = ShortPixelAILogger::instance();
        $this->tagRule = $currentTagRule;
    }

    /**
     * Rewrites the image URLs in a decoded JSON value.
     *
     * Input grammar: any `json_decode()` result (object, array, string, number, bool, null).
     * Recursion ({@see parseRecursive()}), per node:
     * - array / object: every value is parsed recursively; keys are kept; objects are modified in place.
     * - string that itself decodes to a JSON array/object: parsed recursively and re-encoded with
     *   `json_encode()` (default flags, so `\/` escaping).
     * - string that looks like HTML (`/^([\s↵]*(<!--[^>]+-->)*)*<\w*(\s[^>]*|)>/s`, i.e. starts
     *   with a tag after optional whitespace/comments): a new `ShortPixelRegexParser::parse()`.
     * - other string of 20 chars or more: {@see replaceUrls()}; shorter strings are unchanged.
     * - anything else: unchanged.
     *
     * Output grammar: the same structure (encode it with `json_encode()`), with string leaves
     * rewritten.
     *
     * Example (not lazy): `(object)['img' => 'https://example.test/a.jpg', 'n' => 1]` gives
     * `(object)['img' => 'https://cdn.shortpixel.ai/spai/q_lossy+ret_img/example.test/a.jpg', 'n' => 1]`.
     *
     * @todo parse() has unreachable code after `return`: the `affectedTags.record()` call (which
     *       also uses `.` instead of `->`). Remove it; callers already call `affectedTags->record()`.
     *
     * @param mixed $content Decoded JSON.
     * @return mixed The rewritten value.
     */
    function parse($content) {
        //return $content;
        (SHORTPIXEL_AI_DEBUG & ShortPixelAILogger::DEBUG_AREA_JSON) && $this->logger->log("******** JSON PARSER *********");
        return $this->parseRecursive($content);
        if(count($this->ctrl->affectedTags->get())) {
            $this->ctrl->affectedTags.record();
        }
    }

    /**
     * Recursive worker of {@see parse()}; see there for the per-node rules.
     *
     * @param mixed $content A JSON node.
     * @return mixed The rewritten node.
     */
    protected function parseRecursive($content) {
        if(is_array($content) || is_object($content) ) {
            foreach ($content as $key => $value) {
                if(is_array($content)) {
                    (SHORTPIXEL_AI_DEBUG & ShortPixelAILogger::DEBUG_AREA_JSON) && $this->logger->log("JSON: Key $key: ", $content[$key]);
                    $content[$key] = $this->parseRecursive($value);
                    //echo ("$key changed to: " . json_encode($content[$key]) . " \n\n");
                } else {
                    (SHORTPIXEL_AI_DEBUG & ShortPixelAILogger::DEBUG_AREA_JSON) && $this->logger->log("JSON: Key $key: ", $content->$key);
                    $content->$key = $this->parseRecursive($value);
                    //echo ("$key changed to: " . json_encode($content->$key) . " \n\n");
                }
            }
            return $content;
        } elseif(is_string($content)) {
            $parsed = json_decode($content);
            if(json_last_error() === JSON_ERROR_NONE && (is_array($parsed) || is_object($parsed))) {
                (SHORTPIXEL_AI_DEBUG & ShortPixelAILogger::DEBUG_AREA_JSON) && $this->logger->log("LEAF IS JSON");
                return json_encode($this->parseRecursive($parsed));
            } else {
                if(preg_match('/^([\s↵]*(<!--[^>]+-->)*)*<\w*(\s[^>]*|)>/s', $content)) {
                    //that's HTML, use the regex parser
                    (SHORTPIXEL_AI_DEBUG & ShortPixelAILogger::DEBUG_AREA_JSON) && $this->logger->log("LEAF IS HTML: " . $content);
                    $parser = new ShortPixelRegexParser($this->ctrl);
                    $ret = $parser->parse($content);
                    (SHORTPIXEL_AI_DEBUG & ShortPixelAILogger::DEBUG_AREA_JSON) && $this->logger->log("LEAF PARSED BY REGEX: " . $ret);
                    return $ret;
                } else {
                    //here replace the urls
                    (SHORTPIXEL_AI_DEBUG & ShortPixelAILogger::DEBUG_AREA_JSON) && $this->logger->log("LEAF IS PLAIN: " . $content);
                    if(strlen($content) <= 19) return $content;
                    $replaced = $this->replaceUrls($content);
                    return $replaced ? $replaced : $content;
                }
            }
        } else {
            return $content;
        }
    }

    /**
     * Rewrites every URL-like token of a plain string with {@see replaceUrl()}.
     *
     * The token regex (case-insensitive, unicode) is the "liberal URL" pattern: `http(s)://`,
     * `wwwN.` or `domain.tld/` followed by non-space chars and balanced parentheses, not ending in
     * punctuation. The extension filter happens in `replaceUrl()` (the stricter extension-based
     * regex caused catastrophic backtracking). Relative paths (`/wp-content/a.jpg`) are not matched.
     * Scheme-less tokens are: in `//example.test/a.jpg` the token is `example.test/a.jpg`.
     *
     * @todo replaceUrls() matches scheme-less tokens (`example.test/a.jpg` inside a
     *       protocol-relative `//example.test/a.jpg`, or `www.example.test/a.jpg`), which
     *       `absoluteUrl()` then resolves relative to the current page, giving a wrong CDN URL
     *       (and `//` + CDN URL for the protocol-relative case). Include a leading `//` in the
     *       token and prefix `www`/domain tokens with the current scheme before `absoluteUrl()`.
     *
     * When lazy, every non-`img` tag in the result that carries a `data:image/svg+xml;` value is
     * added to `affectedTags` and to `$tagRule->used` with `SRC_ATTR | CSS_ATTR`.
     *
     * @param string $text A string leaf.
     * @return string|null The rewritten string (null on PCRE failure; the caller then keeps the original).
     */
    protected function replaceUrls($text) {
        (SHORTPIXEL_AI_DEBUG & ShortPixelAILogger::DEBUG_AREA_JSON) && $this->logger->log("try replace URLs in $text \n\n");

        //the below expression still produces catastrophic backtracking in some cases, use this one instead (and test the extension inside replaceUrl, as it's the main culprit):
        $pattern = '/(?i)\b((?:https?:\/\/|www\d{0,3}[.]|[a-z\d.\-]+[.][a-z]{2,4}\/)(?:[^\s()<>]+|\(([^\s()<>]+|(\([^\s()<>]+\)))*\))+(?:\(([^\s()<>]+|(\([^\s()<>]+\)))*\)|[^\s`!()\[\]{};:\'".,<>?«»“”‘’]))/su';

        //this expression is better because it works with characters such as three bytes accented letters (é vs. é - notice any difference? the first is on three bytes, second is UTF8...)
        //$pattern = '/(?:https?:\/\/)(?:\S+(?::\S*)?@)?(?:(?!10(?:\.\d{1,3}){3})(?!127(?:\.\d{1,3}){3})(?!169\.254(?:\.\d{1,3}){2})(?!192\.168(?:\.\d{1,3}){2})(?!172\.(?:1[6-9]|2\d|3[0-1])(?:\.\d{1,3}){2})(?:[1-9]\d?|1\d\d|2[01]\d|22[0-3])(?:\.(?:1?\d{1,2}|2[0-4]\d|25[0-5])){2}(?:\.(?:[1-9]\d?|1\d\d|2[0-4]\d|25[0-4]))|(?:(?:[a-z\x{00a1}-\x{ffff}0-9]+-?)*[a-z\x{00a1}-\x{ffff}0-9]+)(?:\.(?:[a-z\x{00a1}-\x{ffff}0-9]+-?)*[a-z\x{00a1}-\x{ffff}0-9]+)*(?:\.(?:[a-z\x{00a1}-\x{ffff}]{2,})))(?::\d{2,5})?(?:\/[^\s]*)?\.(?:jpe?g|png|gif|webp|avif|svg)\b/su';
        //$pattern = "/(\bhttps?:|)\/\/(?:www\.)?[-a-zA-Z0-9@:%._\+~#=]{2,256}\.[a-z]{2,4}\b(?:[-a-zA-Z0-9@:%_\+.~#?&\/\/=\(\)]*)\.(jpe?g|png|gif)\b/s";

        $ret = preg_replace_callback($pattern, array($this, 'replaceUrl'), $text);

        if($this->lazy) {
            //the text might be HTML, we need to mark the possible tags that have lazy replacements
            preg_match_all('/\<([\w]+)[^\>]+data:image\/svg\+xml;/s', stripslashes($ret), $matches);
            if(isset($matches[1])) {
                foreach($matches[1] as $tag) {
                    if(strtolower($tag) !== 'img') {
                        $flags = \ShortPixel\AI\AffectedTags::SRC_ATTR | \ShortPixel\AI\AffectedTags::CSS_ATTR;
                        $this->ctrl->affectedTags->add($tag, $flags);
                        if($this->tagRule) $this->tagRule->used[$tag] = $flags;
                    }
                }
            }
        }
        return $ret;
    }

    /**
     * Rewrites one URL token.
     *
     * Unchanged when it contains `settings.behaviour.api_url`, or (after `absoluteUrl()`) its
     * extension is not in `ShortPixelUrlTools::$PROCESSABLE_EXTENSIONS`, or it is
     * `urlIsExcluded()` or not `isValid()`. Otherwise lazy:
     * `generate_placeholder_svg(<get_image_size()>, $url)`; eager:
     * `get_api_url($url, false, false, <ext>)` (no `w_` token).
     *
     * @param string[] $match [0] the token.
     * @return string The replacement.
     */
    protected function replaceUrl($match) {
        (SHORTPIXEL_AI_DEBUG & ShortPixelAILogger::DEBUG_AREA_JSON) && $this->logger->log('Matches' . json_encode($match) . "\n");
        if(strpos($match[0], $this->ctrl->settings->behaviour->api_url) === false) {
            $url = ShortPixelUrlTools::absoluteUrl($match[0]);
            $ext = $this->ctrl->get_extension($url);
            if(!in_array($ext, ShortPixelUrlTools::$PROCESSABLE_EXTENSIONS) || $this->ctrl->urlIsExcluded($url) || !ShortPixelUrlTools::isValid($url)) {
                return $match[0];
            }
            if($this->lazy) {
                $sizes = ShortPixelUrlTools::get_image_size($url);
                $ret = ShortPixelUrlTools::generate_placeholder_svg(isset($sizes[0]) ? $sizes[0]: false, isset($sizes[1]) ? $sizes[1]: false, $url);
            } else {
                $ret = $this->ctrl->get_api_url($url, false, false, $ext);
            }
            (SHORTPIXEL_AI_DEBUG & ShortPixelAILogger::DEBUG_AREA_JSON) && $this->logger->log("Changing to $ret.\n\n");
            return $ret;
        } else {
            return $match[0];
        }
    }
}
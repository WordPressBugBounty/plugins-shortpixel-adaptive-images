<?php
/**
 * Helps with holding the affected tags list and their classes, in order to limit the JS load.
 */

namespace ShortPixel\AI;

/**
 * Registry of the HTML tag names whose image URLs the parsers rewrote, with a bitmask
 * of how each tag was affected.
 *
 * One instance lives in `ShortPixelAI::$affectedTags` (created in the `ShortPixelAI`
 * constructor); it is not a singleton and holds no static state. The regex, CSS, JS
 * and JSON parsers call add(); the front-end loaders read it: `JqueryJsLoader`
 * injects getAll() as JSON into the `{{SPAI-AFFECTED-TAGS}}` placeholder (or a
 * `spai_affectedTags` inline script before `</body>`), and `VanillaJsLoader` uses
 * filter() to decide whether to emit the lazy `img` and lazy background logic.
 *
 * Persistence: tags seen while parsing AJAX/JSON responses are stored in the
 * `spai_settings_lazy_ajax_tags` option (array tag name => int flags) by record(),
 * which `ShortPixelAI::maybe_replace_images_src()` calls after parsing JSON
 * content or an HTML fragment during AJAX. The option is read back on construction,
 * so later full-page loads already know the tags that AJAX content will need.
 * A test must delete that option to start from an empty registry.
 */
class AffectedTags {

    /**
     * Flag bits combined with `|` in the per-tag value.
     *
     * - SRC_ATTR (1): a URL attribute (src, srcset, data-* URL) of the tag was rewritten or lazy-loaded.
     * - CSS_ATTR (2): a CSS background (inline `style`, bg attribute or `<style>` rule) applies to the tag.
     * - SCRIPT_ATTR (4): image URLs were found inside a `<script>` (set by the CSS parser on the `script` tag).
     * - INNER_CSS (8): inner CSS of the tag is affected; defined but not set by any caller.
     */
    const SRC_ATTR = 1;
    const CSS_ATTR = 2;
    const SCRIPT_ATTR = 4;
    const INNER_CSS = 8;

    /**
     * Tag name => int flag bitmask (see the flag constants). Seeded from the
     * `spai_settings_lazy_ajax_tags` option on construction.
     *
     * @var array<string,int>
     */
    private $affectedTags;
    /**
     * Intended map of tag => classes for getSelectors(). Initialised to an empty
     * array and never filled by any method.
     *
     * @var array
     */
    private $classMap;
    /**
     * Logger instance, set in the constructor.
     *
     * @var \ShortPixelAILogger|false
     */
    protected $logger = false;

    /**
     * Loads the recorded tags from `spai_settings_lazy_ajax_tags` and grabs the logger.
     */
    public function __construct() {
        $this->affectedTags = $this->getRecorded();
        $this->classMap = [];
        $this->logger = \ShortPixelAILogger::instance();
        //(SHORTPIXEL_AI_DEBUG & \ShortPixelAILogger::DEBUG_AREA_HTML) && $this->logger->log("NEW AFFECTED TAGS - type " . gettype($this->affectedTags));
    }

    /**
     * Returns the flags of one tag, or the whole in-memory registry.
     *
     * @param string|false $tag Tag name, or false for all tags.
     *
     * @return int|false|array<string,int> The tag's flag bitmask, false when the tag is not
     *                                     registered, or the full tag => flags array.
     */
    public function get($tag = false) {
        if($tag) {
            return isset($this->affectedTags[$tag]) ? $this->affectedTags[$tag] : false;
        }
        else return $this->affectedTags;
    }

    /**
     * Returns the grouped class selectors of the affected tags.
     *
     * No caller in the plugin uses it.
     *
     * @return mixed
     *
     * @todo getSelectors() calls `$this->group()`, which is not defined in this class, so any
     *       call is a fatal "Call to undefined method" error; `$classMap` is also never filled.
     *       Implement group() and populate `$classMap`, or delete getSelectors() and `$classMap`.
     */
    public function getSelectors() {
        return $this->group($this->classMap);
    }

    /**
     * ORs the given flags into the tag's entry, creating it with 0 first.
     *
     * Ignored unless the tag name, with dashes removed, is alphanumeric (so custom
     * elements such as `lite-youtube` are accepted and selectors such as `img|div` are not).
     * Logs under `DEBUG_AREA_HTML`.
     *
     * @param string $tag  Tag name.
     * @param int    $type One or more flag constants combined with `|`.
     *
     * @return void
     */
    public function add($tag, $type) {
        if(ctype_alnum(str_replace('-', '', $tag))) { //allow custom elements, e.g. lite-youtube
            if(!isset($this->affectedTags[$tag])) {
                $this->affectedTags[$tag] = 0;//(object)['srcAttrs' => [], 'bgClasses' => [], 'srcsetAttrs' => [], 'innerCss' => false];
            }
            $this->affectedTags[$tag] = $type | $this->affectedTags[$tag];
            (SHORTPIXEL_AI_DEBUG & \ShortPixelAILogger::DEBUG_AREA_HTML) && $this->logger->log("ADDED AFFECTED TAG " . $tag . " AS " . $type);
        }
    }

    /**
     * Removes the tag from the in-memory registry only.
     *
     * The recorded option is untouched, so getAll() still returns the tag if it was
     * recorded earlier (the regex parser removes `img` when the jQuery loader is used).
     *
     * @param string $tag Tag name.
     *
     * @return void
     */
    public function remove($tag) {
        unset($this->affectedTags[$tag]);
        (SHORTPIXEL_AI_DEBUG & \ShortPixelAILogger::DEBUG_AREA_HTML) && $this->logger->log("REMOVED AFFECTED TAG " . $tag);
    }

    /**
     * Persists the in-memory tags to the `spai_settings_lazy_ajax_tags` option.
     *
     * Writes (merging with the stored value, flags ORed) only when at least one in-memory
     * tag is missing from the option or has a different flag value; otherwise it does
     * not call update_option().
     *
     * @return void
     */
    public function record() {
        $affectedTags = $this->getRecorded();
        foreach($this->affectedTags as $tag => $val) {
            if(!isset($affectedTags[$tag]) || ($affectedTags[$tag] !== $val)) {
                //this makes sure that we don't run the update if all the affected tags are already recorded.
                update_option('spai_settings_lazy_ajax_tags', $this->mergeTags($this->affectedTags, $affectedTags));
                return;
            }
        }
    }

    /**
     * Returns the in-memory tags that should carry the given flag.
     *
     * @param int $flag Flag constant to filter by.
     *
     * @return array<string,int> Tag name => flags.
     *
     * @todo filter() tests `$flags | $flag`, which is non-zero for any non-zero `$flag`, so it
     *       returns every registered tag instead of only those with the flag set; as a result
     *       `VanillaJsLoader` emits the lazy img and background logic whenever any tag is
     *       registered. Use `$flags & $flag`.
     */
    public function filter($flag) {
        $filtered = [];
        foreach ($this->affectedTags as $tag => $flags) {
            if($flags | $flag) {
                $filtered[$tag] = $flags;
            }
        }
        return $filtered;
    }

    /**
     * Returns the tags stored in the `spai_settings_lazy_ajax_tags` option.
     *
     * @return array<string,int> Tag name => flags; an empty array when the option is absent.
     */
    public function getRecorded() {
        return get_option('spai_settings_lazy_ajax_tags', array());
    }

    /**
     * Returns the in-memory tags merged with the stored option (flags ORed per tag).
     *
     * @return array<string,int> Tag name => flags.
     */
    public function getAll() {
        return $this->mergeTags($this->affectedTags, get_option('spai_settings_lazy_ajax_tags', array()));
    }

    /**
     * Merges two tag => flags arrays, ORing the flags of tags present in both.
     *
     * @param array<string,int> $tags     Tags to merge in.
     * @param array<string,int> $moreTags Base array.
     *
     * @return array<string,int> `$moreTags` with every entry of `$tags` ORed in.
     */
    protected function mergeTags($tags, $moreTags) {
        foreach($tags as $key => $val) {
            $moreTags[$key] = (isset($moreTags[$key]) ? $moreTags[$key] : 0) | $val;
        }
        return $moreTags;
    }


}
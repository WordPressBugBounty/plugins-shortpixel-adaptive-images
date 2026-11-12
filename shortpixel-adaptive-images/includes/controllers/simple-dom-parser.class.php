<?php
/**
 * DOM-based HTML rewriter. DEAD CODE: never instantiated.
 *
 * The root plugin file requires this file, so the class is declared on every request, but
 * `ShortPixelAI::__construct()` hardcodes `ShortPixelRegexParser` (the line creating this parser
 * is commented out) and nothing else references it.
 *
 * @package ShortPixel\AI
 */

/**
 * Abandoned alternative to `ShortPixelRegexParser` built on the simple_html_dom library.
 *
 * It cannot run as is:
 * 1. `parse()` calls `str_get_html()`, and `parseNode()` uses `HDOM_TYPE_ELEMENT` /
 *    `HDOM_TYPE_TEXT`; simple_html_dom is not bundled, so the call is a fatal
 *    "undefined function" error (and the constants an "undefined constant" Error on PHP 8).
 * 2. `parseNode()` calls `parseVisualNode($node, $exceptions)` with 2 arguments but the method
 *    requires 4: ArgumentCountError.
 * 3. `replaceUrl()` reads `->image` / `->meta` on the string that
 *    `ShortPixelUrlTools::generate_placeholder_svg()` returns.
 * Further: `$rulesMap` / `$exceptions` are dynamic properties (deprecated since PHP 8.2), the
 * `in_array()` arguments in `parseVisualNode()` are reversed, and `$cssClasses` is undefined
 * there when the node has no `class` attribute.
 *
 * @todo ShortPixelSimpleDomParser is dead and unusable (missing library, wrong argument count,
 *       string used as object). Delete the class and its `require_once` in the root plugin file,
 *       together with `TagRules::map()` and `ShortPixelCssParser::replace_in_tag_style_backgrounds()`.
 */
class ShortPixelSimpleDomParser {
    /**
     * @var ShortPixelAI The plugin controller.
     */
    protected $ctrl;
    /**
     * @var bool Global lazy switch; combined with each rule's `lazy` flag in `replaceUrl()`.
     */
    protected $lazy = true;

    /**
     * @var ShortPixelAILogger Shared logger instance.
     */
    private $logger;
    /**
     * @var ShortPixelCssParser The controller's CSS parser.
     */
    private $cssParser;

    /**
     * Stores the controller, logger and CSS parser, builds `$this->rulesMap` from
     * `TagRules::_()->map()` and sets `$this->exceptions` to an empty array (the loop over
     * `$ctrl->exclusions` has an empty body). Both are undeclared (dynamic) properties.
     *
     * @param ShortPixelAI $ctrl The plugin controller.
     */
    public function __construct(ShortPixelAI $ctrl)
    {
        $this->ctrl = $ctrl;
        $this->logger = ShortPixelAILogger::instance();
        $this->cssParser = $ctrl->getCssParser();
        $this->rulesMap = \ShortPixel\AI\TagRules::_()->map();
        $exceptions = $this->ctrl->exclusions;
        $this->exceptions = array();
        foreach($exceptions as $exception) {
            //$this->exceptions[] = aici folosim saberworm probabil.
        }
        //$this->lazy = true; //TODO option
    }

    /**
     * Intended to load `$content` into a simple_html_dom tree, rewrite every node and return the
     * tree. Fatal: `str_get_html()` is undefined (library not bundled).
     *
     * @param string $content HTML.
     * @return object The simple_html_dom document (never reached).
     */
    public function parse($content)
    {
        $this->logger->log('******** DOM PARSER *********');
        $dom = str_get_html($content);

        $nodes = $dom->childNodes();

        //step 1: parse the elements
        foreach($dom->childNodes() as $childNode) {
            $this->parseNode($childNode, $this->exceptions);
        }
        //$this->logger->log(' RULESSS: ', $this->rulesMap);
        return $dom;
    }

    /**
     * Visits a node recursively: `<style>` bodies go through
     * `ShortPixelCssParser::replace_inline_style_backgrounds()`, `<script>` and `<link>` are left
     * alone, other elements go to `parseVisualNode()` (with 2 of its 4 required arguments, which
     * throws ArgumentCountError), text nodes are logged.
     *
     * @param object $node       simple_html_dom node.
     * @param array  $exceptions Unused exclusion list (always empty).
     * @return void
     */
    private function parseNode(&$node, $exceptions) {

        //$this->logger->log("SIMPLE Node class " . get_class($node));
        //$this->logger->log("SIMPLE Node type ". $node->nodetype ." name ". $node->tag ." attributes ", $node->attr);

        switch($node->nodetype) {
            case HDOM_TYPE_ELEMENT: //html tag

                switch($node->tag) {
                    case 'script':
                        //$this->logger->log("SIMPLE script!! ");
                        break;
                    case 'style':
                        //$this->logger->log("SIMPLE inline style!! " . $node->innertext);
                        $node->innertext = $this->cssParser->replace_inline_style_backgrounds($node->innertext);
                        break;
                    case 'link':
                        if(isset($node->attr['rel']) && ($node->attr['rel'] === 'stylesheet' || $node->attr['rel'] === 'preload') && isset($node->attr['href'])) {
                            //TODO aici adaugam la URL https://cdn.shortpixel.ai/spai/q_minify/
                            //$this->logger->log("SIMPLE css!! " . $node->attr['href']);
                        }
                        break;
                    default:
                        $this->parseVisualNode($node, $exceptions);
                        break;
                }


                if(count($node->childNodes())) {
                    //$this->logger->log("SIMPLE Node " . $node->tag . " children: " . count($node->childNodes()));
                }

                foreach($node->childNodes() as $childNode) {
                    $this->parseNode($childNode, $exceptions);
                }
                break;
            case HDOM_TYPE_TEXT:
                $this->logger->log('TEXT ' . $node->innertext());
                break;
            default:
                $this->logger->log("TODO: " . $node->nodetype);
                break;

        }
    }

    /**
     * Logs a node's tag, attributes and CSS classes.
     *
     * @param object $node simple_html_dom node.
     * @return void
     */
    private function logNode($node) {
        $attributes = " ATTRIBUTES: ";
        $cssClasses = array();
        foreach($node->attr as $attrName => $val) {
            $attributes .= $attrName . '="' . $val . '" ';
            switch($attrName) {
                case 'class':
                    $cssClasses = explode(' ', $val);
                    break;
            }
        }
        $this->logger->log( 'CHECKING ' . $node->tag . $attributes . " CSS class: ", $cssClasses);
    }

    /**
     * Applies the `TagRules::map()` rules for the node's tag plus the `*` rule: filters by
     * attribute, attribute value and class, then calls the rule's `customReplacer` or
     * {@see replaceUrl()}; sets `data-spai="1"` when anything changed.
     *
     * @param object $node     simple_html_dom node.
     * @param mixed  $exclude  Unused.
     * @param mixed  $noresize Unused.
     * @param mixed  $eager    Unused.
     * @return void
     */
    private function parseVisualNode(&$node, $exclude, $noresize, $eager) {

        if(isset($node->attr['class'])) {
            $cssClasses = explode(' ', $node->attr['class']);
        }

        $changed = false;
        if(isset($this->rulesMap[$node->tag]) || $node->tag === '*') { //* matches all nodes
            if(isset($node->attr['style']) && strpos($node->attr['style'], 'background') !== false) $this->logNode($node);
            foreach(array_merge($this->rulesMap[$node->tag], $this->rulesMap['*'])  as $rule) {

//                if(isset($node->attr['style']) && strpos($node->attr['style'], 'background') !== false) $this->logger->log(" TRY RULE:" . json_encode($rule));

                if(!isset($node->attr[$rule->attr])) {
//                    if(isset($node->attr['style']) && strpos($node->attr['style'], 'background') !== false) $this->logger->log(" UNAA");
                    continue;
                }
                if(   $rule->attrFilter
                   && (   !isset($node->attr[$rule->attrFilter])
                       || ($rule->attrValFilter && $node->attr[$rule->attrFilter] != $rule->attrValFilter))) {
//                    if(isset($node->attr['style']) && strpos($node->attr['style'], 'background') !== false) $this->logger->log(" dooo");
                    continue;
                }
                if($rule->classFilter && !in_array($cssClasses, $rule->classFilter)) {
//                    if(isset($node->attr['style']) && strpos($node->attr['style'], 'background') !== false) $this->logger->log(" 3");
                    continue;
                }
                //if(isset($node->attr['style']) && strpos($node->attr['style'], 'background') !== false) $this->logger->log(" TRY RULE:" . json_encode($rule));

                if($rule->customReplacer) {
                    $attr = $node->attr[$rule->attr];
                    $this->logger->log("CUSTOM REPLACE in $attr using CUSTOM REPLACER " . json_encode($rule->customReplacer));
                    $changedAttr = call_user_func($rule->customReplacer, $attr);
                    if($changedAttr !== $attr) {
                        $changed = true;
                        $node->attr[$rule->attr] = $changedAttr;
                    }
                } else {
                    $changed = $changed || $this->replaceUrl($node, $rule);
                }
            }
        }
        if($changed) {
            $node->__set('data-spai', '1');
        }
    }

    /**
     * Replaces the rule attribute's URL with a placeholder (lazy) or CDN URL (eager), picking the
     * widest valid candidate of the merge attribute first. Written against the obsolete
     * placeholder API (object with `image` and `meta`); with the current string return value the
     * lazy branch reads properties of a string.
     *
     * @param object $node simple_html_dom node.
     * @param object $rule Rule node from `TagRules::map()`.
     * @return bool True when the attribute was replaced.
     */
    private function replaceUrl(&$node, $rule) {
        $url = $node->attr[$rule->attr];
        //$this->logger->log( 'MATCHES RULE ' . $node->tag  . " with " . $rule->attr . " VALUE: " . $url);
        if(ShortPixelUrlTools::isValid($url)){
            $sizes = ShortPixelUrlTools::get_image_size($url);

            if($rule->mergeAttr && isset($node->attr[$rule->mergeAttr])) {
                $extra = $node->attr[$rule->mergeAttr];
                $extraUrls = explode(',', $extra);
                foreach($extraUrls as $extraUrl) {
                    $extraParts = explode(' ', trim($extraUrl));
                    if(ShortPixelUrlTools::isValid($extraParts[0])) {
                        $extraSizes = ShortPixelUrlTools::get_image_size($extraParts[0]);
                        if($extraSizes[0] > $sizes[0]) {
                            $url = $extraParts[0];
                            $sizes = $extraSizes;
                        }
                    }
                }
            }

            if($this->lazy && $rule->lazy) {
                $data = isset($sizes[0]) ? ShortPixelUrlTools::generate_placeholder_svg($sizes[0], $sizes[1], $url) : ShortPixelUrlTools::generate_placeholder_svg(false, false, $url);
                $inlinePlaceholder = $data->image;
                $spaiMeta = $data->meta;
            } else {
                $inlinePlaceholder = $this->ctrl->get_api_url($url, false, false, $this->ctrl->get_extension( $url ));
                $spaiMeta = false;
            }
            //$this->logger->log( '------ REPLACING ' . $rule->attr  . " with " . $inlinePlaceholder);
            $node->__set($rule->attr, $inlinePlaceholder);
            if($spaiMeta) {
                $node->__set('data-spai-' . $rule->attr . '-meta', $spaiMeta);
            }
            return true;
        }
        return false;
    }

    /**
     * this method is NOT USED
     * @param $node
     * @param $rule
     * @return bool
    private function replaceBackground(&$node, $rule) {
        if(isset($node->attr['style']) && strpos($node->attr['style'], 'background') !== false) {
            $this->logger->log( '------****** REPLACING BACKGROUND ' . $node->attr['style']);
            $style = preg_replace_callback(
                '/(^|\s|;)(background-image|background)\s*:([^;]*[,\s]|\s*)url\((?:\'|")?([^\'"\)]*)(\'|")?\s*\)/s',
                array(&$this->cssParser, 'replace_background_image'),
                $node->style);
            $this->logger->log( '------******------ REPLACED BACKGROUND ' . $node->style  . " with " . $style);
            $node->style = $style;
            return true;
        }
        return false;
    }
     */

}
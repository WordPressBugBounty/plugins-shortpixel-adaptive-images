<?php
/**
 * WP-CLI commands of SPAI (file loaded only when `WP_CLI` is defined).
 */

/**
 * `wp shortpixel <command>` (WP-CLI turns the method underscores into dashes):
 * - `clear-css`: increments `flags.all.css_ver` in `short_pixel_ai_options` (new `v_` token for CSS/JS CDN URLs);
 * - `cleanup-postmeta-pseudourls`: rewrites post meta values that contain SPAI SVG placeholders back to the original URLs;
 * - `clear-lqips`: deletes option `shortpixel_ai_lqip_state` and the `uploads/spai/lqip` cache directory.
 */
class ShortPixelCLI extends WP_CLI_Command {
    /**
     * Clear the CSS cache.
     *
     * Calls `ShortPixelAI::clear_css_cache()` and prints whether the option write succeeded.
     *
     * @return void
     */
    function clear_css() {
        if(!!ShortPixelAI::clear_css_cache()) {
            WP_CLI::line( 'CSS cache cleared successfully.' );
        } else {
            WP_CLI::line( 'Could not clear CSS cache.' );
        }
    }

    /**
     * Restores original URLs in post meta that stored SPAI placeholders.
     *
     * Selects rows of `<prefix>postmeta` whose `meta_value` is LIKE `%data:image_svg+xml;base64%`; serialized values are
     * unserialized, cleaned recursively and re-serialized, plain strings are cleaned directly; rows with replacements are
     * updated by `meta_id`. Prints progress lines.
     *
     * @return void
     */
    function cleanup_postmeta_pseudourls() {
        global $wpdb;
        //SELECT * FROM `wp_postmeta WHERE meta_value like '%data:image/svg+xml;base64%'
        WP_CLI::line( 'CLEANUP POSTMETA PSEUDO-URLS');
        //SELECT * FROM `wp_postmeta WHERE meta_value like '%data:image/svg+xml;base64%'
        $sql = "SELECT * FROM " . $wpdb->get_blog_prefix() . "postmeta WHERE meta_value like '%data:image_svg+xml;base64%'";
        $rows = $wpdb->get_results( $sql );
        WP_CLI::line( 'Postmeta query returned ' . count($rows) . ' rows.' );
        foreach($rows as $row) {
            //WP_CLI::line( 'FIRST ROW:' . print_r($row->meta_value, true));return;
            $data = @unserialize($row->meta_value);
            //WP_CLI::line( 'FIRST ROW UNSERIALIZED:' . print_r($data, true));return;
            if ($data !== false) {
                WP_CLI::line( 'Checking serialized ' . $row->meta_key . '...' );
                $replacements = $this->clean_item($data);
                $value = serialize($data);
            }
            else {
                WP_CLI::line( 'Checking string ' . $row->meta_key . '...' );
                $value = $row->meta_value;
                $replacements = $this->clean_item($value);
            }
            if($replacements) {
                WP_CLI::line( 'HAVE ' . $replacements . ' REPLACEMENTS, UPDATE: ' . print_r(
                        $wpdb->update( $wpdb->prefix . 'postmeta', ['meta_value' => $value], ['meta_id' => $row->meta_id]), true));
                //$rows = $wpdb->query( $wpdb->prepare($sql, $value, $ro->meta_id));
            }
        }

    }

    /**
     * Replaces each `data:image/svg+xml;base64,...` placeholder in `$item` (string, or array/object recursively) with the
     * URL decoded by `ShortPixelUrlTools::url_from_placeholder_svg()`.
     *
     * @param mixed $item Modified by reference
     * @return int Number of replacements
     * @todo The base64 pattern `[a-zA-Z0-9=\+]+` in `clean_item()` omits `/`, so placeholders whose base64 contains `/` are cut short and not decoded. Add `/` to the class.
     * @todo For objects, `clean_item()` writes `$item[ $key ]`, which throws an Error ("Cannot use object as array"); use `$item->$key` for objects.
     */
    private function clean_item(&$item) {
        $count = 0;
        if(is_array($item) || is_object($item)) {
            foreach($item as $key => $value) {
                $count += $this->clean_item($value);
                $item[$key] = $value;
            }
        } elseif(is_string($item)) {
            preg_match_all('/data:image\/svg\+xml;base64,[a-zA-Z0-9=\+]+/', $item, $matches);
            if(isset($matches[0])) foreach($matches[0] as $match) {
                $decoded = ShortPixelUrlTools::url_from_placeholder_svg($match);

                WP_CLI::line( 'MATCH ' . $match);
                if(strlen($decoded)) {
                    $count++;
                    WP_CLI::line( 'REPLACE WITH: ' . $decoded);
                    $item = str_replace($match, $decoded, $item);
                }

            }
        }
        return $count;
    }


    /**
     * Clear the LQIPs
     *
     * Calls `LQIP::clearCache()` and always prints "LQIP images cleared." regardless of its result.
     *
     * @return void
     */
    function clear_lqips() {
        \ShortPixel\AI\LQIP::clearCache();
        WP_CLI::line( 'LQIP images cleared.' );
    }
}

// Registers the `wp shortpixel` command namespace.
WP_CLI::add_command( 'shortpixel', 'ShortPixelCLI' );
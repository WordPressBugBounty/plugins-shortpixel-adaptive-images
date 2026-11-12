<?php
namespace ShortPixel\AI;

/**
 * Extra text under the plugin's "There is a new version" row on the Plugins screen.
 *
 * Hooked by `ShortPixelAI` on `in_plugin_update_message-<plugin basename>` (priority 10, 2 args). Sources:
 * - a PHP compatibility warning built from `$response->requires_php`;
 * - a "major"/"minor" heads-up computed from `$response->new_version`;
 * - the matching block of the `== Upgrade Notice ==` section of
 *   `https://plugins.svn.wordpress.org/shortpixel-adaptive-images/trunk/readme.txt`, cached one day in the transient
 *   `spai_update_notice_<new_version>`.
 *
 * Readme block grammar (inside `== Upgrade Notice ==`):
 *
 *     = 3.1.0 =
 *     **Bold**, __italic__ and [links](https://…) are converted; one `<span>` per line.
 *     Placeholders: {{ NEW VERSION }}, {{ CURRENT VERSION }}, {{ PHP VERSION }}, {{ REQUIRED PHP VERSION }}
 *     = 3.1.0:END =
 *
 * A block without its `:END` marker runs to the end of the file. The version tried, in order, is
 * `maj.min.micro.build`, `maj.min.micro`, `maj.min.0`, `maj.min`, `maj.0.0`, `maj.0`, skipping candidates lower than
 * the installed `SHORTPIXEL_AI_VERSION`; the first block found wins.
 */
class PluginUpdateNotice {

    /**
     * Echoes the update notice for the plugin row.
     *
     * When the running PHP is older than `requires_php` it echoes only the "do not update" warning. Otherwise it adds
     * "x is a major update" (new version `X.0` / `X.0.0` above the installed one) or "x is a minor update"
     * (`X.Y` / `X.Y.0`, `Y != 0`, above the installed one), then the readme notice. Output goes through
     * `wp_kses_post()`.
     *
     * @todo `in_plugin_update_message()` passes `$response->requires_php` to `version_compare()` without checking it is
     *       set (null on PHP 8.1+ is deprecated) and reads `$version_parts[1]` without checking the version has a dot.
     *
     * @param array  $data     Plugin data row (unused).
     * @param object $response Update API response (`new_version`, `requires_php`, …).
     *
     * @return void
     */
    public static function in_plugin_update_message( $data, $response ) {
        $version_parts = explode( '.', $response->new_version );

        $notice = '';

        if ( version_compare( PHP_VERSION, $response->requires_php, '<' ) ) {
            $notice .= '<span>' . sprintf( __( '<strong>Heads up! We do not recommend to update!</strong> ShortPixel Adaptive Images version <strong>%s</strong> is not compatible with your PHP version.', 'shortpixel-adaptive-images' ), $response->new_version ) . '</span>';
            $notice .= '<span>' . sprintf( __( 'The new ShortPixel Adaptive Images version requires at least PHP <strong>%s</strong> and your PHP version is <strong>%s</strong>', 'shortpixel-adaptive-images' ), $response->requires_php, PHP_VERSION ) . '</span>';

            echo wp_kses_post( $notice );

            return;
        }

        // Major
        if ( $version_parts[ 1 ] == '0' && ( isset( $version_parts[ 2 ] ) ? $version_parts[ 2 ] == '0' : true ) && version_compare( $version_parts[ 0 ] . '.' . $version_parts[ 1 ], SHORTPIXEL_AI_VERSION, '>' ) ) {
            $notice .= '<span>' . sprintf( __( '<strong>Heads up!</strong> %s is a %s update.', 'shortpixel-adaptive-images' ), $response->new_version, __( 'major', 'shortpixel-adaptive-images' ) ) . '</span>';
        }
        // Minor update message
        else if ( $version_parts[ 1 ] != '0' && ( isset( $version_parts[ 2 ] ) ? $version_parts[ 2 ] == '0' : true ) && version_compare( $version_parts[ 0 ] . '.' . $version_parts[ 1 ], SHORTPIXEL_AI_VERSION, '>' ) ) {
            $notice .= '<span>' . sprintf( __( '<strong>Heads up!</strong> %s is a %s update.', 'shortpixel-adaptive-images' ), $response->new_version, __( 'minor', 'shortpixel-adaptive-images' ) ) . '</span>';
        }

        $notice .= self::get_update_notice( $response );

        echo wp_kses_post( $notice );
    }


    /**
     * Returns the readme upgrade notice for `$response->new_version`, from the transient or from wordpress.org.
     *
     * Fetches the trunk `readme.txt` with `Request::get( …, false )` on a transient miss and caches the parsed result
     * (possibly `''`) for `DAY_IN_SECONDS`. An empty response is not cached, so it is fetched again next time.
     *
     * @todo `get_update_notice()` passes the `Request::get()` result to `substr()` for logging and to the parser
     *       without checking `is_wp_error()`; on a transport error `substr( WP_Error )` throws a `TypeError` on PHP 8.
     *       Treat a `WP_Error` as empty (and cache the miss briefly).
     *
     * @param object $response WordPress response
     *
     * @return string|false Notice HTML; false when nothing is cached and the download is empty.
     */
    private static function get_update_notice( $response ) {
        \ShortPixelAILogger::instance()->log("UPDATE NOTICE?", $response);
        $transient_name = 'spai_update_notice_' . $response->new_version;
        $update_notice  = get_transient( $transient_name );
        \ShortPixelAILogger::instance()->log("UPDATE NOTICE TRANSIENT?", $update_notice);

        if ( $update_notice === false ) {
            $readme_response = Request::get( 'https://plugins.svn.wordpress.org/shortpixel-adaptive-images/trunk/readme.txt', false );
            //$readme_response = Request::get( 'http://spaitest.shortpixel.com/wp-content/plugins/shortpixel-adaptive-images/readme.txt', false );
            \ShortPixelAILogger::instance()->log("GETTING FROM WP README:", substr($readme_response, 0, 500));

            if ( !empty( $readme_response ) ) {

                $update_notice = self::parse_update_notice( $readme_response, $response );
                \ShortPixelAILogger::instance()->log("UPDATE NOTICE SET", $update_notice);
                set_transient( $transient_name, $update_notice, DAY_IN_SECONDS );
            }
        }

        return $update_notice;
    }

    /**
     * Finds the first matching upgrade-notice block (see the version order in the class docblock).
     *
     * @param string $content  ShortPixel AI readme file content
     * @param object $response WordPress response
     *
     * @return string `wp_kses_post()`ed notice HTML, `''` when no block matches.
     */
    private static function parse_update_notice( $content, $response ) {
        $version_parts     = explode( '.', $response->new_version );
        $maj = $version_parts[ 0 ];
        $min = $version_parts[ 1 ];
        $micro = isset($version_parts[ 2 ]) ? $version_parts[ 2 ] : '';
        $build = isset($version_parts[ 3 ]) ? $version_parts[ 3 ] : '';
        $check_for_notices = [
            $maj . '.' . $min . '.' . $micro . '.' . $build, // build
            $maj . '.' . $min . '.' . $micro, // patch (micro)
            $maj . '.' . $min . '.0', // minor
            $maj . '.' . $min, // minor
            $maj . '.0.0', // major
            $maj . '.0', // major
        ];

        $update_notice = '';

        foreach ( $check_for_notices as $id => $check_version ) {
            if ( version_compare( SHORTPIXEL_AI_VERSION, $check_version, '>' ) ) {
                continue;
            }

            $result = self::parse_readme_content( $content, $check_version, $response );

            if ( !empty( $result ) ) {
                $update_notice .= $result;
                break;
            }
        }

        return wp_kses_post( $update_notice );
    }

    /**
     * Extracts the `= $version =` … `= $version:END =` block (or `= $version =` to end of file) that follows
     * `== Upgrade Notice ==`, and renders each non-empty line as `<span>` after `markdown2html()` and
     * `replace_readme_constants()`. Matching is case-insensitive and ungreedy.
     *
     * @param string $content Readme file content
     * @param string $version Checked version
     * @param object $response WordPress response
     *
     * @return string Notice HTML or `''`.
     */
    private static function parse_readme_content( $content, $version, $response ) {
        $notice_regexp = '/==\s*Upgrade Notice\s*==.*=\s*(' . preg_quote( $version ) . ')\s*=(.*)(=\s*' . preg_quote( $version . ':END' ) . '\s*=|$)/Uis';

        $notice = '';
        $matches = null;
        if ( preg_match( $notice_regexp, $content, $matches ) ) {
            $notices = (array) preg_split( '/[\r\n]+/', trim( $matches[ 2 ] ) );

            if ( version_compare( trim( $matches[ 1 ] ), $version, '=' ) ) {
                foreach ( $notices as $index => $line ) {
                    $notice .= '<span>';
                    $notice .= self::replace_readme_constants( self::markdown2html( $line ), $response );
                    $notice .= '</span>';
                }
            }
        }

        return $notice;
    }

    /**
     * Replaces `{{ NEW VERSION }}`, `{{ CURRENT VERSION }}`, `{{ PHP VERSION }}` and `{{ REQUIRED PHP VERSION }}` with
     * `$response->new_version`, `SHORTPIXEL_AI_VERSION`, `PHP_VERSION` and `$response->requires_php`.
     *
     * @param string $content
     * @param object $response Update API response.
     *
     * @return string
     */
    private static function replace_readme_constants( $content, $response ) {
        $constants    = [ '{{ NEW VERSION }}', '{{ CURRENT VERSION }}', '{{ PHP VERSION }}', '{{ REQUIRED PHP VERSION }}' ];
        $replacements = [ $response->new_version, SHORTPIXEL_AI_VERSION, PHP_VERSION, $response->requires_php ];

        return str_replace( $constants, $replacements, $content );
    }

    /**
     * Converts the readme's inline markdown: `**bold**` -> `<strong>`, `__italic__` -> `<em>`, `[text](url)` ->
     * `<a href="url" target="_blank">text</a>`. Nothing else (no escaping).
     *
     * @param string $content One line of text.
     *
     * @return string Converted line, or the input when `preg_replace()` fails.
     */
    public static function markdown2html( $content ) {
        $patterns = [
            '/\*\*(.+)\*\*/U', // bold
            '/__(.+)__/U', // italic
            '/\[([^\]]*)\]\(([^\)]*)\)/U', // link
        ];

        $replacements = [
            '<strong>${1}</strong>',
            '<em>${1}</em>',
            '<a href="${2}" target="_blank">${1}</a>',
        ];

        $prepared_content = preg_replace( $patterns, $replacements, $content );

        return isset( $prepared_content ) ? $prepared_content : $content;
    }
}


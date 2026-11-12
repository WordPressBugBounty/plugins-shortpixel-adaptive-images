<?php
namespace ShortPixel\AI;

/**
 * Manages the CORS (`Access-Control-Allow-Origin: *`) header for CSS, JS and font files, needed while the CDN
 * redirects such files to the origin (CSS parsing, `settings.areas.parse_css_files`).
 *
 * On Apache and LiteSpeed it writes this block into `<home path>/.htaccess` with `insert_with_markers()`:
 *
 *     # BEGIN ShortPixel Adaptive Images
 *     # Allow initial redirect to origin of CSS, JS and Font resources from the CDN (while they're processed by ShortPixel).
 *     <IfModule mod_headers.c>
 *         <FilesMatch "\.(css|js|eot|otf|ttf|woff|woff2|svg)($|\?.*)">
 *             Header add Access-Control-Allow-Origin: "*"
 *         </FilesMatch>
 *     </IfModule>
 *     # END ShortPixel Adaptive Images
 *
 * On Nginx nothing is written; `settings.tpl.php` shows the `getAllowOriginNginx()` snippet instead.
 *
 * Called by: `Page\Actions::handleSettings()` `save` when `parse_css_files` turns on (add) or off (remove);
 * `ShortPixelAI` activation when `parse_css_files > 0` (add) and deactivation (remove).
 * Uses `get_home_path()` and `insert_with_markers()`, which exist only after `wp-admin/includes/file.php` and
 * `misc.php` are loaded (admin, AJAX and activation requests).
 *
 * @todo On a network every site shares one `.htaccess`: activating or saving a site with CSS parsing on
 *       rewrites the block for all, and deactivating one site removes it while the others still need it.
 *       Write the block when any site parses CSS and remove it only when none does.
 */
class AccessControlHeaders {

    /**
     * @var string Server id returned for Apache and LiteSpeed (both read `.htaccess`).
     */
    const APACHE = 'apache';
    /**
     * @var string Matched in `SERVER_SOFTWARE` but never returned (LiteSpeed is reported as `APACHE`).
     */
    const LITESPEED = 'litespeed';
    /**
     * @var string Server id returned for Nginx.
     */
    const NGINX = 'nginx';

    /**
     * Detects the web server from `getenv( 'SERVER_SOFTWARE' )` (case-insensitive substring).
     *
     * @return string `APACHE` for Apache or LiteSpeed, `NGINX`, or `'unknown'`.
     */
    public static function getServerName() {
        $server = getenv('SERVER_SOFTWARE');
        if(stripos($server, self::APACHE) !== false) return self::APACHE;
        if(stripos($server, self::LITESPEED) !== false) return self::APACHE;
        if(stripos($server, self::NGINX) !== false) return self::NGINX;
        return 'unknown';
    }

    /**
     * Returns the Nginx `location` snippet that adds `Access-Control-Allow-Origin *` to CSS, JS and font files,
     * for display in the settings page. No origin parameter: the header is always `*`.
     *
     * @return string
     */
    public static function getAllowOriginNginx() {
        return 'location ~* \.(css|js|eot|otf|ttf|woff|woff2|svg)($|\?.*) {
    add_header Access-Control-Allow-Origin *;
}';
    }

    /**
     * Returns the Apache `<IfModule mod_headers.c><FilesMatch …>Header add Access-Control-Allow-Origin: "*"…` block
     * (with a leading newline) written by `addHeadersToHtaccess()`. No origin parameter: the header is always `*`.
     *
     * @return string
     */
    public static function getAllowOriginApache() {
        return
            '
<IfModule mod_headers.c>
    <FilesMatch "\.(css|js|eot|otf|ttf|woff|woff2|svg)($|\?.*)">
        Header add Access-Control-Allow-Origin: "*"
    </FilesMatch>
</IfModule>';
    }

    /**
     * Writes the marker block into `.htaccess` (Apache/LiteSpeed only).
     *
     * Reads `.htaccess`, removes the plugin's own block, then inspects the content READ BEFORE the removal: if a
     * `<FilesMatch …css…>` with `Header set Access-Control-Allow-Origin *` exists nothing is written; if one with
     * another `set`/`add` header exists it returns -2; otherwise it writes the block.
     *
     * @todo `addHeadersToHtaccess()` tests the content read before `removeHeadersFromHtaccess()`, and the plugin's own
     *       block (`Header add Access-Control-Allow-Origin: "*"`) matches the "different value" regex, so when the
     *       block is already present the call removes it and returns -2. Re-read `.htaccess` after the removal.
     * @todo `addHeadersToHtaccess()` calls `file_get_contents()` without checking that `.htaccess` exists or is
     *       readable (warning, then `preg_match()` on `false`).
     *
     * @return int 1 block written; 0 not Apache/LiteSpeed, or a `Header set … *` for CSS already exists;
     *             -1 `insert_with_markers()` failed (not writable); -2 a different CSS CORS header already exists.
     */
    public static function addHeadersToHtaccess() {
        if(self::getServerName() === self::APACHE) {
            $htaccessPath = get_home_path() . '.htaccess';
            $htaccess = file_get_contents($htaccessPath);
            self::removeHeadersFromHtaccess(); //make sure we remove our block, then check if the header was added by someone else.
            if(!preg_match('/<FilesMatch[^>]*css[^<]*Header\s+set\s+Access-Control-Allow-Origin\s+[\'"]?\*/s', $htaccess)) {
                if(preg_match('/<FilesMatch[^>]*css[^<]*Header\s+(set|add)\s+Access-Control-Allow-Origin/s', $htaccess)) {
                    //header already present for CSS but with a different value, don't add it but return a different value to let the front-end notify.
                    return -2;
                }
                $lines = '# Allow initial redirect to origin of CSS, JS and Font resources from the CDN (while they\'re processed by ShortPixel).'
                    . self::getAllowOriginApache() . '
';
                $success = insert_with_markers( $htaccessPath, 'ShortPixel Adaptive Images', $lines);
                return ($success ? 1 : -1);
            }
        }
        return 0;
    }

    /**
     * Empties the plugin's marker block in `.htaccess` (Apache/LiteSpeed only). The `# BEGIN`/`# END` lines stay,
     * and `insert_with_markers()` creates `.htaccess` with an empty block when the file does not exist.
     *
     * @return int 1 written, -1 `insert_with_markers()` failed, 0 not Apache/LiteSpeed.
     */
    public static function removeHeadersFromHtaccess() {
        if(self::getServerName() === 'apache') {
            return (insert_with_markers(get_home_path() . '.htaccess', 'ShortPixel Adaptive Images', '') ? 1 : -1);
        }
        return 0;
    }


}
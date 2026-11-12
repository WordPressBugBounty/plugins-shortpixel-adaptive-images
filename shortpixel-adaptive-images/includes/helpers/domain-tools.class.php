<?php
use ShortPixel\AI\Options;
/**
 * Account and domain calls to the ShortPixel CDN and API.
 *
 * @package ShortPixel\AI
 */

/**
 * Static helpers for the remote ShortPixel endpoints: domain status, CDN usage, domain
 * association, upgrade proposal and CDN cache purge.
 *
 * Remote URLs built here (constants from `ShortPixelAI`):
 * - `https://<cdn_domain>/assets/js/bundles/spai-lib.<SHORTPIXEL_AI_VANILLAJS_VER>.min.js` ({@see is_our_cdn()})
 * - `DEFAULT_STATUS_AI/read-domain-cdn-usage/<domain>/<key>` = `https://cdn.shortpixel.ai/read-domain-cdn-usage/...` ({@see get_cdn_domain_usage()})
 * - `DEFAULT_STATUS_AI/read-domain/<domain>` ({@see get_domain_status()})
 * - `DEFAULT_STATUS_AI/add-domain/<domain>/<key>` ({@see associate_domain()})
 * - `SP_API . '/v2/user-info.php?key=<key>'` = `https://api.shortpixel.com//v2/user-info.php?key=...` (double slash; {@see get_shortpixel_account()})
 * - `DEFAULT_MAIN_DOMAIN/propose-upgrade-frag` = `https://shortpixel.com/propose-upgrade-frag` (POST; {@see propose_upgrade()})
 * - `PURGE_CDN_CACHE_ENDPOINT<key>/<domain>` = `https://no-cdn.shortpixel.ai/purge-cdn-cache-bulk/<key>/<domain>` ({@see purge_cdn_cache()})
 * `<domain>` is the host of `get_home_url()`; none of the path parts are URL-encoded.
 *
 * @todo `$domainStatus` and `$cdnUsage` are cached per PHP process, not per site: after `switch_to_blog()`
 *       the first site's answer is returned for every other site of the network. Key the cache by domain.
 *
 * State: `$domainStatus` and `$cdnUsage` (private static) cache the first successful answer for
 * the rest of the request, whatever arguments later calls pass; tests must reset both through
 * reflection, and delete transient `spai_domain_status`.
 */
class ShortPixelDomainTools {

    /**
     * @var object|null|false Request-wide cache of {@see get_domain_status()}.
     */
    private static $domainStatus;
    /**
     * @var object|null Request-wide cache of {@see get_cdn_domain_usage()}.
     */
    private static $cdnUsage;

    /**
     * Tells whether `$cdn_domain` is served by ShortPixel: fetches the vanilla JS bundle
     * `https://<cdn_domain>/assets/js/bundles/spai-lib.<SHORTPIXEL_AI_VANILLAJS_VER>.min.js`
     * (`wp_safe_remote_get`, default timeout) and checks for the `x-sp-owner` response header.
     * The settings view uses it to show traffic (bytes) instead of credits.
     *
     * @param string $cdn_domain Host of the configured CDN.
     * @return bool False on WP_Error or when the header is missing.
     */
    public static function is_our_cdn($cdn_domain) {
        $request = wp_safe_remote_get( 'https://' . $cdn_domain . '/assets/js/bundles/spai-lib.' . SHORTPIXEL_AI_VANILLAJS_VER . '.min.js' );

        if ( is_wp_error( $request ) ) {
                return false;
        }

        $headers = wp_remote_retrieve_headers( $request );

        return isset( $headers['x-sp-owner'] );

    }


    /**
     * Fills missing days in a date-keyed usage series with `$empty_data`, in place, then sorts by key.
     *
     * For each `Y-m-d` key whose next day is not in the future and missing, the next day is added;
     * the method recurses until no gap was filled. Objects are converted to arrays and back.
     *
     * Example: `['2024-01-01' => a, '2024-01-03' => b]` becomes
     * `['2024-01-01' => a, '2024-01-02' => $empty_data, '2024-01-03' => b, '2024-01-04' => $empty_data, ...]`
     * up to today.
     *
     * @param array|object $place      Series keyed by date (`Y-m-d`); empty values are ignored.
     * @param mixed        $empty_data Value for missing days.
     * @return void
     */
    private static function fill_cdn_usage( &$place, $empty_data ) {
        if ( empty( $place ) || ( !is_array( $place ) && !is_object( $place ) ) ) {
            return;
        }

        $is_object = false;

        if ( is_object( $place ) ) {
            $is_object = true;
            $place     = (array) $place;
        }

        // flag to double check the array has it more skipped dates?
        $has_skipped = false;

        foreach ( $place as $date => $value ) {
            $current_time       = time();
            $next_day_time      = strtotime( '+1 day', strtotime( $date ) );
            $next_day_formatted = date( 'Y-m-d', $next_day_time );

            // if next day is a real next day - stop it
            if ( $current_time < $next_day_time ) {
                break;
            }

            if ( empty( $place[ $next_day_formatted ] ) ) {
                $has_skipped                  = true;
                $place[ $next_day_formatted ] = $empty_data;
            }
        }

        if ( $has_skipped ) {
            self::fill_cdn_usage( $place, $empty_data );
        }

        // sorting by keys
        ksort( $place );

        $place = $is_object ? (object) $place : $place;
    }

    /**
     * Fetches and reshapes the account usage of a domain for the settings and on-boarding views.
     *
     * Request: `GET https://cdn.shortpixel.ai/read-domain-cdn-usage/<domain>/<key>` through
     * `\ShortPixel\AI\Request::get()` (15 s timeout, JSON-decoded). The first successful result is
     * cached in `$cdnUsage` for the request; later calls return it unless `$refresh`, whatever
     * `$domain`/`$key` they pass.
     *
     * Returns false when: no key (argument or `settings_general_apiKey`); the response is `''`
     * or not an object; any top-level key equals today's date or any top-level value has
     * `Traf == 0`. A WP_Error gives `(object)['error' => <message>]` (not cached).
     *
     * Output object:
     * ```
     * email (truncated), isSubaccount                         only when the response has Email
     * quota.monthly  {total, used (capped), available, usedPercent, availablePercent, totalCDN,
     *                 nextBillingDate (now + DaysToReset), lastBillingDate (next - 30 days)}
     * quota.oneTime  {total, used (capped), available, usedPercent, availablePercent}
     * cdn            {total = CDNQuota, used (traffic since lastBillingDate), available,
     *                 usedPercent, availablePercent, detailed[{date, traffic}],
     *                 chart{labels['M d'], data{b, kb, mb, gb}}}
     * credits        {original, optimized, optimizedPercent, detailed[{date, paid, free, total,
     *                 original, optimized}], chart{labels, data{paid, free, total, original{b..gb}, optimized{b..gb}}}}
     * ```
     * Missing days in `UsedCDN` / `UsedCredits` are filled by {@see fill_cdn_usage()}.
     *
     * @param null|string $domain  Domain (default: {@see get_site_domain()}).
     * @param null|string $key     API key (default: option `settings_general_apiKey`).
     * @param bool        $refresh Bypass the request cache.
     *
     * @return false|object
     */
    public static function get_cdn_domain_usage( $domain = null, $key = null , $refresh = false) {
        if(self::$cdnUsage && !$refresh) {
            return self::$cdnUsage;
        }

        if ( empty( $key ) ) {
            $key = Options::_()->settings_general_apiKey;
        }

        if ( !is_string( $key ) || empty( $key ) ) {
            return false;
        }

        if ( !is_string( $domain ) || empty( $domain ) ) {
            $domain = self::get_site_domain();
        }

        $api_url  = ShortPixelAI::DEFAULT_STATUS_AI . '/read-domain-cdn-usage/' . $domain . '/' . $key;
        //echo($api_url);
        $response = \ShortPixel\AI\Request::get( $api_url );
        //var_dump($response);

        if ( $response === '' || !is_object($response)) {
            return false;
        }
        if($response instanceof WP_Error) {
            return (object)['error' => $response->get_error_message()];
        }

        foreach ( $response as $key => $value ) {
            if ( $key === date( 'Y-m-d' ) || ( isset( $value->Traf ) && $value->Traf == 0 ) ) {
                return false;
            }
        }

        $return = (object) [];

        if ( !empty( $response->Email ) ) {
            $return->email = self::truncate_email( $response->Email );
            $return->isSubaccount = $response->IsSubaccount;
        }

        $return->quota   = (object) [];
        $return->cdn     = (object) [];
        $return->credits = (object) [];

        $return->quota->monthly = (object) [];
        $return->quota->oneTime = (object) [];

        $return->cdn->total     = $response->CDNQuota;
        $return->cdn->used      = 0;
        $return->cdn->available = null;
        // it'll be calculated later
        $return->cdn->usedPercent      = null;
        $return->cdn->availablePercent = null;
        $return->cdn->detailed         = [];
        $return->cdn->chart            = (object) [
            'labels' => [],
            'data'   => (object) [
                'b'  => [],
                'kb' => [],
                'mb' => [],
                'gb' => [],
            ],
        ];

        // Monthly quota
        $return->quota->monthly->total            = (int) $response->APIQuota;
        $return->quota->monthly->used             = (int) $response->PaidAPICalls;
        $return->quota->monthly->used             = $return->quota->monthly->used > $return->quota->monthly->total ? $return->quota->monthly->total : $return->quota->monthly->used;
        $return->quota->monthly->available        = $return->quota->monthly->total - $return->quota->monthly->used;
        $return->quota->monthly->usedPercent      = $return->quota->monthly->total > 0 ? round( $return->quota->monthly->used / ( $return->quota->monthly->total / 100 ), 2 ) : 0.0;
        $return->quota->monthly->availablePercent = 100 - $return->quota->monthly->usedPercent;

        $return->quota->monthly->totalCDN         = (int) $response->CDNQuota;

        $return->quota->monthly->nextBillingDate = new DateTime();
        $return->quota->monthly->nextBillingDate->add(new DateInterval('P' . $response->DaysToReset . 'D'));
        $return->quota->monthly->lastBillingDate = clone $return->quota->monthly->nextBillingDate;
        $return->quota->monthly->lastBillingDate->sub(new DateInterval('P30D'));

        // One-time quota
        $return->quota->oneTime->total            = (int) $response->APIQuotaOneTime;
        $return->quota->oneTime->used             = (int) $response->PaidAPICallsOneTime;
        $return->quota->oneTime->used             = $return->quota->oneTime->used > $return->quota->oneTime->total ? $return->quota->oneTime->total : $return->quota->oneTime->used;
        $return->quota->oneTime->available        = $return->quota->oneTime->total - $return->quota->oneTime->used;
        $return->quota->oneTime->usedPercent      = $return->quota->oneTime->total > 0 ? round( $return->quota->oneTime->used / ( $return->quota->oneTime->total / 100 ), 2 ) : 0.0;
        $return->quota->oneTime->availablePercent = 100 - $return->quota->oneTime->usedPercent;

        $return->credits->detailed  = [];
        $return->credits->chart     = (object) [
            'labels' => [],
            'data'   => (object) [
                'paid'      => [],
                'free'      => [],
                'total'     => [],
                'original'  => (object) [
                    'b'  => [],
                    'kb' => [],
                    'mb' => [],
                    'gb' => [],
                ],
                'optimized' => (object) [
                    'b'  => [],
                    'kb' => [],
                    'mb' => [],
                    'gb' => [],
                ],
            ],
        ];
        $return->credits->original  = 0;
        $return->credits->optimized = 0;

        /* CDN Usage */
        if ( !empty( $response->UsedCDN ) ) {
            // filling empty spaces with zero data
            self::fill_cdn_usage( $response->UsedCDN, (object) [
                'Traf' => 0,
            ] );

            // creating our own object
            foreach ( $response->UsedCDN as $date => $value ) {
                $traffic = isset( $value->Traf ) ? $value->Traf : 0;

                $return->cdn->chart->labels[]   = date( 'M d', strtotime( $date ) );
                $return->cdn->chart->data->b[]  = $traffic;
                $return->cdn->chart->data->kb[] = round( $traffic / 1024, 2 );
                $return->cdn->chart->data->mb[] = round( $traffic / 1024 / 1024, 2 );
                $return->cdn->chart->data->gb[] = round( $traffic / 1024 / 1024 / 1024, 2 );

                $return->cdn->detailed[] = (object) [
                    'date'    => $date,
                    'traffic' => $traffic,
                ];

                if(new DateTime($date) >= $return->quota->monthly->lastBillingDate) {
                    $return->cdn->used += $traffic;
                }
            }
        }

        $return->cdn->available        = $return->cdn->total - $return->cdn->used;
        $return->cdn->usedPercent      = $return->cdn->total ? round( 100 * $return->cdn->used / $return->cdn->total, 2 ) : 0;
        $return->cdn->availablePercent = 100 - $return->cdn->usedPercent;

        // Credits Usage
        if ( !empty( $response->UsedCredits ) ) {
            // filling empty spaces with zero data
            self::fill_cdn_usage( $response->UsedCredits, (object) [
                'Orig' => 0,
                'Opt'  => 0,
                'Paid' => 0,
                'Free' => 0,
            ] );

            // creating our own object
            foreach ( $response->UsedCredits as $date => $value ) {
                $original  = isset( $value->Orig ) ? $value->Orig : 0;
                $optimized = isset( $value->Opt ) ? $value->Opt : 0;
                $free      = isset( $value->Free ) ? $value->Free : 0;
                $paid      = isset( $value->Paid ) ? $value->Paid : 0;

                $return->credits->chart->labels[]      = $date;
                $return->credits->chart->data->paid[]  = $paid;
                $return->credits->chart->data->free[]  = $free;
                $return->credits->chart->data->total[] = $paid + $free;

                // Original size
                $return->credits->chart->data->original->b[]  = $original;
                $return->credits->chart->data->original->kb[] = round( $original / 1024, 2 );
                $return->credits->chart->data->original->mb[] = round( $original / 1024 / 1024, 2 );
                $return->credits->chart->data->original->gb[] = round( $original / 1024 / 1024 / 1024, 2 );

                // Optimized size
                $return->credits->chart->data->optimized->b[]  = $optimized;
                $return->credits->chart->data->optimized->kb[] = round( $optimized / 1024, 2 );
                $return->credits->chart->data->optimized->mb[] = round( $optimized / 1024 / 1024, 2 );
                $return->credits->chart->data->optimized->gb[] = round( $optimized / 1024 / 1024 / 1024, 2 );

                $return->credits->detailed[] = (object) [
                    'date'      => $date,
                    'paid'      => $paid,
                    'free'      => $free,
                    'total'     => $paid + $free,
                    'original'  => $original,
                    'optimized' => $optimized,
                ];

                $return->credits->original  += $original;
                $return->credits->optimized += $optimized;

                $original_percent = $original / 100;

                $return->credits->optimizedPercent = $original_percent === 0 ? 0 : round( ( $original - $optimized ) / $original_percent, 2 );
            }
        }

        self::$cdnUsage = $return;
        return $return;
    }

    /**
     * Host of `get_home_url()` (current site on multisite), e.g. `www.example.test`; `www.` is kept.
     *
     * The `function_exists('parse_url')` guard is always true (parse_url is core PHP).
     *
     * @todo get_site_domain() guards `parse_url` with function_exists(), which is dead on every
     *       supported PHP (floor 7.4); drop the guard.
     *
     * @return false|string|null The host; null when the home URL has no host.
     */
    public static function get_site_domain() {
        return function_exists( 'parse_url' ) ? parse_url( get_home_url(), PHP_URL_HOST ) : false;
    }

    /**
     * Masks an e-mail address: keeps the first max(3, round(len/2)) chars of the local part,
     * appends `...`, keeps the domain.
     *
     * Example: `jonathan@example.test` gives `jona...@example.test`.
     *
     * @param string $email
     *
     * @return false|string False when there is not exactly one `@` or the domain has 3 chars or fewer.
     */
    public static function truncate_email( $email ) {
        $email = explode( '@', $email );

        if ( count( $email ) === 2 ) {
            $email[ 0 ] = substr( $email[ 0 ], 0, max( 3, round(strlen( $email[ 0 ] ) / 2 ) ) ) . "...";

            if ( strlen( $email[ 1 ] ) > 3 ) {
                return implode( '@', $email );
            }
        }

        return false;
    }

    /**
     * Credits as traffic: `formatBytes($credits * ShortPixelAI::ONE_CREDIT_IN_TRAFFIC)` (5 MiB per credit).
     *
     * Example: `credits2bytes(200)` gives `1000MB`.
     *
     * @param int|float $credits
     * @param int       $precision Decimals.
     * @return string
     */
    public static function credits2bytes($credits, $precision = 2) {
        return self::formatBytes($credits * ShortPixelAI::ONE_CREDIT_IN_TRAFFIC, $precision);
    }
    /**
     * Formats bytes with binary steps and units `B`, `KB`, `MB`, `GB`, `TB` (no space).
     * Negative input counts as 0.
     *
     * Example: `formatBytes(1536)` gives `1.5KB`.
     *
     * @param int|float $bytes
     * @param int       $precision Decimals.
     * @return string
     */
    public static function formatBytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        // Uncomment one of the following alternatives
        // $bytes /= pow(1024, $pow);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . $units[$pow];
    }


    /**
     * Describes the ShortPixel account that SPIO's API key (option `wp-short-pixel-apiKey`)
     * would bring, for the "use your ShortPixel account" offer.
     *
     * - No SPIO key: `{Status: 0, key: false, email: ''}`.
     * - {@see get_domain_status()} returns Status -3: that object.
     * - Domain already associated (`HasAccount`): `{Status: 3, Message: 'already associated', key: '', email: <status Email>}`.
     * - Otherwise `GET https://api.shortpixel.com//v2/user-info.php?key=<key>` (120 s); WP_Error
     *   gives `{Status: -3, Message: 'connection error', key: '', email: ''}`; else the domain
     *   status merged with `key` and the masked user e-mail.
     *
     * @todo get_shortpixel_account() builds `SP_API . '/v2/...'` with `SP_API` already ending in
     *       `/`, giving `//v2/user-info.php`. Drop the leading slash.
     * @todo get_shortpixel_account() reads `$respSP->email` without checking the decoded body;
     *       an error page or invalid JSON raises warnings. Check `isset($respSP->email)`.
     *
     * @return object
     */
    public static function get_shortpixel_account()
    {
        $email = array();
        $resp = (object)['Status' => 0];
        if (($spKey = get_option('wp-short-pixel-apiKey', false))) {
            $resp = self::get_domain_status();
            if ($resp->Status == -3) {
                return $resp;
            }
            if ($resp->HasAccount) {
                return (object)array('Status' => 3, 'Message' => 'already associated', 'key' => '', 'email' => $resp->Email);
            }
            //the domain is not associated, check with SP API the user info for the key found locally
            $responseSP = wp_safe_remote_get(ShortPixelAI::SP_API . '/v2/user-info.php?key=' . $spKey, array('timeout' => 120, 'httpversion' => '1.1'));
            if (is_object($responseSP) && get_class($responseSP) == 'WP_Error') {
                return (object)array('Status' => -3, 'Message' => 'connection error', 'key' => '', 'email' => '');
            }
            if (isset($responseSP['response'])) {
                $respSP = json_decode($responseSP['body']);
                $email = explode('@', $respSP->email);
                if (/* $resp->HasAccount && */
                    count($email) == 2) {
                    $email[0] = substr($email[0], 0, max(3, intval(strlen($email[0]) / 2))) . "...";
                }
            }
        }
        return (object)array_merge((array)$resp, array('key' => $spKey, 'email' => implode('@', $email)));
    }

    /**
     * Associates the current domain with SPIO's API key (option `wp-short-pixel-apiKey`) via
     * {@see associate_domain()}; on success sets options `flags_all_account = true` and
     * `settings_general_apiKey = <SPIO key>`.
     *
     * @todo use_shortpixel_account() reads `$response->success` although associate_domain()
     *       returns false on failure (warning "property on bool"). Check the type first.
     *
     * @param ShortPixelAI $ctrl Controller whose `options` are written.
     * @return bool True when associated.
     */
    public static function use_shortpixel_account($ctrl) {
        $spio_api_key = get_option( 'wp-short-pixel-apiKey', false );

        if ( $spio_api_key ) {
            $response = self::associate_domain( null, $spio_api_key );

            if ( $response->success ) {
                $ctrl->options->flags_all_account       = true;
                $ctrl->options->settings_general_apiKey = $spio_api_key;

                return true;
            }
        }

        return false;
    }

    /**
     * Returns the CDN's view of this domain: credits state, association, quotas.
     *
     * Caching, in order:
     * 1. `$domainStatus` (static): once set, returned for the rest of the request, even with
     *    `$refresh` or another `$domain`.
     * 2. Transient `spai_domain_status` (600 s), unless `$refresh`: returned with `cached = 'yes'`
     *    added. The transient key does not include the domain.
     * 3. `GET https://cdn.shortpixel.ai/read-domain/<domain>` (`wp_safe_remote_get`, 120 s,
     *    HTTP/1.1); a non-empty decoded body is stored in the transient and the static cache.
     *
     * `Status` values:
     * - `2`: OK, credits available (also for domains not associated with an account);
     * - `0`: credits near the limit;
     * - `-1`: credits depleted, CDN still active;
     * - `-2`: credits depleted, CDN inactive;
     * - `-3`: connection error, built locally
     *   (`{Status: -3, Message: 'connection error: <msg>', HasAccount: false, FreeCredits: 0}`),
     *   neither cached nor stored, so the next call retries.
     * Other fields used by callers: `HasAccount`, `Email`, `FreeCredits`, `UsedFreeCredits`,
     * `CDNQuota`, `UsedCDN`, `Message`.
     *
     * Side effect: when the method reaches its end with a status in the static cache (after a
     * remote fetch, or on any later call; NOT on the call that returns early from the transient),
     * option `flags_all_credits` is set to `Status != -1` (only `-1` switches the front-end
     * rewriting off; `-2` and `0` keep it on). This calls `ShortPixelAI::_()`.
     *
     * Example: a fresh request with the CDN answering `{"Status":2,"HasAccount":false}` returns that
     * object, sets the transient and `flags_all_credits = true`.
     *
     * @todo get_domain_status() calls `$response->get_error_message()` also when `$response` is an
     *       array without a `response` key (e.g. from a `pre_http_request` filter), which is a fatal
     *       Error. Use the message only for WP_Error.
     * @todo get_domain_status() returns null (and caches nothing) when the body is not JSON;
     *       callers then read `->Status` on null. Return the -3 object in that case.
     * @todo get_domain_status() keys the transient without the domain, so a call for another
     *       domain gets the cached status of the first one. Include the domain in the key or ignore
     *       the transient when `$domain` is given.
     *
     * @param bool        $refresh Skip the transient (not the static cache).
     * @param null|string $domain  Domain to query (default: {@see get_site_domain()}).
     * @return object|null
     */
    public static function get_domain_status($refresh = false, $domain = null) {
        if(!self::$domainStatus) {
            if ( !is_string( $domain ) || empty( $domain ) ) {
                $domain = self::get_site_domain();
            }

            if ( !$refresh && ( $domain_status = get_transient( 'spai_domain_status' ) ) ) {
                $domain_status->cached = 'yes';

                return self::$domainStatus = $domain_status;
            }

            //possible statuses: 2 OK (credits available, this is also for not associated domains) 0 - credits near limit,
            // -1 credits depleted, CDN active, -2 credits depleted, CDN inactive, -3 connection error
            $api_url  = ShortPixelAI::DEFAULT_STATUS_AI . '/read-domain/' . $domain;
            $response = wp_safe_remote_get( $api_url, [ 'timeout' => 120, 'httpversion' => '1.1' ] );

            if ( $response instanceof WP_Error || !isset( $response[ 'response' ] ) ) {
                return (object) [ 'Status' => -3, 'Message' => 'connection error: ' . $response->get_error_message(), 'HasAccount' => false, 'FreeCredits' => 0];
            }

            $domain_status = json_decode( wp_remote_retrieve_body( $response ) );
            if($domain_status) {
                set_transient('spai_domain_status', $domain_status, 600);
            }

            self::$domainStatus = $domain_status;
        }

        //deactivate SPAI until the status gets back to 2
        if(self::$domainStatus) ShortPixelAI::_()->options->flags_all_credits = !( self::$domainStatus->Status == -1 );

        return self::$domainStatus;
    }

    /**
     * Associates a domain with an API key: `GET https://cdn.shortpixel.ai/add-domain/<domain>/<key>`
     * (120 s, HTTP/1.1).
     *
     * @param null|string $domain Domain (default: {@see get_site_domain()}).
     * @param null|string $key    API key (default: SPIO's option `wp-short-pixel-apiKey`).
     * @return false|object False without a key, on WP_Error or a non-200 answer; else
     *                      `{success: Status is 1 or 2, status: Status, message: Message}` from the JSON body.
     */
    public static function associate_domain( $domain = null, $key = null ) {
        if ( empty( $key ) ) {
            $key = get_option( 'wp-short-pixel-apiKey', false );
        }

        if ( !is_string( $key ) || empty( $key ) ) {
            return false;
        }

        if ( !is_string( $domain ) || empty( $domain ) ) {
            $domain = self::get_site_domain();
        }

        $api_url = ShortPixelAI::DEFAULT_STATUS_AI . '/add-domain/' . $domain . '/' . $key;
        $request = wp_safe_remote_get( $api_url, [ 'timeout' => 120, 'httpversion' => '1.1' ] );

        if ( $request instanceof WP_Error ) {
            return false;
        }

        if ( empty( $request[ 'response' ] ) || $request[ 'response' ][ 'code' ] !== 200 ) {
            return false;
        }

        $response = json_decode( $request[ 'body' ] );

        return (object) [
            'success' => $response->Status === 1 || $response->Status === 2,
            'status'  => $response->Status,
            'message' => $response->Message,
        ];
    }

    /**
     * Prints the upgrade-proposal HTML fragment and ends the request.
     *
     * `POST https://shortpixel.com/propose-upgrade-frag` (10 s, HTTP/1.0) with body field `params`
     * = JSON `{product: 'spai', plugin_version, key, domain, iconsUrl: base64('https://shortpixel.com/old/img')}`.
     * Then `die()` with the response body, or with a translated error text on WP_Error.
     * Called by the AJAX action `spai_propose_upgrade` (`ShortPixelAI::propose_upgrade()`).
     *
     * @todo propose_upgrade() translates its error with text domain `shortpixel-image-optimiser`
     *       (SPIO's); use `shortpixel-adaptive-images`.
     * @todo propose_upgrade() ends with `die()`, which tests cannot intercept; use `wp_die()` (or
     *       `echo` plus `wp_die()`) so the WP die handler applies.
     *
     * @param string $key SPAI API key.
     * @return void Never returns.
     */
    public static function propose_upgrade($key) {
        $args = [
            'method' => 'POST',
            'timeout' => 10,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking' => true,
            'headers' => array(),
            'body' => array("params" => json_encode(array(
                'product' => 'spai',
                'plugin_version' => SHORTPIXEL_AI_VERSION,
                'key' => $key,
                'domain' => self::get_site_domain(),
                /* */
                'iconsUrl' => base64_encode(ShortPixelAI::DEFAULT_MAIN_DOMAIN . '/old/img')
            ))),
            'cookies' => array()
        ];

        $proposal = wp_remote_post(ShortPixelAI::DEFAULT_MAIN_DOMAIN . "/propose-upgrade-frag", $args);

        if(is_wp_error( $proposal )) {
            $proposal = array('body' => __('Error. Could not contact ShortPixel server for proposal', 'shortpixel-image-optimiser'));
        }
        die( $proposal['body'] );

    }


    /**
     * Purges the CDN cache of this domain (settings page / admin bar "Purge CDN cache").
     *
     * Steps:
     * 1. Nonce: `$_POST['spainonce']` must verify for action `shortpixel-ai-settings`, else
     *    `['success' => false, 'message' => 'Invalid nonce.']`. No capability check here (the
     *    caller, `Page\Actions`, handles it).
     * 2. Domain = {@see get_site_domain()} with a leading `www.` removed; key = option
     *    `settings_general_apiKey`. Either empty: `['success' => false, 'message' => 'API Key missing or domain not associated.']`.
     * 3. `GET https://no-cdn.shortpixel.ai/purge-cdn-cache-bulk/<key>/<domain>` (`wp_safe_remote_get`,
     *    default 5 s timeout). WP_Error: `['success' => false, 'message' => 'Error occurred: <msg>']`.
     * 4. JSON body `Status`:
     *    - `1` with `Message` containing `Not the right API Key`: `['success' => false, 'message' => 'Invalid API key.']`;
     *    - `2` with a `Message`: `['success' => true, 'message' => <the whole decoded body array>]`;
     *    - anything else: `['success' => false, 'message' => 'Unknown error during the purge process.']`.
     * Messages are translated (`shortpixel-adaptive-images`).
     *
     * Example: a valid nonce, key `KEY`, site `https://www.example.test` and answer
     * `{"Status":2,"Message":"ok"}` request `.../purge-cdn-cache-bulk/KEY/example.test` and return
     * `['success' => true, 'message' => ['Status' => 2, 'Message' => 'ok']]`.
     *
     * @todo purge_cdn_cache() passes a concatenated string to `__()`, so the WP_Error message is
     *       never translatable. Use `sprintf(__('Error occurred: %s', ...), $msg)`.
     *
     * @return array{success: bool, message: string|array}
     */
    static function purge_cdn_cache() {
        if (!isset($_POST['spainonce']) || !wp_verify_nonce($_POST['spainonce'], 'shortpixel-ai-settings')) {
            return ['success' => false, 'message' => __('Invalid nonce.', 'shortpixel-adaptive-images')];
        }

        $domain = ShortPixelDomainTools::get_site_domain();
        /** get_site_domain() fetches the site_domain as it is set in wp admin->settings->general->Site Address (URL),
         * which is not the best way for present situation, since on the SPAI api, domain must be stripped of www.,
         * so we search for the prefix and eliminate it
         */
        if ( $domain && strpos( $domain, 'www.' ) === 0 ) {
                $domain = substr( $domain, 4 ); // strip 'www.' if it's present
            }

        $api_key = Options::_()->settings_general_apiKey;
        /** //$api_key = get_option('wp-short-pixel-apiKey'); was wrong, here imported the spio instead of spai apikey.
         * because: what happens if you do not have spio...??*/
        if (empty($api_key) || empty($domain)) {
            return ['success' => false, 'message' => __('API Key missing or domain not associated.', 'shortpixel-adaptive-images')];
        }
        //construct purge url just like the call in the browser
        $purge_url = ShortPixelAI::PURGE_CDN_CACHE_ENDPOINT . $api_key . '/' . $domain;
        $response = wp_safe_remote_get($purge_url);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => __('Error occurred: ' . $response->get_error_message(), 'shortpixel-adaptive-images')];
        }
        // decode the json response, (true for associative arrary)
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        // check status returned from the API
        if (isset($data['Status'])) {
            if ($data['Status'] == 1 && isset($data['Message']) && strpos($data['Message'], 'Not the right API Key') !== false) {
                return ['success' => false, 'message' => __('Invalid API key.', 'shortpixel-adaptive-images')];
            } elseif ($data['Status'] == 2 && isset($data['Message'])) {
                return ['success' => true, 'message' => $data];
            }
        }
        return ['success' => false, 'message' => __('Unknown error during the purge process.', 'shortpixel-adaptive-images')];
    }

}

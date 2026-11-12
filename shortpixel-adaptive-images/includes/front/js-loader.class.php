<?php
/**
 * Front-end JS loader base class.
 */

namespace ShortPixel\AI;

/**
 * Base of the two front-end engines: picks and holds the loader, and shares the on-boarding "front worker" enqueue.
 *
 * Selection (`_()`): `settings.behaviour.nojquery > 0` (default 2 on new installs) -> `VanillaJsLoader` (CDN bundle),
 * otherwise `JqueryJsLoader` (`assets/js/ai-2.0.js`). The choice is fixed for the request once made.
 *
 * Static state a test must reset: private static `JsLoader::$instance` (set it back to false through reflection).
 */
abstract class JsLoader {

    /**
     * Singleton loader, false until `_()` is first called with a controller.
     * @var JqueryJsLoader|VanillaJsLoader|false
     */
    private static $instance = false;

    /**
     * @var \ShortPixelAI
     */
    protected $ctrl = false;
    /**
     * The controller's settings object (`ShortPixelAI::$settings`, same instance, so `init_ob()` in-memory changes are visible).
     * @var object
     */
    protected $settings = false;
    /**
     * @var \ShortPixelAILogger
     */
    protected $logger = false;

    /**
     * Returns the loader singleton, creating it on first call.
     *
     * Rule: `$ctrl->options->settings_behaviour_nojquery > 0` -> `VanillaJsLoader`, else `JqueryJsLoader`.
     * Later calls return the stored instance and ignore `$ctrl`.
     *
     * @param \ShortPixelAI $ctrl Required on the first call
     * @return bool|JqueryJsLoader|VanillaJsLoader
     * @throws \Exception When called the first time without a controller
     */
    public static function _($ctrl = false) {
        if( self::$instance !== false) {
            return self::$instance;
        }
        if($ctrl === false) {
            throw new \Exception("JsLoader cannot be constructed without controller");
        }

        $loader = ($ctrl->options->settings_behaviour_nojquery > 0 ? new VanillaJsLoader() : new JqueryJsLoader() );
        $loader->ctrl = $ctrl;
        $loader->settings = $ctrl->settings;
        self::$instance = $loader;
        return self::$instance;
    }

    /**
     * Sets the logger; `ctrl` and `settings` are assigned by `_()`.
     */
    protected function __construct() {
        $this->logger = \ShortPixelAILogger::instance();
    }

    /**
     * Tells whether the engine is present in the buffered page; on false `ShortPixelAI::maybe_replace_images_src()` leaves HTML untouched.
     *
     * @param string $content Buffered HTML
     * @return bool true if SPAI is setup correctly in the buffered HTML (js not dequeued, etc.)
     */
    public abstract function check($content);

    /**
     * Shared part of the enqueue: the on-boarding front worker.
     *
     * Runs for a logged-in user with a truthy `shortpixel-ai-front-worker` cookie, `manage_options`, and
     * `pages.on_boarding.front_worker.<user ID>` having `enabled` truthy and `token === ShortPixelAI::get_user_token()`.
     * Then adds body class `spai-fw-sidebar-hidden`, renders `front-checker.tpl.php` on `wp_head` (closure), re-registers
     * `js-cookie` 3.0.0-rc.0, and enqueues `spai-front-worker` (`front.worker.js`) localized as `SPAIFrontConstants`:
     * `apiUrl` (`get_cdn_url()`), `folderUrls.plugins` / `.content` / `.includes` (folder names relative to their parent, with trailing `/`),
     * with the CSP nonce applied.
     *
     * @return null
     */
    public function enqueue() {
        $current_user_login = wp_get_current_user()->ID;
        if ( !empty( $current_user_login ) && isset( $_COOKIE[ 'shortpixel-ai-front-worker' ] ) && !!$_COOKIE[ 'shortpixel-ai-front-worker' ])
        {
            $front_worker = Options::_()->get( 'front_worker', [ 'pages', 'on_boarding' ], Options\Option::_() );
            $front_worker = $front_worker instanceof Options\Option ? $front_worker : Options\Option::_();

            $front_worker->{$current_user_login} = $front_worker->{$current_user_login} instanceof Options\Option ? $front_worker->{$current_user_login} : Options\Option::_();

            if (
                \ShortPixelAI::userCan( 'manage_options' )
                && !!$front_worker->{$current_user_login}->enabled && $front_worker->{$current_user_login}->token === $this->ctrl->get_user_token()
            ) {
                /**
                 * Adds `spai-fw-sidebar-hidden` to the body classes.
                 * @param string[] $classes
                 * @return string[]
                 */
                add_filter( 'body_class', function( $classes ) {
                    $classes[] = 'spai-fw-sidebar-hidden';

                    return $classes;
                } );

                /**
                 * Renders the front-checker panel template in the head.
                 * @return void
                 */
                add_action( 'wp_head', function() {
                    Page::_( $this->ctrl )->render( 'front-checker.tpl.php' );
                } );

                wp_deregister_script( 'js-cookie');
                $this->ctrl->register_js('js-cookie', 'libs/js.cookie', true, true, '3.0.0-rc.0');
                $this->ctrl->register_js( 'spai-front-worker', 'front.worker', false);

                wp_localize_script( 'spai-front-worker', 'SPAIFrontConstants', [
                    'apiUrl'     => $this->ctrl->get_cdn_url(),
                    'folderUrls' => [
                        'plugins'  => str_replace( [ WP_CONTENT_URL, '/' ], '', WP_PLUGIN_URL ) . '/',
                        'content'  => str_replace( [ site_url(), '/' ], '', WP_CONTENT_URL ) . '/',
                        'includes' => WPINC . '/',
                    ],
                ] );
                \ShortPixelUrlTools::applyNonce('spai-front-worker');
                wp_enqueue_script( 'spai-front-worker' );
            }
        }
    }

    /**
     * adds the info about tags that need to be handled by the JS because they have been modified server-side
     * (called by `ShortPixelRegexParser::parse()` at the end of the page parse).
     *
     * @param string $content Parsed HTML
     * @return string updated content
     */
    public abstract function addTagData($content);

    /**
     * adds the fade-in CSS (called by `ShortPixelAI::enqueue_script()` when `behaviour.fadein` and not `behaviour.lqip`)
     * @return null
     */
    public abstract function fadeInCss();

}
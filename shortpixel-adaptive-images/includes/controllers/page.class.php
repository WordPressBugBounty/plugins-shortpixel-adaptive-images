<?php

	namespace ShortPixel\AI;

	use ShortPixel\AI\Notice\Constants;
	use ShortPixel\AI\Options\Option;
	use ShortPixel\AI\Options\Category;
	use ShortPixel\AI\Options\Collection;

	/**
	 * Admin pages of the plugin: menus, assets, templates, export/import, on-boarding redirect, nonce helpers.
	 *
	 * Instantiated (singleton) by `ShortPixelAI` in the back-end branch and in admin AJAX requests whose `action`
	 * starts with `shortpixel_ai` and that carry `data`.
	 *
	 * Pages (`NAMES`, all require `manage_options`):
	 * - `options-general.php?page=shortpixel-ai-settings`: settings page, renders `settings.tpl.php`.
	 * - `admin.php?page=shortpixel-ai-on-boarding`: wizard, hidden (parent `admin.php`), renders `on-boarding.tpl.php`.
	 * - `admin.php?page=shortpixel-ai-export-settings`: hidden; called by `settings.js` with `spainonce` and
	 *   `noheader=true`; sends `settings.*` as `SPAI-settings.json` (`Category::exportRecursive()`) and dies.
	 * - `admin.php?page=shortpixel-ai-import-settings`: hidden; target of `#import_settings_form` (file field
	 *   `import_settings_file`, hidden `spainonce`); JSON-decodes the upload and stores every
	 *   `{ area: { name: value } }` pair as `settings.<area>.<name>` with `Options::set()`, then shows a notice and
	 *   redirects to the settings page after 5 s with JS.
	 *
	 * Hooks (`hooks()`): `init` -> `globalInit`, `admin_footer` -> `adminFooter` (Chatbase embed),
	 * `admin_init` -> `adminInit` (plugin data + wizard redirect), `admin_menu` -> `initAdminPages`,
	 * `admin_bar_menu` (500) -> `initAdminBarItems`, `admin_enqueue_scripts` -> `enqueueAdminScripts`,
	 * `wp_ajax_shortpixel_ai_handle_page_action` -> `Page\Actions::handle`, and on the front end
	 * `wp_enqueue_scripts` -> `enqueueScripts`.
	 *
	 * Nonce: action `shortpixel-ai-settings`, request field `spainonce` (`getNonce()`, `checkSpaiNonce()`). The
	 * templates print it in `data-spainonce` attributes and hidden `spainonce` inputs.
	 *
	 * @todo The import page callback (in `initAdminPages()`) stores any area and setting name from the uploaded
	 *       JSON without a whitelist or sanitising (values containing `<` are NOT dropped here, unlike the AJAX
	 *       save), and it checks the upload only with `file_exists()` (no `is_uploaded_file()`, size or type check).
	 *       Restrict areas/names to the known option paths and run values through the same sanitising as
	 *       `Page\Actions::handle()`.
	 * @todo On a bad nonce the import page (in `initAdminPages()`) calls `checkSpaiNonce()` after the admin header
	 *       has been printed, so `wp_send_json()` emits JSON inside the HTML page (with "headers already sent"
	 *       warnings). Check the nonce on `load-admin_page_shortpixel-ai-import-settings` or print a notice instead.
	 */
	class Page {
		/**
		 * Absolute directory of the `*.tpl.php` templates rendered by `render()`.
		 * @var string
		 */
		const VIEWS_DIR = SHORTPIXEL_AI_PLUGIN_DIR . '/includes/views';

		/**
		 * Internal page key => admin page slug (`?page=` value). Keys are the values accepted by `isCurrent()`.
		 * @var array<string, string>
		 */
		const NAMES = [
			'settings'    => 'shortpixel-ai-settings',
            'export-settings' => 'shortpixel-ai-export-settings',
            'import-settings' => 'shortpixel-ai-import-settings',
			'on-boarding' => 'shortpixel-ai-on-boarding',
		];

		/**
		 * @var \ShortPixel\AI\Page $instance The singleton, set by the first constructor call.
		 */
		private static $instance;

		/**
		 * @var \ShortPixelAI $ctrl AI Controller
		 */
		protected $ctrl;

		/**
		 * @var array $data Plugin header data from `get_plugin_data()`, filled in `adminInit()`; templates read it.
		 */
		protected $data;

        /**
         * @var string|false $nonce Cached `shortpixel-ai-settings` nonce; false until `getNonce()` creates it.
         */
        protected $nonce = false;

		/**
		 * @var Collection|Category|Option Never assigned in this class; templates use `$this->ctrl->options`.
		 */
		protected $options;

		/**
		 * @var \ShortPixel\AI\Notice\Constants Notice texts/causers, used by the templates.
		 */
		protected $noticeConstants;

		/**
		 * @var object|false $domain_status Result of `ShortPixelDomainTools::get_domain_status( true )`, set in
		 *                                  `enqueueAdminScripts()` on the settings page; read by `settings.tpl.php`.
		 */
		protected $domain_status = false;

		/**
		 * @var object|false|null $domain_usage Result of `ShortPixelDomainTools::get_cdn_domain_usage()` (false when no
		 *                                      API key), set in `enqueueAdminScripts()` on the settings page.
		 */
		protected $domain_usage;

		/**
		 * Returns the singleton, creating it (and registering the hooks) on first call.
		 *
		 * @param \ShortPixelAI $controller Main plugin controller; ignored once the instance exists.
		 *
		 * @return \ShortPixel\AI\Page
		 */
		public static function _( $controller ) {
			return self::$instance instanceof self ? self::$instance : new self( $controller );
		}

		/**
		 * Tells whether the current admin screen is one of the plugin pages.
		 *
		 * Matches with `strpos( $screen->id, NAMES[ $page ] )`, so any screen id containing the slug matches.
		 * Returns false for unknown keys and before `get_current_screen()` exists (front end, early admin hooks).
		 *
		 * @param string $page Key of `NAMES` (`settings`, `on-boarding`, `export-settings`, `import-settings`).
		 *
		 * @return bool
		 */
		public static function isCurrent( $page ) {
			if ( !in_array( $page, array_keys( self::NAMES ) ) || !function_exists( 'get_current_screen' ) ) {
				return false;
			}

			$screen = get_current_screen();

			return $screen && strpos( $screen->id, self::NAMES[ $page ] ) !== false;
		}

        /**
         * Returns the settings nonce (action `shortpixel-ai-settings`), created once per request and cached.
         *
         * Printed by the templates as `data-spainonce` / hidden `spainonce`, and by the admin-bar CSS/CDN cache
         * buttons in `ShortPixelAI`.
         *
         * @return string
         */
        public function getNonce() {
            if($this->nonce === false) {
                $this->nonce = wp_create_nonce('shortpixel-ai-settings');
            }
            return $this->nonce;
        }

        /**
         * Verifies `$_REQUEST['spainonce']` against action `shortpixel-ai-settings`.
         *
         * On failure it ends the request with `wp_send_json( [ 'success' => false, 'message' => 'Invalid or expired
         * nonce. ...' ] )`; it never returns false. It does not check capabilities.
         *
         * @return true
         */
        public static function checkSpaiNonce() {
            if(!isset($_REQUEST['spainonce']) || !wp_verify_nonce($_REQUEST['spainonce'], 'shortpixel-ai-settings')) {
                wp_send_json( [ 'success' => false, 'message' => __( 'Invalid or expired nonce. Please retry the action.', 'shortpixel-adaptive-images' ) ] );
            }
            return true;
        }

		/**
		 * `init` callback. Empty.
		 *
		 * @return void
		 */
		public function globalInit() {
			// add things here :)
		}

		/**
		 * `admin_init` callback: loads the plugin header data into `$data` and runs `checkForWizardRedirect()`.
		 *
		 * @return void
		 */
		public function adminInit() {
			$this->data = get_plugin_data( SHORTPIXEL_AI_PLUGIN_FILE );

			$this->checkForWizardRedirect();
		}

		/**
		 * `admin_footer` callback: prints the Chatbase chatbot embed on the settings and on-boarding screens.
		 *
		 * The inline script installs a queueing `window.chatbase` proxy (unless already initialised) and, on window
		 * `load`, appends `https://www.chatbase.co/embed.min.js` with id `M__c7Yk58ZL23gk7UIWY-` (the bot id) and
		 * `domain` `www.chatbase.co`. The inline script carries no CSP nonce. The beacon div for the settings page
		 * is commented out.
		 *
		 * @return void
		 */
		public function adminFooter() {
			if ( self::isCurrent( 'settings' ) ) {
				// Commented because of WordPress Plugins Reviewing team
				// echo '<div class="shortpixel-ai-beacon"></div>';
			}

			// Add chatbot script to settings and on-boarding pages
			if ( self::isCurrent( 'settings' ) || self::isCurrent( 'on-boarding' ) ) {
				?>
<script>
(function() {
	if (!window.chatbase || window.chatbase("getState") !== "initialized") {
		window.chatbase = (...arguments) => {
			if (!window.chatbase.q) {
				window.chatbase.q = [];
			}
			window.chatbase.q.push(arguments);
		};
		window.chatbase = new Proxy(window.chatbase, {
			get(target, prop) {
				if (prop === "q") {
					return target.q;
				}
				return (...args) => target(prop, ...args);
			}
		});
	}
	const onLoad = function() {
		const script = document.createElement("script");
		script.src = "https://www.chatbase.co/embed.min.js";
		script.id = "M__c7Yk58ZL23gk7UIWY-";
		script.domain = "www.chatbase.co";
		document.body.appendChild(script);
	};
	if (document.readyState === "complete") {
		onLoad();
	} else {
		window.addEventListener("load", onLoad);
	}
})();
</script>
				<?php
			}
		}

		/**
		 * `wp_enqueue_scripts` callback (front end only): enqueues `assets/css/admin(.min).css` as
		 * `spai-admin-styles` for `manage_options` users (styles the admin-bar items).
		 *
		 * The version is the file's CRC32 when `SHORTPIXEL_AI_DEBUG` is set, else `SHORTPIXEL_AI_VERSION`.
		 *
		 * @return void
		 */
		public function enqueueScripts() {
			$min    = ( !!SHORTPIXEL_AI_DEBUG ? '' : '.min' );
			$styles = [];

			$styles[ 'admin' ][ 'file' ]    = 'assets/css/admin' . $min . '.css';
			$styles[ 'admin' ][ 'url' ]     = $this->ctrl->plugin_url . $styles[ 'admin' ][ 'file' ];
			$styles[ 'admin' ][ 'version' ] = !!SHORTPIXEL_AI_DEBUG ? hash_file( 'crc32', $this->ctrl->plugin_dir . $styles[ 'admin' ][ 'file' ] ) : SHORTPIXEL_AI_VERSION;

			if ( \ShortPixelAI::userCan( 'manage_options' ) ) {
				wp_enqueue_style( 'spai-admin-styles', $styles[ 'admin' ][ 'url' ], [], $styles[ 'admin' ][ 'version' ] );
			}
		}

		/**
		 * `admin_enqueue_scripts` callback.
		 *
		 * Settings page: fetches domain usage (remote, only with an API key) and domain status (remote, refreshed)
		 * into `$domain_usage`/`$domain_status`; registers `chart.js` and enqueues `spai-settings`
		 * (`pages/settings`) with `exclusionsL10n` strings, the CSP nonce (`ShortPixelUrlTools::applyNonce`) and,
		 * when the domain has an account and usage quota, the `statusBox` chart data. A pending
		 * `spaiModeSwitchNotification` transient is passed as `window.spaiModeSwitchNotification` (only `"` is
		 * escaped) and deleted. The current user's name pieces are computed but not used.
		 *
		 * On-boarding page: registers `spai-js-cookie` (`libs/js.cookie`) and `spai-on-boarding`.
		 *
		 * @return void
		 */
		public function enqueueAdminScripts() {
			$min     = ( !!SHORTPIXEL_AI_DEBUG ? '' : '.min' );
			$scripts = [];

			if ( self::isCurrent( 'settings' ) ) {
				$spai_key      = Options::_()->settings_general_apiKey;
                $this->domain_usage  = empty( $spai_key ) ? false : \ShortPixelDomainTools::get_cdn_domain_usage( null, $spai_key );
				$this->domain_status = \ShortPixelDomainTools::get_domain_status( true );

				// Registering scripts
                $this->ctrl->register_js('chart.js', 'libs/chart');

                $this->ctrl->register_js('spai-settings', 'pages/settings', false);

				wp_localize_script( 'spai-settings', 'exclusionsL10n', [
					'add'      => __( 'Add', 'shortpixel-adaptive-images' ),
					'save'     => __( 'Save', 'shortpixel-adaptive-images' ),
					'messages' => [
						'selectors' => [
							'alreadyExists' => __( 'Selector(s) already present.', 'shortpixel-adaptive-images' ),
							'invalid'       => __( 'This doesn\'t look like a valid selector. <a href="https://vegibit.com/css-selectors-tutorial/" target="_blank">How to write a selector?</a>', 'shortpixel-adaptive-images' ),
						],
					],
				] );
                \ShortPixelUrlTools::applyNonce('spai-settings');
                wp_enqueue_script( 'spai-settings' );

				if ( @$this->domain_status->HasAccount && isset($this->domain_usage->quota) ) {
					wp_localize_script( 'spai-settings', 'statusBox', [
						'chart' => [
							'titles'      => [
								'cdn'     => __( 'CDN (Mb)', 'shortpixel-adaptive-images' ),
								'credits' => __( 'Credits (pcs)', 'shortpixel-adaptive-images' ),
							],
							'colors'      => [ 'cdn' => 'rgb(238, 44, 36)', 'credits' => 'rgb(75, 192, 192)' ],
							'backgrounds' => [ 'cdn' => 'rgba(238, 44, 36, 0.2)', 'credits' => 'rgba(75, 192, 192, 0.2)' ],
							'cdn'         => $this->domain_usage->cdn->chart,
							'credits'     => $this->domain_usage->credits->chart,
						],
					] );
				}

				$current_user = wp_get_current_user();
				$name_pieces  = [];

				if ( $current_user->first_name ) {
					$name_pieces[] = $current_user->first_name;
				}

				if ( $current_user->last_name ) {
					$name_pieces[] = $current_user->last_name;
				}
				if($spaiModeSwitchNotification = get_transient('spaiModeSwitchNotification')) {
                    wp_add_inline_script('spai-settings' , 'window.spaiModeSwitchNotification = "' . str_replace('"', '\"', $spaiModeSwitchNotification) . '"');
					delete_transient('spaiModeSwitchNotification');
                }
			}

			if ( self::isCurrent( 'on-boarding' ) ) {
				// Registering & enqueuing scripts
                $this->ctrl->register_js('spai-js-cookie', 'libs/js.cookie', true, true, '3.0.0-rc.0');
                $this->ctrl->register_js('spai-on-boarding', 'pages/on-boarding');
			}
		}

		/**
		 * `admin_menu` callback: registers the four pages listed in the class docblock (`manage_options`).
		 *
		 * The export and import pages are hidden submenus of `admin.php` with a null menu title; their callbacks
		 * are closures. Export: `checkSpaiNonce()`, JSON download of `settings.*`, `die()`. Import: see class
		 * docblock; ends with `wp_die()`. Nothing is printed when the nonce is valid but no file was uploaded or
		 * the file is not valid JSON.
		 *
		 * @return void
		 */
		public function initAdminPages() {
			add_submenu_page(
				'admin.php',
				'ShortPixel AI On-Boarding',
				'ShortPixel AI On-Boarding',
				'manage_options',
				self::NAMES[ 'on-boarding' ],
				/**
				 * Renders the on-boarding wizard template.
				 * @return void
				 */
				function() {
					$this->render( 'on-boarding.tpl.php' );
				} );

			add_submenu_page(
				'options-general.php',
				'ShortPixel AI Settings',
				'ShortPixel AI',
				'manage_options',
				self::NAMES[ 'settings' ],
				/**
				 * Renders the settings template.
				 * @return void
				 */
				function() {
					$this->render( 'settings.tpl.php' );
				} );

            add_submenu_page(
                'admin.php',
                'ShortPixel AI Export Settings',
                null,
                'manage_options',
                self::NAMES[ 'export-settings' ],
                /**
                 * Checks the nonce and sends `settings.*` as the `SPAI-settings.json` download, then dies.
                 * @return void
                 */
                function() {
                    Page::checkSpaiNonce();
                    header('Content-Type: application/json');
                    header('Content-Disposition: attachment; filename=SPAI-settings.json');
                    echo(json_encode($this->ctrl->options->get()->settings->exportRecursive(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    die();
                } );

            add_submenu_page(
                'admin.php',
                'ShortPixel AI Import Settings',
                null,
                'manage_options',
                self::NAMES[ 'import-settings' ],
                /**
                 * Checks the nonce, imports the uploaded JSON into `settings.*` (see class docblock), then `wp_die()`.
                 * @return void
                 */
                function() {
                    echo('<h1>' . esc_html( get_admin_page_title() ) . '</h1>');
                    if(self::checkSpaiNonce() && isset($_FILES['import_settings_file']) && file_exists($_FILES['import_settings_file'] ["tmp_name"]))
                    {
                        $settings = json_decode(file_get_contents($_FILES['import_settings_file'] ["tmp_name"]));
                        if(null !== $settings) {
                            $settings = (array)$settings;
                            foreach ($settings as $areaName => $areaValues) {
                                $areaValues = (array)$areaValues;
                                foreach($areaValues as $settingName => $value) {
                                    $this->ctrl->options->set($value, $settingName, ['settings', $areaName]);
                                }
                            }
                            ?>
                            <div class="notice notice-success is-dismissible" data-icon="none" data-causer="" data-plugin="short-pixel-ai">
                                <div class="body-wrap">
                                    <div class="message-wrap">
                                        <p><?= __("The settings were successfully imported. Redirecting you back to the settings page in a moment...", 'shortpixel-adaptive-images' ) ?></p>
                                    </div>
                                </div>
                                <button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>
                            </div>
                            <script>
                                setTimeout(function(){
                                    window.location.href = 'options-general.php?page=shortpixel-ai-settings';
                                }, 5000);
                            </script>
                            <?php
                        }

                    }
                    wp_die();
                } );
        }

		/**
		 * `admin_bar_menu` callback: adds a "ShortPixel AI Setup" node linking to the wizard while the wizard is
		 * allowed (`pages.on_boarding.display_allowed`) and not passed (`has_been_passed`), only in wp-admin and only
		 * for `manage_options` users.
		 *
		 * @param \WP_Admin_Bar $admin_bar
		 *
		 * @return void
		 */
		public function initAdminBarItems( $admin_bar ) {
			if ( !!$this->ctrl->options->pages_onBoarding_displayAllowed && !$this->ctrl->options->pages_onBoarding_hasBeenPassed ) {
				if ( !is_admin() || !\ShortPixelAI::userCan( 'manage_options' ) ) {
					return;
				}

				$admin_bar->add_node( [
					'id'     => self::NAMES[ 'on-boarding' ],
					'parent' => null,
					'group'  => null,
					'title'  => '<span class="ab-icon"></span><span class="ab-label">' . __( 'ShortPixel AI Setup', 'shortpixel-adaptive-images' ) . '</span>',
					'href'   => admin_url( 'admin.php?page=' . self::NAMES[ 'on-boarding' ] ),
				] );
			}
		}

		/**
		 * Includes a template from `VIEWS_DIR` inside `<div class="wrap">`. The template runs in this method's scope,
		 * so `$this` is the Page instance. Uses `require_once`, so a view renders at most once per request. Prints
		 * nothing when the file does not exist.
		 *
		 * @param string $view Template file name, e.g. `settings.tpl.php`.
		 *
		 * @return void
		 */
		public function render( $view ) {
			if ( file_exists( self::VIEWS_DIR . DIRECTORY_SEPARATOR . $view ) ) {
				echo '<div class="wrap">';
                    require_once( self::VIEWS_DIR . DIRECTORY_SEPARATOR . $view );
				echo '</div>';
			}
		}

		/**
		 * Stores the singleton (if none yet), the controller and the notice constants, and registers the hooks.
		 *
		 * @param \ShortPixelAI $controller
		 */
		private function __construct( $controller ) {
			if ( !isset( self::$instance ) || !self::$instance instanceof self ) {
				self::$instance = $this;
			}

			$this->ctrl = $controller;

			$this->noticeConstants = Constants::_( $controller );

			$this->hooks();
		}

		/**
		 * Registers the WordPress hooks listed in the class docblock.
		 *
		 * @return void
		 */
		private function hooks() {
			add_action( 'init', [ $this, 'globalInit' ] );
			add_action( 'admin_footer', [ $this, 'adminFooter' ] );
			add_action( 'admin_init', [ $this, 'adminInit' ] );
			add_action( 'admin_menu', [ $this, 'initAdminPages' ], 10 );
			add_action( 'admin_bar_menu', [ $this, 'initAdminBarItems' ], 500 );
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueueAdminScripts' ] );
			add_action( 'wp_ajax_shortpixel_ai_handle_page_action', [ 'ShortPixel\AI\Page\Actions', 'handle' ] );

			if ( !is_admin() ) {
				add_action( 'wp_enqueue_scripts', [ $this, 'enqueueScripts' ] );
			}
		}

		/**
		 * Redirects once to the on-boarding wizard after the first activation.
		 *
		 * Runs on every `admin_init`. When `pages.on_boarding.redirect_allowed` is anything but `false` (activation
		 * sets it to `true`; a missing value reads as null and also passes) it is set to `false` (one shot), and the
		 * redirect happens only if ALL of these hold: `flags.all.first_install` is truthy,
		 * `pages.on_boarding.display_allowed` is falsy, `pages.on_boarding.step` is empty and
		 * `pages.on_boarding.has_been_passed` is falsy. Then `first_install` becomes false, `display_allowed` true,
		 * and it calls `wp_redirect( admin.php?page=shortpixel-ai-on-boarding )` + `die`.
		 *
		 * @todo `checkForWizardRedirect()` checks neither the user's capability, nor `wp_doing_ajax()`, nor bulk or
		 *       network activation, so the one-shot redirect can be consumed by an admin-ajax request (which then
		 *       answers with a redirect) or by a user without `manage_options` (who lands on a page they cannot
		 *       open). Skip when `wp_doing_ajax()`, `isset( $_GET['activate-multi'] )`, `is_network_admin()` or
		 *       `!current_user_can( 'manage_options' )`, without consuming the flag.
		 *
		 * @return void
		 */
		private function checkForWizardRedirect() {
			if ( $this->ctrl->options->pages_onBoarding_redirectAllowed !== false) {
				$this->ctrl->options->set( false, 'redirect_allowed', [ 'pages', 'on_boarding' ] );
				if ( !!$this->ctrl->options->flags_all_firstInstall && !$this->ctrl->options->pages_onBoarding_displayAllowed
                    && empty( $this->ctrl->options->pages_onBoarding_step ) && !$this->ctrl->options->pages_onBoarding_hasBeenPassed ) {
					// Setting the flag that plugin has been installed
					$this->ctrl->options->flags_all_firstInstall = false;
					// Setting the flag that display of On-Boarding Wizard is allowed
					$this->ctrl->options->pages_onBoarding_displayAllowed = true;

					wp_redirect( admin_url( 'admin.php?page=' . self::NAMES[ 'on-boarding' ] ) );
					die;
				}
			}
		}
	}
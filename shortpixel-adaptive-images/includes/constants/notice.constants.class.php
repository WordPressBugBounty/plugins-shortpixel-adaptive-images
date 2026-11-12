<?php

	namespace ShortPixel\AI\Notice;

	use ShortPixel\AI\ActiveIntegrations;
	use ShortPixel\AI\Page;
	use ShortPixelAI;

	/**
	 * Texts and buttons of the local admin notices, one public array property per notice.
	 *
	 * Each property has the shape `[ 'causer' => string (optional, informational), 'title' => string,
	 * 'body' => string[] (HTML), 'buttons' => array[] (see `Notice::renderButtons()`) ]` and is passed as
	 * `$data['message']` to `Notice::render()`. `Notice::renderNotices()` passes the causer explicitly; the
	 * dismissed-notices panel of `settings.tpl.php` reads these properties by dismissal key and uses `causer`
	 * (or the key) as `data-causer`. Button `action` values are the `data[action]` handled by `Notice\Actions`.
	 *
	 * Built once (singleton) by `ShortPixelAI` in the back end and by `Page`.
	 *
	 * @todo The constructor's `mbstring` `__()` call has no text domain; add `'shortpixel-adaptive-images'`.
	 * @todo The constructor reads `$_SERVER['HTTP_HOST']`/`REQUEST_URI` for the `missing_jquery` and
	 *       `temporary_redirect` `return_url`, which raises warnings when built outside a web request (WP-CLI, cron).
	 *       Guard with `isset()`.
	 * @todo The constructor's comment "if causer not specified, the name of constant will be used as causer" is
	 *       true only for the settings-page dismissed panel; `renderNotices()` hardcodes the causers.
	 */
	class Constants {
		/**
		 * @var \ShortPixel\AI\Notice\Constants|null The singleton, set by the first constructor call.
		 */
		private static $instance;

		/**
		 * @var array $autoptimize Autoptimize "Optimize images on the fly" conflict; causer `ao`; button `solve conflict`.
		 */
		public $autoptimize;
		/**
		 * @var array $avadalazy Avada theme lazy-loading conflict; link to the Avada options only.
		 */
		public $avadalazy;
        /**
         * @var array $ginger Ginger - EU Cookie Law opt-in conflict; links only.
         */
        public $ginger;
        /**
         * @var array $speedoptimizer Speed Optimizer "Combine JavaScript Files" conflict; link only.
         */
        public $speedoptimizer;
		/**
		 * @var array $divitoolbox Divi Toolbox "Custom Post Meta" conflict; links only.
		 */
		public $divitoolbox;
        /**
         * @var array $mbstring Missing PHP mbstring extension; no buttons.
         */
        public $mbstring;
		/**
		 * @var array $elementorexternal Never assigned (the Elementor external-CSS message is commented out as obsolete).
		 */
		public $elementorexternal;
		/**
		 * @var array $beta Beta-phase notice with a contact link.
		 */
		public $beta;
		/**
		 * @var array $on_boarding "Open Wizard" notice (causer `on boarding`, buttons `redirect` and `dismiss`); currently never rendered.
		 */
		public $on_boarding;
		/**
		 * @var array $lazy Another lazy loader detected; button `dismiss forever`.
		 */
		public $lazy;
		/**
		 * @var array $wp_rocket_defer_js WP Rocket deferred JS without safe mode (causer `wp rocket defer js`); button `solve conflict`.
		 */
		public $wp_rocket_defer_js;
		/**
		 * @var array $wp_rocket_lazy WP Rocket lazy-load conflict (causer `wp rocket lazy`); link to WP Rocket media settings.
		 */
		public $wp_rocket_lazy;
		/**
		 * @var array $wprocketcss WP Rocket "Minify CSS" vs SPAI CSS parsing; link to WP Rocket file optimisation settings.
		 */
		public $wprocketcss;
		/**
		 * @var array $key "You already have a ShortPixel account" (body[0] has a `%s` for the account e-mail); button `use account`.
		 */
		public $key;
        /**
         * @var array $remote_get_error Network error contacting ShortPixel (body[0] has a `%s` for the error text); no buttons.
         */
        public $remote_get_error;
		/**
		 * @var array $credits Static quota-exceeded text; the rendered credits notice uses `Notice::getCreditsNoticeInfo()` instead.
		 */
		public $credits;
		/**
		 * @var array $twicelossy SPAI and SPIO both lossy; links to both settings pages.
		 */
		public $twicelossy;
		/**
		 * @var array $missing_jquery jQuery missing on the front end (causer `missing jquery`); button `re-check` with `additional.return_url` = current URL.
		 */
		public $missing_jquery;
        /**
         * @var array $temporary_redirect Images not reachable by the CDN (causer `temporary redirect`); button `re-check` with `additional.return_url`.
         */
        public $temporary_redirect;
		/**
		 * @var array $swift_performance Swift Performance merge-styles/normalize conflict (causer `swift performance`); title includes the edition; button `solve conflict`.
		 */
		public $swift_performance;
		/**
		 * @var array $imagify Imagify WebP delivery conflict; button `solve conflict`.
		 */
		public $imagify;
		/**
		 * @var array $spio_webp SPIO "Deliver next generation images" conflict (causer `spio webp`); button `solve conflict`.
		 */
		public $spio_webp;
		/**
		 * @var array $litespeed_js_combine LiteSpeed Cache JS Combine conflict (causer `litespeed js combine`); button `solve conflict`.
		 */
		public $litespeed_js_combine;
		/**
		 * @var array $wpo_merge_css WP-Optimize CSS merging conflict (causer `wpo merge css`); buttons `add exclusions`, `solve conflict`.
		 */
		public $wpo_merge_css;
		/**
		 * @var array $lqip_mkdir_failed LQIP folder could not be created (causer `lqip mkdir failed`); no buttons.
		 */
		public $lqip_mkdir_failed;

		/**
		 * Single ton implementation
		 *
		 * @param \ShortPixelAI|null $controller
		 *
		 * @return \ShortPixel\AI\Notice\Constants
		 */
		public static function _( $controller = null ) {
			return self::$instance instanceof self ? self::$instance : new self( $controller );
		}

		/**
		 * Fills every notice property (translated strings, admin URLs, affiliate-filtered links). Reads the Swift
		 * Performance integration info from `ActiveIntegrations` for its title. The controller is not used.
		 *
		 * @param \ShortPixelAI|null $controller
		 */
		private function __construct( $controller ) {
			if ( !isset( self::$instance ) || !self::$instance instanceof self ) {
				self::$instance = $this;
			}

			$this->autoptimize = [
				'causer' => 'ao', //if causer not specified, the name of constant will be used as causer
				'title' => __( 'Autoptimize option conflict', 'shortpixel-adaptive-images' ),
				'body'  => [
					__( 'The option "<strong>Optimize images on the fly and serve them from a CDN.</strong>" is active in Autoptimize. Please <span>deactivate it</span> to let ShortPixel Adaptive Images serve the images properly optimized and scaled.',
						'shortpixel-adaptive-images' ),
				],
				'buttons' => [
					[
						'title'   => __( 'Deactivate it', 'shortpixel-adaptive-images' ),
						'action'  => 'solve conflict',
						'primary' => true,
					],
					[
						'type'    => 'link',
						'title'   => __( 'More info', 'shortpixel-adaptive-images' ),
						'url'     => 'https://shortpixel.com/knowledge-base/article/198-shortpixel-adaptive-images-vs-autoptimizes-optimize-images-option',
						'target'  => '_blank',
						'primary' => false,
					],
				],
			];

			$this->avadalazy = [
				'title' => __( 'Avada option conflict', 'shortpixel-adaptive-images' ),
				'body'  => [
					__( 'The option "Enable Lazy Loading" is active in your Avada theme options, under the Performance section. Please <span>deactivate it</span> to let ShortPixel Adaptive Images serve the images properly optimized and scaled.',
						'shortpixel-adaptive-images' ),
				],
				'buttons' => [
					[
						'type'    => 'link',
						'title'   => __( 'Deactivate it', 'shortpixel-adaptive-images' ),
						'url'     => 'themes.php?page=avada_options',
						'primary' => true,
					],
				],
			];

            $this->speedoptimizer = [
                'title' => __( 'SpeedOptimizer option conflict', 'shortpixel-adaptive-images' ),
                'body'  => [
                    __( 'The option "<strong>Combine JavaScript Files</strong>" is active in Speed Optimizer, under Frontend -> Javascript section. Please <span>deactivate it</span> to let ShortPixel Adaptive Images serve the images properly from CDN.',
                        'shortpixel-adaptive-images' ),
                ],
                'buttons' => [
                    [
                        'type'    => 'link',
                        'title'   => __( 'Deactivate it', 'shortpixel-adaptive-images' ),
                        'url'     => 'admin.php?page=sgo_frontend',
                        'primary' => true,
                    ],
                ],
            ];

			$this->ginger = [
				'title' => __( 'Ginger option conflict', 'shortpixel-adaptive-images' ),
				'body'  => [
					__( 'The option "<strong>Cookie Confirmation Type</strong>" is set to Opt-in in Ginger - EU Cookie Law and this conflicts with ShortPixel. Please <span>set it differently</span> to let ShortPixel Adaptive Images serve the images properly optimized and scaled.',
						'shortpixel-adaptive-images' ),
				],
				'buttons' => [
					[
						'type'    => 'link',
						'title'   => __( 'Ginger EU Cookie Law settings', 'shortpixel-adaptive-images' ),
						'url'     => 'admin.php?page=ginger-setup',
						'primary' => true,
					],
					[
						'type'    => 'link',
						'title'   => __( 'More info', 'shortpixel-adaptive-images' ),
						'url'     => 'https://shortpixel.com/knowledge-base/article/198-shortpixel-adaptive-images-vs-autoptimizes-optimize-images-option',
						'target'  => '_blank',
						'primary' => false,
					],
				],
			];

			$this->divitoolbox = [
				'title' => __( 'Divi Toolbox option conflict', 'shortpixel-adaptive-images' ),
				'body'  => [
					__( 'The option "Custom Post Meta" is active in your Divi Toolbox options, under the Blog section. Please either update the plugin to version > 1.4.2 or <span>deactivate the option</span> to let ShortPixel Adaptive Images serve the images.',
						'shortpixel-adaptive-images' ),
				],
				'buttons' => [
					[
						'type'    => 'link',
						'title'   => __( 'Deactivate it', 'shortpixel-adaptive-images' ),
						'url'     => 'admin.php?page=divi_toolbox&tab=blog',
						'primary' => true,
					],
					[
						'type'    => 'link',
						'title'   => __( 'More info', 'shortpixel-adaptive-images' ),
						'url'     => 'https://shortpixel.com/knowledge-base/article/269-shortpixel-adaptive-image-errors-when-divi-toolbox-is-enabled',
						'target'  => '_blank',
						'primary' => false,
					],
				],
			];

			/* Obsolete
			$this->elementorexternal = [
				'title' => __( 'Elementor option conflict', 'shortpixel-adaptive-images' ),
				'body'  => [
					__( 'The option "<strong>CSS Print Method</strong>" is set on External File in your Elementor options. Please either activate the "Replace in CSS files" in the Advanced tab of <span>ShortPixel Adaptive Images options</span>', 'shortpixel-adaptive-images' ) . ' 😰',
					__( 'or <span>change Elementor\'s option</span> to Internal Embedding in order to let ShortPixel Adaptive Images also optimize background images.', 'shortpixel-adaptive-images' ),
				],
			];
			*/

            $this->mbstring = [
                'title' => __( 'ShortPixel Adaptive Images needs the PHP MBString extension.', 'shortpixel-adaptive-images' ),
                'body'  => [
                    __( 'ShortPixel Adaptive Images needs the PHP MBString extension. Please ask your admin or your hosting to enable it for your website.' ),
                ],
            ];

            $this->beta = [
				'title' => __( 'ShortPixel Adaptive Images is in BETA', 'shortpixel-adaptive-images' ),
				'body'  => [
					__( 'Currently the plugin is in the Beta phase. While we have tested it a lot, we can\'t possibly test it with all the themes out there. On Javascript-intensive themes, layout issues could occur or some images might not be replaced.',
						'shortpixel-adaptive-images' ),
					__( 'If you notice any problems, just deactivate the plugin and the site will return to the previous state. Please kindly <span>let us know</span> and we\'ll be more than happy to work them out.',
						'shortpixel-adaptive-images' ),
				],
				'buttons' => [
					[
						'type'    => 'link',
						'title'   => __( 'Contact us', 'shortpixel-adaptive-images' ),
						'url'     => apply_filters('spai_affiliate_link',ShortPixelAI::DEFAULT_MAIN_DOMAIN. '/contact'),
						'target'  => '_blank',
						'primary' => true,
					],
				],
			];

			$this->on_boarding = [
				'causer' => 'on boarding',
				'title' => __( 'ShortPixel Adaptive Images new feature', 'shortpixel-adaptive-images' ),
				'body'  => [
					__( 'Thank you for updating to our new 3.0 version!', 'shortpixel-adaptive-images' ),
					__( 'Please let us introduce our <span>On-Boarding Wizard</span> which has been developed to help you decide exactly which advanced options are really necessary for your website.', 'shortpixel-adaptive-images' ),
				],
				'buttons' => [
					[
						'title'   => __( 'Open Wizard', 'shortpixel-adaptive-images' ),
						'action'  => 'redirect',
						'primary' => true,
					],
					[
						'title'  => __( 'No, I do not need it!', 'shortpixel-adaptive-images' ),
						'action' => 'dismiss',
					],
				],
			];

			$this->lazy = [
				'title' => __( 'ShortPixel Adaptive Images conflicts with other lazy-loading settings', 'shortpixel-adaptive-images' ),
				'body'  => [
					__( '<strong>ShortPixel Adaptive Images</strong> has detected that your theme or another plugin is providing lazy-loading functionality to your website.',
						'shortpixel-adaptive-images' ),
					__( '<strong>ShortPixel Adaptive Images</strong> is also using a lazy-loading method as means to provide its service, so please deactivate the other lazy-loading setting.',
						'shortpixel-adaptive-images' ),
				],
                'buttons' => [
                    [
                        'title'  => __( 'Dismiss, I know what I\'m doing.', 'shortpixel-adaptive-images' ),
                        'action' => 'dismiss forever',
                    ],
                ],
			];

			$this->wp_rocket_defer_js = [
				'causer' => 'wp rocket defer js',
				'title' => __( 'ShortPixel Adaptive Images conflicts with defer of all JavaScript files', 'shortpixel-adaptive-images' ),
				'body'  => [
					__( '<strong>ShortPixel Adaptive Images</strong> has found that conflicting option <span>Load JavaScript deferred</span> in the WP Rocket has been enabled without safe mode.',
						'shortpixel-adaptive-images' ),
				],
				'buttons' => [
					[
						'title'   => __( 'Change conflicting settings', 'shortpixel-adaptive-images' ),
						'action'  => 'solve conflict',
						'primary' => true,
					],
				],
			];

			$this->wp_rocket_lazy = [
				'causer' => 'wp rocket lazy',
				'title' => __( 'ShortPixel Adaptive Images conflicts with other lazy-loading settings', 'shortpixel-adaptive-images' ),
				'body'  => [
					__( '<strong>ShortPixel Adaptive Images</strong> is also using a lazy-loading method as means to provide its service, so please deactivate the other lazy-loading setting. <span>Open the WP Rocket Settings</span> to turn off the Lazy Load option.',
						'shortpixel-adaptive-images' ),
				],
				'buttons' => [
					[
						'type'    => 'link',
						'title'   => __( 'Open the WP Rocket Settings', 'shortpixel-adaptive-images' ),
						'url'     => 'options-general.php?page=wprocket#media',
						'primary' => true,
					],
				],
			];

			$this->wprocketcss = [
				'title' => __( 'ShortPixel Adaptive Images conflicts with other CSS settings', 'shortpixel-adaptive-images' ),
				'body'  => [
					__( 'You have enabled the "Replace in CSS files" option in ShortPixel. Please either <span>Open the WP Rocket Settings</span> to turn off the "Minify CSS files" option of WP Rocket or <span>update your WP Rocket plugin</span> to at least version 3.4.3.',
						'shortpixel-adaptive-images' ),
				],
				'buttons' => [
					[
						'type'    => 'link',
						'title'   => __( 'Open the WP Rocket Settings', 'shortpixel-adaptive-images' ),
						'url'     => 'options-general.php?page=wprocket#file_optimization',
						'primary' => true,
					],
				],
			];

			$this->key = [
				'title' => __( 'ShortPixel account', 'shortpixel-adaptive-images' ),
				'body'  => [
					__( 'You already have a ShortPixel account for this website: <span>%s</span>. Do you want to use ShortPixel Adaptive Images with this account?', 'shortpixel-adaptive-images' ),
				],
				'buttons' => [
					[
						'title'   => __( 'Use this account', 'shortpixel-adaptive-images' ),
						'action'  => 'use account',
						'primary' => true,
					],
				],
			];

            $this->remote_get_error = [
                'title' => __( 'ShortPixel network issue', 'shortpixel-adaptive-images' ),
                'body'  => [
                    __( 'An error occurred when trying to contact ShortPixel\'s servers: %s. Please check this error with your hosting provider.', 'shortpixel-adaptive-images' ),
                ],
            ];

            $this->credits = [
				'title' => __( 'ShortPixel CDN traffic', 'shortpixel-adaptive-images' ),
				'body'  => [
					  __( 'Your ShortPixel Adaptive Images quota has been exceeded.', 'shortpixel-adaptive-images' ) . ' '
                    . __( 'Your images are served from the origin server until you top-up your account.', 'shortpixel-adaptive-images' ),
				],
			];

			$this->twicelossy = [
				'title' => __( 'ShortPixel optimization alert', 'shortpixel-adaptive-images' ),
				'body'  => [
					__( 'ShortPixel Adaptive Images and ShortPixel Image Optimizer are both set to do Lossy optimization which could result in a too aggressive optimization of your images, please set one of them to Glossy or Lossless.', 'shortpixel-adaptive-images' ),
				],
				'buttons' => [
					[
						'type'    => 'link',
						'title'   => __( 'ShortPixel Image Optimizer options', 'shortpixel-adaptive-images' ),
						'url'     => 'options-general.php?page=wp-shortpixel-settings',
						'primary' => true,
					],
					[
						'type'    => 'link',
						'title'   => __( 'ShortPixel Adaptive Images options', 'shortpixel-adaptive-images' ),
						'url'     => 'options-general.php?page=' . Page::NAMES[ 'settings' ],
						'primary' => false,
					],
				],
			];

			$this->missing_jquery = [
				'causer' => 'missing jquery',
				'title' => __( 'ShortPixel Adaptive Images has found that jQuery is missing', 'shortpixel-adaptive-images' ),
				'body'  => [
					sprintf( __( 'Your theme is missing the <a href="%s" target="_blank">jQuery</a> library. In order for ShortPixel to properly run, please either go to <a href="%s" target="_blank">ShortPixel\'s settings</a>, in the Behaviour tab, scroll down to the bottom and activate the option "New AI engine" or restore jQuery.',
						'shortpixel-adaptive-images' ), 'https://jquery.com', 'options-general.php?page=shortpixel-ai-settings#top#behaviour' ),
					__( 'Please press <span>Re-Check</span> button if <b>jQuery</b> has been restored in your theme.', 'shortpixel-adaptive-images' ),
				],
				'buttons' => [
					[
						'title'      => __( 'Re-Check', 'shortpixel-adaptive-images' ),
						'action'     => 're-check',
						'additional' => [
							'return_url' => '//' . $_SERVER[ 'HTTP_HOST' ] . $_SERVER[ 'REQUEST_URI' ],
						],
						'primary'    => true,
					],
				],
			];

            $this->temporary_redirect = [
                'causer' => 'temporary redirect',
                'title' => __( 'ShortPixel optimization alert', 'shortpixel-adaptive-images' ),
                'body'  => [
                    __( 'Important: The images on your website aren\'t accessible to ShortPixel\'s Image Optimization Cloud. 
                        Therefore, they cannot be optimized and then delivered by the ShortPixel CDN. Here is a short list of possible causes and how to fix them: 
                        <a href="https://shortpixel.com/knowledge-base/article/148-why-are-my-images-redirected-from-cdn-shortpixel-ai" target="_blank">Why are my images redirected?</a>', 'shortpixel-adaptive-images' ),
                ],
                'buttons' => [
                    [
                        'title'      => __( 'Re-Check', 'shortpixel-adaptive-images' ),
                        'action'     => 're-check',
                        'additional' => [
                            'return_url' => '//' . $_SERVER[ 'HTTP_HOST' ] . $_SERVER[ 'REQUEST_URI' ],
                        ],
                        'primary'    => true,
                    ],
                ],
            ];

            $sw = ActiveIntegrations::_()->get( 'swift-performance' );
			$this->swift_performance = [
				'causer' => 'swift performance',
				'title' => 'Swift Performance ' . ( empty( $sw[ 'plugin' ] ) ? '' : ucfirst( $sw[ 'plugin' ] ) . ' ' ) . __( 'options conflict', 'shortpixel-adaptive-images' ),
				'body'  => [
					__( 'There is a known compatibility issue between ShortPixel Adaptive Images and older Swift Performance plugin versions which makes some background images to never get displayed.', 'shortpixel-adaptive-images' ),
					__( 'Please update to the latest plugin version, or deactivate either "<b>Merge Styles</b>" or "<b>Normalize Static Resources</b>" options from the Swift Performance <a href="tools.php?page=swift-performance&subpage=settings" target="_blank">plugin settings</a>.',
						'shortpixel-adaptive-images' ),
				],
				'buttons' => [
					[
						'title'   => __( 'Change conflicting settings', 'shortpixel-adaptive-images' ),
						'action'  => 'solve conflict',
						'primary' => true,
					],
				],
			];

			$this->imagify = [
				'title' => __( 'Imagify options conflict', 'shortpixel-adaptive-images' ),
				'body'  => [
					__( 'There is a known compatibility issue with <i>Imagify\'s WebP delivery</i> that will make images not display on the site.', 'shortpixel-adaptive-images' ),
					__( 'Please deactivate <b>"Display images in webp format on the site"</b> from the Imagify <a href="options-general.php?page=imagify" target="_blank">plugin settings</a>. <b>ShortPixel</b> will handle the delivery of WebP images to supporting browsers.',
						'shortpixel-adaptive-images' ),
				],
				'buttons' => [
					[
						'title'   => __( 'Change conflicting settings', 'shortpixel-adaptive-images' ),
						'action'  => 'solve conflict',
						'primary' => true,
					],
				],
			];

			$this->spio_webp = [
				'causer' => 'spio webp',
				'title' => __( 'ShortPixel optimization alert', 'shortpixel-adaptive-images' ),
				'body'  => [
					sprintf( __( 'Please deactivate the <span>ShortPixel Image Optimizer\'s</span> <a href="%s" target="_blank">Deliver next generation images</a> option when the ShortPixel Adaptive Images plugin is active. The next generation images will transparently be delivered by ShortPixel Adaptive Images CDN.', 'shortpixel-adaptive-images' ),
						admin_url( 'options-general.php?page=wp-shortpixel-settings&part=adv-settings' ) ),
				],
				'buttons' => [
					[
						'title'   => __( 'Deactivate option', 'shortpixel-adaptive-images' ),
						'action'  => 'solve conflict',
						'primary' => true,
					],
				],
			];

			$this->litespeed_js_combine = [
				'causer' => 'litespeed js combine',
				'title' => __( 'LiteSpeed Cache options conflict', 'shortpixel-adaptive-images' ),
				'body'  => [
					sprintf( __( 'Please deactivate the <span>LiteSpeed Cache\'s</span> <a href="%s" target="_blank">JS Combine</a> option when the ShortPixel Adaptive Images plugin is active.', 'shortpixel-adaptive-images' ),
						admin_url( 'admin.php?page=litespeed-page_optm#settings_js' ) ),
				],
				'buttons' => [
					[
						'title'   => __( 'Change conflicting settings', 'shortpixel-adaptive-images' ),
						'action'  => 'solve conflict',
						'primary' => true,
					],
				],
			];

			$plugin_folder = plugin_basename( SHORTPIXEL_AI_PLUGIN_DIR );

			$conflicting_files = [
				'/' . $plugin_folder . '/assets/css/admin.css',
				'/' . $plugin_folder . '/assets/css/admin.min.css',
				'/' . $plugin_folder . '/assets/css/style-bar.css',
				'/' . $plugin_folder . '/assets/css/style-bar.min.css',
			];

			$this->wpo_merge_css = [
				'causer' => 'wpo merge css',
				'title' => __( 'WP Optimize CSS options conflict', 'shortpixel-adaptive-images' ),
				'body'  => [
					sprintf( __( 'In some circumstances, the <span>WP Optimize\'s</span> <a href="%s" target="_blank">Enable merging of CSS files</a> option breaks the ShortPixel Adaptive Images plugin CSS. Please check your website and if you find CSS issues, please deactivate this option.',
						'shortpixel-adaptive-images' ),
						admin_url( 'admin.php?page=wpo_minify&tab=wp_optimize_css' ) ),
					sprintf( __( 'Also you could add the following ShortPixel Adaptive Images plugin CSS files present below to <a href="%s" target="_blank">Default exclusions</a> or <a href="%s" target="_blank">CSS exclusions</a>.', 'shortpixel-adaptive-images' ),
						admin_url( 'admin.php?page=wpo_minify&tab=wp_optimize_advanced' ),
						admin_url( 'admin.php?page=wpo_minify&tab=wp_optimize_css' ) ),
					'<pre>' . implode( PHP_EOL, $conflicting_files ) . '</pre>',
				],
				'buttons' => [
					[
						'title'   => __( 'Add exclusions', 'shortpixel-adaptive-images' ),
						'action'  => 'add exclusions',
						'primary' => true,
					],
					[
						'title'   => __( 'Change conflicting settings', 'shortpixel-adaptive-images' ),
						'action'  => 'solve conflict',
						'primary' => false,
					],
				],
			];
      
      $this->lqip_mkdir_failed = [
		        'causer' => 'lqip mkdir failed',
				'title' => __( 'LQIP creation failed', 'shortpixel-adaptive-images' ),
				'body'  => [
					__( '<strong>ShortPixel Adaptive Images</strong> was trying to create Low Quality Image Placeholders of your images in the last 12 hours but <span>the plugin has no permissions to do that</span>.', 'shortpixel-adaptive-images' ),
					__( 'LQIP option has been <span>temporarily</span> disabled until you configure your server and grant the permissions to <strong>create folders and files</strong> in your uploads folder.', 'shortpixel-adaptive-images' ),
				],
			];
		}
	}

<?php

	namespace ShortPixel\AI;

	/**
	 * Contextual "Help" tabs on the plugin's admin screens and the help AJAX hook.
	 *
	 * Adds a "Help & Support" tab and, while `pages.on_boarding.display_allowed` is truthy, a "Setup Wizard" tab whose
	 * `[data-action="enable on boarding"][data-plugin="shortpixel-adaptive-images"]` button is handled by `admin.js`,
	 * which posts `action=shortpixel_ai_handle_help_action`, `data[action]=enable on boarding` (no nonce) to
	 * `Help\Actions::handle()`.
	 *
	 * @todo `_()` never stores the instance (the constructor does not set `self::$instance`), so every call builds a
	 *       new object and registers the hooks again. Set `self::$instance = $this` in the constructor.
	 */
	class Help {
		/**
		 * Instance of the class (singleton); never assigned (see the class `@todo`).
		 * @var \ShortPixel\AI\Help|null $instance
		 */
		private static $instance;

		/**
		 * Returns the instance; currently always a new one that registers its hooks.
		 *
		 * @return \ShortPixel\AI\Help
		 */
		public static function _() {
			return self::$instance instanceof self ? self::$instance : new self;
		}

		/**
		 * `current_screen` callback (priority 50): on allowed screens (see `getScreens()`) adds the support tab, the
		 * wizard tab when `pages.on_boarding.display_allowed` is truthy, and a sidebar with documentation/support links.
		 *
		 * @return void
		 */
		public function addTabs() {
			$screen = get_current_screen();

			if ( !$screen || !$this->isAllowedScreen( $screen ) ) {
				return;
			}

			$screen->add_help_tab( $this->getTab( 'support' ) );

			if ( !!Options::_()->get( 'display_allowed', [ 'pages', 'on_boarding' ] ) ) {
				$screen->add_help_tab( $this->getTab( 'on-boarding' ) );
			}

			$screen->set_help_sidebar(
				'<p><strong>' . __( 'For more information:', 'shortpixel-adaptive-images' ) . '</strong></p>' .
				'<p><a href="https://shortpixel.com/knowledge-base/category/307-shortpixel-adaptive-images" target="_blank">' . __( 'Documentation & Help', 'shortpixel-adaptive-images' ) . '</a></p>' .
				'<p><a href="' . apply_filters('spai_affiliate_link','https://shortpixel.com/contact') . '" target="_blank">' . __( 'Support', 'shortpixel-adaptive-images' ) . '</a></p>'
			);
		}

		/**
		 * Help real constructor: registers the hooks.
		 */
		private function __construct() {
			$this->hooks();
		}

		/**
		 * Hooks `addTabs` on `current_screen` (50) and `Help\Actions::handle` on `wp_ajax_shortpixel_ai_handle_help_action`.
		 *
		 * @return void
		 */
		private function hooks() {
			add_action( 'current_screen', [ $this, 'addTabs' ], 50 );
			add_action( 'wp_ajax_shortpixel_ai_handle_help_action', [ 'ShortPixel\AI\Help\Actions', 'handle' ] );
		}

		/**
		 * Returns a help tab definition for `WP_Screen::add_help_tab()`.
		 *
		 * @param string $id `support` or `on-boarding`.
		 *
		 * @return array{id?: string, title?: string, content?: string} Empty array for an unknown id.
		 */
		private function getTab( $id ) {
			switch ( $id ) {
				case 'support':
					return [
						'id'      => 'shortpixel-ai-' . $id . '-tab',
						'title'   => __( 'Help & Support', 'shortpixel-adaptive-images' ),
						'content' =>
							'<h2>' . __( 'Help & Support', 'shortpixel-adaptive-images' ) . '</h2>' .
							'<p>' . sprintf( __( 'If you need help using the ShortPixel Adaptive Images plugin, <a href="%s">open a support request at ShortPixel.com</a>.', 'shortpixel-adaptive-images' ), apply_filters('spai_affiliate_link','https://shortpixel.com/contact') ) . '</p>' .
							'<p>' . sprintf( __( 'Before asking our ShortPixel support team for help, we strongly recommend that you <a href="%s" target="_blank">read the articles on shortpixel.com/knowledge-base/</a>.', 'shortpixel-adaptive-images' ), 'https://shortpixel.com/knowledge-base/category/307-shortpixel-adaptive-images' ) . '</p>' .
							'<p><a href="https://shortpixel.com/knowledge-base/category/307-shortpixel-adaptive-images" class="button button-primary" target="_blank">' . __( 'Documentation & Help', 'shortpixel-adaptive-images' )
                            . '</a> <a href="' . apply_filters('spai_affiliate_link','https://shortpixel.com/contact') . '" class="button button-secondary" target="_blank">' . __( 'ShortPixel Support', 'shortpixel-adaptive-images' ) . '</a></p>',
					];
				case 'on-boarding':
					return [
						'id'      => 'shortpixel-ai-' . $id . '-tab',
						'title'   => __( 'Setup Wizard', 'shortpixel-adaptive-images' ),
						'content' =>
							'<h2>' . __( 'Setup Wizard', 'shortpixel-adaptive-images' ) . '</h2>' .
							'<p>' . __( 'If you need to access the setup wizard again, please click on the button below.', 'shortpixel-adaptive-images' ) . '</p>' .
							'<p><button class="button button-primary" data-action="enable on boarding" data-plugin="shortpixel-adaptive-images">' . __( 'Setup Wizard', 'shortpixel-adaptive-images' ) . '</button></p>',
					];
			}

			return [];
		}

		/**
		 * Tells whether the screen id contains one of the slugs from `getScreens()`.
		 *
		 * @param \WP_Screen $screen
		 *
		 * @return bool
		 */
		private function isAllowedScreen( $screen ) {
			$screens = $this->getScreens();

			// Closure: true when the screen id contains the page slug `$id`.
			return !empty( array_filter( $screens, function( $id ) use ( $screen ) {
				return strpos( $screen->id, $id ) !== false;
			} ) );
		}

		/**
		 * Returns the page slugs from `Page::NAMES` that get help tabs: all except the keys in
		 * `apply_filters( 'shortpixel/ai/help/disallowedPages', [ 'on-boarding' ] )`.
		 *
		 * @return string[]
		 */
		private function getScreens() {
			$current_pages    = Page::NAMES;
			$disallowed_pages = [ 'on-boarding' ];

			$disallowed_pages = apply_filters( 'shortpixel/ai/help/disallowedPages', $disallowed_pages );

			// Closure: keeps the `Page::NAMES` entries whose key is not in `$disallowed_pages`.
			return array_values( array_filter( $current_pages, function( $page_key ) use ( $disallowed_pages ) {
				return !in_array( $page_key, $disallowed_pages );
			}, ARRAY_FILTER_USE_KEY ) );
		}
	}

<?php

	namespace ShortPixel\AI;

	use ShortPixel\AI\Options\Option;
	use ShortPixel\AI\Options\Category;
	use ShortPixel\AI\Options\Collection;

	/**
	 * Settings store of the plugin.
	 *
	 * Every setting, flag and dismissal lives in ONE WordPress option, `short_pixel_ai_options`, which holds a
	 * serialized tree of `Options\Collection` (root) -> `Options\Category` (nodes) -> scalar values or
	 * `Options\Option` (leaf bags of key/value pairs, used for associative arrays and objects). The 1.x layout
	 * (`spai_settings_*` options) is migrated into this tree and deleted by `ShortPixelAI::migrate_options()`.
	 *
	 * Access styles:
	 * - Explicit: `Options::_()->get( 'api_url', [ 'settings', 'behaviour' ], $default )` and
	 *   `Options::_()->set( $value, 'api_url', [ 'settings', 'behaviour' ] )`. Name and categories are passed
	 *   through `Converter::toSnakeCase()`; a single string category is accepted.
	 * - Property-like: `Options::_()->settings` returns the `settings` Category; chained property access then goes
	 *   through the model classes' `__get()` (`$options->settings->behaviour->api_url`).
	 * - Magic grammar (`__get`/`__set`): the property name is split on `_`; every segment goes through
	 *   `lcfirst()` + `Converter::fromCamelCase()`; the last segment is the option name and the others are the
	 *   category path. `settings_behaviour_apiUrl` -> `settings.behaviour.api_url`, `flags_all_firstInstall`
	 *   -> `flags.all.first_install`, `settings_behaviour_amazonS3` -> `settings.behaviour.amazon_s3`.
	 *   Because `_` is the separator, a segment that itself contains an underscore must be written in camelCase.
	 *   A name without `_` addresses a root-level entry (`$options->settings`).
	 *
	 * There is no schema, default table or key whitelist: `set()` creates any missing category on the way and
	 * stores any name and any value. Defaults are filled by `ShortPixelAI` (the default/migration code in
	 * `short-pixel-ai.class.php`) and by the `$default` argument of `get()`.
	 *
	 * Option paths used by the plugin (verified by grep over `includes/`, views included):
	 * - `settings.general.api_key`
	 * - `settings.compression.level` (`lossy|glossy|lossless`), `.webp`, `.avif`, `.webp_detect`, `.remove_exif`,
	 *   `.png_to_webp`, `.jpg_to_webp`, `.gif_to_webp` (read as `<type>_to_webp`)
	 * - `settings.behaviour.api_url`, `.fadein`, `.crop`, `.replace_method` (`src|srcset|both`), `.generate_noscript`,
	 *   `.lazy_threshold`, `.hover_handling`, `.replace_logged_in`, `.lqip`, `.process_way` (`cron|instant`),
	 *   `.local_storage`, `.native_lazy`, `.alter2wh`, `.sizespostmeta`, `.size_breakpoints`, `.size_breakpoints_base`,
	 *   `.size_breakpoints_rate`, `.nojquery` (`2` vanilla default, `<=0` jQuery mode), `.topbarmenu`, `.amazon_s3`,
	 *   `.storage_url`, `.host_removal`, `.sync_initial_parsing`
	 * - `settings.areas.parse_css_files` (`1` on, `0` never set, `-1` explicitly unset), `.css_domains`, `.parse_js`,
	 *   `.parse_js_lazy`, `.parse_json`, `.parse_json_lazy`, `.js2cdn`, `.lity`, `.serve_svg`, `.native_lazy` (stale,
	 *   still read by `JqueryJsLoader`), `.backgrounds_lazy`, `.backgrounds_lazy_style`, `.backgrounds_max_width`
	 * - `settings.exclusions.excluded_paths`, `.eager_paths`, `.excluded_pages`, `.eager_selectors`,
	 *   `.noresize_selectors`, `.excluded_selectors`
	 * - `settings.simple.*` (simple-mode meta options, translated by `ShortPixelAI::translateSimpleOptions()`)
	 * - `flags.all.advanced`, `.account`, `.credits`, `.css_ver`, `.first_install`
	 * - `pages.on_boarding.step`, `.has_been_passed`, `.display_allowed`, `.redirect_allowed`, `.front_worker`
	 *   (an Option keyed by user ID)
	 * - `tests.front_end.enqueued`, `.missing_jquery`
	 * - `notices.dismissed.<snake_case causer>` (an Option, see `Notice::dismiss()`)
	 *
	 * Options that live OUTSIDE this tree: `spai_settings_lazy_ajax_tags`, `shortpixel_ai_lqip_state`,
	 * `shortpixel_ai_installed_time` (plus the 1.x `spai_settings_*` keys while migrating).
	 *
	 * @todo `set()` and `delete()` always return `$this` (truthy) whatever `update_option()` does, so every
	 *       caller's `!!Options::_()->set(...)` "success" flag is always true. Return the `update_option()` result
	 *       (or `true` when the value is unchanged) instead.
	 */
	class Options {
		/**
		 * @var \ShortPixel\AI\Options $instance The singleton instance, set by the first constructor call.
		 */
		private static $instance;

		/**
		 * @var string $optionsName Options collection stored in database using this name
		 */
		private static $optionsName = 'short_pixel_ai_options';

		/**
		 * Returns the singleton, creating it (and hooking `init()` to `admin_init`) on first call.
		 *
		 * @return \ShortPixel\AI\Options
		 */
		public static function _() {
			return self::$instance instanceof self ? self::$instance : new self();
		}

		/**
		 * Stores the instance as the singleton (only if none is stored yet) and hooks `init()` on `admin_init`.
		 */
		private function __construct() {
			if ( !isset( self::$instance ) || !self::$instance instanceof self ) {
				self::$instance = $this;
			}

			add_action( 'admin_init', [ $this, 'init' ] );
		}

		/**
		 * Registers `short_pixel_ai_options` in the settings group `short_pixel_ai_option_group` on `admin_init`.
		 *
		 * The group is never rendered (no `settings_fields()` call) and the plugin never posts to `options.php`: all
		 * saves go through AJAX (`Page\Actions`). The registration has no `sanitize_callback` and no `type`.
		 *
		 * @todo `init()` registers the option without a `sanitize_callback`, so a `manage_options` user posting the
		 *       group to `options.php` replaces the whole tree with a raw request value. Remove the registration or
		 *       add a sanitize callback that rejects non-`Collection` values.
		 *
		 * @return void
		 */
		public function init() {
			// Registering settings
			register_setting( 'short_pixel_ai_option_group', self::$optionsName );
		}

		/**
		 * Magic read using the `category_category_optionName` grammar (see class docblock).
		 *
		 * `settings_behaviour_apiUrl` reads `get( 'api_url', [ 'settings', 'behaviour' ], null )`. There is no way
		 * to pass a default: missing options read as `null`.
		 *
		 * @param string $name Magic property name.
		 *
		 * @return mixed The stored value, a Category/Option for non-leaf paths, or null.
		 */
		public function __get( $name ) {
			$exploded_name = $this->explodeMagicName( $name );

			return $this->get( $exploded_name[ 'name' ], $exploded_name[ 'categories' ], null );
		}

		/**
		 * Magic write using the `category_category_optionName` grammar (see class docblock).
		 *
		 * `$options->flags_all_firstInstall = 1` calls `set( 1, 'first_install', [ 'flags', 'all' ] )`, which
		 * persists the whole tree immediately.
		 *
		 * @param string $name  Magic property name.
		 * @param mixed  $value Value to store.
		 *
		 * @return bool|Options Result of `set()` (ignored by PHP for magic assignment).
		 */
		public function __set( $name, $value ) {
			$exploded_name = $this->explodeMagicName( $name );

			return $this->set( $value, $exploded_name[ 'name' ], $exploded_name[ 'categories' ] );
		}

		/**
		 * Reads an option, a category, or the whole tree from `short_pixel_ai_options`.
		 *
		 * Loads the option (falling back to an empty Collection when it is missing or not a Collection), snake-cases
		 * name and categories, walks down the category path with `walker()`, then returns:
		 * - the reached node itself when `$name` is empty (the whole Collection for `get()`);
		 * - the node's `$name` entry when set (read through the node's protected `__dyna` bag via its `__get()`);
		 * - `$default` otherwise.
		 *
		 * Returns false when WordPress is not loaded (`get_option` missing).
		 *
		 * @todo `get()` (via `walker()`) stops at the deepest EXISTING category when a category in the path is
		 *       missing and then looks `$name` up at that level, so `get( 'level', [ 'settings', 'nope' ] )` can return
		 *       a `settings.level` entry instead of `$default`. Return `$default` when the path is not fully resolved.
		 *
		 * @param string|null       $name     Option name (any case, converted to snake_case); empty returns the node.
		 * @param string|array|null $category Category path, outermost first (e.g. `[ 'settings', 'behaviour' ]`) or a
		 *                                    single category name.
		 * @param mixed             $default  Value returned when the option is not set.
		 *
		 * @return mixed|Collection|Category|Option|false
		 */
		public function get( $name = null, $category = null, $default = null ) {
			if ( !function_exists( 'get_option' ) ) {
				return false;
			}

			$collection = get_option( self::$optionsName, Collection::_() );
			$collection = $collection instanceof Collection ? $collection : Collection::_();
            //echo("aaaiciiii:"); var_dump($collection);

			$name     = $this->prepareName( $name );
			$category = $this->prepareCategories( $category );

			$collection = $this->walker( $collection, $category );

			// return empty( $name ) ? $collection : ( $collection->{$name} instanceof Category || $collection->{$name} instanceof Option ? $collection->{$name} : Option::_() );
			return empty( $name )
				? $collection
				: ( isset( $collection->__dyna->{$name} )
					? $collection->__dyna->{$name}
					: (isset($collection->{$name})
                        ? $collection->{$name}
                        : $default
                    )
				);
		}

		/**
		 * Stores an option and persists the whole tree with `update_option( 'short_pixel_ai_options', ... )`.
		 *
		 * Missing categories on the path are created (`creator()`); associative arrays and objects are converted to
		 * `Option` trees (`optionCreator()`), lists and scalars are stored as they are. No validation, no whitelist.
		 * With an empty `$name` nothing is assigned but the categories are still created and the tree is saved.
		 *
		 * @param mixed             $value    Value of option.
		 * @param string|null       $name     Name of option (converted to snake_case).
		 * @param string|array|null $category Category path, outermost first, or a single category name.
		 *
		 * @return bool|Options False when WordPress is not loaded, otherwise `$this` (whatever `update_option()` did).
		 */
		public function set( $value, $name = null, $category = null ) {
			if ( !function_exists( 'update_option' ) ) {
				return false;
			}

			$collection = $this->get();

			$name = $this->prepareName( $name );

			$category = $this->prepareCategories( $category );

			$this->creator( $collection, $category, $name, $value );

			update_option( self::$optionsName, $collection );

			return $this;
		}

		/**
		 * Removes one option from a category and persists the tree.
		 *
		 * Nothing is removed when a category on the path does not exist; the tree is saved anyway.
		 *
		 * @param string            $name     Option name (converted to snake_case); empty returns false.
		 * @param string|array|null $category Category path, outermost first, or a single category name.
		 *
		 * @return bool|Options False when WordPress is not loaded or `$name` is empty, otherwise `$this`.
		 */
		public function delete( $name, $category = null ) {
			if ( !function_exists( 'update_option' ) || empty( $name ) ) {
				return false;
			}

			$collection = $this->get();

			$name     = $this->prepareName( $name );
			$category = $this->prepareCategories( $category );

			$this->remover( $collection, $category, $name );

			update_option( self::$optionsName, $collection );

			return $this;
		}

		/**
		 * Deletes the whole `short_pixel_ai_options` option.
		 *
		 * @return bool|Options False when WordPress is not loaded, otherwise `$this`.
		 */
		public function clearCollection() {
			if ( !function_exists( 'delete_option' ) ) {
				return false;
			}

			delete_option( self::$optionsName );

			return $this;
		}

		/**
		 * Descends recursively through `$categories` and returns the deepest node reached.
		 *
		 * Descends only into `Category` instances; when the next category is missing (or is an `Option`), it
		 * returns the current node instead of signalling a miss (see the `@todo` on `get()`).
		 *
		 * @param Collection|Category|mixed $collection Current node.
		 * @param array                     $categories Snake-cased category path.
		 * @param int                       $index      Current depth.
		 *
		 * @return Collection|Category|mixed
		 */
		private function walker( $collection, $categories, $index = 0 ) {
			if ( ( $collection instanceof Collection || $collection instanceof Category ) && $index < count( $categories ) ) {
				if ( $collection->{$categories[ $index ]} instanceof Category ) {
					return $this->walker( $collection->{$categories[ $index ]}, $categories, $index + 1 );
				}
				else {
					return $collection;
				}
			}
			else {
				return $collection;
			}
		}

		/**
		 * Creates the missing categories along `$categories` and assigns `$name` at the end of the path.
		 *
		 * A non-Category entry found on the path (scalar or `Option`) is REPLACED by a new empty Category. The value
		 * is passed through `optionCreator()` before assignment. Works by reference on `$collection`.
		 *
		 * @param Collection|Category $collection Current node (by reference).
		 * @param array               $categories Snake-cased category path.
		 * @param string|false        $name       Snake-cased option name; nothing is assigned when empty.
		 * @param mixed               $value      Value to store.
		 * @param int                 $index      Current depth.
		 *
		 * @return void
		 */
		private function creator( &$collection, $categories, $name, $value, $index = 0 ) {
			if ( ( $collection instanceof Collection || $collection instanceof Category ) && $index < count( $categories ) ) {
				if ( !$collection->{$categories[ $index ]} instanceof Category ) {
					$collection->{$categories[ $index ]} = Category::_();
				}

				$this->creator( $collection->{$categories[ $index ]}, $categories, $name, $value, $index + 1 );
			}
			else {
				if ( !empty( $name ) ) {
					$value = $this->optionCreator( $value );

					$collection->{$name} = $value;
				}
			}
		}

		/**
		 * Converts associative arrays and objects to an `Option` tree, recursively; returns other values unchanged.
		 *
		 * `Option`, `Category` and `Collection` inputs are unwrapped through `getData()` first, so a Category passed
		 * to `set()` is stored as an `Option` (and `walker()` will no longer descend into it). Keys are snake-cased.
		 * List arrays (keys `0..n-1`) and empty arrays are kept as plain arrays.
		 *
		 * @param mixed $option Value to convert.
		 *
		 * @return \ShortPixel\AI\Options\Option|mixed
		 */
		private function optionCreator( $option ) {
			if ( ( ( is_array( $option ) && $this->isAssoc( $option ) ) || is_object( $option ) ) ) {
				$options = Option::_();

                if($option instanceof Options\Option || $option instanceof Options\Category || $option instanceof Options\Collection) {
                    $option = $option->getData();
                }

				foreach ( $option as $key => $item ) {
					$key = $this->prepareName( $key );

					if ( ( is_array( $item ) && $this->isAssoc( $item ) ) || is_object( $item ) ) {
						$options->{$key} = $this->optionCreator( $item );
					}
					else {
						$options->{$key} = $item;
					}
				}

				return $options;
			}
			else {
				return $option;
			}
		}

		/**
		 * Walks `$categories` and unsets `$name` at the end of the path via the node's `unsetProperty()`.
		 *
		 * Does nothing when a category on the path is missing. Works by reference on `$collection`.
		 *
		 * @param Collection|Category $collection Current node (by reference).
		 * @param array               $categories Snake-cased category path.
		 * @param string|false        $name       Snake-cased option name.
		 * @param int                 $index      Current depth.
		 *
		 * @return void
		 */
		private function remover( &$collection, $categories, $name, $index = 0 ) {
			if ( ( $collection instanceof Collection || $collection instanceof Category ) && $index < count( $categories ) ) {
				if ( $collection->{$categories[ $index ]} instanceof Category ) {
					$this->remover( $collection->{$categories[ $index ]}, $categories, $name, $index + 1 );
				}
			}
			else {
				if ( !empty( $name ) ) {
					$collection->unsetProperty( $name );
				}
			}
		}

		/**
		 * Normalises a category argument to a list of snake_case names.
		 *
		 * Empty input gives `[]`; a scalar gives a one-element list; array items that do not snake-case to a
		 * non-empty string are dropped (keys are kept, so the result can have gaps; `walker()`/`creator()` index it
		 * from 0, so a dropped first item makes them stop at the root).
		 *
		 * @param string|array|null $categories Category name or path.
		 *
		 * @return array
		 */
		private function prepareCategories( $categories ) {
			if ( empty( $categories ) ) {
				return [];
			}

			if ( !is_array( $categories ) && !empty( $categories ) ) {
				// transforming category to snake_case
				$categories = [ $this->prepareName( $categories ) ];
			}
			else if ( is_array( $categories ) ) {
				foreach ( $categories as $index => $item ) {
					$item = $this->prepareName( $item );

					if ( is_string( $item ) && !empty( $item ) ) {
						$categories[ $index ] = $item;
					}
					else {
						unset( $categories[ $index ] );
					}
				}
			}
			else {
				return [];
			}

			return $categories;
		}

		/**
		 * Converts an option or category name with `Converter::toSnakeCase()` (non-alphanumerics removed, whitespace
		 * runs to `_`, lower-cased).
		 *
		 * @param mixed $name Name to convert.
		 *
		 * @return string|false|null False for arrays, objects and empty values; null when the converter gets a
		 *                           non-string or empty string.
		 */
		private function prepareName( $name ) {
			if ( is_object( $name ) || is_array( $name ) ) {
				return false;
			}

			$name = (string) $name;

			if ( empty( $name ) ) {
				return false;
			}

			return Converter::toSnakeCase( $name );
		}

		/**
		 * Splits a magic property name into option name and category path (see the grammar in the class docblock).
		 *
		 * @param string $name Magic name, e.g. `settings_behaviour_apiUrl`.
		 *
		 * @return array{name: string, categories: string[]|null} e.g. `[ 'name' => 'api_url',
		 *               'categories' => [ 'settings', 'behaviour' ] ]`; categories is null for a single segment.
		 */
		private function explodeMagicName( $name ) {
			$split_name = explode( '_', $name );
			$split_qty  = count( $split_name );

			$return = [ 'name' => null, 'categories' => null ];

			if ( $split_qty > 1 ) {
				$return[ 'name' ]       = Converter::fromCamelCase( lcfirst( array_pop( $split_name ) ) );
				$return[ 'categories' ] = $split_name;

				foreach ( $return[ 'categories' ] as $index => $category ) {
					$return[ 'categories' ][ $index ] = Converter::fromCamelCase( lcfirst( $category ) );
				}
			}
			else {
				$return[ 'name' ] = Converter::fromCamelCase( lcfirst( $split_name[ 0 ] ) );
			}

			return $return;
		}

		/**
		 * Tells whether a non-empty array has keys other than `0..n-1`.
		 *
		 * @param mixed $array Value to test.
		 *
		 * @return bool False for non-arrays, empty arrays and lists.
		 */
		private function isAssoc( $array ) {
			if ( !is_array( $array ) || !$array ) {
				return false;
			}

			return array_keys( $array ) !== range( 0, count( $array ) - 1 );
		}
	}

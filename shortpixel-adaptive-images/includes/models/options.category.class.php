<?php

	namespace ShortPixel\AI\Options;

	/**
	 * Middle level of the settings tree: `Collection` > `Category` > `Option`.
	 *
	 * In practice the tree is `Collection` > `settings` (Category) > `behaviour`, `areas`,
	 * `exclusions`, … (Category) > scalar values. The whole tree is stored as a serialized
	 * `Collection` in the `short_pixel_ai_options` option. Every dynamic property is kept in
	 * the protected `$__dyna` stdClass through __get()/__set(), so PHP 8.2+ never sees a
	 * dynamic property on this class. There is no __isset(), so `isset( $category->foo )`
	 * is always false and `empty( $category->foo )` always true. No static state.
	 */
	class Category {
		/**
		 * Returns a new, empty instance.
		 *
		 * @return Category
		 */
		public static function _() {
			return new self();
		}

        /**
         * Storage of every dynamic property (name => Category, Option or scalar). Added to
         * avoid the deprecation notice in PHP 8.x when adding properties dynamically.
         *
         * @var \stdClass
         */
        protected $__dyna;
        /**
         * Creates the empty `$__dyna` storage.
         */
        public function __construct() {
            $this->__dyna = new \stdClass();
        }
        /**
         * Returns the storage object itself (not a copy).
         *
         * @return \stdClass
         */
        public function getData() {
            return $this->__dyna;
        }

        /**
         * Exports the stored properties as an array for the settings export (`Page`).
         *
         * For each stored property: a `Category` or `Option` value becomes its getData()
         * stdClass (not expanded further), a stdClass becomes an associative array through a
         * JSON round trip, and anything else is copied as is.
         *
         * @return array Property name => value.
         *
         * @todo exportRecursive() expands only one level: a `Category` or `Option` nested two
         *       levels down stays an object inside the returned stdClass and json_encode()
         *       renders it as `{}`. Recurse into Category/Option values (call exportRecursive()
         *       on a Category, cast getData() of an Option).
         */
        public function exportRecursive() {
            $result = [];

            foreach ((array) $this->getData() as $key => $value) {
                if ($value instanceof \ShortPixel\AI\Options\Category) {
                    $result[$key] = $value->getData();
                } elseif ($value instanceof \ShortPixel\AI\Options\Option) {
                    $result[$key] = $value->getData();
                } elseif ($value instanceof \stdClass) {
                    $result[$key] = json_decode(json_encode($value), true);
                } else {
                    $result[$key] = $value;
                }
            }
            return $result;
        }

        /**
         * Removes one stored property.
         *
         * @param string $name Property name.
         *
         * @return void
         */
        public function unsetProperty( $name ) {
            unset($this->__dyna->$name);
        }

		/**
		 * Returns a stored property.
		 *
		 * Falls back to a real property of the same name (only `__dyna` exists, so
		 * `$category->__dyna` returns the storage from outside the class).
		 *
		 * @param string $name Property name.
		 *
		 * @return mixed The value, or null when it is not stored or is null.
		 */
		public function __get( $name ) {
            return isset($this->__dyna->$name) ? $this->__dyna->$name : (isset( $this->$name ) ? $this->$name : null);
		}

		/**
		 * Stores a property in `$__dyna`.
		 *
		 * @param string                 $name  Property name.
		 * @param Category|Option|mixed  $value Child node or scalar value.
		 *
		 * @return void
		 */
		public function __set( $name, $value ) {
			$this->__dyna->$name = $value;
		}

        /**
         * Migrates objects serialized before `$__dyna` existed.
         *
         * Older plugin versions stored values as real public properties. On unserialize this
         * creates `$__dyna` when it is missing, moves every property other than `__dyna` into
         * it and unsets the original, so the object behaves like a new one.
         *
         * @return void
         */
        public function __wakeup() {
            if(!isset($this->__dyna)) {
                $this->__dyna = new \stdClass();
            }
            foreach ($this as $key => $value) {
                if($key != '__dyna') {
                    $this->__dyna->$key = $value;
                    unset($this->$key);
                }
            }
        }
	}

<?php

	namespace ShortPixel\AI\Options;

	/**
	 * Root of the settings tree: `Collection` > `Category` > `Option`.
	 *
	 * Stored serialized in the `short_pixel_ai_options` option; `ShortPixel\AI\Options::get()`
	 * replaces a stored value that is not a Collection with a new empty one. Every dynamic
	 * property is kept in the protected `$__dyna` stdClass through __get()/__set(), so PHP
	 * 8.2+ never sees a dynamic property on this class. There is no __isset(), so
	 * `isset( $collection->foo )` is always false and `empty( $collection->foo )` always
	 * true. No static state.
	 */
	class Collection {
		/**
		 * Returns a new, empty instance.
		 *
		 * @return Collection
		 */
		public static function _() {
			return new self();
		}

        /**
         * Storage of every dynamic property (name => Category). Added to avoid the
         * deprecation notice in PHP 8.x when adding properties dynamically.
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
		 * `$collection->__dyna` returns the storage from outside the class).
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
		 * @param string   $name  Property name.
		 * @param Category $value Child category.
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

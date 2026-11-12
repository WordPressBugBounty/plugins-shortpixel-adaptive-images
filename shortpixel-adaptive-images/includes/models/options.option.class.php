<?php

	namespace ShortPixel\AI\Options;

	/**
	 * Leaf value bag of the settings tree: `Collection` > `Category` > `Option`.
	 *
	 * The whole tree is stored as a serialized `Collection` in the `short_pixel_ai_options`
	 * option and accessed through `ShortPixel\AI\Options`. Every dynamic property is kept in
	 * the protected `$__dyna` stdClass through __get()/__set(), so PHP 8.2+ never sees a
	 * dynamic property on this class. There is no __isset(), so `isset( $option->foo )`
	 * is always false and `empty( $option->foo )` always true, whatever is stored; read the
	 * value or use getData() instead. No static state.
	 */
	class Option {
		/**
		 * Returns a new, empty instance.
		 *
		 * @return Option
		 */
		public static function _() {
			return new self();
		}

        /**
         * Storage of every dynamic property (name => value). Added to avoid the
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
		 * `$option->__dyna` returns the storage from outside the class).
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
		 * @param string $name  Property name.
		 * @param mixed  $value Value.
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

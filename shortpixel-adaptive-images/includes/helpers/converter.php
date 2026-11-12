<?php

	namespace ShortPixel\AI;

	/**
	 * Stateless string-case helpers.
	 *
	 * Used to map option names between their stored snake_case form and the camelCase
	 * property names of `Options` (`Options::get()`, `Options::__get()`), to build the
	 * `settings_exclusions_*` option names in the selector AJAX handlers, to normalise
	 * notice causers, and to turn AJAX action strings into handler method names
	 * (`'handle' . Converter::toTitleCase( $action )` in the `*\Actions` classes).
	 * This is not a wrapper around MatthiasMullie Minify or PathConverter.
	 *
	 * Holds no static state.
	 */
	class Converter {
		/**
		 * Returns the string transformed into snake_case.
		 *
		 * Trims the input, removes every character that is not an underscore, an ASCII
		 * letter, a digit or whitespace (non-ASCII letters are removed too), replaces each
		 * run of whitespace with a single underscore and lowercases the result.
		 * Example: `'Lazy Ajax-Tags!'` becomes `'lazy_ajaxtags'`.
		 *
		 * @param string $string String to be transformed to snake_case
		 *
		 * @return string|null The snake_case string, or null when `$string` is not a string
		 *                     or is empty (including `'0'`, which `empty()` treats as empty).
		 */
		public static function toSnakeCase( $string ) {
			// 1st Reg Ex is an all non-alphabetic chars, 2nd Reg Ex is an all whitespace chars
			return is_string( $string ) && !empty( $string ) ? strtolower( preg_replace( [ '/(?>[^_A-Za-z\d\s]+)/su', '/[\s]+/su' ], [ '', '_' ], trim( $string ) ) ) : null;
		}

        /**
         * Returns the snake_case string transformed into camelCase (or PascalCase).
         *
         * Replaces underscores with spaces, uppercases the first letter of each word,
         * removes the spaces and, unless `$capitalizeFirstCharacter` is true, lowercases
         * the first character. Example: `'eager_paths'` becomes `'eagerPaths'`, which the
         * selector AJAX handlers append to `'settings_exclusions_'`.
         *
         * @param string $string                   snake_case input.
         * @param bool   $capitalizeFirstCharacter True to return PascalCase.
         *
         * @return string
         *
         * @todo snakeToCamelCase() fails on an empty string (or one made only of underscores):
         *       reading `$str[0]` on `''` raises an "Uninitialized string offset" warning and
         *       assigning `''` to a string offset throws an Error. Return early when `$str === ''`.
         */
        public static function snakeToCamelCase($string, $capitalizeFirstCharacter = false)
        {

            $str = str_replace(' ', '', ucwords(str_replace('_', ' ', $string)));

            if (!$capitalizeFirstCharacter) {
                $str[0] = strtolower($str[0]);
            }

            return $str;
        }

        /**
		 * Returns the string transformed from camelCase to snake_case.
		 *
		 * Splits the input into words (an uppercase acronym such as `CSS`, or a letter
		 * followed by one or more lowercase letters/digits), lowercases all-uppercase
		 * words, lcfirst()s the others and joins them with underscores.
		 * Example: `'lazyAjaxTags'` becomes `'lazy_ajax_tags'`, `'parseCSSFiles'` becomes
		 * `'parse_css_files'`. Single lowercase letters and leading digits that do not
		 * match either word pattern are dropped from the result.
		 *
		 * @param string $string String to be transformed from camelCase to snake_case
		 *
		 * @return string
		 */
		public static function fromCamelCase( $string ) {
			preg_match_all( '!([A-Z][A-Z0-9]*(?=$|[A-Z][a-z0-9])|[A-Za-z][a-z0-9]+)!', $string, $matches );

			$return = $matches[ 0 ];

			foreach ( $return as &$match ) {
				$match = $match == strtoupper( $match ) ? strtolower( $match ) : lcfirst( $match );
			}

			return implode( '_', $return );
		}

		/**
		 * Returns the string in TitleCase with every delimiter removed.
		 *
		 * Applies `mb_convert_case( MB_CASE_TITLE )`, then for each delimiter splits the
		 * string and normalises each word against `$exceptions`: words whose uppercase
		 * form is in the list are uppercased (Roman numerals), words whose lowercase form
		 * is in the list are lowercased (Hungarian street words such as `utca`), and other
		 * words get ucfirst(). Finally it removes every `.`, `-`, `_` and whitespace.
		 * Example: `'dismiss forever'` becomes `'DismissForever'`; the `*\Actions` classes
		 * call `'handle' . Converter::toTitleCase( $action )` with the result.
		 * Because of the exception list, an action word such as `v` or `ix` becomes `V`/`IX`
		 * and `u` or `s` stays lowercase, except in first position, where the final ucfirst()
		 * re-capitalises it (`'u turn'` gives `'UTurn'`). Requires the mbstring extension (no guard).
		 *
		 * @param string   $string     Input string.
		 * @param string[] $delimiters Word delimiters, including the `O'` and `Mc` name prefixes.
		 * @param string[] $exceptions Words kept lowercase (lowercase entries) or forced to
		 *                             uppercase (uppercase entries).
		 *
		 * @return string
		 */
		public static function toTitleCase(
			$string,
			$delimiters = [ " ", "-", ".", "'", "O'", "Mc" ],
			$exceptions = [ "út",
							"u",
							"s",
							"és",
							"utca",
							"tér",
							"krt",
							"körút",
							"sétány",
							"I",
							"II",
							"III",
							"IV",
							"V",
							"VI",
							"VII",
							"VIII",
							"IX",
							"X",
							"XI",
							"XII",
							"XIII",
							"XIV",
							"XV",
							"XVI",
							"XVII",
							"XVIII",
							"XIX",
							"XX",
							"XXI",
							"XXII",
							"XXIII",
							"XXIV",
							"XXV",
							"XXVI",
							"XXVII",
							"XXVIII",
							"XXIX",
							"XXX",
			]
		) {
			/*
			 * Exceptions in lower case are words you don't want converted
			 * Exceptions all in upper case are any words you don't want converted to title case
			 *   but should be converted to upper case, e.g.:
			 *   king henry viii or king henry Viii should be King Henry VIII
			 */
			$string = mb_convert_case( $string, MB_CASE_TITLE, "UTF-8" );

			foreach ( $delimiters as $dlnr => $delimiter ) {
				$words    = explode( $delimiter, $string );
				$newwords = [];
				foreach ( $words as $wordnr => $word ) {

					if ( in_array( mb_strtoupper( $word, "UTF-8" ), $exceptions ) ) {
						// check exceptions list for any words that should be in upper case
						$word = mb_strtoupper( $word, "UTF-8" );
					}
					else if ( in_array( mb_strtolower( $word, "UTF-8" ), $exceptions ) ) {
						// check exceptions list for any words that should be in upper case
						$word = mb_strtolower( $word, "UTF-8" );
					}

					else if ( !in_array( $word, $exceptions ) ) {
						// convert to uppercase (non-utf8 only)

						$word = ucfirst( $word );
					}
					array_push( $newwords, $word );
				}
				$string = join( $delimiter, $newwords );
			}

			return preg_replace( '/(?:\.|-|_|\s)+/su', '', $string );
		}
	}
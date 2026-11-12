<?php

	/**
	 * Debug logger writing to `<uploads basedir>/shortpixel-ai.log` (`ShortPixelAI::LOG_NAME`).
	 *
	 * Debug switch: the `SHORTPIXEL_AI_DEBUG` constant. It comes from wp-config.php, or, when
	 * not defined there, from `?SHORTPIXEL_AI_DEBUG=<value>` for users with `manage_options`
	 * (defined on `plugins_loaded`, priority 0; `?SHORTPIXEL_AI_DEBUG=delete` deletes the log
	 * via clearLog() and leaves debug off). Otherwise it is defined as false.
	 *
	 * How the value is interpreted:
	 * - Truthiness (`SHORTPIXEL_AI_DEBUG && …`, and log()): any truthy value (true, `1`, a
	 *   bitmask, a request string) turns on general logging.
	 * - Bitmask (`SHORTPIXEL_AI_DEBUG & self::DEBUG_AREA_*`): area-specific logging needs the
	 *   bit. Boolean true counts as 1, which has no named area, so `true` enables only the
	 *   ungated log lines. A numeric string from the request is converted to an int.
	 * - PHP error capture additionally needs `is_numeric( SHORTPIXEL_AI_DEBUG )`.
	 *
	 * Bits:
	 * - 1: no constant and no area; a value of 1 only turns general logging on.
	 * - DEBUG_AREA_PHPERR (2): turns on display_errors, `error_reporting( E_ALL )` and
	 *   errorHandler() (numeric values only).
	 * - DEBUG_INCLUDE_CONTENT (4): include full page/CSS/JSON content in log lines.
	 * - DEBUG_AREA_CACHE (8): `CacheCleaner` logging.
	 * - DEBUG_AREA_JSON (16): JSON content handling.
	 * - DEBUG_AREA_CSS (32): CSS parsing and the affected-tags dump.
	 * - DEBUG_AREA_HTML (64): HTML parsing and `AffectedTags` changes.
	 * - DEBUG_AREA_LQIP (64): LQIP logging; it shares the value 64 with DEBUG_AREA_HTML, so the
	 *   two areas cannot be enabled separately.
	 * For example `126` (2|4|8|16|32|64) enables every area.
	 *
	 * Static state a test must reset: `ShortPixelAILogger::$instance` (unset/null). The log
	 * path is fixed at construction from wp_upload_dir(). The constant itself cannot be
	 * redefined, so debug-on tests belong in an isolated process.
	 *
	 * @todo `DEBUG_AREA_LQIP` has the same value (64) as `DEBUG_AREA_HTML`, so LQIP logging
	 *       cannot be enabled without HTML logging and vice versa. Give DEBUG_AREA_LQIP its own
	 *       bit (128).
	 * @todo The log file is written in the public uploads directory with a predictable name, so
	 *       anyone can download it while debug is on, including page content when
	 *       DEBUG_INCLUDE_CONTENT is set. Write it to a non-web-accessible location or a
	 *       randomised file name, or protect the directory.
	 * @todo A non-numeric `?SHORTPIXEL_AI_DEBUG=` string (e.g. `yes`) is accepted for admins and
	 *       every `SHORTPIXEL_AI_DEBUG & self::DEBUG_AREA_*` check then throws a TypeError
	 *       ("Unsupported operand types") on PHP 8. Cast the request value to int (or reject
	 *       non-numeric values) when defining the constant.
	 */
	class ShortPixelAILogger {
		/**
		 * The singleton, or null before the first call to instance().
		 *
		 * @var ShortPixelAILogger|null
		 */
		private static $instance;
		/**
		 * Absolute path of the log file: `<uploads basedir>/shortpixel-ai.log`.
		 *
		 * @var string
		 */
		private        $logPath;

        /**
         * `SHORTPIXEL_AI_DEBUG` bitmask values; see the class docblock.
         * DEBUG_AREA_HTML and DEBUG_AREA_LQIP share the value 64.
         */
        const DEBUG_AREA_PHPERR = 2;
        const DEBUG_INCLUDE_CONTENT = 4;
		const DEBUG_AREA_CACHE = 8;
        const DEBUG_AREA_JSON = 16;
        const DEBUG_AREA_CSS = 32;
        const DEBUG_AREA_HTML = 64;
        const DEBUG_AREA_LQIP = 64;

        /**
		 * Make sure only one instance is running.
		 *
		 * @return ShortPixelAILogger
		 */
		public static function instance() {
			if ( !isset ( self::$instance ) ) {
				self::$instance = new self;
			}

			return self::$instance;
		}

		/**
		 * Sets the log path from wp_upload_dir() (which may create the uploads directory) and
		 * logs the upload dir data when debug is on.
		 */
		private function __construct() {
			$upload_dir    = wp_upload_dir();
			$this->logPath = $upload_dir[ 'basedir' ] . '/' . ShortPixelAI::LOG_NAME;
			$this->log("LOGGER INITIALIZED. UPLOAD DIR DATA: ", $upload_dir);
		}

		/**
		 * Appends a line to the log when `SHORTPIXEL_AI_DEBUG` is truthy.
		 *
		 * @param string $msg   Message.
		 * @param mixed  $extra Value appended as pretty-printed JSON; falsy values are omitted.
		 *
		 * @return void
		 */
		public function log( $msg, $extra = false ) {
			if ( SHORTPIXEL_AI_DEBUG ) {
                $this->logAnyway( $msg, $extra );
			}
		}

        /**
         * Appends `[Y-m-d H:i:s.mmm] <msg><json extra>` and a newline to the log, regardless of
         * `SHORTPIXEL_AI_DEBUG`.
         *
         * The timestamp uses date(), i.e. the PHP default timezone (UTC under WordPress).
         * Only log() calls it.
         *
         * @param string $msg   Message.
         * @param mixed  $extra Value appended as pretty-printed JSON; falsy values (including
         *                      0, '' and empty arrays) are omitted.
         *
         * @return void
         */
        public function logAnyway($msg, $extra = false) {
            $micro_date = microtime();
            $date_array = explode(" ",$micro_date);
            $date = date("Y-m-d H:i:s",$date_array[1]);
            $date = $date . '.' . substr($date_array[0], 2, 3);
            file_put_contents( $this->logPath, '[' . $date . "] $msg" . ( $extra ? json_encode( $extra, JSON_PRETTY_PRINT ) : '' ) . "\n", FILE_APPEND );
        }

		/**
		 * Custom (PHP) error handler
		 *
		 * Registered with set_error_handler() only when `SHORTPIXEL_AI_DEBUG` is numeric and has
		 * the DEBUG_AREA_PHPERR bit. Logs the error type, message, file and line between
		 * `--- CAUGHT:BEGIN ---` / `--- CAUGHT:END ---` markers and returns true, so PHP's
		 * default handler (and display_errors output) is skipped. Errors excluded by the current
		 * error_reporting() level return false and go to the default handler. The E_ERROR and
		 * E_CORE_* cases are never reached, because PHP does not pass those to user handlers.
		 *
		 * @param int    $errno   Error level.
		 * @param string $errstr  Message.
		 * @param string $errfile File.
		 * @param int    $errline Line.
		 *
		 * @return bool False when the level is not reported, true otherwise.
		 */
		public static function errorHandler( $errno, $errstr, $errfile, $errline ) {
			if ( !( error_reporting() & $errno ) ) {
				// this error code doesn't contain in error_reporting,
				// so let default error handler does its job
				return false;
			}

			switch ( $errno ) {
				case E_ERROR:
					$type = 'Run-time ERROR';
					break;

				case E_WARNING:
					$type = 'Run-time WARNING';
					break;

				case E_NOTICE:
					$type = 'Run-time NOTICE';
					break;

				case E_DEPRECATED:
					$type = 'Run-time DEPRECATED';
					break;

				case E_CORE_ERROR:
					$type = 'PHP core ERROR';
					break;

				case E_CORE_WARNING:
					$type = 'PHP core WARNING';
					break;

				case E_USER_ERROR:
					$type = 'Generated ERROR';
					break;

				case E_USER_WARNING:
					$type = 'Generated WARNING';
					break;

				case E_USER_NOTICE:
					$type = 'Generated NOTICE';
					break;

				case E_USER_DEPRECATED:
					$type = 'Generated DEPRECATED';
					break;

				default:
					$type = 'OTHER';
					break;
			}

			$logger = self::instance();

			$logger->log( '--- CAUGHT:BEGIN ---' );
			$logger->log( $type . ': ' . $errstr );
			$logger->log( 'IN FILE: ' . $errfile );
			$logger->log( 'ON LINE: ' . $errline );
			// Backtrace could be uncommented if needed
			// $logger->log( json_encode( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ), JSON_PRETTY_PRINT ) );
			$logger->log( '--- CAUGHT:END ---' );

			// don't run default error handler
			return true;
		}

		/**
		 * Deletes the log file, silently ignoring a missing file.
		 *
		 * @return void
		 */
		public function clearLog() {
			@unlink( $this->logPath );
		}

		/**
		 * Returns the absolute path of the log file.
		 *
		 * @return string
		 */
		public function getLogPath() {
		    return $this->logPath;
        }
	}
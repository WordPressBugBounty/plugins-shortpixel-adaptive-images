<?php
	/**
	 * Uninstall script, run by WordPress when the plugin is deleted from the Plugins screen.
	 *
	 * Steps:
	 * 1. Exits unless `ABSPATH` or `WP_UNINSTALL_PLUGIN` is defined.
	 * 2. Includes `short-pixel-ai.php` (constants and classes; a no-op when the plugin is already
	 *    loaded). The `ShortPixelAI` singleton is not booted by this.
	 * 3. `\ShortPixel\AI\LQIP::clearCache()`: deletes option `shortpixel_ai_lqip_state`, then, when
	 *    the current LQIP directory `<uploads>/spai/lqip` exists, every file and folder in it and the
	 *    directory itself, and the LQIP `processed` entry of the plugin options; returns true only when
	 *    both the directory and that entry were removed.
	 * 4. On true, `rmdir()` of `<uploads>/<SHORTPIXEL_AI_PLUGIN_BASEDIR>` (normally
	 *    `uploads/shortpixel-adaptive-images`), the parent of the OLD LQIP directory, if it exists;
	 *    `rmdir()` fails with a warning when it is not empty.
	 * 5. `PromoBanner::uninstall()`: deletes the promo banner's dismissals option `spai-promo-banner` (the
	 *    banner module cannot hook the uninstall itself).
	 *
	 * Left behind: `<uploads>/spai/`, the old `<uploads>/shortpixel-adaptive-images/lqip` cache, option
	 * `short_pixel_ai_options` and the other options and transients, the `.htaccess` block (removed on
	 * deactivation), cron events (cleared on deactivation). On multisite only the current site is handled.
	 *
	 * @todo uninstall.php removes `<uploads>/shortpixel-adaptive-images`, the parent of the OLD LQIP
	 *       directory, instead of `<uploads>/spai` (parent of `LQIP::DIR`), and only when empty. Remove
	 *       `dirname(LQIP::DIR)` and `LQIP::OLD_DIR` recursively.
	 * @todo uninstall.php deletes no plugin option or transient (`short_pixel_ai_options`,
	 *       `spai_settings_lazy_ajax_tags`, `shortpixel_ai_installed_time`, `spai_domain_status`, ...)
	 *       and does not iterate the sites of a network. Delete them per site.
	 * @todo uninstall.php accepts `ABSPATH` alone as the guard, so any WordPress context that includes
	 *       it runs the clean-up. Require `WP_UNINSTALL_PLUGIN`.
	 *
	 * @package ShortPixel\AI
	 */

	( defined( 'ABSPATH' ) || defined( 'WP_UNINSTALL_PLUGIN' ) ) || die;

	include_once __DIR__ . '/short-pixel-ai.php';

	// clearCache() exists in every shipped version; the guards only protect against a partial install.
	if ( class_exists( '\ShortPixel\AI\LQIP' ) && method_exists( '\ShortPixel\AI\LQIP', 'clearCache' ) ) {
		$is_cache_cleared = ShortPixel\AI\LQIP::clearCache();

        $uploadsSpaiDir = SHORTPIXEL_AI_WP_UPLOADS_DIR . DIRECTORY_SEPARATOR . SHORTPIXEL_AI_PLUGIN_BASEDIR;
		if ( $is_cache_cleared && !empty( SHORTPIXEL_AI_WP_UPLOADS_DIR ) && file_exists($uploadsSpaiDir) ) {
			rmdir( $uploadsSpaiDir );
		}
	}

	// The promo banner module keeps its dismissals in its own option; it cannot hook the uninstall itself.
	if ( class_exists( '\ShortPixel\AI\PromoBanner\PromoBanner' ) ) {
		\ShortPixel\AI\PromoBanner\PromoBanner::uninstall( ShortPixelAI::PROMO_BANNER_PREFIX );
	}

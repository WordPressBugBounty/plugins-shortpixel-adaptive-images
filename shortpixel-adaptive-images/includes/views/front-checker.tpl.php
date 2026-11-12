<?php
	/**
	 * Front-worker panel template, shown on the FRONT END while the on-boarding "run front worker" check is active.
	 *
	 * Included by `ShortPixel\AI\Page::render( 'front-checker.tpl.php' )` from a `wp_head` closure registered by
	 * `ShortPixel\AI\JsLoader::enqueue()` (logged-in `manage_options` user with the `shortpixel-ai-front-worker` cookie
	 * and a matching `pages.on_boarding.front_worker.<user ID>` token), so `$this` is the `ShortPixel\AI\Page` instance.
	 * Assets: `spai-front-worker` (`assets/js/front.worker.js`) with `SPAIFrontConstants`.
	 *
	 * Variables defined here: `$controller` (`$this->ctrl`). No remote calls.
	 *
	 * Markup hooks used by `front.worker.js`: `.spai-popup__wrap` (checking overlay), `.spai-fw-maximize-button`,
	 * `aside.spai-fw-sidebar` with `.back` (Done), `.spai__recommended-options-wrap[data-spainonce]` (nonce sent as
	 * `spainonce` with `action=shortpixel_ai_handle_page_action`, `page=frontWorker`), `.option[data-option]` for
	 * `lazy-load-backgrounds`, `parse-css`, `parse-js`, `parse-json`, `hover-handling` (checkbox `name` = option),
	 * `.revert-confirmation` (`.cancel`, `.revert`), `.has-recommendations`, `.no-recommendations`, `.no-options`,
	 * `.cache-message`, `[data-mission="general"] .reload`, `.collapse-button`. Icons from
	 * `assets/img/hide-show-sprite.svg`.
	 *
	 * @todo The checkboxes print `checked( … )` with a short echo tag; `checked()` already echoes, so the attribute is
	 *       printed twice. Call `checked( … );` inside a plain `php` tag instead.
	 * @todo The panel is printed from `wp_head`, so `<div class="wrap">` and the panel markup land inside `<head>`
	 *       (browsers move them to `<body>`). Render it on `wp_footer` or `wp_body_open`.
	 *
	 * @var \ShortPixelAI $controller
	 */
	$controller = $this->ctrl;
?>

<div class="spai-popup__wrap">
	<div class="spai-popup__inner">
		<div class="spai-popup__body">
			<div class="spai-popup__title"><?= __( 'Hang on, <strong>ShortPixel Adaptive Images</strong> is checking your page…', 'shortpixel-adaptive-images' ); ?></div>
			<div class="roller-wrap">
				<div class="roller">
					<div></div>
					<div></div>
					<div></div>
					<div></div>
					<div></div>
					<div></div>
					<div></div>
					<div></div>
				</div>
			</div>
		</div>
	</div>
</div>
<div class="spai-fw-maximize-button clearfix" title="<?= __( 'Show Controls', 'shortpixel-adaptive-images' ); ?>">
	<svg class="icon">
		<use xlink:href="<?= $controller->plugin_url; ?>assets/img/hide-show-sprite.svg#show"></use>
	</svg>
</div>
<aside class="spai-fw-sidebar">
	<div class="spai-fw-sidebar__header clearfix">
		<h6 class="title"><?= __( 'ShortPixel AI', 'shortpixel-adaptive-images' ); ?></h6>
		<div class="buttons-wrap">
			<button class="dark_blue_link back"><?= __( 'Done', 'shortpixel-adaptive-images' ); ?></button>
		</div>
	</div>
	<div class="spai-fw-sidebar__body">
		<div class="spai__recommended-options-wrap" data-spainonce="<?= $this->getNonce(); ?>">
			<p class="has-recommendations hidden"><?= __( 'Based on this page’s contents, we recommend to activate the following options:', 'shortpixel-adaptive-images' ); ?></p>
			<p class="no-recommendations hidden"><?= __( 'The images on this page are properly optimized.', 'shortpixel-adaptive-images' ); ?></p>

			<div class="option clearfix hidden" data-option="lazy-load-backgrounds">
                <input id="lazy-load-backgrounds" type="checkbox" name="lazy-load-backgrounds" class="tgl" value="1" <?= checked( true, $controller->settings->areas->backgrounds_lazy ); ?>>
				<label for="lazy-load-backgrounds" class="tgl-btn">
					<span></span><?= __( 'Lazy-load the backgrounds', 'shortpixel-adaptive-images' ); ?>
				</label>
			</div>

			<div class="option clearfix hidden" data-option="parse-css">
				<input id="parse-css" type="checkbox" name="parse-css" class="tgl" value="1" <?= checked( true, $controller->settings->areas->parse_css_files > 0 ); ?>>
				<label for="parse-css" class="tgl-btn">
					<span></span><?= __( 'Replace in CSS files', 'shortpixel-adaptive-images' ); ?>
				</label>
			</div>

			<div class="option clearfix hidden" data-option="parse-js">
				<input id="parse-js" type="checkbox" name="parse-js" class="tgl" value="1" <?= checked( true, $controller->settings->areas->parse_js ); ?>>
				<label for="parse-js" class="tgl-btn">
					<span></span><?= __( 'Replace in the JS blocks', 'shortpixel-adaptive-images' ); ?>
				</label>
			</div>

			<div class="option clearfix hidden" data-option="parse-json">
				<input id="parse-json" type="checkbox" name="parse-json" class="tgl" value="1" <?= checked( true, $controller->settings->areas->parse_json ); ?>>
				<label for="parse-json" class="tgl-btn">
					<span></span><?= __( 'Replace in JSON data', 'shortpixel-adaptive-images' ); ?>
				</label>
			</div>

			<div class="option clearfix hidden" data-option="hover-handling">
				<input id="hover-handling" type="checkbox" name="hover-handling" class="tgl" value="1" <?= checked( true, $controller->settings->behaviour->hover_handling ); ?>>
				<label for="hover-handling" class="tgl-btn">
					<span></span><?= __( 'Images hover handling', 'shortpixel-adaptive-images' ); ?>
				</label>
			</div>

			<div class="revert-confirmation hidden">
				<?= __( 'Based on our checks, your website needs this option.', 'shortpixel-adaptive-images' ); ?>
				<span><?= __( 'Are you sure you want to revert it?', 'shortpixel-adaptive-images' ); ?></span>
				<div class="buttons-wrap">
					<button class="blue_link cancel"><?= __( 'Cancel', 'shortpixel-adaptive-images' ); ?></button>
					<button class="bordered_link revert"><?= __( 'Revert', 'shortpixel-adaptive-images' ); ?></button>
				</div>
			</div>
			<p class="no-options hidden"><span><?= __( 'Good job!', 'shortpixel-adaptive-images' ); ?></span>
				<?= __( 'All recommended options for this page are successfully enabled. You can now navigate to another page of the site in order to run the check on that page too. Please press the <span>Done</span> button above when you\'re ready, to go back to the onboarding setup.',
					'shortpixel-adaptive-images' ); ?></p>
			<p class="cache-message hidden"><?= sprintf( __( 'Please properly clear all levels of cache and open the website in a private window to check the results. <a href="%s" target="_blank">How do I do this?</a>',
					'shortpixel-adaptive-images' ), 'https://www.howtogeek.com/269265/how-to-enable-private-browsing-on-any-web-browser/' ); ?></p>
		</div>
		<div class="buttons-wrap hidden" data-mission="general">
			<button class="dark_blue_link reload" style="z-index:1"><?= __( 'OK, Reload the page', 'shortpixel-adaptive-images' ); ?></button>
		</div>
	</div>
	<div class="spai-fw-sidebar__footer">
		<div class="collapse-button clearfix">
			<svg class="icon icon--hide">
				<use xlink:href="<?= $controller->plugin_url; ?>assets/img/hide-show-sprite.svg#hide"></use>
			</svg>
			<div class="label"><?= __( 'Hide Controls', 'shortpixel-adaptive-images' ); ?></div>
		</div>
	</div>
</aside>
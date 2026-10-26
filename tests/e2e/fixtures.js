// Shared test object for every spec. WordPress 7.1's admin opts into cross-document view
// transitions (wp-admin/css/view-transitions.css, gated on prefers-reduced-motion:
// no-preference). In headless Chromium the transition after a form submit keeps rendering
// suppressed: requestAnimationFrame never fires, so actionability checks never see a
// "stable" element and screenshots hang. Emulating reduced motion on the page avoids it
// (the `reducedMotion` config option did not take effect with Playwright 1.61 here).
const base = require('@playwright/test');

exports.test = base.test.extend({
	page: async ({ page }, use) => {
		await page.emulateMedia({ reducedMotion: 'reduce' });
		await use(page);
	},
});
exports.expect = base.expect;

const { test, expect } = require('./fixtures');

const PAGES = ['wpfilebase_manage', 'wpfilebase_files', 'wpfilebase_cats', 'wpfilebase_filebrowser', 'wpfilebase_css', 'wpfilebase_tpls', 'wpfilebase_sets'];

// Signed in by auth.setup.js (storageState in playwright.config.js).

for (const slug of PAGES) {
	test(`admin page ${slug} has no console errors`, async ({ page }) => {
		const errors = [];
		page.on('pageerror', (e) => errors.push(e.message));
		page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
		// Unreachable hosts only (dead third-party scripts); ERR_ABORTED is the browser cancelling core assets on navigation.
		page.on('requestfailed', (r) => {
			const why = r.failure() ? r.failure().errorText : '';
			if (why !== 'net::ERR_ABORTED') errors.push(`request failed: ${r.url()} (${why})`);
		});
		const res = await page.goto(`/wp-admin/admin.php?page=${slug}`);
		expect(res.status()).toBe(200);
		await expect(page.locator('#wpbody')).toBeVisible();
		// PHP's html_errors markup, not plain text: the dashboard legitimately prints the plugin's own log.
		const html = await page.content();
		expect(html).not.toMatch(/<b>(Fatal error|Warning|Deprecated|Notice)<\/b>:/);
		expect(errors, errors.join('\n')).toEqual([]);
	});
}

// WordPress 7.1 without the Classic Editor plugin opens the block editor, which has no TinyMCE
// toolbar; the TinyMCE 4 button can only be exercised where the classic editor is installed.
test('classic editor shows the WP-Filebase button', async ({ page }) => {
	test.skip(!process.env.WPFB_CLASSIC_EDITOR, 'set WPFB_CLASSIC_EDITOR=1 on a site with the Classic Editor plugin');
	await page.goto('/wp-admin/post-new.php?classic-editor');
	const btn = page.locator('.mce-i-wpfbInsertTag, [aria-label="WP-Filebase"], .mce-btn img[src*="btn.gif"]');
	await expect(btn.first()).toBeVisible({ timeout: 15000 });
});

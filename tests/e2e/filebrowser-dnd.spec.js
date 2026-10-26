// The admin File Browser's drag & drop: every row stays draggable after async loads, a drag only
// moves the row it started on, and drops into folders / back out to the root persist server-side.
const { test, expect } = require('./fixtures');
const { execFileSync } = require('child_process');
const path = require('path');

const FB = '/wp-admin/admin.php?page=wpfilebase_filebrowser';
const AJAX = '/wp-admin/admin-ajax.php?action=wpfilebase';
let ids;

test.beforeAll(({ }, testInfo) => {
	ids = JSON.parse(execFileSync('php', [path.join(__dirname, 'fixture.php'), 'setup'], { encoding: 'utf8', env: { ...process.env, WP_BASE_URL: testInfo.project.use.baseURL } }));
});
test.afterAll(({ }, testInfo) => {
	execFileSync('php', [path.join(__dirname, 'fixture.php'), 'teardown', JSON.stringify(ids)], { env: { ...process.env, WP_BASE_URL: testInfo.project.use.baseURL } });
});

// Server truth: which category does the tree put this item under?
async function parentOf(page, type, id) {
	const res = await page.request.get(`${AJAX}&wpfb_action=${type === 'file' ? 'fileinfo' : 'catinfo'}&id=${id}`);
	return (await res.json()).path.split('/').slice(0, -1).join('/'); // '' = root, else cat path
}

async function expand(page, catId) {
	const li = page.locator(`#wpfb-cat-${catId}`);
	if (await li.evaluate((el) => el.classList.contains('expandable'))) {
		await Promise.all([
			page.waitForResponse((r) => r.url().includes('wpfb_action=tree') || (r.request().postData() || '').includes('wpfb_action=tree')),
			li.locator('> .hitarea').click(),
		]);
	}
	await expect(li.locator(`#wpfb-file-${ids.file_in_a}`)).toBeVisible();
}

test('files loaded when a folder expands are draggable', async ({ page }) => {
	await page.goto(FB, { waitUntil: 'networkidle' });
	await expand(page, ids.cat_a);
	await expect(page.locator(`#wpfb-file-${ids.file_in_a}`)).toHaveAttribute('draggable', 'true', { timeout: 3000 });
	await expect(page.locator(`#wpfb-cat-${ids.cat_a_child}`)).toHaveAttribute('draggable', 'true');
	// the inline "+ Add File" link inside the expanded folder got its file input too
	await expect(page.locator(`#wpfb-cat-${ids.cat_a} a.add-file`).first()).toHaveClass(/file-input/);
});

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

test('drag from a node the observer has not armed yet does not move its parent', async ({ page }) => {
	await page.goto(FB, { waitUntil: 'networkidle' });
	await expand(page, ids.cat_a);
	// Simulate the window before the observer's debounced re-arm has run: a freshly loaded row
	// has neither the attribute nor the handlers yet (its <img>/<a> children are still draggable
	// by default). Let the debounced re-arms from the expand settle first, then disable the
	// re-arm so nothing restores them during the drag.
	await page.waitForTimeout(500);
	await page.locator(`#wpfb-file-${ids.file_in_a}`).evaluate((el) => {
		Object.keys(window).filter((k) => k.startsWith('wpfb_fbDOMModHandle')).forEach((k) => { window[k] = function () {}; });
		el.removeAttribute('draggable');
		window.jQuery(el).off();
	});
	const posts = [];
	page.on('request', (r) => { if (r.method() === 'POST' && (r.postData() || '').includes('change-category')) posts.push(r.postData()); });
	await page.locator(`#wpfb-file-${ids.file_in_a}`).dragTo(page.locator(`#wpfb-cat-${ids.cat_b}`), { targetPosition: { x: 40, y: 8 } });
	await page.waitForTimeout(500);
	expect(posts, 'no move request may fire').toEqual([]);
	expect(await parentOf(page, 'cat', ids.cat_a)).toBe(''); // cat A is still at root
	await expect(page.locator(`#wpfb-cat-${ids.cat_b} #wpfb-cat-${ids.cat_a}`)).toHaveCount(0);
});

test('drag a file into a folder, then back out to the root', async ({ page }) => {
	await page.goto(FB, { waitUntil: 'networkidle' });
	// root file -> folder B
	const [res1] = await Promise.all([
		page.waitForResponse((r) => (r.request().postData() || '').includes('change-category')),
		page.locator(`#wpfb-file-${ids.file_root}`).dragTo(page.locator(`#wpfb-cat-${ids.cat_b}`), { targetPosition: { x: 40, y: 8 } }),
	]);
	expect(await res1.json()).toMatchObject({ error: false, cat_id: ids.cat_b });
	expect(await parentOf(page, 'file', ids.file_root)).toBe(ids.cat_b_path);
	await expect(page.locator(`#wpfb-cat-${ids.cat_b} #wpfb-file-${ids.file_root}`)).toHaveCount(1);
	await expect(page.locator(`#wpfb-filebrowser-1 > #wpfb-file-${ids.file_root}`)).toHaveCount(0);

	// folder B's file -> back to the root, dropped on the tree's own "+ Add Category / + Add File"
	// row (no row handler there: the tree-level drop handler resolves it to the root). The
	// reloaded row is armed by the observer first.
	await expect(page.locator(`#wpfb-file-${ids.file_root}`)).toHaveAttribute('draggable', 'true');
	const rootAddRow = page.locator('#wpfb-filebrowser-1 > #wpfb-cat-0');
	const [res2] = await Promise.all([
		page.waitForResponse((r) => (r.request().postData() || '').includes('change-category')),
		page.locator(`#wpfb-file-${ids.file_root}`).dragTo(rootAddRow, { targetPosition: { x: 40, y: 8 } }),
	]);
	expect(await res2.json()).toMatchObject({ error: false, cat_id: 0 });
	expect(await parentOf(page, 'file', ids.file_root)).toBe('');
	await expect(page.locator(`#wpfb-filebrowser-1 > #wpfb-file-${ids.file_root}`)).toHaveCount(1);
});

test('drop on a sibling file is a no-op', async ({ page }) => {
	await page.goto(FB, { waitUntil: 'networkidle' });
	await expand(page, ids.cat_a);
	const posts = [];
	page.on('request', (r) => { if (r.method() === 'POST' && (r.postData() || '').includes('change-category')) posts.push(r.postData()); });
	page.on('dialog', async (d) => { posts.push('DIALOG ' + d.message()); await d.dismiss(); });
	await page.locator(`#wpfb-file-${ids.file_in_a}`).dragTo(page.locator(`#wpfb-file-${ids.file_offline}`), { targetPosition: { x: 40, y: 8 } });
	await page.waitForTimeout(500);
	expect(posts).toEqual([]);
	await expect(page.locator(`#wpfb-cat-${ids.cat_a} #wpfb-file-${ids.file_in_a}`)).toHaveCount(1);
});

test('drop on a stale category id lands at root', async ({ page }) => {
	await page.goto(FB, { waitUntil: 'networkidle' });
	await expand(page, ids.cat_a);
	await page.locator(`#wpfb-cat-${ids.cat_b}`).evaluate((el) => { el.id = 'wpfb-cat-999999'; }); // the other tab deleted it
	const [res] = await Promise.all([
		page.waitForResponse((r) => (r.request().postData() || '').includes('change-category')),
		page.locator(`#wpfb-file-${ids.file_in_a}`).dragTo(page.locator('#wpfb-cat-999999'), { targetPosition: { x: 40, y: 8 } }),
	]);
	expect(await res.json()).toMatchObject({ error: false, cat_id: 0 });
	expect(await parentOf(page, 'file', ids.file_in_a)).toBe('');
	await expect(page.locator(`#wpfb-filebrowser-1 > #wpfb-file-${ids.file_in_a}`)).toHaveCount(1);
});

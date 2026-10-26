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

// Put a fixture file back through the server (not the UI), so tests do not depend on each other.
async function moveBack(page, fileId, catId) {
	const nonce = await page.evaluate(() => wpfbConf.nonce);
	const res = await page.request.post(AJAX, { form: { wpfb_action: 'change-category', type: 'file', id: fileId, new_cat_id: catId, _wpfb_nonce: nonce } });
	expect(await res.json()).toMatchObject({ error: false });
}

async function expand(page, catId) {
	const li = page.locator(`#wpfb-cat-${catId}`);
	if (await li.evaluate((el) => el.classList.contains('expandable'))) {
		await Promise.all([
			page.waitForResponse((r) => r.url().includes('wpfb_action=tree') || (r.request().postData() || '').includes('wpfb_action=tree')),
			li.locator('> .hitarea').click(),
		]);
	}
	await expect(li).toHaveClass(/collapsable/); // children loaded and shown
	await expect(li.locator('> ul > li').first()).toBeVisible();
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
	await moveBack(page, ids.file_in_a, ids.cat_a); // leave the fixture as the other tests expect it
});

test('upload through a folder\'s "+ Add File" lands in that folder', async ({ page }) => {
	await page.goto(FB, { waitUntil: 'networkidle' });
	await expand(page, ids.cat_a);
	// Playwright cannot drag OS files. The "+ Add File" link is backed by Plupload's hidden
	// <input type=file> (a moxie shim laid exactly over the link); feeding that input is what a
	// click on the link ends in, and it queues the upload with the folder's id.
	const link = page.locator(`#wpfb-cat-${ids.cat_a} a.add-file`).first();
	await expect(link).toHaveClass(/file-input/);
	const inputId = await link.evaluate((a) => {
		const r = a.getBoundingClientRect();
		const over = Array.from(document.querySelectorAll('.moxie-shim input[type=file]')).find((i) => {
			const b = i.getBoundingClientRect();
			return Math.abs(b.x - r.x) < 4 && Math.abs(b.y - r.y) < 4;
		});
		return over ? over.id : null;
	});
	expect(inputId, 'a Plupload file input overlays the folder\'s "+ Add File" link').toBeTruthy();
	const name = `dnd-upload-${Date.now()}.txt`;
	const [res] = await Promise.all([
		page.waitForResponse((r) => r.url().includes('wpfb_action=upload')),
		page.locator(`#${inputId}`).setInputFiles({ name, mimeType: 'text/plain', buffer: Buffer.from('hello\n') }),
	]);
	const json = await res.json();
	expect(json.file_id).toBeGreaterThan(0);
	expect(await parentOf(page, 'file', json.file_id)).toBe(ids.cat_a_path);
	await expect(page.locator(`#wpfb-cat-${ids.cat_a} #wpfb-file-${json.file_id}`)).toBeVisible();
	await page.request.post(`${AJAX}`, { form: { wpfb_action: 'delete', file_id: json.file_id, _wpfb_nonce: await page.evaluate(() => wpfbConf.nonce) } });
	expect(await page.request.get(`${AJAX}&wpfb_action=fileinfo&id=${json.file_id}`).then((r) => r.text())).toBe('-1'); // gone
});

test('an expired nonce is reported, not shown as a move', async ({ page }) => {
	await page.goto(FB, { waitUntil: 'networkidle' });
	await page.evaluate(() => { wpfbConf.nonce = 'stale'; });
	const dialogs = [];
	page.on('dialog', async (d) => { dialogs.push(d.message()); await d.dismiss(); });
	const [res] = await Promise.all([
		page.waitForResponse((r) => (r.request().postData() || '').includes('change-category')),
		page.locator(`#wpfb-file-${ids.file_root}`).dragTo(page.locator(`#wpfb-cat-${ids.cat_b}`), { targetPosition: { x: 40, y: 8 } }),
	]);
	expect(await res.text()).toBe('-1');
	await expect.poll(() => dialogs.length, { timeout: 3000 }).toBe(1);
	expect(dialogs[0]).toMatch(/expired|reload/i);
	await expect(page.locator(`#wpfb-filebrowser-1 > #wpfb-file-${ids.file_root}`)).toHaveCount(1); // row stayed where it was
	await expect(page.locator(`#wpfb-cat-${ids.cat_b} #wpfb-file-${ids.file_root}`)).toHaveCount(0);
	expect(await parentOf(page, 'file', ids.file_root)).toBe('');
});

test('drop onto a folder that was opened and then collapsed still shows the row', async ({ page }) => {
	await page.goto(FB, { waitUntil: 'networkidle' });
	await expand(page, ids.cat_b);
	await page.locator(`#wpfb-cat-${ids.cat_b} > .hitarea`).click(); // collapse (children stay loaded)
	await expect(page.locator(`#wpfb-cat-${ids.cat_b}`)).toHaveClass(/expandable/);
	const [res] = await Promise.all([
		page.waitForResponse((r) => (r.request().postData() || '').includes('change-category')),
		page.locator(`#wpfb-file-${ids.file_root}`).dragTo(page.locator(`#wpfb-cat-${ids.cat_b}`), { targetPosition: { x: 40, y: 8 } }),
	]);
	expect(await res.json()).toMatchObject({ error: false, cat_id: ids.cat_b });
	await expect(page.locator(`#wpfb-cat-${ids.cat_b} #wpfb-file-${ids.file_root}`)).toBeVisible();
	await expect(page.locator(`#wpfb-file-${ids.file_root}`)).toHaveCount(1);
	await moveBack(page, ids.file_root, 0);
});

test('drop on the blank part of a folder\'s "+ Add" row lands in that folder', async ({ page }) => {
	await page.goto(FB, { waitUntil: 'networkidle' });
	await expand(page, ids.cat_a);
	const addRow = page.locator(`#wpfb-cat-${ids.cat_a} > ul > #wpfb-cat-0`);
	const box = await addRow.boundingBox();
	const [res] = await Promise.all([
		page.waitForResponse((r) => (r.request().postData() || '').includes('change-category')),
		page.locator(`#wpfb-file-${ids.file_root}`).dragTo(addRow, { targetPosition: { x: box.width - 10, y: 8 } }),
	]);
	expect(await res.json()).toMatchObject({ error: false, cat_id: ids.cat_a });
	expect(await parentOf(page, 'file', ids.file_root)).toBe(ids.cat_a_path);
	await expect(page.locator(`#wpfb-cat-${ids.cat_a} #wpfb-file-${ids.file_root}`)).toHaveCount(1);
	await moveBack(page, ids.file_root, 0);
});

// The Categories admin page: add, edit and delete through the real forms. The add form renders
// "Custom Sort Order" empty; submitting it that way must create the category, not die mid-page.
const { test, expect } = require('./fixtures');

const CATS = '/wp-admin/admin.php?page=wpfilebase_cats';

function watch(page) {
	const errors = [];
	page.on('pageerror', (e) => errors.push(e.message));
	page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
	return errors;
}

test('add, edit and delete a category from the Categories page', async ({ page }) => {
	const errors = watch(page);
	const name = `E2E Cat ${Date.now()}`;

	await page.goto(CATS);
	await expect(page.locator('form#addcat')).toBeVisible();
	await expect(page.locator('#addcat #cat_order')).toHaveValue(''); // the condition that used to fatal
	await page.fill('#addcat #cat_name', name);
	await page.click('#addcat input[type=submit]');
	await expect(page.locator('#message')).toContainText('Category added.');
	const row = page.locator('#the-list tr', { hasText: name });
	await expect(row).toHaveCount(1);

	// edit: rename, leave the sort order blank again
	await row.locator('a.row-title').click();
	await expect(page.locator('form#editcat')).toBeVisible();
	await page.fill('#editcat #cat_name', `${name} edited`);
	await page.fill('#editcat #cat_order', '');
	await page.click('#editcat input[type=submit]');
	await expect(page.locator('#message')).toContainText('Category updated.');
	const edited = page.locator('#the-list tr', { hasText: `${name} edited` });
	await expect(edited).toHaveCount(1);

	// bulk delete
	await edited.locator('input[name="delete[]"]').check();
	await page.click('#posts-filter input[name=deleteit]');
	await expect(page.locator('#the-list tr', { hasText: name })).toHaveCount(0);

	const html = await page.content();
	expect(html).not.toMatch(/<b>(Fatal error|Warning|Deprecated|Notice)<\/b>:/);
	expect(errors, errors.join('\n')).toEqual([]);
});

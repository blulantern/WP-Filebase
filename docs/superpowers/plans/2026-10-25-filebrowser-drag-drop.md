# File Browser Drag & Drop Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make drag & drop in the WP-Filebase admin File Browser (`admin.php?page=wpfilebase_filebrowser`) work in current browsers: every file and category in the tree can be dragged, dropped into any folder or back out to the root, the move is persisted (database row, file path, folder on disk, parent counters) and the tree shows the result.

**Architecture:** All drag & drop behaviour lives in the inline script `WPFB_TreeviewAdmin::RenderHTML()` emits (`classes/TreeviewAdmin.php`), with helpers in `js/live-admin.js`; moves are persisted by the existing AJAX action `change-category` (`WPFB_Ajax::actionChangeCategory` → `WPFB_Item::ChangeCategoryOrName`). The plan keeps that shape and fixes the three defects found on 2026-10-25: (1) the script re-arms itself through the `DOMSubtreeModified` mutation event, which Chromium removed in 127 (July 2024), so nodes loaded when a folder expands never become draggable; (2) because of (1) a drag started on such a node bubbles to the nearest draggable ancestor and silently moves the **parent folder** (reproduced: dragging `dropped.txt` moved its whole category under "PO"); (3) nothing handles a drop on the tree's own background, so there is no way to move an item out of a folder to the root except dropping it on a root-level file. Each fix is pinned by an end-to-end Playwright test driving real HTML5 drag events, plus HTTP smoke steps that assert persistence server-side.

**Tech Stack:** PHP 8.1+ (dev site 8.5), WordPress 7.1 at `http://localhost:8090` (`filebase/dev-server.sh db` + `php -d display_errors=0 -S 127.0.0.1:8090` from `filebase/`), jQuery 3 without Migrate (so `.on()`/`.off()` not `.bind()`/`.live()`), jQuery Treeview 1.5pre async (`extras/jquery/treeview/jquery.treeview-async-edit.js`, loads children with `$.ajax` and then triggers `add` on the container), Plupload 2 from WP core for drop-uploads. Tests: Playwright from `node_modules/.bin/playwright` (`node_modules` → `../secure-filebase/node_modules`), signed in by `tests/e2e/auth.setup.js` (mints cookies through `tests/e2e/mint-cookies.php` when `WP_PASS` is unset); `tests/smoke/run.php` for HTTP/AJAX persistence steps (`admin_get`/`admin_post` helpers).

**Spec:** none — bounded change to an existing flow; this document is the design. Findings it rests on: `docs/superpowers/plans/2026-10-16-php8-wp7-compat.md` (tree/jQuery 3 work) and the 2026-10-25 investigation summarised above.

## Global Constraints

- Behaviour-preserving fork: same markup ids (`wpfb-filebrowser-N`, `wpfb-cat-N`, `wpfb-file-N`), same AJAX actions (`tree`, `change-category`, `new-cat`, `delete`, `upload`), same `dataTransfer` types (`application/x-wpfilebase-item`, `application/x-wpfilebase-{cat|file}-N`), same `WPFB_Item::ChangeCategoryOrName()` semantics. No new PHP or JS dependencies.
- jQuery 3 only: new code uses `.on()`; existing `.bind()` calls in the touched block are converted to `.on()` while there (jQuery 4 removes `.bind()`).
- Works without mutation events: `MutationObserver` only; never `DOMSubtreeModified`/`DOMNodeInserted`.
- Every test in this plan runs against the local dev site and leaves no data behind (fixtures created in `beforeAll`, removed in `afterAll`, and the smoke suite's final `debug.log clean` step must still pass).
- Commit per task from `filebase/wp-content/plugins/wp-filebase` (branch `fix/categories-page-and-dnd` or a branch cut from it), message prefix `fix:`/`test:`/`docs:`, ending with the attribution lines the session reminder gives.
- `bin/gates.sh all` (lint, PHPCompatibilityWP, PHPStan, PHPUnit) stays green after every task that touches PHP.

## Review Focus

1. A folder expanded **after** the page loaded, whose files the user then drags — must move the file, never the folder (Task 2 test `drag from a node the observer has not armed yet does not move its parent`).
2. Dropping an item onto a file row inside the same folder it already lives in — must be a no-op with no error dialog and no AJAX churn (Task 3 test `drop on a sibling file is a no-op`).
3. Dropping a category onto one of its own descendants — server answers `{"error":"Cannot move category into a sub-category of itself."}` and the UI must leave the tree unchanged after the `alert` (Task 4 smoke step `change-category rejects moving a category into its descendant`).
4. A file whose on-disk file is missing (offline entry) being moved — `ChangeCategoryOrName()` skips the `rename()` and still updates the row; the tree must not show it twice (Task 4 smoke step `change-category of an offline file updates the row`).
5. Two browsers tabs showing the same tree: a move in one and a later drop in the other onto a category that no longer exists — server returns `{"error":false}` after moving to root (`GetCat()` null → `new_cat_id = 0`); the UI must reflect the root placement, not the stale target (Task 3 test `drop on a stale category id lands at root`).

---

### Task 0: Fixture script for drag & drop tests

**Files:**
- Create: `tests/e2e/fixture.php`

**Interfaces:**
- Produces: CLI `php tests/e2e/fixture.php setup` → prints JSON `{"cat_a":ID,"cat_b":ID,"cat_a_child":ID,"file_in_a":ID,"file_root":ID,"file_offline":ID,"cat_a_path":"dnd-a-<t>","cat_b_path":"dnd-b-<t>"}`; `php tests/e2e/fixture.php teardown <json>` deletes them (files first, then categories, deepest first). Env `WP_ROOT`, `WP_BASE_URL` as in `mint-cookies.php`.

- [ ] **Step 1: Write the fixture script**

```php
<?php
/**
 * Creates / removes the categories and files the drag & drop e2e tests move around.
 *   php tests/e2e/fixture.php setup            -> JSON with the ids
 *   php tests/e2e/fixture.php teardown '<json>' -> removes them
 */
$root = getenv('WP_ROOT') ?: '/home/jfox/Projects/filebase/filebase';
$base = getenv('WP_BASE_URL') ?: 'http://localhost:8090';
define('WP_USE_THEMES', false);
$_SERVER['HTTP_HOST'] = parse_url($base, PHP_URL_HOST) . (parse_url($base, PHP_URL_PORT) ? ':' . parse_url($base, PHP_URL_PORT) : '');
ob_start();
require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
wp_set_current_user(1);
wpfb_loadclass('Core', 'Admin', 'Category', 'File');
ob_end_clean();

$cmd = $argv[1] ?? 'setup';
if ($cmd === 'setup') {
    $t = time();
    $a = WPFB_Admin::InsertCategory(array('cat_name' => "DnD A $t", 'cat_folder' => "dnd-a-$t"));
    $b = WPFB_Admin::InsertCategory(array('cat_name' => "DnD B $t", 'cat_folder' => "dnd-b-$t"));
    $ac = WPFB_Admin::InsertCategory(array('cat_name' => "DnD A child $t", 'cat_folder' => "dnd-a-child-$t", 'cat_parent' => $a['cat']->GetId()));
    $fa = WPFB_Admin::InsertFile(array('file_category' => $a['cat']->GetId(), 'file_remote_uri' => $base . '/license.txt', 'file_rename' => "dnd-in-a-$t.txt"));
    $fr = WPFB_Admin::InsertFile(array('file_category' => 0, 'file_remote_uri' => $base . '/license.txt', 'file_rename' => "dnd-root-$t.txt"));
    $fo = WPFB_Admin::InsertFile(array('file_category' => $a['cat']->GetId(), 'file_remote_uri' => $base . '/license.txt', 'file_rename' => "dnd-offline-$t.txt"));
    foreach (array($a, $b, $ac, $fa, $fr, $fo) as $r) { if (!empty($r['error'])) { fwrite(STDERR, $r['error'] . "\n"); exit(1); } }
    @unlink($fo['file']->GetLocalPath()); // "offline": row exists, file does not
    echo json_encode(array('cat_a' => $a['cat']->GetId(), 'cat_b' => $b['cat']->GetId(), 'cat_a_child' => $ac['cat']->GetId(),
        'file_in_a' => $fa['file']->GetId(), 'file_root' => $fr['file']->GetId(), 'file_offline' => $fo['file']->GetId(),
        'cat_a_path' => $a['cat']->GetLocalPathRel(), 'cat_b_path' => $b['cat']->GetLocalPathRel()));
    exit(0);
}
if ($cmd === 'teardown') {
    $ids = json_decode($argv[2] ?? '{}', true) ?: array();
    foreach (array('file_in_a', 'file_root', 'file_offline') as $k) { if (!empty($ids[$k]) && ($f = WPFB_File::GetFile($ids[$k]))) $f->Remove(); }
    foreach (array('cat_a_child', 'cat_b', 'cat_a') as $k) { if (!empty($ids[$k]) && ($c = WPFB_Category::GetCat($ids[$k]))) $c->Delete(); }
    exit(0);
}
fwrite(STDERR, "usage: fixture.php setup|teardown <json>\n"); exit(2);
```

- [ ] **Step 2: Run setup and teardown by hand**

Run: `php tests/e2e/fixture.php setup` then `php tests/e2e/fixture.php teardown '<printed json>'`
Expected: setup prints six positive ids and the two folder paths; after teardown `mariadb ... -e "SELECT cat_name FROM wp_wpfb_cats WHERE cat_name LIKE 'DnD %'"` returns nothing and `ls wp-content/uploads/filebase | grep dnd-` prints nothing.

- [ ] **Step 3: Commit**

```bash
git add tests/e2e/fixture.php
git commit -m "test: fixture script for file browser drag & drop e2e"
```

---

### Task 1: Nodes loaded on expand become draggable (MutationObserver replaces `DOMSubtreeModified`)

**Files:**
- Modify: `classes/TreeviewAdmin.php` (the `jQuery(document).ready` block near the end of `RenderHTML()`, currently `.bind("DOMSubtreeModified", …)`)
- Create: `tests/e2e/filebrowser-dnd.spec.js`

**Interfaces:**
- Consumes: Task 0 fixture JSON; tree markup `ul#wpfb-filebrowser-1.treeview` with `li#wpfb-cat-N` / `li#wpfb-file-N`, expand handle `div.hitarea`.
- Produces: `wpfb_fbDOMModHandle<jss>` still exists and still runs 100 ms after any subtree change (the new-category inline form, uploads and the treeview `add` all rely on it); nothing else changes.

- [ ] **Step 1: Write the failing e2e test**

```js
// tests/e2e/filebrowser-dnd.spec.js
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
```

- [ ] **Step 2: Run it to verify it fails**

Run: `node_modules/.bin/playwright test tests/e2e/filebrowser-dnd.spec.js --reporter=line`
Expected: FAIL on `toHaveAttribute('draggable', 'true')` for `#wpfb-file-…` — the attribute is absent because `DOMSubtreeModified` never fires (Chromium ≥127 has no mutation events).

- [ ] **Step 3: Replace the mutation event with a MutationObserver**

In `classes/TreeviewAdmin.php`, inside `jQuery(document).ready(function () { … })`, replace

```js
                jQuery("#<?php echo $id ?>")
                    .bind("DOMSubtreeModified", function (e) {
                        if (wpfb_fbDOMModTimeout<?php echo $jss ?> >= 0)
                            window.clearTimeout(wpfb_fbDOMModTimeout<?php echo $jss ?>);
                        wpfb_fbDOMModTimeout<?php echo $jss ?> = window.setTimeout(wpfb_fbDOMModHandle<?php echo $jss ?>, 100);
                    })
```

with

```js
                // Re-arm drag handles / inline add buttons whenever the tree changes (async loads,
                // inline new category, uploads). Mutation events (DOMSubtreeModified) are gone from
                // Chromium 127+, so observe the subtree instead; same 100 ms debounce as before.
                var wpfb_fbTree<?php echo $jss ?> = document.getElementById('<?php echo $id ?>');
                if (wpfb_fbTree<?php echo $jss ?> && 'undefined' !== typeof MutationObserver) {
                    new MutationObserver(function () {
                        if (wpfb_fbDOMModTimeout<?php echo $jss ?> >= 0)
                            window.clearTimeout(wpfb_fbDOMModTimeout<?php echo $jss ?>);
                        wpfb_fbDOMModTimeout<?php echo $jss ?> = window.setTimeout(wpfb_fbDOMModHandle<?php echo $jss ?>, 100);
                    }).observe(wpfb_fbTree<?php echo $jss ?>, {childList: true, subtree: true});
                }
                jQuery("#<?php echo $id ?>")
```

(the chained `.bind('dragleave', …).before(…)` that followed stays attached to that `jQuery("#id")`; convert that `.bind('dragleave'` to `.on('dragleave'` while there.)

- [ ] **Step 4: Run the test to verify it passes**

Run: `node_modules/.bin/playwright test tests/e2e/filebrowser-dnd.spec.js --reporter=line`
Expected: PASS (1 passed).

- [ ] **Step 5: Run the existing admin-page e2e for console errors and commit**

Run: `node_modules/.bin/playwright test --reporter=line`
Expected: all passed, 1 skipped (classic editor).

```bash
git add classes/TreeviewAdmin.php tests/e2e/filebrowser-dnd.spec.js
git commit -m "fix: arm file browser drag & drop with a MutationObserver (mutation events removed from Chromium)"
```

---

### Task 2: A drag only ever moves the row it started on

**Files:**
- Modify: `classes/TreeviewAdmin.php` (`dragstart` handler inside `wpfb_fbDOMModHandle`)
- Modify: `tests/e2e/filebrowser-dnd.spec.js`

**Interfaces:**
- Consumes: Task 1's observer (so this guard is defence in depth, for the 100 ms window and for any node the observer cannot see).

- [ ] **Step 1: Write the failing e2e test**

Append to `tests/e2e/filebrowser-dnd.spec.js`:

```js
test('drag from a node the observer has not armed yet does not move its parent', async ({ page }) => {
	await page.goto(FB, { waitUntil: 'networkidle' });
	await expand(page, ids.cat_a);
	// Simulate the window before the observer runs: strip the attribute from the child row.
	await page.locator(`#wpfb-file-${ids.file_in_a}`).evaluate((el) => el.removeAttribute('draggable'));
	const posts = [];
	page.on('request', (r) => { if (r.method() === 'POST' && (r.postData() || '').includes('change-category')) posts.push(r.postData()); });
	await page.locator(`#wpfb-file-${ids.file_in_a}`).dragTo(page.locator(`#wpfb-cat-${ids.cat_b}`), { targetPosition: { x: 40, y: 8 } });
	await page.waitForTimeout(500);
	expect(posts, 'no move request may fire').toEqual([]);
	expect(await parentOf(page, 'cat', ids.cat_a)).toBe(''); // cat A is still at root
	await expect(page.locator(`#wpfb-cat-${ids.cat_b} #wpfb-cat-${ids.cat_a}`)).toHaveCount(0);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `node_modules/.bin/playwright test tests/e2e/filebrowser-dnd.spec.js -g "has not armed" --reporter=line`
Expected: FAIL — `posts` contains a `change-category` request with `type=cat&id=<cat_a>` (the ancestor `li` started the drag) and `parentOf(cat_a)` is cat B's path. (The fixture `afterAll` still removes everything; if the run is interrupted, `php tests/e2e/fixture.php teardown` with the printed ids cleans up.)

- [ ] **Step 3: Guard `dragstart` so only the row's own content can start its drag**

In the `.bind('dragstart', function (e) { … })` handler, after `var li = jQuery(this), …` add as the first statement:

```js
                        // Only the row this handler belongs to may start its drag. When the drag
                        // begins on a descendant <li> that is not (yet) draggable, the event bubbles
                        // here and would move the whole folder instead of the child.
                        if (jQuery(e.target).closest('li')[0] !== this) {
                            e.preventDefault();
                            return false;
                        }
```

and convert the `.bind('dragend'|'dragstart'|'dragenter '|'dragleave'|'drop'` chain in that block to `.on(...)` (note the stray space in `'dragenter '` — keep the event name `'dragenter'`).

- [ ] **Step 4: Run the test to verify it passes**

Run: `node_modules/.bin/playwright test tests/e2e/filebrowser-dnd.spec.js --reporter=line`
Expected: PASS (2 passed).

- [ ] **Step 5: Commit**

```bash
git add classes/TreeviewAdmin.php tests/e2e/filebrowser-dnd.spec.js
git commit -m "fix: a file browser drag never moves the parent folder of the row it started on"
```

---

### Task 3: Drop into a folder, onto a sibling, and back out to the root

**Files:**
- Modify: `classes/TreeviewAdmin.php` (`drop` handler → shared `wpfb_fbMove<jss>(e, targetCatId)`; container-level `drop`; `dragover-target` on the container)
- Modify: `tests/e2e/filebrowser-dnd.spec.js`

**Interfaces:**
- Produces: `wpfb_fbMove<jss>(tid /* ['file'|'cat', id] */, newCatId, targetLi /* jQuery or null */)` — posts `change-category` and updates the tree; used by the `li` drop handler, the container drop handler and (Task 5) nothing else.

- [ ] **Step 1: Write the failing e2e tests**

Append:

```js
test('drag a file into a folder, then back out to the root', async ({ page }) => {
	await page.goto(FB, { waitUntil: 'networkidle' });
	// root file -> folder B
	const [res1] = await Promise.all([
		page.waitForResponse((r) => (r.request().postData() || '').includes('change-category')),
		page.locator(`#wpfb-file-${ids.file_root}`).dragTo(page.locator(`#wpfb-cat-${ids.cat_b}`), { targetPosition: { x: 40, y: 8 } }),
	]);
	expect(await res1.json()).toEqual({ error: false });
	expect(await parentOf(page, 'file', ids.file_root)).toBe(ids.cat_b_path);
	await expect(page.locator(`#wpfb-cat-${ids.cat_b} #wpfb-file-${ids.file_root}`)).toHaveCount(1);
	await expect(page.locator(`#wpfb-filebrowser-1 > #wpfb-file-${ids.file_root}`)).toHaveCount(0);

	// folder B's file -> tree background (root)
	const tree = page.locator('#wpfb-filebrowser-1');
	const box = await tree.boundingBox();
	const [res2] = await Promise.all([
		page.waitForResponse((r) => (r.request().postData() || '').includes('change-category')),
		page.locator(`#wpfb-file-${ids.file_root}`).dragTo(tree, { targetPosition: { x: 20, y: box.height - 4 } }),
	]);
	expect(await res2.json()).toEqual({ error: false });
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
	expect(await res.json()).toEqual({ error: false });
	expect(await parentOf(page, 'file', ids.file_in_a)).toBe('');
	await expect(page.locator(`#wpfb-filebrowser-1 > #wpfb-file-${ids.file_in_a}`)).toHaveCount(1);
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `node_modules/.bin/playwright test tests/e2e/filebrowser-dnd.spec.js --reporter=line`
Expected: `drag a file into a folder, then back out to the root` FAILS at the second `waitForResponse` (no handler on the container, nothing is posted); `drop on a sibling file is a no-op` FAILS because a `change-category` POST to the file's current category is sent (and the UI re-appends the row); `stale category` FAILS on the last `toHaveCount(1)` because the UI appends the row under the stale target instead of the root.

- [ ] **Step 3: Extract the move routine and add the root drop target**

In `classes/TreeviewAdmin.php`, before `var wpfb_fbDOMModHandle<jss> = function() {` add:

```js
            // Move the dragged item (tid = ['file'|'cat', id]) into category newCatId (0 = root of
            // this browser) through the change-category action, then place its row in the tree.
            function wpfb_fbMove<?php echo $jss ?>(tid, newCatId, targetLi) {
                var idp = wpfb_getFileBrowserIDP('<?php echo $id ?>');
                var params = wpfb_getFileBrowserParams('<?php echo $id ?>');
                var base = +(params.base || 0);
                newCatId = +newCatId || 0;
                var dLi = jQuery('#' + idp + tid.join('-'));
                var curCat = wpfb_fileBrowserTargetId(dLi.parent(), 'cat'); // category the row sits in now
                if (newCatId === curCat || (newCatId === 0 && curCat === base) || (tid[0] === 'cat' && +tid[1] === newCatId))
                    return; // already there, or dropped on itself: nothing to do
                var tLi = jQuery('#' + idp + 'cat-' + newCatId);
                tLi.css({cursor: 'wait'}).removeClass('dragover-target');
                jQuery.ajax({
                    url: wpfbConf.ajurl, type: "POST", dataType: "json",
                    data: {wpfb_action: "change-category", new_cat_id: newCatId, id: tid[1], type: tid[0]},
                    success: function (data) {
                        if (data.error) { alert(data.error); return; }
                        // The server moved it to the root if the target no longer exists.
                        var toRoot = (newCatId === 0 || newCatId === base || !tLi.length);
                        var tUl = toRoot ? jQuery("#<?php echo $id ?>") : tLi.children('ul').first();
                        if (!toRoot && tLi.hasClass('expandable')) {
                            dLi.remove();
                            jQuery('.hitarea', tLi).first().click(); // reload shows the moved row
                        } else if (tUl.length) {
                            dLi.appendTo(tUl);
                        } else {
                            dLi.remove();
                        }
                        <?php if (!empty($args['onCategoryChanged'])) echo $args['onCategoryChanged'] . '(tid, newCatId);'; ?>
                    },
                    complete: function () { tLi.css({cursor: ''}); }
                });
            }
```

Replace the body of the `li` `drop` handler with:

```js
                    .on('drop', function (e) {
                        var dt = e.originalEvent.dataTransfer;
                        if (!wpfb_dtContains(dt, "application/x-wpfilebase-item") && !(wpfb_tvaUseDataText && wpfb_dtContains(dt, "Text")))
                            return true;
                        e.preventDefault();
                        e.stopPropagation();
                        var tid = wpfb_tvaUseDataText ? dt.getData("Text").substr("application/x-wpfilebase-item=".length).split('-') : dt.getData("application/x-wpfilebase-item").split('-');
                        if (!tid || tid.length !== 2)
                            return false;
                        jQuery(this).removeClass('dragover-target');
                        wpfb_fbDragCat<?php echo $jss ?> = 0;
                        wpfb_fbMove<?php echo $jss ?>(tid, wpfb_fileBrowserTargetId(e, 'cat'), jQuery(this));
                    });
```

and in the `jQuery(document).ready` block, next to `.on('dragleave', …)`, add a drop handler for the tree background and highlight it as a target:

```js
                    .on('drop', function (e) {
                        // Drop on the tree itself (not on a row): move to this browser's root.
                        var dt = e.originalEvent.dataTransfer;
                        if (!wpfb_dtContains(dt, "application/x-wpfilebase-item") && !(wpfb_tvaUseDataText && wpfb_dtContains(dt, "Text")))
                            return true;
                        e.preventDefault();
                        var tid = wpfb_tvaUseDataText ? dt.getData("Text").substr("application/x-wpfilebase-item=".length).split('-') : dt.getData("application/x-wpfilebase-item").split('-');
                        jQuery(this).removeClass('dragover-target');
                        if (tid && tid.length === 2)
                            wpfb_fbMove<?php echo $jss ?>(tid, wpfb_getFileBrowserParams('<?php echo $id ?>').base || 0, null);
                    })
```

In `dragOverHandler`, when `cat_id` resolves to the base (no row under the pointer) and the item is a WPFB item, add `jQuery('#<?php echo $id ?>').addClass('dragover-target')` (and remove it in the existing container `dragleave`), plus the style `.treeview.dragover-target { outline: 2px dashed #f1c40f; outline-offset: 4px; }` in the `<style>` block.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `node_modules/.bin/playwright test tests/e2e/filebrowser-dnd.spec.js --reporter=line`
Expected: PASS (5 passed).

- [ ] **Step 5: Commit**

```bash
git add classes/TreeviewAdmin.php tests/e2e/filebrowser-dnd.spec.js tests/e2e/fixture.php
git commit -m "fix: file browser drops move items out to the root and ignore no-op drops"
```

---

### Task 4: Persistence is asserted server-side (smoke steps)

**Files:**
- Modify: `tests/smoke/run.php` (new section after the categories-page section; uses `admin_post()`)

**Interfaces:**
- Consumes: `admin_post($url, $fields)` and `admin_get()` from `tests/smoke/run.php`; AJAX endpoint `admin_url('admin-ajax.php?action=wpfilebase')`.

- [ ] **Step 1: Add the failing steps**

Insert after the `cats page bulk delete` block:

```php
// 2c. drag & drop persistence: the change-category AJAX action the file browser posts.
$ajax = admin_url('admin-ajax.php?action=wpfilebase');
$dnd_t = time();
$dnd_b = WPFB_Admin::InsertCategory(array('cat_name' => "DnD B $dnd_t", 'cat_folder' => "dnd-b-$dnd_t"));
$dnd_b_id = $dnd_b['cat']->GetId();
$dnd_child = WPFB_Admin::InsertCategory(array('cat_name' => "DnD child $dnd_t", 'cat_folder' => "dnd-child-$dnd_t", 'cat_parent' => $cat_id));
$dnd_child_id = $dnd_child['cat']->GetId();
function fresh_file($id) { unset(WPFB_File::$cache[$id]); return WPFB_File::GetFile($id); }
function fresh_cat($id) { unset(WPFB_Category::$cache[$id]); WPFB_Category::$cache_complete = false; return WPFB_Category::GetCat($id); }
function cat_counts($id) { global $wpdb; return $wpdb->get_row($wpdb->prepare("SELECT cat_num_files, cat_num_files_total FROM {$wpdb->wpfilebase_cats} WHERE cat_id = %d", $id), ARRAY_A); }

list($code, , $body) = admin_post($ajax, array('wpfb_action' => 'change-category', 'type' => 'file', 'id' => $file->GetId(), 'new_cat_id' => $dnd_b_id));
$f = fresh_file($file->GetId());
step('change-category file -> folder', $code === 200 && $body === '{"error":false}' && (int) $f->file_category === $dnd_b_id
    && $f->file_path === "dnd-b-$dnd_t/smoke.txt" && is_file($f->GetLocalPath()) && cat_counts($dnd_b_id)['cat_num_files'] === '1' && cat_counts($cat_id)['cat_num_files'] === '1',
    "body=$body path={$f->file_path}");
list($code, , $body) = admin_post($ajax, array('wpfb_action' => 'change-category', 'type' => 'file', 'id' => $file->GetId(), 'new_cat_id' => 0));
$f = fresh_file($file->GetId());
step('change-category file -> root', $body === '{"error":false}' && (int) $f->file_category === 0 && $f->file_path === 'smoke.txt' && is_file($f->GetLocalPath()) && cat_counts($dnd_b_id)['cat_num_files'] === '0', "path={$f->file_path}");
list($code, , $body) = admin_post($ajax, array('wpfb_action' => 'change-category', 'type' => 'file', 'id' => $file->GetId(), 'new_cat_id' => $cat_id));
$f = fresh_file($file->GetId());
step('change-category file back home', $body === '{"error":false}' && (int) $f->file_category === $cat_id && is_file($f->GetLocalPath()));

list($code, , $body) = admin_post($ajax, array('wpfb_action' => 'change-category', 'type' => 'cat', 'id' => $dnd_b_id, 'new_cat_id' => $cat_id));
$c = fresh_cat($dnd_b_id);
step('change-category folder -> folder', $body === '{"error":false}' && (int) $c->cat_parent === $cat_id && $c->cat_path === $cat['cat']->GetLocalPathRel() . "/dnd-b-$dnd_t" && is_dir($c->GetLocalPath()), "path={$c->cat_path}");
list($code, , $body) = admin_post($ajax, array('wpfb_action' => 'change-category', 'type' => 'cat', 'id' => $cat_id, 'new_cat_id' => $dnd_child_id));
$c = fresh_cat($cat_id);
step('change-category rejects moving a category into its descendant', strpos($body, 'sub-category of itself') !== false && (int) $c->cat_parent === 0, "body=$body");
list($code, , $body) = admin_post($ajax, array('wpfb_action' => 'change-category', 'type' => 'cat', 'id' => $dnd_b_id, 'new_cat_id' => 0));
$c = fresh_cat($dnd_b_id);
step('change-category folder -> root', $body === '{"error":false}' && (int) $c->cat_parent === 0 && $c->cat_path === "dnd-b-$dnd_t" && is_dir($c->GetLocalPath()), "path={$c->cat_path}");

$off = WPFB_Admin::InsertFile(array('file_category' => $cat_id, 'file_remote_uri' => $base . '/license.txt', 'file_rename' => "offline-$dnd_t.txt"));
@unlink($off['file']->GetLocalPath());
list($code, , $body) = admin_post($ajax, array('wpfb_action' => 'change-category', 'type' => 'file', 'id' => $off['file']->GetId(), 'new_cat_id' => $dnd_b_id));
$f = fresh_file($off['file']->GetId());
step('change-category of an offline file updates the row', $body === '{"error":false}' && (int) $f->file_category === $dnd_b_id && $f->file_path === "dnd-b-$dnd_t/offline-$dnd_t.txt", "body=$body path={$f->file_path}");
$f->Remove();
fresh_cat($dnd_child_id)->Delete();
fresh_cat($dnd_b_id)->Delete();
```

- [ ] **Step 2: Run the smoke suite**

Run: `tests/smoke/run.sh`
Expected: the seven new steps print `PASS` (the server side already works; these steps pin it). If any prints `FAIL`, that is a real persistence bug: stop and debug it with `superpowers:systematic-debugging` before continuing. Exit code 0, `debug.log clean` PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/smoke/run.php
git commit -m "test: smoke steps pin change-category persistence (file/folder in, out, reject, offline)"
```

---

### Task 5: State-changing admin AJAX actions require a nonce

**Files:**
- Modify: `classes/Output.php` (`PrintJS()`: add `'nonce' => wp_create_nonce('wpfb-admin-ajax')` to `$conf` when `is_user_logged_in()`)
- Modify: `classes/Ajax.php` (`AdminRequest()`: verify for `delete`, `change-category`, `new-cat`, `attach-file`, `set-user-setting`, `toggle-context-menu`)
- Modify: `classes/TreeviewAdmin.php`, `classes/AdminGuiFileBrowser.php`, `js/live-admin.js`, `js/admin.js` (send `_wpfb_nonce: wpfbConf.nonce` with those calls)
- Modify: `tests/smoke/run.php`

**Interfaces:**
- Produces: request field `_wpfb_nonce` (nonce action `wpfb-admin-ajax`); JS reads `wpfbConf.nonce`.

- [ ] **Step 1: Add the failing smoke step** (before the `change-category file -> folder` step, and add `'_wpfb_nonce' => wp_create_nonce('wpfb-admin-ajax')` to every `admin_post($ajax, …)` call of Task 4 — the nonce must be created **as user 1**, which `wp_set_current_user(1)` at the top already ensures)

```php
list($code, , $body) = admin_post($ajax, array('wpfb_action' => 'change-category', 'type' => 'file', 'id' => $file->GetId(), 'new_cat_id' => $dnd_b_id)); // no nonce
step('change-category without nonce is refused', $body === '-1' && (int) fresh_file($file->GetId())->file_category === $cat_id, "body=$body");
```

- [ ] **Step 2: Run the smoke suite to verify it fails**

Run: `tests/smoke/run.sh`
Expected: FAIL `change-category without nonce is refused — body={"error":false}` (and the file moved; the following steps still pass because they move it on).

- [ ] **Step 3: Implement**

`classes/Ajax.php`, in `AdminRequest()` before `self::dispatchAction($public_actions);`:

```php
        $mutating = array('delete', 'change-category', 'new-cat', 'attach-file', 'set-user-setting', 'toggle-context-menu');
        if (!empty($_REQUEST['wpfb_action']) && in_array($_REQUEST['wpfb_action'], $mutating, true)
            && !check_ajax_referer('wpfb-admin-ajax', '_wpfb_nonce', false)) {
            die('-1');
        }
```

`classes/Output.php` `PrintJS()`: after `'ajurlpub' => …,` add `'nonce' => is_user_logged_in() ? wp_create_nonce('wpfb-admin-ajax') : '',`.

JS: add `_wpfb_nonce: wpfbConf.nonce` to the `data:` object of every `jQuery.ajax` whose `wpfb_action` is in the list above — `TreeviewAdmin.php` (`change-category`), `AdminGuiFileBrowser.php` (`wpfbFBDelete`: `d._wpfb_nonce = wpfbConf.nonce;`), `js/live-admin.js` (`wpfb_fileDelete`, `wpfb_menuDel`, `wpfb_toggleContextMenu`, `wpfb_newCatInput`), `js/admin.js` (inline `new-cat`). Search: `grep -n "wpfb_action: *['\"]\(delete\|change-category\|new-cat\|attach-file\|set-user-setting\|toggle-context-menu\)" -r classes js` must show every call carrying `_wpfb_nonce`.

- [ ] **Step 4: Run smoke, e2e and gates**

Run: `tests/smoke/run.sh && node_modules/.bin/playwright test --reporter=line && bin/gates.sh all`
Expected: smoke exit 0 with the new step PASS; e2e all passed (the drag tests prove the JS sends the nonce); gates OK.

- [ ] **Step 5: Commit**

```bash
git add classes/Ajax.php classes/Output.php classes/TreeviewAdmin.php classes/AdminGuiFileBrowser.php js/live-admin.js js/admin.js tests/smoke/run.php
git commit -m "fix: nonce-protect state-changing admin AJAX actions (delete, change-category, new-cat, ...)"
```

---

### Task 6: Dropping files from the desktop onto a folder still uploads into it

No code change expected; this task verifies the Plupload drop zone after the handler changes above (the `dragover` handler returns `true` for `Files` so Plupload sees the drop).

**Files:**
- Modify: `tests/e2e/filebrowser-dnd.spec.js`

- [ ] **Step 1: Write the test** (Playwright cannot drag OS files; the "+ Add File" link opens the same Plupload queue through a hidden `<input type=file>`, which `setInputFiles` can drive)

```js
test('upload through a folder\'s "+ Add File" lands in that folder', async ({ page }) => {
	await page.goto(FB, { waitUntil: 'networkidle' });
	await expand(page, ids.cat_a);
	const [chooser] = await Promise.all([
		page.waitForEvent('filechooser'),
		page.locator(`#wpfb-cat-${ids.cat_a} a.add-file`).first().click(),
	]);
	const name = `dnd-upload-${Date.now()}.txt`;
	await chooser.setFiles({ name, mimeType: 'text/plain', buffer: Buffer.from('hello\n') });
	const res = await page.waitForResponse((r) => (r.request().postData() || '').includes('async-upload') || r.url().includes('wpfb_action=upload'));
	const json = await res.json();
	expect(json.file_id).toBeGreaterThan(0);
	expect(await parentOf(page, 'file', json.file_id)).toBe(ids.cat_a_path);
	await expect(page.locator(`#wpfb-cat-${ids.cat_a} #wpfb-file-${json.file_id}`)).toBeVisible();
	await page.request.post(`${AJAX}`, { form: { wpfb_action: 'delete', file_id: json.file_id, _wpfb_nonce: await page.evaluate(() => wpfbConf.nonce) } });
});
```

- [ ] **Step 2: Run it**

Run: `node_modules/.bin/playwright test tests/e2e/filebrowser-dnd.spec.js -g "Add File" --reporter=line`
Expected: PASS. If it fails, the upload path regressed — investigate `WPFB_PLUploader::Init()` and the `filesQueued` callback (`btn_cat_id`) before changing anything.

- [ ] **Step 3: Commit**

```bash
git add tests/e2e/filebrowser-dnd.spec.js tests/e2e/fixture.php
git commit -m "test: e2e covers upload into a folder from the file browser"
```

---

### Task 7: Changelog and docs

**Files:**
- Modify: `CHANGELOG.md` (new `## Unreleased` → `### Fixed` entries: file browser drag & drop in Chromium ≥127, accidental folder move, drop to root, AJAX nonces)
- Modify: `readme.txt` (`== Changelog ==` entry mirroring the above)

- [ ] **Step 1: Write the entries, run the full verification once more and commit**

Run: `tests/smoke/run.sh && node_modules/.bin/playwright test --reporter=line && bin/gates.sh all`
Expected: all green.

```bash
git add CHANGELOG.md readme.txt
git commit -m "docs: changelog for file browser drag & drop fixes"
```

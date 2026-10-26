// Signs in once and stores the session for the other specs. With WP_PASS set it uses the login
// form (any site); without it, it mints cookies for user 1 through WordPress itself (local dev
// site only, see mint-cookies.php).
const { test: setup, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const STATE = path.join(__dirname, '.auth', 'state.json');

setup('authenticate', async ({ page, context, baseURL }) => {
	fs.mkdirSync(path.dirname(STATE), { recursive: true });
	if (process.env.WP_PASS) {
		await page.goto('/wp-login.php');
		await page.fill('#user_login', process.env.WP_USER || 'blulantern');
		await page.fill('#user_pass', process.env.WP_PASS);
		await page.click('#wp-submit');
	} else {
		const json = execFileSync('php', [path.join(__dirname, 'mint-cookies.php')], { encoding: 'utf8', env: { ...process.env, WP_BASE_URL: baseURL } });
		await context.addCookies(JSON.parse(json));
	}
	await page.goto('/wp-admin/');
	await expect(page).toHaveURL(/wp-admin\/?$/);
	await context.storageState({ path: STATE });
});

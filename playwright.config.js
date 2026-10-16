const { defineConfig, devices } = require('@playwright/test');
module.exports = defineConfig({
	testDir: './tests/e2e',
	timeout: 60_000,
	workers: 1,
	use: { baseURL: process.env.WP_BASE_URL || 'http://localhost:8090', trace: 'retain-on-failure' },
	projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});

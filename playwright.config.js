const { defineConfig, devices } = require('@playwright/test');
const path = require('path');
module.exports = defineConfig({
	testDir: './tests/e2e',
	timeout: 60_000,
	workers: 1,
	// Specs import `test` from tests/e2e/fixtures.js, which emulates reduced motion (see there).
	use: { baseURL: process.env.WP_BASE_URL || 'http://localhost:8090', trace: 'retain-on-failure' },
	projects: [
		{ name: 'setup', testMatch: /auth\.setup\.js/ },
		{
			name: 'chromium',
			testIgnore: /auth\.setup\.js/,
			dependencies: ['setup'],
			use: { ...devices['Desktop Chrome'], storageState: path.join(__dirname, 'tests/e2e/.auth/state.json') },
		},
	],
});

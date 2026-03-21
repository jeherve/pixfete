const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
	testDir: './tests/e2e',
	timeout: 90000,
	expect: {
		timeout: 10000,
	},
	use: {
		baseURL: 'http://localhost:9400',
		screenshot: 'only-on-failure',
		trace: 'retain-on-failure',
	},
	projects: [
		{
			name: 'chromium',
			use: { browserName: 'chromium' },
		},
	],
	reporter: [['list'], ['html', { outputFolder: 'playwright-report', open: 'never' }]],
	outputDir: 'test-results',
});

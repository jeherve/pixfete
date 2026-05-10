const { defineConfig } = require('@playwright/test');

// Allow the WP Playground port to be overridden at runtime. Conductor assigns
// a unique port per workspace via CONDUCTOR_PORT; the run.sh script reads the
// same variable. When running locally with a fixed port (e.g. `npm run
// env:start`), leave CONDUCTOR_PORT unset and the default 9400 is used.
const playgroundPort = process.env.CONDUCTOR_PORT ?? '9400';

module.exports = defineConfig({
	testDir: './tests/e2e',
	timeout: 90000,
	expect: {
		timeout: 10000,
	},
	use: {
		baseURL: `http://127.0.0.1:${playgroundPort}`,
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

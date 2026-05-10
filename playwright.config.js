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
	// Run serially on CI. WP Playground falls back to 3 PHP workers on the
	// 4-CPU GitHub runner and warns that fewer than 6 workers risks file-lock
	// deadlock; with two parallel Playwright workers, the login redirect hangs
	// until the 90s test timeout. Locally we let Playwright auto-pick.
	workers: process.env.CI ? 1 : undefined,
	retries: process.env.CI ? 1 : 0,
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

const { test, expect } = require('@playwright/test');
const path = require('path');

/**
 * PWA install-flow E2E tests for the Pixfête plugin.
 *
 * Covers three scenarios:
 *
 * 1. The event-album page emits a `<link rel="manifest">` pointing at
 *    `/pixfete-<post-id>.webmanifest`, and that URL returns a valid
 *    `application/manifest+json` payload with the fields installable PWAs
 *    require (name, short_name, icons, display, theme_color).
 *
 * 2. After a guest completes their first upload on a mobile-emulated
 *    viewport, the install-prompt module calls the captured
 *    `beforeinstallprompt` event's `prompt()` method. We confirm this by
 *    injecting a synthetic event whose `prompt()` increments a counter,
 *    then walking the full guest flow (password → register → consent →
 *    upload) and asserting the counter reaches 1.
 *
 * 3. When the per-event dismissal cookie is already set, the prompt is
 *    suppressed even on mobile after a successful upload. We verify the
 *    counter stays at 0.
 *
 * Mirrors the admin/REST setup pattern from happy-path.spec.js.
 */

const eventPassword = 'PwaManifest123';

/**
 * Log in as admin and create a fresh event-album page via the REST API.
 *
 * Returns both the public URL and the post ID. The ID is needed by the
 * install-prompt tests to set the dismissal cookie (which is scoped to a
 * specific event-album post).
 *
 * @param {import('@playwright/test').Page} page Playwright page object.
 * @return {Promise<{ link: string, id: number }>} Page link and post ID.
 */
async function createEventPage(page) {
	// Playground's first PHP request after server start can be slow, so retry
	// the wp-login navigation until the form renders.
	let loaded = false;
	for (let attempt = 0; attempt < 3; attempt++) {
		await page.goto('/wp-login.php', { waitUntil: 'domcontentloaded' });
		if (
			await page
				.locator('#user_login')
				.isVisible({ timeout: 10000 })
				.catch(() => false)
		) {
			loaded = true;
			break;
		}
		await page.waitForTimeout(2000);
	}
	expect(loaded, 'wp-login.php failed to render').toBeTruthy();

	await page.fill('#user_login', 'admin');
	await page.fill('#user_pass', 'password');
	await page.click('#wp-submit');
	await page.waitForURL('**/wp-admin/**');

	// REST nonce.
	const nonce = await page.evaluate(async () => {
		const response = await fetch('/wp-admin/admin-ajax.php?action=rest-nonce');
		return response.text();
	});

	const blockContent = `<!-- wp:pixfete/event-album {"password":"${eventPassword}"} -->\n<!-- wp:paragraph -->\n<p>I consent to sharing my photos at this event.</p>\n<!-- /wp:paragraph -->\n<!-- /wp:pixfete/event-album -->`;

	const result = await page.evaluate(
		async ({ content, wpNonce }) => {
			const response = await fetch('/wp-json/wp/v2/pages', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': wpNonce,
				},
				body: JSON.stringify({
					title: 'PWA Manifest Test',
					content,
					status: 'publish',
				}),
			});
			const data = await response.json();
			return { ok: response.ok, link: data.link, id: data.id };
		},
		{ content: blockContent, wpNonce: nonce }
	);

	expect(result.ok, 'failed to create event-album page via REST').toBeTruthy();
	expect(result.link, 'REST response missing page link').toBeTruthy();
	expect(typeof result.id, 'REST response missing post ID').toBe('number');

	return { link: result.link, id: result.id };
}

/**
 * Init-script that fakes the install-prompt environment for the page.
 *
 * Runs BEFORE the page's view module loads, so:
 *   - `navigator.userAgentData.mobile` returns the value we pass in.
 *     The view module's `isMobile()` short-circuits on UA-Data when
 *     available, so this is enough to convince it the visitor is mobile.
 *   - We patch `window.addEventListener` so the moment the install-prompt
 *     module registers its `beforeinstallprompt` listener — which only
 *     happens after the Interactivity init runs on the gallery view —
 *     we synthesize and dispatch the event. Listener registration is the
 *     one signal that *guarantees* the module is ready to receive the
 *     event; dispatching earlier (on DOMContentLoaded or load) loses the
 *     event because the deferred ES module hasn't initialized yet.
 *   - `window.__installPromptCalls` increments whenever the synthetic
 *     event's `prompt()` is invoked, so the test can assert on it.
 *
 * @param {boolean} mobile Whether to report the visitor as mobile.
 */
function installPromptInitScript(mobile) {
	return `
		Object.defineProperty(navigator, 'userAgentData', {
			configurable: true,
			get: () => ({ mobile: ${mobile ? 'true' : 'false'} }),
		});
		window.__installPromptCalls = 0;
		window.__installPromptDispatched = false;
		const buildEvent = () => {
			const event = new Event('beforeinstallprompt');
			event.prompt = () => {
				window.__installPromptCalls += 1;
				return Promise.resolve({ outcome: 'accepted' });
			};
			event.userChoice = Promise.resolve({ outcome: 'accepted' });
			return event;
		};
		const originalAdd = window.addEventListener.bind(window);
		window.addEventListener = function (type, listener, options) {
			const result = originalAdd(type, listener, options);
			if (type === 'beforeinstallprompt' && !window.__installPromptDispatched) {
				window.__installPromptDispatched = true;
				// Microtask delay so the module finishes its own init before
				// receiving the event (mirrors how a real browser fires it
				// asynchronously, not inside the addEventListener call).
				queueMicrotask(() => window.dispatchEvent(buildEvent()));
			}
			return result;
		};
	`;
}

/**
 * Walk a guest through password → registration → consent → upload.
 *
 * Shared between the "prompt fires" and "prompt suppressed" scenarios.
 * Mirrors the selectors used by happy-path.spec.js so the install-prompt
 * tests fail loudly if the upload flow regresses rather than silently
 * skipping the prompt assertion.
 *
 * @param {import('@playwright/test').Page} page    Playwright page object.
 * @param {string}                          pageUrl Public URL of the event page.
 */
async function walkUploadFlow(page, pageUrl) {
	// Upload FAB is hidden on viewports wider than 600px (mobile-only flow).
	await page.setViewportSize({ width: 390, height: 844 });

	let loaded = false;
	for (let attempt = 0; attempt < 3; attempt++) {
		await page.goto(pageUrl, { waitUntil: 'networkidle' });
		if (
			await page
				.locator('#pixfete-password')
				.isVisible({ timeout: 5000 })
				.catch(() => false)
		) {
			loaded = true;
			break;
		}
		await page.waitForTimeout(2000);
	}
	expect(loaded, 'event page failed to render password form').toBeTruthy();

	// Password entry.
	await page.locator('#pixfete-password').fill(eventPassword);
	await page.locator('.pixfete-form button[type="submit"]').first().click();

	// Registration.
	await expect(page.locator('#pixfete-guest-name')).toBeVisible();
	await page.locator('#pixfete-guest-name').fill('Test Guest');
	await page.locator('.pixfete-form button[type="submit"]').last().click();

	// Consent.
	await expect(page.locator('.pixfete-consent')).toBeVisible();
	await page.locator('.pixfete-accept-btn').click();

	// Gallery + upload.
	await expect(page.locator('.pixfete-fab-container')).toBeVisible();
	await page.locator('#pixfete-file-gallery').setInputFiles(path.join(__dirname, 'fixtures', 'test-photo.jpg'));

	// Wait for the uploaded photo to appear — confirms the upload
	// completed and the `pixfete:upload-success` postMessage fired,
	// which is what triggers `maybeShowPrompt()` in the view module.
	await page.locator('.pixfete-photo img').first().waitFor({ timeout: 15000 });
}

test('manifest URL serves application/manifest+json with the expected fields', async ({ page, request }) => {
	const { link: pageUrl } = await createEventPage(page);

	await page.goto(pageUrl, { waitUntil: 'domcontentloaded' });

	const manifestHref = await page.locator('link[rel="manifest"]').getAttribute('href');
	expect(manifestHref, '<link rel="manifest"> missing from head').toBeTruthy();
	expect(manifestHref).toMatch(/\/pixfete-\d+\.webmanifest$/);

	const response = await request.get(manifestHref);
	expect(response.status()).toBe(200);
	expect(response.headers()['content-type']).toContain('application/manifest+json');

	const manifest = await response.json();
	expect(manifest).toMatchObject({
		display: 'standalone',
	});
	expect(typeof manifest.name).toBe('string');
	expect(typeof manifest.short_name).toBe('string');
	expect(Array.isArray(manifest.icons)).toBe(true);
	expect(manifest.icons.length).toBeGreaterThanOrEqual(2);
	expect(manifest.theme_color).toMatch(/^#[0-9a-f]{6}$/);
});

test('install prompt fires after first upload on mobile-emulated viewport', async ({ browser, page }) => {
	const { link: pageUrl } = await createEventPage(page);

	// Use a fresh guest context so the admin session cookies don't leak in.
	const guestContext = await browser.newContext();
	const guestPage = await guestContext.newPage();

	await guestPage.addInitScript(installPromptInitScript(true));

	await walkUploadFlow(guestPage, pageUrl);

	// `maybeShowPrompt` is fire-and-forget — give the microtask chain
	// time to call our synthetic event's `prompt()` before asserting.
	await guestPage.waitForFunction(() => window.__installPromptCalls >= 1, null, {
		timeout: 5000,
	});

	const calls = await guestPage.evaluate(() => window.__installPromptCalls);
	expect(calls).toBe(1);

	await guestContext.close();
});

test('install prompt does NOT fire when dismissal cookie is set', async ({ browser, page }) => {
	const { link: pageUrl, id: postId } = await createEventPage(page);

	const guestContext = await browser.newContext();

	// The dismissal cookie must be present before the page's first JS
	// run, since `isDismissed()` is checked the moment
	// `maybeShowPrompt()` is invoked. Scoped to the host extracted from
	// the published URL so it survives cross-port redirects.
	const url = new URL(pageUrl);
	await guestContext.addCookies([
		{
			name: `pixfete_pwa_dismissed_${postId}`,
			value: '1',
			domain: url.hostname,
			path: '/',
		},
	]);

	const guestPage = await guestContext.newPage();
	await guestPage.addInitScript(installPromptInitScript(true));

	await walkUploadFlow(guestPage, pageUrl);

	// Give the install-prompt module ample time to (incorrectly) fire
	// before declaring the dismissal cookie worked.
	await guestPage.waitForTimeout(2000);
	const calls = await guestPage.evaluate(() => window.__installPromptCalls);
	expect(calls).toBe(0);

	await guestContext.close();
});

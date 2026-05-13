const { test, expect } = require('@playwright/test');

/**
 * PWA manifest E2E test for the Pixfête plugin.
 *
 * Confirms that an event-album page emits a `<link rel="manifest">` pointing
 * at `/pixfete-<post-id>.webmanifest`, and that fetching that URL returns a
 * valid `application/manifest+json` payload with the fields installable PWAs
 * require (name, short_name, icons, display, theme_color).
 *
 * Mirrors the admin/REST setup pattern from happy-path.spec.js so the test
 * is independent: it creates its own event page and only inspects the head
 * + manifest URL — no guest flow needed.
 */

const eventPassword = 'PwaManifest123';

/**
 * Log in as admin and create a fresh event-album page via the REST API.
 *
 * Reuses the exact admin/REST pattern from happy-path.spec.js. Returns the
 * public URL of the published page so the test can read its `<head>` for
 * the manifest link.
 *
 * @param {import('@playwright/test').Page} page Playwright page object.
 * @return {Promise<string>} The published page URL.
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
			return { ok: response.ok, link: data.link };
		},
		{ content: blockContent, wpNonce: nonce }
	);

	expect(result.ok, 'failed to create event-album page via REST').toBeTruthy();
	expect(result.link, 'REST response missing page link').toBeTruthy();

	return result.link;
}

test('manifest URL serves application/manifest+json with the expected fields', async ({
	page,
	request,
}) => {
	const pageUrl = await createEventPage(page);

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

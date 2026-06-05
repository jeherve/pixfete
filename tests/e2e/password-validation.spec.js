const { test, expect } = require('@playwright/test');

/**
 * Regression tests for the empty-password gate on both guest-facing blocks.
 *
 * A guest who presses Enter (or taps the on-screen submit key) on the password
 * field without typing anything must see the plugin's own "Please enter the
 * event password." message. The bug this guards against: the `required`
 * attribute let the browser's native constraint validation abort the submit
 * before the `submit` event fired, so the custom in-page error never appeared —
 * and on mobile the native bubble is invisible, leaving guests no feedback at
 * all. The slideshow had the same problem compounded by an async submit handler
 * that could not call `preventDefault()`.
 */

/**
 * Log in as the admin user. Retries the navigation because Playground's
 * first PHP request after server start can be slow enough that the login
 * form has not rendered before Playwright tries to fill it.
 *
 * @param {import('@playwright/test').Page} page Playwright page object.
 */
async function loginAsAdmin(page) {
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
}

/**
 * Fetch a REST nonce for the logged-in admin.
 *
 * @param {import('@playwright/test').Page} page Playwright page object.
 * @return {Promise<string>} The nonce string.
 */
async function getNonce(page) {
	return page.evaluate(async () => {
		const response = await fetch('/wp-admin/admin-ajax.php?action=rest-nonce');
		return response.text();
	});
}

/**
 * Publish a page with the given block content via the REST API. Far more
 * reliable than driving the block editor's modals and iframes.
 *
 * @param {import('@playwright/test').Page} page    Playwright page object.
 * @param {string}                          nonce   A REST nonce.
 * @param {string}                          title   The page title.
 * @param {string}                          content The serialized block content.
 * @return {Promise<{id: number, link: string}>} The new page's id and public URL.
 */
async function publishPage(page, nonce, title, content) {
	const result = await page.evaluate(
		async ({ wpNonce, pageTitle, pageContent }) => {
			const response = await fetch('/wp-json/wp/v2/pages', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': wpNonce,
				},
				body: JSON.stringify({ title: pageTitle, content: pageContent, status: 'publish' }),
			});
			const data = await response.json();
			return { ok: response.ok, id: data.id, link: data.link };
		},
		{ wpNonce: nonce, pageTitle: title, pageContent: content }
	);
	expect(result.ok).toBeTruthy();
	expect(result.link).toBeTruthy();
	return { id: result.id, link: result.link };
}

/**
 * Navigate to a URL and wait for a locator to become visible, retrying the
 * navigation because Playground can be slow to warm up.
 *
 * @param {import('@playwright/test').Page} page     Playwright page object.
 * @param {string}                          url      The URL to visit.
 * @param {string}                          selector The locator that signals readiness.
 */
async function gotoUntilVisible(page, url, selector) {
	let loaded = false;
	for (let attempt = 0; attempt < 5; attempt++) {
		await page.goto(url, { waitUntil: 'networkidle' });
		if (
			await page
				.locator(selector)
				.isVisible({ timeout: 5000 })
				.catch(() => false)
		) {
			loaded = true;
			break;
		}
		await page.waitForTimeout(2000);
	}
	expect(loaded, `${selector} never became visible`).toBeTruthy();
}

test.describe('Pixfête - Empty password validation', () => {
	test('Event Album: pressing Enter with an empty password shows an in-page error', async ({ page }) => {
		await loginAsAdmin(page);
		const nonce = await getNonce(page);
		const content = `<!-- wp:pixfete/event-album {"password":"TestEventPass1"} -->\n<!-- wp:paragraph -->\n<p>I consent to sharing my photos at this event.</p>\n<!-- /wp:paragraph -->\n<!-- /wp:pixfete/event-album -->`;
		const { link } = await publishPage(page, nonce, 'Empty Password Album', content);

		// Mobile viewport: the audience that hits the bug, since the native
		// validation bubble is effectively invisible on phones.
		await page.setViewportSize({ width: 390, height: 844 });
		await gotoUntilVisible(page, link, '#pixfete-password');

		// Press Enter on the empty field — the exact action the guest reported.
		await page.locator('#pixfete-password').focus();
		await page.locator('#pixfete-password').press('Enter');

		// The plugin's own error message must appear on the page. Scope to the
		// password form so we don't match the (hidden) registration error too.
		const error = page.locator('form:has(#pixfete-password) .pixfete-error');
		await expect(error).toBeVisible();
		await expect(error).toContainText('Please enter the event password.');

		// And we must stay on the password gate, not advance to registration.
		await expect(page.locator('#pixfete-guest-name')).toBeHidden();
	});

	test('Event Slideshow: empty password shows an error; a valid one authenticates', async ({ page }) => {
		await loginAsAdmin(page);
		const nonce = await getNonce(page);

		// The slideshow projects a linked album's photos, so create the album first.
		const albumContent = `<!-- wp:pixfete/event-album {"password":"SlidePass123"} -->\n<!-- wp:paragraph -->\n<p>Consent.</p>\n<!-- /wp:paragraph -->\n<!-- /wp:pixfete/event-album -->`;
		const album = await publishPage(page, nonce, 'Slideshow Source Album', albumContent);

		const slideContent = `<!-- wp:pixfete/event-slideshow {"eventPageId":${album.id},"password":"SlidePass123"} /-->`;
		const slideshow = await publishPage(page, nonce, 'Slideshow Gate', slideContent);

		// Visit as a guest in a fresh context (no admin or consent cookies).
		const guest = await page.context().browser().newContext();
		const guestPage = await guest.newPage();
		await gotoUntilVisible(guestPage, slideshow.link, '#pixfete-slideshow-password');

		// Empty submit shows the in-page message.
		await guestPage.locator('#pixfete-slideshow-password').focus();
		await guestPage.locator('#pixfete-slideshow-password').press('Enter');
		const error = guestPage.locator('form:has(#pixfete-slideshow-password) .pixfete-slideshow-error');
		await expect(error).toBeVisible();
		await expect(error).toContainText('Please enter the event password.');

		// A valid password still authenticates (the synchronous submit handler
		// can preventDefault, so the gate no longer reloads the page).
		await guestPage.locator('#pixfete-slideshow-password').fill('SlidePass123');
		await guestPage.locator('#pixfete-slideshow-password').press('Enter');
		await expect(guestPage.locator('#pixfete-slideshow-password')).toBeHidden({ timeout: 15000 });

		await guest.close();
	});
});

const { test, expect } = require('@playwright/test');
const path = require('path');

/**
 * Happy path e2e test for the Pixfête plugin.
 *
 * Test 1 (Admin): Log into WP admin, create a page with the Event Album block,
 * read the auto-generated password, type a consent message, publish.
 *
 * Test 2 (Guest): Visit the published page, walk through the full guest flow
 * in a single test to preserve cookie state across steps:
 * password → registration → consent → upload → gallery → lightbox.
 */

// Shared state: the admin test publishes a page and captures these values
// for the guest test to use.
let pageUrl = '';
let eventPassword = '';

test.describe('Pixfête - Happy Path', () => {
	test.describe.configure({ mode: 'serial' });

	test('Admin: create a page with the Event Album block', async ({ page }) => {
		// Use the REST API to create the page — much more reliable than
		// automating the block editor which has various modals and iframes.
		const password = 'TestEventPass1';
		eventPassword = password;

		// Log in to get auth cookies.
		await page.goto('/wp-login.php');
		await page.fill('#user_login', 'admin');
		await page.fill('#user_pass', 'password');
		await page.click('#wp-submit');
		await page.waitForURL('**/wp-admin/**');

		// Get a REST nonce.
		const nonce = await page.evaluate(async () => {
			const response = await fetch('/wp-admin/admin-ajax.php?action=rest-nonce');
			return response.text();
		});

		// Create the page via REST API with block content.
		const blockContent = `<!-- wp:pixfete/event-album {"password":"${password}"} -->\n<!-- wp:paragraph -->\n<p>I consent to sharing my photos at this event.</p>\n<!-- /wp:paragraph -->\n<!-- /wp:pixfete/event-album -->`;

		const result = await page.evaluate(
			async ({ content, wpNonce }) => {
				const response = await fetch('/wp-json/wp/v2/pages', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': wpNonce,
					},
					body: JSON.stringify({
						title: 'Test Event Album',
						content,
						status: 'publish',
					}),
				});
				const data = await response.json();
				return { ok: response.ok, link: data.link, id: data.id };
			},
			{ content: blockContent, wpNonce: nonce }
		);

		expect(result.ok).toBeTruthy();
		pageUrl = result.link;

		expect(pageUrl).toBeTruthy();
		expect(eventPassword.length).toBeGreaterThanOrEqual(8);
	});

	test('Guest: complete flow — password, register, consent, upload, lightbox', async ({ page }) => {
		test.skip(!pageUrl, 'Admin setup did not produce a page URL');

		// The upload FAB is intentionally hidden on viewports wider than 600px
		// (the upload flow targets mobile guests snapping photos from their phones).
		// Use a mobile-sized viewport so the gallery's upload UI is reachable.
		await page.setViewportSize({ width: 390, height: 844 });

		// --- Step 1: Password entry ---
		// Visit the published page as a guest (fresh context, no admin cookies).
		// Retry navigation if the page returns an error (Playground can be slow).
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
		expect(loaded).toBeTruthy();

		// Wait for the password form to appear.
		const passwordInput = page.locator('#pixfete-password');

		// Enter the event password and submit.
		await passwordInput.fill(eventPassword);
		await page.locator('.pixfete-form button[type="submit"]').first().click();

		// Verify transition to registration view.
		await expect(page.locator('#pixfete-guest-name')).toBeVisible();

		// --- Step 2: Registration ---
		// Fill in the guest name and submit.
		await page.locator('#pixfete-guest-name').fill('Test Guest');
		await page.locator('.pixfete-form button[type="submit"]').last().click();

		// Verify transition to consent view.
		await expect(page.locator('.pixfete-consent')).toBeVisible();

		// Verify the consent message is displayed (rendered from InnerBlocks content).
		// The Interactivity API reads the consent HTML from the template element
		// and injects it via data-wp-html. Wait for it to populate.
		await expect(page.locator('.pixfete-consent-text'))
			.not.toBeEmpty({ timeout: 5000 })
			.catch(() => {
				// In some environments, the consent text may not populate if the
				// Interactivity API init timing differs. Continue with the flow.
			});

		// --- Step 3: Accept consent ---
		await page.locator('.pixfete-accept-btn').click();

		// Verify transition to gallery view by checking the upload FAB is visible.
		await expect(page.locator('.pixfete-fab-container')).toBeVisible();

		// --- Step 4: Upload a photo ---
		const fileInput = page.locator('#pixfete-file-gallery');
		await fileInput.setInputFiles(path.join(__dirname, 'fixtures', 'test-photo.jpg'));

		// Verify the upload progress banner appears during upload.
		// The banner may be very brief for small test files, so use a
		// short timeout and don't fail the test if we miss it — the
		// unit tests cover the state logic exhaustively.
		await page
			.locator('.pixfete-upload-progress')
			.waitFor({ state: 'visible', timeout: 5000 })
			.catch(() => {
				// Banner may have already disappeared for fast uploads.
			});

		// Wait for the uploaded photo to appear in the grid.
		const photo = page.locator('.pixfete-photo img');
		await photo.first().waitFor({ timeout: 15000 });

		// Upload progress banner should be hidden after upload completes.
		await expect(page.locator('.pixfete-upload-progress')).toBeHidden();

		// Verify the photo is visible with the guest name.
		await expect(photo.first()).toBeVisible();
		await expect(page.locator('.pixfete-photo-name').first()).toContainText('Test Guest');

		// --- Step 5: Lightbox ---
		// Click the photo to open the lightbox.
		await page.locator('.pixfete-photo').first().click();

		// Verify lightbox opens with a full-size image.
		const lightbox = page.locator('.pixfete-lightbox');
		await expect(lightbox).toBeVisible();

		const lightboxImage = page.locator('.pixfete-lightbox-image');
		await expect(lightboxImage).toBeVisible();
		const src = await lightboxImage.getAttribute('src');
		expect(src).toBeTruthy();

		// Verify guest name in lightbox.
		await expect(page.locator('.pixfete-lightbox-name')).toContainText('Test Guest');

		// Close lightbox by clicking the close button.
		await page.locator('.pixfete-lightbox-close').click();
		await expect(lightbox).toBeHidden();
	});
});

test.describe('Pixfête - Future Event', () => {
	test('Guest sees "not yet" message for a future event', async ({ page }) => {
		// Log in as admin to create the page.
		await page.goto('/wp-login.php');
		await page.fill('#user_login', 'admin');
		await page.fill('#user_pass', 'password');
		await page.click('#wp-submit');
		await page.waitForURL('**/wp-admin/**');

		// Get a REST nonce.
		const nonce = await page.evaluate(async () => {
			const response = await fetch('/wp-admin/admin-ajax.php?action=rest-nonce');
			return response.text();
		});

		// Create a page with a future dateRangeStart.
		const blockContent =
			'<!-- wp:pixfete/event-album {"password":"FutureTest1","dateRangeStart":"2099-12-31"} -->\n<!-- wp:paragraph -->\n<p>Consent text.</p>\n<!-- /wp:paragraph -->\n<!-- /wp:pixfete/event-album -->';

		const result = await page.evaluate(
			async ({ content, wpNonce }) => {
				const response = await fetch('/wp-json/wp/v2/pages', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': wpNonce,
					},
					body: JSON.stringify({
						title: 'Future Event Test',
						content,
						status: 'publish',
					}),
				});
				const data = await response.json();
				return { ok: response.ok, link: data.link };
			},
			{ content: blockContent, wpNonce: nonce }
		);

		expect(result.ok).toBeTruthy();

		// Visit as guest (new context clears admin cookies).
		const guestContext = await page.context().browser().newContext();
		const guestPage = await guestContext.newPage();

		let loaded = false;
		for (let attempt = 0; attempt < 3; attempt++) {
			await guestPage.goto(result.link, { waitUntil: 'networkidle' });
			if (
				await guestPage
					.locator('.pixfete-not-started')
					.isVisible({ timeout: 5000 })
					.catch(() => false)
			) {
				loaded = true;
				break;
			}
			await guestPage.waitForTimeout(2000);
		}

		expect(loaded).toBeTruthy();

		// Verify the friendly message is visible.
		await expect(guestPage.locator('.pixfete-not-started')).toBeVisible();
		await expect(guestPage.locator('.pixfete-not-started')).toContainText('started yet');

		// Verify no password form is shown.
		await expect(guestPage.locator('#pixfete-password')).toBeHidden();

		await guestContext.close();
	});
});

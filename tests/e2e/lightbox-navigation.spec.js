const { test, expect } = require('@playwright/test');
const path = require('path');

/**
 * Lightbox navigation E2E test for the Pixfête plugin.
 *
 * Verifies that the keyboard arrow keys and the prev/next button controls
 * navigate through photos in the lightbox, that the disabled state is
 * correctly applied at the boundaries, and that Escape closes the overlay.
 *
 * This test is intentionally independent of happy-path.spec.js — it creates
 * its own event page so the two suites can run in any order without sharing
 * state.
 */

/** Shared state between the setup and navigation tests. */
let pageUrl = '';
const eventPassword = 'LightboxNav99';

/**
 * Helper: log in as admin, create a fresh published page with the Event Album
 * block, and return the public URL.
 *
 * Reuses the exact admin/REST pattern established in happy-path.spec.js.
 *
 * @param {import('@playwright/test').Page} page Playwright page object.
 * @return {Promise<string>} The published page URL.
 */
async function createEventPage(page) {
	// Log in to obtain auth cookies required for the REST API.
	await page.goto('/wp-login.php');
	await page.fill('#user_login', 'admin');
	await page.fill('#user_pass', 'password');
	await page.click('#wp-submit');
	await page.waitForURL('**/wp-admin/**');

	// Retrieve a REST API nonce from the admin-ajax endpoint.
	const nonce = await page.evaluate(async () => {
		const response = await fetch('/wp-admin/admin-ajax.php?action=rest-nonce');
		return response.text();
	});

	// Build the block content with our unique password.
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
					title: 'Lightbox Navigation Test',
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
	return result.link;
}

/**
 * Helper: complete the guest flow (password → registration → consent) and
 * return when the gallery/upload view is visible.
 *
 * @param {import('@playwright/test').Page} page     Playwright page object.
 * @param {string}                          password The event password to enter.
 */
async function completeGuestFlow(page, password) {
	// Retry navigation to handle occasional slow Playground starts.
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

	// Step 1: enter the event password.
	await page.locator('#pixfete-password').fill(password);
	await page.locator('.pixfete-form button[type="submit"]').first().click();

	// Step 2: fill in the guest name and submit.
	await expect(page.locator('#pixfete-guest-name')).toBeVisible();
	await page.locator('#pixfete-guest-name').fill('Nav Tester');
	await page.locator('.pixfete-form button[type="submit"]').last().click();

	// Step 3: accept the consent prompt.
	await expect(page.locator('.pixfete-consent')).toBeVisible();
	await page.locator('.pixfete-accept-btn').click();

	// Confirm we have reached the gallery view. The Interactivity API removes
	// the `hidden` HTML attribute from the gallery container when
	// state.currentView === 'gallery'. We target the wrapper directly via its
	// data-wp-bind attribute — there is exactly one such element in the block.
	await expect(page.locator('[data-wp-bind--hidden="!state.isGalleryView"]')).not.toHaveAttribute('hidden', {
		timeout: 20000,
	});
}

/**
 * Helper: upload the test fixture and wait for the new photo to appear in the
 * grid before returning. Waits for a new thumbnail to appear each time so
 * sequential calls do not race with each other.
 *
 * @param {import('@playwright/test').Page} page          Playwright page object.
 * @param {number}                          expectedCount Total photo count to wait for after this upload.
 */
async function uploadPhoto(page, expectedCount) {
	const fileInput = page.locator('#pixfete-file-gallery');
	await fileInput.setInputFiles(path.join(__dirname, 'fixtures', 'test-photo.jpg'));

	// Wait until the expected number of photo thumbnails is present.
	await expect(page.locator('.pixfete-photo img')).toHaveCount(expectedCount, {
		timeout: 20000,
	});
}

test.describe('Pixfête - Lightbox Navigation', () => {
	test.describe.configure({ mode: 'serial' });

	// -----------------------------------------------------------------
	// Setup: admin creates the page; result stored in module-level var.
	// -----------------------------------------------------------------
	test('Admin: create event page for navigation test', async ({ page }) => {
		pageUrl = await createEventPage(page);
		expect(pageUrl).toBeTruthy();
		expect(eventPassword.length).toBeGreaterThanOrEqual(8);
	});

	// -----------------------------------------------------------------
	// Main test: guest flow + uploads + lightbox navigation assertions.
	// -----------------------------------------------------------------
	test('Guest: lightbox keyboard and arrow-button navigation', async ({ page }) => {
		test.skip(!pageUrl, 'Admin setup did not produce a page URL');

		// --- Guest flow: password → registration → consent. ---
		await completeGuestFlow(page, eventPassword);

		// --- Upload three photos sequentially, waiting for each one. ---
		// Photos are prepended on upload, so after three uploads:
		//   source index 0 = 3rd upload (newest)
		//   source index 1 = 2nd upload
		//   source index 2 = 1st upload (oldest)
		await uploadPhoto(page, 1);
		await uploadPhoto(page, 2);
		await uploadPhoto(page, 3);

		// Confirm we have exactly three thumbnails in the grid.
		await expect(page.locator('.pixfete-photo img')).toHaveCount(3);

		// --- Open the lightbox at the middle photo (source index 1). ---
		// This gives us one photo on each side, so we can test both directions.
		await page.locator('.pixfete-photo').nth(1).click();

		// The lightbox overlay should now be visible.
		const lightbox = page.locator('.pixfete-lightbox');
		await expect(lightbox).toBeVisible();

		// The full-size image should be displayed.
		const lightboxImage = page.locator('.pixfete-lightbox-image');
		await expect(lightboxImage).toBeVisible();

		// Verify the prev arrow button carries the correct aria-label so we
		// catch any i18n-wiring regressions early.
		const prevBtn = page.locator('.pixfete-lightbox-nav--prev');
		await expect(prevBtn).toHaveAttribute('aria-label', 'Previous photo');

		// --- Keyboard: ArrowRight moves to next photo. ---
		const srcAtMiddle = await lightboxImage.getAttribute('src');
		expect(srcAtMiddle).toBeTruthy();

		await page.keyboard.press('ArrowRight');

		const srcAfterFirst = await lightboxImage.getAttribute('src');
		expect(srcAfterFirst).not.toBe(srcAtMiddle);

		// After one ArrowRight from index 1 we are at index 2 (the last photo
		// in navigation terms, which is the oldest = source index 2).
		// At this boundary the Next button must be disabled.
		const nextBtn = page.locator('.pixfete-lightbox-nav--next');
		await expect(nextBtn).toBeDisabled();

		// Prev must still be enabled — there is one photo to the left.
		await expect(prevBtn).toBeEnabled();

		// --- Keyboard: ArrowLeft navigates back. ---
		await page.keyboard.press('ArrowLeft');
		const srcAfterLeft = await lightboxImage.getAttribute('src');
		expect(srcAfterLeft).not.toBe(srcAfterFirst);

		// --- Arrow button: click Next to advance. ---
		// We are back at index 1, so the Next button should be enabled.
		await expect(nextBtn).toBeEnabled();

		const srcBeforeNextClick = await lightboxImage.getAttribute('src');
		await nextBtn.click();

		const srcAfterNextClick = await lightboxImage.getAttribute('src');
		expect(srcAfterNextClick).not.toBe(srcBeforeNextClick);

		// We are at index 2 again — the Next button must be disabled.
		await expect(nextBtn).toBeDisabled();

		// --- Navigate all the way back to index 0 using ArrowLeft. ---
		// From index 2, two ArrowLefts should take us to index 0.
		await page.keyboard.press('ArrowLeft');
		const srcAtIndex1 = await lightboxImage.getAttribute('src');
		expect(srcAtIndex1).not.toBe(srcAfterNextClick);

		await page.keyboard.press('ArrowLeft');
		const srcAtIndex0 = await lightboxImage.getAttribute('src');
		expect(srcAtIndex0).not.toBe(srcAtIndex1);

		// At index 0, the Prev button must now be disabled.
		await expect(prevBtn).toBeDisabled();

		// Next must be enabled — there are still photos to the right.
		await expect(nextBtn).toBeEnabled();

		// --- Escape closes the lightbox. ---
		await page.keyboard.press('Escape');

		// The overlay uses data-wp-bind--hidden, so after close the `hidden`
		// HTML attribute is added and the element is invisible to Playwright.
		await expect(lightbox).toBeHidden();
	});
});

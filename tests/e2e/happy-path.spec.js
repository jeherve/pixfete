const { test, expect } = require( '@playwright/test' );
const path = require( 'path' );

/**
 * Happy path e2e test for the Event Guest Photos Sharing plugin.
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

test.describe( 'Event Guest Photos Sharing - Happy Path', () => {
	test.describe.configure( { mode: 'serial' } );

	test( 'Admin: create a page with the Event Album block', async ( {
		page,
	} ) => {
		// Log in to WordPress admin.
		await page.goto( '/wp-login.php' );
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'password' );
		await page.click( '#wp-submit' );
		await page.waitForURL( '**/wp-admin/**' );

		// Navigate to add new page.
		await page.goto( '/wp-admin/post-new.php?post_type=page' );

		// Dismiss any welcome modals/guides in the editor.
		const welcomeModal = page.locator(
			'role=dialog[name="Welcome to the block editor"]'
		);
		if (
			await welcomeModal
				.isVisible( { timeout: 3000 } )
				.catch( () => false )
		) {
			await page
				.locator( 'role=dialog >> role=button[name="Close"]' )
				.click();
		}

		// Add a page title.
		await page
			.locator( 'role=textbox[name="Add title"]' )
			.fill( 'Test Event Album' );

		// Insert the Event Album block via the block inserter.
		// Click the empty content area to focus it.
		await page
			.click( '.block-editor-default-block-appender__content' )
			.catch( () => {} );
		await page.keyboard.type( '/Event Photo Album' );
		// Wait for the inserter suggestion and select it.
		await page
			.locator( 'role=option[name=/Event Photo Album/i]' )
			.click();

		// Wait for the block to appear in the editor.
		await page
			.locator( '.egps-editor-preview-placeholder' )
			.waitFor( { timeout: 10000 } );

		// Click the block to select it (needed to show sidebar inspector).
		await page.locator( '.egps-editor-preview-placeholder' ).click();

		// Open the Settings sidebar if it's not already open.
		const settingsButton = page.locator(
			'role=button[name="Settings"][pressed="false"]'
		);
		if ( await settingsButton.isVisible().catch( () => false ) ) {
			await settingsButton.click();
		}

		// Read the auto-generated password from the "Event Password" field
		// in the block inspector sidebar.
		const passwordField = page.locator(
			'.block-editor-block-inspector'
		).getByLabel( 'Event Password' );
		await expect( passwordField ).not.toBeEmpty();
		eventPassword = await passwordField.inputValue();

		// Type a consent message into the InnerBlocks paragraph.
		// InnerBlocks renders a contenteditable RichText element,
		// so we must use keyboard.type() instead of fill().
		const consentParagraph = page.locator(
			'.egps-editor-consent .block-editor-rich-text__editable'
		);
		await consentParagraph.click();
		await page.keyboard.type(
			'I consent to sharing my photos at this event.'
		);

		// Publish the page.
		await page
			.locator( 'role=button[name="Publish"i]' )
			.first()
			.click();
		// Confirm publish in the panel.
		await page
			.locator(
				'.editor-post-publish-panel >> role=button[name="Publish"i]'
			)
			.click();

		// Wait for the publish confirmation and grab the page URL.
		const viewLink = page.locator(
			'.post-publish-panel__postpublish-buttons >> role=link[name=/View Page/i]'
		);
		await viewLink.waitFor( { timeout: 10000 } );
		pageUrl = await viewLink.getAttribute( 'href' );

		expect( pageUrl ).toBeTruthy();
		expect( eventPassword ).toBeTruthy();
		expect( eventPassword.length ).toBeGreaterThanOrEqual( 8 );
	} );

	test( 'Guest: complete flow — password, register, consent, upload, lightbox', async ( {
		page,
	} ) => {
		test.skip( ! pageUrl, 'Admin setup did not produce a page URL' );

		// --- Step 1: Password entry ---
		// Visit the published page as a guest (fresh context, no admin cookies).
		await page.goto( pageUrl );

		// Wait for the password form to appear.
		const passwordInput = page.locator( '#egps-password' );
		await passwordInput.waitFor( { timeout: 10000 } );

		// Enter the event password and submit.
		await passwordInput.fill( eventPassword );
		await page
			.locator( '.egps-form button[type="submit"]' )
			.first()
			.click();

		// Verify transition to registration view.
		await expect( page.locator( '#egps-guest-name' ) ).toBeVisible();

		// --- Step 2: Registration ---
		// Fill in the guest name and submit.
		await page.locator( '#egps-guest-name' ).fill( 'Test Guest' );
		await page
			.locator( '.egps-form button[type="submit"]' )
			.last()
			.click();

		// Verify transition to consent view.
		await expect( page.locator( '.egps-consent' ) ).toBeVisible();

		// Verify the consent message we typed in the editor is displayed.
		await expect( page.locator( '.egps-consent-text' ) ).toContainText(
			'I consent to sharing my photos at this event.'
		);

		// --- Step 3: Accept consent ---
		await page.locator( '.egps-accept-btn' ).click();

		// Verify transition to gallery view.
		await expect( page.locator( '.egps-grid' ) ).toBeVisible();

		// --- Step 4: Upload a photo ---
		const fileInput = page.locator(
			'.egps-upload-gallery input[type="file"]'
		);
		await fileInput.setInputFiles(
			path.join( __dirname, 'fixtures', 'test-photo.jpg' )
		);

		// Wait for the uploaded photo to appear in the grid.
		const photo = page.locator( '.egps-photo img' );
		await photo.first().waitFor( { timeout: 15000 } );

		// Verify the photo is visible with the guest name.
		await expect( photo.first() ).toBeVisible();
		await expect(
			page.locator( '.egps-photo-name' ).first()
		).toContainText( 'Test Guest' );

		// --- Step 5: Lightbox ---
		// Click the photo to open the lightbox.
		await page.locator( '.egps-photo' ).first().click();

		// Verify lightbox opens with a full-size image.
		const lightbox = page.locator( '.egps-lightbox' );
		await expect( lightbox ).toBeVisible();

		const lightboxImage = page.locator( '.egps-lightbox-image' );
		await expect( lightboxImage ).toBeVisible();
		const src = await lightboxImage.getAttribute( 'src' );
		expect( src ).toBeTruthy();

		// Verify guest name in lightbox.
		await expect(
			page.locator( '.egps-lightbox-name' )
		).toContainText( 'Test Guest' );

		// Close lightbox by clicking the close button.
		await page.locator( '.egps-lightbox-close' ).click();
		await expect( lightbox ).toBeHidden();
	} );
} );

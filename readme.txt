=== Event Guest Photos Sharing ===
Contributors: jeherve
Tags: photo album, event, guest photos, sharing, wedding
Stable tag: 1.1.0
Requires at least: 6.9
Requires PHP: 8.3
Tested up to: 7.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Allow guests at your event to share their photos in a shared album — no account required.

== Description ==

Event Guest Photos Sharing lets event and wedding planners create pages where guests can upload and browse photos without creating an account.

**How it works:**

1. Create a page in the block editor and add the Event Photo Album block (or use the provided pattern).
2. Configure a password for the event, optionally set a date range and enable table names.
3. Share the page URL (or a QR code with the password embedded) with your guests.
4. Guests enter the password, provide their name, accept a consent message, then upload and view photos.

**Features:**

* Mobile-first guest experience — designed for phones at events.
* No guest accounts required — password-based access with cookie authentication.
* Password protection with QR code support for easy access.
* Built-in QR code generator (Tools > Event QR Codes) — create styled, downloadable QR codes with custom colors, corner styles, and embedded logos.
* Real-time gallery with automatic polling for new photos.
* Immersive photo grid — photos fill the screen edge-to-edge on mobile for a gallery-app feel.
* Lightbox viewer for full-size photos.
* Floating upload button that stays visible as you scroll through photos.
* Upload progress banner — see which photo is being uploaded and how many remain.
* Camera capture and gallery picker for uploads (supports multiple file selection).
* Full-screen page template — remove the header, footer, and sidebar for distraction-free photo browsing.
* Customizable consent message via the block editor.
* Optional table name tracking for seating assignments.
* Date range support — uploads automatically stop when the event is over.
* Optional photo moderation — require approval before photos appear in the gallery.
* Extensible via WordPress hooks and filters.

== Installation ==

1. Upload the plugin to your WordPress site and activate it.
2. Create a new page in the block editor.
3. Add the "Event Photo Album" block, or select the "Event Photo Album" pattern (under the "Event" category).
4. Configure the event password and settings in the block sidebar:
   * Set or regenerate the event password.
   * Optionally restrict uploads to a specific date range.
   * Optionally enable table/seating name tracking.
5. Customize the consent message using the block's inner content area.
6. Optionally, assign the "Event Album (Full Screen)" page template in the site editor to hide the header, footer, and sidebar.
7. Publish the page and share the URL with your guests.

== Frequently Asked Questions ==

= Do guests need to create an account? =

No. Guests authenticate with a shared event password and provide their name. No WordPress account is needed.

= How do guests access the event page? =

Share the page URL directly, or use the built-in QR code generator under Tools > Event QR Codes. You can create QR codes that include the event password and table name, so guests scan and go straight to the registration step. QR codes can be customized with your event's colors and logo.

= How do I generate QR codes for my event? =

Go to Tools > Event QR Codes in your WordPress admin. Select an event page, choose which URL parameters to include (password, table name), optionally customize colors and corner styles, then download the QR code as a PNG. You can generate a different QR code for each table.

= What image formats are supported? =

JPEG, PNG, and WebP. HEIC/HEIF are supported if your server has the required image libraries.

= What happens when I regenerate the password? =

Regenerating the password invalidates all existing guest sessions. Guests who authenticated with the old password will need to re-register with the new one.

= Can I use this on multiple pages? =

Yes. Each page with the Event Photo Album block operates independently with its own password, settings, and photo gallery. Only one Event Photo Album block is allowed per page.

= Can I moderate photos before they appear? =

Yes, with a small amount of custom code. Developers can use the `egps_photo_requires_moderation` filter to enable moderation. See the README on GitHub for details.

= Can I hide the header and footer on the event page? =

Yes. In the site editor, assign the "Event Album (Full Screen)" page template to your event page. This removes the header, footer, and sidebar so guests see only the photo album.

= Can I limit how many photos each guest uploads? =

Not by default, but developers can use the `egps_max_uploads_per_guest` filter to set per-event limits. See the README on GitHub for the full list of available hooks.

== Changelog ==

= 1.1.0 =

* New: You can now see upload progress when sharing photos — a banner shows which photo is being uploaded and how many remain.
* New: The photo gallery now feels more immersive on mobile — photos are larger and fill the screen edge-to-edge, inspired by popular photo gallery apps.
* New: Upload buttons are now a floating action button that stays visible as you scroll through photos, making it easier to share your pictures at any time.
* New: A new "Event Album (Full Screen)" page template is available in the site editor — it removes the header, footer, and sidebar for a distraction-free photo browsing experience.
* Fix: Entering a wrong password no longer locks you out with a "CSRF token is invalid" error on every subsequent attempt.
* Fix: The consent message now displays correctly on the front end instead of appearing blank above the "I Accept" button.
* Fix: Returning guests who already entered their name no longer see a "CSRF token is required" error when accepting the consent message.
* Fix: Tapping "Load more" to see older photos no longer causes a JavaScript error in the browser console.
* Fix: Inserting the Event Photo Album pattern no longer triggers a block validation error in the editor.
* Fix: Entering a wrong event password now shows an error immediately instead of letting you continue to the name entry screen.
* Fix: Custom consent messages entered in the block editor are now saved correctly and no longer disappear after refreshing the page.

= 1.0.0 =

* Initial release.

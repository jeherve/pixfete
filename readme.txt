=== Pixfête ===
Contributors: jeherve
Tags: photo album, event, guest photos, sharing, wedding
Stable tag: 1.2.0
Requires at least: 6.9
Requires PHP: 8.3
Tested up to: 7.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Allow guests at your event to share photos in a shared album. They just scan a QR code and start sharing.

== Description ==

Pixfête lets event and wedding planners create pages where guests can upload and browse photos together.

Your guests don't need to create an account or download an app. They scan a QR code at the venue, or tap a link you send them via email, WhatsApp, or text, and they're in. They can start sharing photos right away.

**How it works:**

1. Create a page in the block editor and add the Event Photo Album block.
2. Configure a password for the event. You can also set a date range and enable table names.
3. Share the page with your guests. Print QR codes for table cards, or send the link via email, WhatsApp, or text.
4. Guests scan, enter their name, and start uploading and browsing photos.

**Features:**

* Guests don't need an account or an app. They scan a QR code or tap a link and start sharing right away.
* Designed for phones at events, so the experience feels natural on mobile.
* Password-protected pages with QR code support. You can embed the password in the QR code so guests go straight in.
* Built-in QR code generator (Settings > Pixfête) with custom colors, corner styles, and logo support.
* The gallery updates in real time as new photos come in.
* Photos fill the screen edge-to-edge on mobile for an immersive, gallery-app feel.
* Lightbox viewer for full-size photos.
* A floating upload button stays visible as you scroll, so it's always easy to share another photo.
* Upload progress banner shows which photo is being uploaded and how many are left.
* Camera capture and gallery picker for uploads, with support for selecting multiple files at once.
* A full-screen page template removes the header, footer, and sidebar for distraction-free browsing.
* Customizable consent message via the block editor.
* Optional table name tracking for seating assignments.
* Date range support so uploads stop automatically when the event is over.
* After your event ends, a ZIP file with all original photos is generated automatically. You can download it from the settings page.
* Assign moderators who can remove inappropriate photos from the live gallery on their phone, without needing access to the WordPress dashboard.
* Optional photo moderation if you want to approve photos before they appear in the gallery.
* Live Photo Wall block for projecting photos onto a big screen during your event. Photos cycle one at a time with smooth crossfade transitions, and new submissions show up as guests upload them.
* Extensible via WordPress hooks and filters.

== Installation ==

1. Upload the plugin to your WordPress site and activate it.
2. Create a new page in the block editor.
3. Add the "Event Photo Album" block.
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

Share the page URL directly, or use the built-in QR code generator under Settings > Pixfête. You can create QR codes that include the event password and table name, so guests scan and go straight to the registration step. QR codes can be customized with your event's colors and logo.

= How do I generate QR codes for my event? =

Go to Settings > Pixfête in your WordPress admin. Select an event page, choose which URL parameters to include (password, table name), optionally customize colors and corner styles, then download the QR code as a PNG. You can generate a different QR code for each table.

= What image formats are supported? =

JPEG, PNG, and WebP. HEIC/HEIF are supported if your server has the required image libraries.

= What happens when I regenerate the password? =

Regenerating the password invalidates all existing guest sessions. Guests who authenticated with the old password will need to re-register with the new one.

= Can I use this on multiple pages? =

Yes. Each page with the Event Photo Album block operates independently with its own password, settings, and photo gallery. Only one Event Photo Album block is allowed per page.

= Can I let someone help moderate photos during the event? =

Yes. Create a WordPress user with the "Event Photo Moderator" role, then assign them as a moderator in the Event Photo Album block settings. They log in on their phone, visit the event page, and can tap to delete any inappropriate photo. They don't need access to the WordPress dashboard.

= Can I moderate photos before they appear? =

Yes, with a small amount of custom code. Developers can use the `pixfete_photo_requires_moderation` filter to enable moderation. See the README on GitHub for details.

= Can I hide the header and footer on the event page? =

Yes. In the site editor, assign the "Event Album (Full Screen)" page template to your event page. This removes the header, footer, and sidebar so guests see only the photo album.

= How do I download all photos from an event? =

After your event ends (based on the end date you set in the block settings), the plugin automatically generates a ZIP file with all original, full-resolution photos. Go to Settings > Pixfête, select the event page, and you'll see a "Download ZIP" button in the Photo Archive section. No action needed — the archive is created in the background after the event date passes.

= Can I show photos on a projector during the event? =

Yes! Add the Live Photo Wall block to a separate page and link it to your event page. The photo wall displays photos full-screen with crossfade transitions, automatically cycling through submissions. Set it up on a laptop connected to a projector and it runs hands-free — new photos appear as guests upload them. You can adjust how long each photo stays on screen in the block settings.

= Can I limit how many photos each guest uploads? =

Not by default, but developers can use the `pixfete_max_uploads_per_guest` filter to set per-event limits. See the README on GitHub for the full list of available hooks.

== Screenshots ==

1. Event page on mobile with the photo gallery and upload button.
2. Template selection in the site editor to choose the full-screen layout.
3. Block settings in the editor sidebar to configure the event password, date range, and table name tracking.

== Changelog ==

= 1.3.0 - unreleased =

**Added**

* New Live Photo Wall block for projecting submitted photos onto a big screen during your event. The photo wall displays photos one at a time with smooth crossfade transitions, automatically cycling through submissions as guests upload them.
* When visiting an event page before the event has started, guests now see a friendly message instead of a blank page.
* Event hosts can now assign moderators who can remove inappropriate photos from the live gallery on their phone, without needing access to the WordPress dashboard.

**Changed**

* The plugin has been renamed to Pixfête. You'll see the new name in your plugins list and under the Settings menu.

**Fixed**

* Event passwords shorter than 8 characters are now flagged in the editor with a clear warning, preventing a confusing "incorrect password" error for guests.
* Event names with special characters (like "John & Jane's Wedding") now display correctly in the Live Photo Wall block sidebar instead of showing raw HTML codes.
* The Live Photo Wall block now appears in the block editor as expected.
* The Live Photo Wall now loads photos correctly on sites using plain permalink structures.
* The Live Photo Wall password form and other views now display correctly instead of being hidden behind the loading screen.
* The moderation banner and photo delete buttons no longer appear to all visitors — they are now correctly shown only to assigned moderators.
* The "event not started yet" and loading messages now use the theme's text color, so they remain readable on themes with tinted backgrounds.
* A PHP warning that could appear on the login screen after a failed login attempt has been silenced.

= 1.2.0 - 2026-03-26 =

**Added**

* Photo archives are now automatically generated as ZIP files after events end, and can be downloaded from the settings page.
* Site administrators can now permanently delete all traces of an event (page, photos, and archive) from the admin settings page.

**Fixed**

* QR codes now generate correctly on sites using plain permalink structures (e.g. ?page_id=6).
* The QR code preview no longer fails to render when the site icon is an SVG image.

= 1.1.1 - 2026-03-25 =

**Changed**

* The event page template now uses your theme's own colors and spacing instead of a fixed dark background, so it blends naturally with any theme.
* The photo upload button now only appears on mobile devices, where guests are most likely to snap and share photos.

**Removed**

* The "Event Photo Album" block pattern has been removed — use the "Event Album (Full Screen)" page template instead for a quicker setup.

**Fixed**

* Event page names with special characters (like "&" or apostrophes) now display correctly in the QR Code Generator page selector.

= 1.1.0 - 2026-03-25 =

**Added**

* You can now see upload progress when sharing photos — a banner shows which photo is being uploaded and how many remain.
* The photo gallery now feels more immersive on mobile — photos are larger and fill the screen edge-to-edge, inspired by popular photo gallery apps.
* Upload buttons are now a floating action button that stays visible as you scroll through photos, making it easier to share your pictures at any time.
* A new "Event Album (Full Screen)" page template is available in the site editor — it removes the header, footer, and sidebar for a distraction-free photo browsing experience.

**Fixed**

* Entering a wrong password no longer locks you out with a "CSRF token is invalid" error on every subsequent attempt.
* The consent message now displays correctly on the front end instead of appearing blank above the "I Accept" button.
* Returning guests who already entered their name no longer see a "CSRF token is required" error when accepting the consent message.
* Tapping "Load more" to see older photos no longer causes a JavaScript error in the browser console.
* Entering a wrong event password now shows an error immediately instead of letting you continue to the name entry screen.
* Custom consent messages entered in the block editor are now saved correctly and no longer disappear after refreshing the page.

= 1.0.0 - 2026-03-25 =

* Initial release.

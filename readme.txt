=== Event Guest Photos Sharing ===
Contributors: jeherve
Tags: photo album, event, guest photos, sharing, wedding
Stable tag: 1.0.0-alpha
Requires at least: 6.9
Requires PHP: 8.3
Tested up to: 6.9
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
* No guest accounts required — cookie-based authentication with HMAC signing.
* Password protection with QR code support for easy access.
* Real-time gallery with polling for new photos.
* 3-column photo grid with lightbox viewer.
* Camera capture and gallery picker for uploads.
* Customizable consent message via the block editor.
* Optional table name tracking for seating assignments.
* Date range support — uploads automatically stop when the event is over.
* Extensible via WordPress hooks and filters.

== Installation ==

1. Upload the plugin to your WordPress site and activate it.
2. Create a new page in the block editor.
3. Add the "Event Photo Album" block, or select the "Event Photo Album" pattern.
4. Configure the event password and settings in the block sidebar.
5. Publish the page and share the URL with your guests.

== Frequently Asked Questions ==

= Do guests need to create an account? =

No. Guests authenticate with a shared event password and provide their name. No WordPress account is needed.

= How do guests access the event page? =

Share the page URL directly, or generate a QR code that includes the password as a URL parameter (`?key=yourpassword`). Guests scan the QR code and go straight to the registration step.

= What image formats are supported? =

JPEG, PNG, and WebP. HEIC/HEIF are supported if your server has the required image libraries.

= Can I limit how many photos each guest uploads? =

Not by default, but developers can use the `egps_max_uploads_per_guest` filter to set per-event limits.

== Changelog ==

= 1.0.0-alpha =

* Initial alpha release.

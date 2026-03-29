<div align="center">
	<img src=".wordpress-org/icon-256x256.png" width="200" height="200">
	<h1>Event Guest Photos Sharing</h1>
	<p>
		<b>A WordPress plugin that lets event guests share photos in a shared album. They just scan a QR code and start sharing.</b>
		<br>
		Guests don't need an account or an app. They scan a QR code or tap a link, enter their name, and start uploading and browsing photos in a real-time gallery.
	</p>
	<br>
	<br>
</div>

## Requirements

- WordPress 6.9+
- PHP 8.3+

## Development

### Setup

```bash
npm install
composer install
```

### Build

```bash
npm run build           # Production build
npm run dev             # Development mode (watch)
```

### Local environment

The plugin includes a [wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) configuration:

```bash
npm run env:start       # Start local WordPress environment (http://localhost:8123)
npm run env:stop        # Stop the environment
```

You can also use [WordPress Playground](https://developer.wordpress.org/playground/) with the included blueprints:

- `blueprint.json` — WordPress 6.9
- `blueprint-trunk.json` — WordPress trunk

### Linting

```bash
npm run lint:js         # Lint JavaScript
npm run lint:css        # Lint CSS
npm run format          # Format code
composer run lint       # PHP CodeSniffer
composer run lint:fix   # PHP CodeSniffer autofix
```

### Testing

```bash
composer run phpunit    # PHP unit tests
npm run test:unit       # JavaScript unit tests
npm run test:e2e        # Playwright end-to-end tests
npm run test:e2e:headed # E2E tests with browser visible
```

## Architecture

The plugin registers two blocks and a REST API under the `event-guest-photos-sharing/v1` namespace:

- **Event Photo Album** (`event-guest-photos-sharing/event-album`) — the main guest-facing block for uploading and browsing photos.
- **Live Photo Wall** (`event-guest-photos-sharing/event-slideshow`) — a full-screen projection block that cycles through submitted photos with crossfade transitions.

### Source files

| File | Purpose |
|------|---------|
| `src/class-block.php` | Block registration and page template |
| `src/class-cookie.php` | HMAC-signed cookie management for guest sessions |
| `src/class-rest.php` | REST API endpoints (auth, upload, gallery, cleanup) |
| `src/class-upload.php` | File upload handling and MIME type validation |
| `src/class-admin.php` | Admin settings page with QR code generation and archive status (Settings > Event Guest Photos Sharing) |
| `src/class-archive.php` | Cron-based ZIP archive generation for completed event photos |
| `src/class-cleanup.php` | Permanent deletion of all event data (page, photos, archive, slideshow pages) |
| `src/class-moderator.php` | Custom moderator role, dashboard lockout, and per-event moderator assignment checks |
| `src/class-slideshow.php` | Live Photo Wall block registration and page template |
| `src/blocks/event-album/` | Event Photo Album block assets (edit.js, view.js, render.php, block.json, styles) |
| `src/blocks/event-slideshow/` | Live Photo Wall block assets (edit.js, view.js, render.php, block.json, styles) |
| `src/admin/` | React app for the admin page (QR code generator, archive status, event cleanup, components, utilities, styles) |
| `templates/page-event-album.html` | Full-screen page template for the event album (site logo + content) |
| `templates/page-event-slideshow.html` | Full-screen page template for the photo wall (content only, black background) |

### Guest flow

1. **Password** — Guest enters the event password (or arrives via `?key=` URL parameter).
2. **Registration** — Guest provides their name (and optionally table name via `?table=` parameter or form field).
3. **Consent** — Guest accepts the consent message (customizable via InnerBlocks in the editor).
4. **Gallery** — Guest can upload photos and browse the shared album with 15-second auto-polling. Features a floating upload button, upload progress banner, lightbox viewer, and a "new photos" notification banner.

### Settings Page

Under **Settings > Event Guest Photos Sharing**, admins can access plugin settings and tools.

#### QR Code Generator

The QR Code Generator section lets admins generate styled QR codes for event pages. This helps event planners prepare printed QR codes ahead of time — for example, one per table.

The page lists all published pages containing the Event Photo Album block. For each page, admins can:

- Choose which URL parameters to embed (password, table name).
- Toggle an embedded logo (auto-resolved from the page's featured image or the site icon).
- Customize foreground/background colors and corner styles.
- Preview the QR code live and download it as a PNG.

QR codes are generated client-side using [qr-code-styling](https://www.npmjs.com/package/qr-code-styling). No data is saved — styling choices are ephemeral.

#### Photo Archive

After an event ends (based on the `dateRangeEnd` block attribute), the plugin automatically generates a ZIP archive containing all original, full-resolution guest photos. A daily cron job detects completed events and processes archives in batches of 50 attachments at a time, so it works reliably even on shared hosting with strict PHP time limits.

Archive status is displayed in the admin page below the QR Code Generator. Admins see the current state (queued, generating, ready, or failed) and can download the ZIP once it's complete.

Archives are stored in `wp-content/uploads/egps-archives/` with randomized filenames that are hard to guess. Archive metadata (status, file path, URL) is tracked in the `egps_zip_archives` WordPress option, keyed by page ID.

#### Event Cleanup

Once an event has ended (or if no end date is set), a cleanup section appears below the Photo Archive. Clicking "Delete Event Data" permanently removes all traces of the event:

1. All guest-uploaded photos (attachment posts and files on disk).
2. The ZIP archive file and its option entry.
3. Any orphaned batch cron jobs for the archive.
4. Any pages containing a Live Photo Wall block linked to this event.
5. The event page itself.

This action requires the `delete_post` capability for the specific page and cannot be undone. A browser confirmation dialog is shown before proceeding.

### Photo moderation

Event hosts can assign moderators to remove inappropriate photos from the live gallery during an event. Moderators use their phones — no WordPress dashboard access needed.

**Setup:**

1. Create a WordPress user with the **Event Photo Moderator** role (`egps_moderator`). This role grants only `read` and the custom `egps_moderate_photos` capability — nothing else.
2. In the block editor, open the Event Photo Album block settings and add the user in the **Moderators** panel.
3. Share the event page URL and password with the moderator.

**How it works:**

- The moderator logs in via `wp-login.php` on their phone and visits the event page.
- They go through the same guest flow (password, name, consent) and can upload photos like any guest.
- Once in the gallery, they see a moderation banner and a delete badge on each photo. Tapping the badge permanently deletes the photo after a confirmation dialog.
- The delete is immediate — the photo disappears from all guests' galleries at the next poll (within 15 seconds).

**Dashboard lockout:** Users whose only role is `egps_moderator` are redirected away from wp-admin and don't see the admin bar. Users with additional roles (e.g., administrator + moderator) are not affected.

**Capability check:** The `DELETE /photos/{page_id}/{attachment_id}` endpoint requires the user to have `egps_moderate_photos` (or `manage_options`) AND be explicitly assigned to the event's `moderators` block attribute.

### Page templates

The plugin registers two page templates via `register_block_template()`:

- **Event Album (Full Screen)** (`page-event-album`) — Shows only the site logo and page content — no header, footer, or sidebar — for a distraction-free photo browsing experience. Assign it to an event page in the site editor.
- **Live Photo Wall (Full Screen)** (`page-event-slideshow`) — Even more minimal: just the page content on a black background, optimized for projection displays. Assign it to a page containing the Live Photo Wall block.

### Live Photo Wall block

The Live Photo Wall block is designed for projecting photos onto a big screen during an event. It lives on a separate page from the event album and references it via the `eventPageId` attribute.

**How it works:**

1. Create a new page and add the Live Photo Wall block.
2. In the block settings, select the event page containing the Event Photo Album block. The password and date range are synced automatically.
3. Adjust the transition interval (default: 5 seconds per photo).
4. Assign the "Live Photo Wall (Full Screen)" template and open the page on the projector.
5. Enter the event password once — the photo wall starts automatically, showing a waiting screen until the first photo arrives.

**Photo wall features:**

- Full-viewport display with blurred photo background (no black bars).
- Crossfade transitions between photos (~1 second).
- Guest name and table name displayed in a floating pill overlay.
- 5-second polling for near-real-time photo display.
- Automatic backoff on network failures (recovers when connection returns).
- Respects `prefers-reduced-motion` for transitions and animations.

**Live Photo Wall block attributes:**

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `password` | string | `""` | Event password (synced from the event page) |
| `eventVersion` | integer | `1` | Tracks password regeneration |
| `dateRangeStart` | string | `""` | Event start date (YYYY-MM-DD) |
| `dateRangeEnd` | string | `""` | Event end date (YYYY-MM-DD) |
| `interval` | integer | `5` | Seconds per photo (min: 2, max: 30) |
| `eventPageId` | integer | `0` | ID of the event page whose photos to display |

### Event Photo Album block attributes

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `password` | string | `""` | Event access password (auto-generated on first insertion) |
| `eventVersion` | integer | `1` | Incremented on password regeneration to invalidate old sessions |
| `dateRangeStart` | string | `""` | Upload start date (YYYY-MM-DD) |
| `dateRangeEnd` | string | `""` | Upload end date (YYYY-MM-DD) |
| `enableTableNames` | boolean | `false` | Show table/seating name input during registration |
| `moderators` | array | `[]` | WordPress user IDs assigned as moderators for this event |

### REST API endpoints

All endpoints are under the `event-guest-photos-sharing/v1` namespace.

| Method | Route | Description |
|--------|-------|-------------|
| POST | `/auth/{page_id}` | Password validation (`action=validate_password`), guest registration (`action=register`), consent (`action=consent`), and slideshow auth (`action=slideshow_auth`) |
| POST | `/photos/{page_id}` | Photo upload (requires authenticated guest with consent) |
| GET | `/photos/{page_id}` | Gallery retrieval with pagination and polling support |
| DELETE | `/photos/{page_id}/{attachment_id}` | Delete a single photo (assigned moderators only) |
| DELETE | `/events/{page_id}` | Permanently delete an event page and all associated data (admin only) |

**Gallery query parameters:**

- `per_page` — Number of photos per page (default: 30, max: 100).
- `page` — Page number for pagination.
- `since` — Unix timestamp; returns only photos uploaded after this time (used for polling).

**Gallery response headers:**

- `X-WP-Total` — Total number of photos.
- `X-WP-TotalPages` — Total number of pages.

### Post meta

Guest photo attachments store the following metadata:

| Meta key | Type | Description |
|----------|------|-------------|
| `_egps_guest_name` | string | Guest's display name |
| `_egps_table_name` | string | Guest's table/seating name |
| `_egps_guest_id` | string | SHA-256 hash identifying the guest |
| `_egps_uploaded_at` | int | Unix timestamp of upload |
| `_egps_requires_moderation` | bool | Whether the photo is pending moderation |

## Hooks

### Filters

| Filter | Default | Description |
|--------|---------|-------------|
| `egps_allowed_mime_types` | `['image/jpeg', 'image/png', 'image/webp']` + HEIC/HEIF if supported | Allowed upload MIME types |
| `egps_max_uploads_per_guest` | `0` (unlimited) | Maximum uploads per guest. Receives `$limit`, `$guest_id`, `$page_id` |
| `egps_photo_requires_moderation` | `false` | Whether new uploads require moderation. Receives `$requires_moderation`, `$attachment_id`, `$page_id` |
| `egps_password_min_length` | `8` | Minimum event password length |
| `egps_cookie_expiry_duration` | `30 * DAY_IN_SECONDS` | Guest cookie lifetime in seconds |
| `egps_cookie_expiry` | Computed expiry timestamp | Filters the cookie expiration timestamp directly |
| `egps_honeypot_field_name` | `'email'` | Name of the honeypot form field for spam protection |
| `egps_gallery_query_args` | WP_Query args array | Gallery endpoint query arguments |
| `egps_photo_response` | Photo data array | Individual photo data in gallery API responses |
| `egps_archive_batch_size` | `50` | Number of attachments processed per ZIP generation batch |
| `egps_archive_directory` | `{uploads_basedir}/egps-archives` | Absolute path to the ZIP archive storage directory |

### Actions

| Action | Description |
|--------|-------------|
| `egps_after_photo_upload` | Fires after a photo is uploaded and saved. Receives `$attachment_id`, `$page_id` |
| `egps_after_event_cleanup` | Fires after all event data is permanently deleted. Receives `$page_id`, `$summary` |

### Examples

**Limit uploads to 10 photos per guest:**

```php
add_filter( 'egps_max_uploads_per_guest', function () {
    return 10;
} );
```

**Enable photo moderation:**

```php
add_filter( 'egps_photo_requires_moderation', '__return_true' );
```

**Only allow JPEG uploads:**

```php
add_filter( 'egps_allowed_mime_types', function () {
    return array( 'image/jpeg' );
} );
```

**Send a notification when a photo is uploaded:**

```php
add_action( 'egps_after_photo_upload', function ( $attachment_id, $page_id ) {
    $guest = get_post_meta( $attachment_id, '_egps_guest_name', true );
    wp_mail( 'admin@example.com', 'New event photo', "$guest uploaded a photo to page $page_id." );
}, 10, 2 );
```

## Credits

- [QR Code Styling](https://github.com/kozakdenys/qr-code-styling) — QR code generator library, MIT license.

## License

GPL-2.0-or-later

# Event Guest Photos Sharing

A WordPress plugin that lets event guests share photos in a shared album — no account required.

Guests enter a shared password, provide their name, accept a consent message, then upload and browse photos in a real-time gallery.

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

The plugin registers a single block (`event-guest-photos-sharing/event-album`) and a REST API under the `event-guest-photos-sharing/v1` namespace.

### Source files

| File | Purpose |
|------|---------|
| `src/class-block.php` | Block registration and page template |
| `src/class-cookie.php` | HMAC-signed cookie management for guest sessions |
| `src/class-rest.php` | REST API endpoints (auth, upload, gallery, cleanup) |
| `src/class-upload.php` | File upload handling and MIME type validation |
| `src/class-admin.php` | Admin settings page with QR code generation and archive status (Settings > Event Guest Photos Sharing) |
| `src/class-archive.php` | Cron-based ZIP archive generation for completed event photos |
| `src/class-cleanup.php` | Permanent deletion of all event data (page, photos, archive) |
| `src/blocks/event-album/` | Block assets (edit.js, view.js, render.php, block.json, styles) |
| `src/admin/` | React app for the admin page (QR code generator, archive status, event cleanup, components, utilities, styles) |
| `templates/page-event-album.html` | Full-screen page template (no header, footer, or sidebar) |

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
4. The event page itself.

This action requires the `delete_post` capability for the specific page and cannot be undone. A browser confirmation dialog is shown before proceeding.

### Page template

The plugin registers an "Event Album (Full Screen)" page template (`page-event-album`) via `register_block_template()`. It shows only the site logo and page content — no header, footer, or sidebar — for a distraction-free photo browsing experience. Assign it to an event page in the site editor.

### Block attributes

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `password` | string | `""` | Event access password (auto-generated on first insertion) |
| `eventVersion` | integer | `1` | Incremented on password regeneration to invalidate old sessions |
| `dateRangeStart` | string | `""` | Upload start date (YYYY-MM-DD) |
| `dateRangeEnd` | string | `""` | Upload end date (YYYY-MM-DD) |
| `enableTableNames` | boolean | `false` | Show table/seating name input during registration |

### REST API endpoints

All endpoints are under the `event-guest-photos-sharing/v1` namespace.

| Method | Route | Description |
|--------|-------|-------------|
| POST | `/auth/{page_id}` | Password validation (`action=validate_password`), guest registration (`action=register`), and consent (`action=consent`) |
| POST | `/photos/{page_id}` | Photo upload (requires authenticated guest with consent) |
| GET | `/photos/{page_id}` | Gallery retrieval with pagination and polling support |
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

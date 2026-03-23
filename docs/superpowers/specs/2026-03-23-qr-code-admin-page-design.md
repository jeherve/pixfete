# QR Code Admin Page — Design Spec

## Overview

A React-powered admin page under `Tools > Event QR Codes` that lets site admins generate and download styled QR codes for event pages using the Event Photo Album block. The tool helps event planners prepare printed QR codes (e.g., one per table) ahead of time.

QR codes are generated client-side using the [`qr-code-styling`](https://www.npmjs.com/package/qr-code-styling) library. No new database tables or persistent settings are introduced.

### Known v1 Limitations

- **No batch download.** When `enableTableNames` is on, admins generating QR codes for many tables must download them one at a time. A "Download all as ZIP" feature may be added in a future iteration.

## Scope

This is a **dedicated QR code generation tool**, not a broader event dashboard. All event configuration remains in the block editor. This page is read-only with respect to event settings — it reads block attributes and page metadata to generate QR codes.

## User Flow

1. Admin navigates to `Tools > Event QR Codes`.
2. Selects an event page from a dropdown (only published pages containing the `event-guest-photos-sharing/event-album` block are listed).
3. Configures QR code options:
   - Which URL parameters to include (password, table name).
   - Whether to embed a logo.
   - Foreground and background colors.
   - Corner style.
4. Sees a live QR code preview update as they change settings.
5. Downloads the QR code as a PNG.

## Architecture

### New Files

| File | Purpose |
|---|---|
| `src/class-admin.php` | Registers admin page, enqueues scripts, gathers page data |
| `src/admin/index.js` | React entry point |
| `src/admin/components/AdminPage.js` | Top-level component, state management |
| `src/admin/components/PageSelector.js` | Event page dropdown |
| `src/admin/components/QrConfigPanel.js` | Settings controls (left column) |
| `src/admin/components/QrPreview.js` | Live QR preview + download button (right column) |

### Data Flow

```
PHP (page load)                          React (client-side)
─────────────────                        ─────────────────────
Query published pages                    AdminPage (state owner)
  └─ filter: has event-album block         ├─ PageSelector
  └─ extract per page:                     ├─ QrConfigPanel
       - id, title, slug, permalink        │    ├─ URL param checkboxes
       - password (block attr)             │    ├─ Table name input
       - enableTableNames (block attr)     │    ├─ Logo checkbox
       - logoDataUrl (base64, see below)   │    ├─ ColorPickers (fg/bg)
                                           │    └─ Corner style picker
  └─ wp_localize_script() ──────────────►  └─ QrPreview
                                                ├─ qr-code-styling instance
                                                ├─ Encoded URL display
                                                └─ Download PNG button
```

No additional REST endpoints are needed. All data is resolved server-side at page load and passed via `wp_localize_script()`.

### Build Integration

- New webpack entry point: `src/admin/index.js` → `build/admin/index.js`.
- The project currently has no `webpack.config.js` — it relies on `@wordpress/scripts` defaults. To add a second entry point, create `webpack.config.js` at the project root that extends the default config and adds `admin: './src/admin/index.js'` as an entry.
- `qr-code-styling` is installed via npm and bundled into the admin script. No CDN.
- Scripts are only enqueued on the plugin's own admin page (gated by `$hook_suffix`).

### Plugin Wiring

`class-admin.php` must be `require_once`'d in `event-guest-photos-sharing.php` alongside the other four classes. The `admin_menu` hook registration follows the existing pattern (hook in the main plugin file, callback on the class). The block attribute extraction logic should reuse the existing `parse_blocks()` recursive search pattern from `REST::validate_page()` rather than reimplementing it.

### Logo Resolution

The logo image (featured image → site icon → null) is resolved server-side and **converted to a base64 data URL** before being passed via `wp_localize_script()`. This avoids CORS/canvas-taint issues that would occur if `qr-code-styling` tried to load a cross-origin image URL (e.g., from a CDN) — the canvas `.toBlob()` / `.download()` call would fail silently. Converting server-side ensures the logo works regardless of image hosting configuration.

## Admin Page Registration

- Hook: `admin_menu`
- Function: `add_management_page()`
- Page title: "Event QR Codes"
- Menu title: "Event QR Codes"
- Capability: `manage_options`
- Menu slug: `event-qr-codes`
- Render callback: outputs a `<div id="egps-qr-admin"></div>` mount point.
- Script handle: `egps-qr-admin` — used with `wp_enqueue_script()` and `wp_localize_script()` (object name: `egpsQrAdmin`).

## UI Layout

Two-column layout inside a standard wp-admin page:

### Top: Page Selector

A `SelectControl` dropdown listing all event pages by title. Selecting a page loads its attributes into the config panel.

### Left Column: QR Config Panel

**URL Parameters**
- **Include password** (checkbox) — when checked, appends `?key=<password>` to the encoded URL, allowing guests to skip the password entry step.
- **Include table name** (checkbox + text input) — only visible when the selected event page has `enableTableNames` enabled in its block attributes. When checked, shows a text input for the table name and appends `&table=<name>` to the URL.

**Logo**
- **Include logo** (checkbox, default: checked) — embeds an image in the QR code center. The image is auto-resolved: page featured image if set, otherwise the site icon. Hidden when neither is available.

**Colors**
- **Foreground color** — `ColorPicker` component, default `#1d2327`.
- **Background color** — `ColorPicker` component, default `#ffffff`.

**Corner Style**
- Visual picker with 3 options: square, dot, extra-rounded. Each shown as a clickable swatch. Maps to `qr-code-styling`'s `cornersSquareOptions.type` and `cornersDotOptions.type`. These are the three valid corner types in `qr-code-styling`.

**Dot style** is always `rounded` — not exposed in the UI.

### Right Column: QR Preview

- Live QR code rendered by `qr-code-styling` into a container div.
- Below the QR code: the full encoded URL displayed as text.
- **Download PNG** button — calls `qr-code-styling`'s `.download()` method with PNG format. Filename pattern: `event-qr-{page-slug}.png`, or `event-qr-{page-slug}-{table-name}.png` when a table name is included (spaces replaced with hyphens, lowercased).

## QR Code Configuration

Mapped to `qr-code-styling` options:

| Setting | `qr-code-styling` option | Value |
|---|---|---|
| Foreground color | `dotsOptions.color` | User-selected |
| Background color | `backgroundOptions.color` | User-selected |
| Dot style | `dotsOptions.type` | Always `"rounded"` |
| Corner square style | `cornersSquareOptions.type` | User-selected: `"square"`, `"extra-rounded"`, `"dot"` |
| Corner dot style | `cornersDotOptions.type` | Matches corner square selection |
| Logo | `image` | Base64 data URL (from featured image or site icon), or omitted |
| Error correction | `qrOptions.errorCorrectionLevel` | `"Q"` (25% recovery, needed for logo) |
| Size | `width` / `height` | `300` (reasonable default for print) |

## URL Construction

The encoded URL is built from the selected page's permalink plus optional query parameters:

```
{permalink}                                          # base (always)
{permalink}?key={password}                           # + password
{permalink}?key={password}&table={tableName}         # + password + table
{permalink}?table={tableName}                        # + table only (unusual but allowed)
```

Query parameter values are URL-encoded via `encodeURIComponent()`.

## Edge Cases

| Scenario | Behavior |
|---|---|
| No event pages exist | Empty state: "No pages with the Event Photo Album block were found." with link to create a new page. |
| No logo available (no featured image, no site icon) | "Include logo" checkbox is hidden. |
| `enableTableNames` is off for selected page | "Include table name" checkbox is hidden. |
| Long URLs | Error correction level "Q" handles this. `qr-code-styling` manages module density automatically. |
| Page has empty password | "Include password" checkbox is visible but disabled with a note: "No password set for this event." |

## Capability & Security

- Page requires `manage_options` capability (standard for `add_management_page()`).
- No data is written — the page is entirely read-only.
- Passwords are already stored in block attributes (post content); displaying them on the admin page does not introduce new exposure since the admin already has access to the block editor.

## Testing

### PHP

- `class-admin.php`: test that the admin page is registered under the correct menu.
- Data gathering: test that block attributes (password, enableTableNames) are correctly extracted from pages containing the event-album block.
- Logo resolution: test featured image → site icon → null fallback chain, including base64 data URL conversion.
- Test that scripts are only enqueued on the correct admin page.

### JavaScript

- URL builder: test all checkbox combinations produce correct URLs.
- Download filename: test filename generation with and without table name.
- Component rendering: test that conditional controls (table name, logo) show/hide based on page attributes and checkbox state.
- QR preview: test that `qr-code-styling` is instantiated with correct options when settings change.

## Dependencies

### New npm dependency

- `qr-code-styling` — client-side QR code generation with styling support.

### No new PHP dependencies

All server-side logic uses existing WordPress APIs (`get_posts`, `parse_blocks`, `get_the_post_thumbnail_url`, `get_site_icon_url`).

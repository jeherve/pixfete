# QR Code Admin Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a React-powered admin page under Tools > Event QR Codes that lets admins generate styled, downloadable QR codes for event pages.

**Architecture:** A new `Admin` PHP class registers the page and gathers event page data (block attributes, logo as base64). A React app with `@wordpress/components` renders the UI. QR codes are generated client-side via `qr-code-styling`. No REST endpoints or persistent storage.

**Tech Stack:** PHP 8.3, React, `@wordpress/scripts`, `@wordpress/components`, `qr-code-styling`, Brain Monkey (PHP tests), `@wordpress/scripts test-unit-js` (JS tests)

**Spec:** `docs/superpowers/specs/2026-03-23-qr-code-admin-page-design.md`

---

## File Structure

| File | Responsibility |
|---|---|
| `webpack.config.js` (create) | Extends `@wordpress/scripts` default config to add `admin` entry point |
| `src/class-admin.php` (create) | Registers admin page, enqueues scripts, gathers page data, resolves logos to base64 |
| `src/admin/index.js` (create) | React entry point — renders `<AdminPage />` into `#egps-qr-admin` |
| `src/admin/components/AdminPage.js` (create) | Top-level component, owns all state, two-column layout |
| `src/admin/components/PageSelector.js` (create) | `SelectControl` dropdown for event pages |
| `src/admin/components/QrConfigPanel.js` (create) | Checkboxes, table name input, color pickers, corner style picker |
| `src/admin/components/QrPreview.js` (create) | Live QR code preview + download button |
| `src/admin/utils/build-url.js` (create) | Pure function: builds QR URL from page data + checkbox state |
| `src/admin/utils/build-filename.js` (create) | Pure function: generates download filename from slug + table name |
| `src/admin/utils/build-qr-options.js` (create) | Pure function: maps UI state to `qr-code-styling` config object |
| `tests/src/AdminTest.php` (create) | PHP unit tests for `Admin` class |
| `src/admin/utils/__tests__/build-url.test.js` (create) | JS unit tests for URL builder |
| `src/admin/utils/__tests__/build-filename.test.js` (create) | JS unit tests for filename builder |
| `src/admin/utils/__tests__/build-qr-options.test.js` (create) | JS unit tests for QR options builder |
| `event-guest-photos-sharing.php` (modify) | Add `require_once` and `admin_menu` hook for `Admin` class |
| `tests/bootstrap.php` (modify) | Add `require_once` for `class-admin.php` |

---

### Task 1: Build Setup — webpack config and qr-code-styling dependency

**Files:**
- Create: `webpack.config.js`
- Modify: `package.json` (via npm install)

- [ ] **Step 1: Install qr-code-styling**

```bash
cd /Users/jeherve/code/plugins/event-guest-photos-sharing
npm install qr-code-styling --save
```

Expected: `qr-code-styling` added to `dependencies` in `package.json`.

- [ ] **Step 2: Create webpack.config.js**

Create `webpack.config.js` at project root:

```js
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

const adminEntry = { admin: path.resolve( __dirname, 'src/admin/index.js' ) };

// With --experimental-modules, defaultConfig is an array [scriptConfig, moduleConfig].
// We only add the admin entry to the script config (index 0).
if ( Array.isArray( defaultConfig ) ) {
	module.exports = [
		{
			...defaultConfig[ 0 ],
			entry: { ...defaultConfig[ 0 ].entry(), ...adminEntry },
		},
		defaultConfig[ 1 ],
	];
} else {
	module.exports = {
		...defaultConfig,
		entry: { ...defaultConfig.entry(), ...adminEntry },
	};
}
```

- [ ] **Step 3: Create a minimal src/admin/index.js placeholder**

```js
// Placeholder — will be replaced in Task 6.
console.log( 'EGPS QR Admin loaded' );
```

- [ ] **Step 4: Run build to verify both entry points compile**

```bash
npm run build
```

Expected: `build/admin/index.js` and `build/admin/index.asset.php` are generated alongside the existing `build/blocks/event-album/` output.

- [ ] **Step 5: Commit**

```bash
git add webpack.config.js src/admin/index.js package.json package-lock.json
git commit --no-gpg-sign -m "Add webpack config with admin entry point and qr-code-styling dependency"
```

---

### Task 2: PHP Admin Class — page registration and rendering

**Files:**
- Create: `src/class-admin.php`
- Create: `tests/src/AdminTest.php`
- Modify: `event-guest-photos-sharing.php`
- Modify: `tests/bootstrap.php`

- [ ] **Step 1: Write failing tests for admin page registration**

Create `tests/src/AdminTest.php`:

```php
<?php
declare( strict_types=1 );

namespace Jeherve\Event_Guest_Photos_Sharing\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Jeherve\Event_Guest_Photos_Sharing\Admin;
use PHPUnit\Framework\TestCase;

class AdminTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_menu_calls_add_management_page(): void {
		$captured_slug = null;
		$captured_capability = null;

		Functions\expect( 'add_management_page' )
			->once()
			->withArgs(
				function ( $page_title, $menu_title, $capability, $slug, $callback ) use ( &$captured_slug, &$captured_capability ) {
					$captured_slug       = $slug;
					$captured_capability = $capability;
					return true;
				}
			)
			->andReturn( 'tools_page_event-qr-codes' );

		Admin::register_menu();

		$this->assertSame( 'event-qr-codes', $captured_slug );
		$this->assertSame( 'manage_options', $captured_capability );
	}

	public function test_render_page_outputs_mount_point(): void {
		ob_start();
		Admin::render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="egps-qr-admin"', $output );
		$this->assertStringContainsString( '<div', $output );
	}
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
composer phpunit -- --filter AdminTest
```

Expected: FAIL — `Admin` class methods not found.

- [ ] **Step 3: Create src/class-admin.php with registration and render**

```php
<?php
declare( strict_types=1 );

namespace Jeherve\Event_Guest_Photos_Sharing;

defined( 'ABSPATH' ) || exit;

class Admin {

	private const MENU_SLUG    = 'event-qr-codes';
	private const SCRIPT_HANDLE = 'egps-qr-admin';

	public static function register_menu(): void {
		add_management_page(
			__( 'Event QR Codes', 'event-guest-photos-sharing' ),
			__( 'Event QR Codes', 'event-guest-photos-sharing' ),
			'manage_options',
			self::MENU_SLUG,
			array( static::class, 'render_page' )
		);
	}

	public static function render_page(): void {
		echo '<div class="wrap"><div id="egps-qr-admin"></div></div>';
	}
}
```

- [ ] **Step 4: Wire up in main plugin file**

In `event-guest-photos-sharing.php`, add after the existing `require_once` lines:

```php
require_once EGPS_PLUGIN_DIR . 'src/class-admin.php';
```

Add after the existing `add_action` lines:

```php
add_action( 'admin_menu', array( \Jeherve\Event_Guest_Photos_Sharing\Admin::class, 'register_menu' ) );
```

- [ ] **Step 5: Wire up in test bootstrap**

In `tests/bootstrap.php`, add alongside the other source file `require_once` calls near the end of the file:

```php
require_once dirname( __DIR__ ) . '/src/class-admin.php';
```

Also add an `EGPS_VERSION` constant if not already defined (needed by the `enqueue_scripts` fallback):

```php
if ( ! defined( 'EGPS_VERSION' ) ) {
	define( 'EGPS_VERSION', '1.0.0-alpha' );
}
```

Add this alongside the other constant definitions near the top of the bootstrap file (after `EGPS_PLUGIN_DIR`).

- [ ] **Step 6: Run tests to verify they pass**

```bash
composer phpunit -- --filter AdminTest
```

Expected: 2 tests pass.

- [ ] **Step 7: Commit**

```bash
git add src/class-admin.php tests/src/AdminTest.php event-guest-photos-sharing.php tests/bootstrap.php
git commit --no-gpg-sign -m "Add Admin class with page registration under Tools menu"
```

---

### Task 3: PHP Admin Class — script enqueueing and page data gathering

**Files:**
- Modify: `src/class-admin.php`
- Modify: `tests/src/AdminTest.php`

- [ ] **Step 1: Write failing test for enqueue_scripts gating**

Add to `AdminTest.php`:

```php
public function test_enqueue_scripts_skips_wrong_page(): void {
	Functions\expect( 'wp_enqueue_script' )->never();
	Functions\expect( 'wp_localize_script' )->never();

	Admin::enqueue_scripts( 'edit.php' );
}

public function test_enqueue_scripts_runs_on_correct_page(): void {
	// Stub that no pages have the block.
	Functions\expect( 'get_posts' )
		->once()
		->andReturn( array() );

	Functions\expect( 'wp_enqueue_script' )
		->once()
		->withArgs(
			function ( $handle ) {
				return $handle === 'egps-qr-admin';
			}
		);

	Functions\expect( 'wp_localize_script' )
		->once()
		->withArgs(
			function ( $handle, $object_name, $data ) {
				return $handle === 'egps-qr-admin'
					&& $object_name === 'egpsQrAdmin'
					&& is_array( $data )
					&& array_key_exists( 'pages', $data );
			}
		);

	Admin::enqueue_scripts( 'tools_page_event-qr-codes' );
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
composer phpunit -- --filter AdminTest
```

Expected: FAIL — `enqueue_scripts` method not found.

- [ ] **Step 3: Implement enqueue_scripts method**

Add to `src/class-admin.php` in the `Admin` class:

```php
public static function enqueue_scripts( string $hook_suffix ): void {
	if ( 'tools_page_' . self::MENU_SLUG !== $hook_suffix ) {
		return;
	}

	$asset_file = EGPS_PLUGIN_DIR . 'build/admin/index.asset.php';
	$asset      = file_exists( $asset_file ) ? require $asset_file : array(
		'dependencies' => array(),
		'version'      => EGPS_VERSION,
	);

	wp_enqueue_script(
		self::SCRIPT_HANDLE,
		EGPS_PLUGIN_URL . 'build/admin/index.js',
		$asset['dependencies'],
		$asset['version'],
		true
	);

	wp_localize_script(
		self::SCRIPT_HANDLE,
		'egpsQrAdmin',
		array( 'pages' => self::get_event_pages() )
	);
}
```

- [ ] **Step 4: Implement get_event_pages method**

Add to `src/class-admin.php`:

```php
private static function get_event_pages(): array {
	$pages = get_posts(
		array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'numberposts' => -1,
		)
	);

	$event_pages = array();
	foreach ( $pages as $page ) {
		$attrs = REST::get_block_attributes( $page->ID );
		if ( null === $attrs ) {
			continue;
		}

		$event_pages[] = array(
			'id'               => $page->ID,
			'title'            => get_the_title( $page->ID ),
			'slug'             => $page->post_name,
			'permalink'        => get_permalink( $page->ID ),
			'password'         => $attrs['password'] ?? '',
			'enableTableNames' => $attrs['enableTableNames'] ?? false,
			'logoDataUrl'      => self::get_logo_data_url( $page->ID ),
		);
	}

	return $event_pages;
}
```

- [ ] **Step 5: Add `admin_enqueue_scripts` hook in main plugin file**

In `event-guest-photos-sharing.php`, add after the `admin_menu` hook:

```php
add_action( 'admin_enqueue_scripts', array( \Jeherve\Event_Guest_Photos_Sharing\Admin::class, 'enqueue_scripts' ) );
```

- [ ] **Step 6: Run tests to verify they pass**

```bash
composer phpunit -- --filter AdminTest
```

Expected: 4 tests pass.

- [ ] **Step 7: Commit**

```bash
git add src/class-admin.php tests/src/AdminTest.php event-guest-photos-sharing.php
git commit --no-gpg-sign -m "Add script enqueueing and event page data gathering to Admin class"
```

---

### Task 4: PHP Admin Class — logo resolution with base64 conversion

**Files:**
- Modify: `src/class-admin.php`
- Modify: `tests/src/AdminTest.php`

- [ ] **Step 1: Write failing tests for logo resolution**

Add to `AdminTest.php`:

```php
public function test_get_logo_data_url_returns_featured_image(): void {
	Functions\when( 'get_the_post_thumbnail_url' )->justReturn( '/tmp/egps-test-logo.png' );
	Functions\when( 'wp_get_upload_dir' )->justReturn( array( 'baseurl' => '', 'basedir' => '' ) );
	Functions\when( 'wp_check_filetype' )->justReturn( array( 'type' => 'image/png', 'ext' => 'png' ) );

	// Create a tiny valid PNG for the test.
	$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' );
	file_put_contents( '/tmp/egps-test-logo.png', $png );

	$result = Admin::get_logo_data_url( 1 );

	$this->assertStringStartsWith( 'data:image/', $result );

	unlink( '/tmp/egps-test-logo.png' );
}

public function test_get_logo_data_url_falls_back_to_site_icon(): void {
	Functions\when( 'get_the_post_thumbnail_url' )->justReturn( false );
	Functions\when( 'get_site_icon_url' )->justReturn( '/tmp/egps-test-icon.png' );
	Functions\when( 'wp_get_upload_dir' )->justReturn( array( 'baseurl' => '', 'basedir' => '' ) );
	Functions\when( 'wp_check_filetype' )->justReturn( array( 'type' => 'image/png', 'ext' => 'png' ) );

	$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' );
	file_put_contents( '/tmp/egps-test-icon.png', $png );

	$result = Admin::get_logo_data_url( 1 );

	$this->assertStringStartsWith( 'data:image/', $result );

	unlink( '/tmp/egps-test-icon.png' );
}

public function test_get_logo_data_url_returns_null_when_no_image(): void {
	Functions\when( 'get_the_post_thumbnail_url' )->justReturn( false );
	Functions\when( 'get_site_icon_url' )->justReturn( '' );

	$result = Admin::get_logo_data_url( 1 );

	$this->assertNull( $result );
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
composer phpunit -- --filter AdminTest::test_get_logo
```

Expected: FAIL — `get_logo_data_url` method not found or not public.

- [ ] **Step 3: Implement get_logo_data_url and image_url_to_data_url**

Add to `src/class-admin.php`:

```php
public static function get_logo_data_url( int $page_id ): ?string {
	$image_url = get_the_post_thumbnail_url( $page_id, 'thumbnail' );

	if ( ! $image_url ) {
		$image_url = get_site_icon_url();
	}

	if ( ! $image_url ) {
		return null;
	}

	return self::image_url_to_data_url( $image_url );
}

private static function image_url_to_data_url( string $url ): ?string {
	// Convert URL to local file path if it's on this server.
	$upload_dir = wp_get_upload_dir();
	$local_path = null;

	if ( ! empty( $upload_dir['baseurl'] ) && str_starts_with( $url, $upload_dir['baseurl'] ) ) {
		$local_path = $upload_dir['basedir'] . substr( $url, strlen( $upload_dir['baseurl'] ) );
	} elseif ( str_starts_with( $url, '/' ) && file_exists( $url ) ) {
		$local_path = $url;
	}

	if ( $local_path && file_exists( $local_path ) ) {
		$contents = file_get_contents( $local_path );
	} else {
		$response = wp_remote_get( $url );
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return null;
		}
		$contents = wp_remote_retrieve_body( $response );
	}

	if ( empty( $contents ) ) {
		return null;
	}

	$filetype = wp_check_filetype( $url );
	$mime     = $filetype['type'] ?? 'image/png';
	return 'data:' . $mime . ';base64,' . base64_encode( $contents );
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
composer phpunit -- --filter AdminTest
```

Expected: 7 tests pass.

- [ ] **Step 5: Commit**

```bash
git add src/class-admin.php tests/src/AdminTest.php
git commit --no-gpg-sign -m "Add logo resolution with base64 data URL conversion"
```

---

### Task 5: JS Utility Functions — URL builder, filename builder, QR options builder

**Files:**
- Create: `src/admin/utils/build-url.js`
- Create: `src/admin/utils/build-filename.js`
- Create: `src/admin/utils/build-qr-options.js`
- Create: `src/admin/utils/__tests__/build-url.test.js`
- Create: `src/admin/utils/__tests__/build-filename.test.js`
- Create: `src/admin/utils/__tests__/build-qr-options.test.js`

- [ ] **Step 1: Write failing tests for build-url**

Create `src/admin/utils/__tests__/build-url.test.js`:

```js
import { buildUrl } from '../build-url';

describe( 'buildUrl', () => {
	const basePage = {
		permalink: 'https://example.com/wedding/',
		password: 'secret123',
	};

	test( 'returns permalink when no options selected', () => {
		expect( buildUrl( basePage, { includePassword: false, includeTable: false, tableName: '' } ) )
			.toBe( 'https://example.com/wedding/' );
	} );

	test( 'appends key param when includePassword is true', () => {
		expect( buildUrl( basePage, { includePassword: true, includeTable: false, tableName: '' } ) )
			.toBe( 'https://example.com/wedding/?key=secret123' );
	} );

	test( 'appends table param when includeTable is true', () => {
		expect( buildUrl( basePage, { includePassword: false, includeTable: true, tableName: 'Table 5' } ) )
			.toBe( 'https://example.com/wedding/?table=Table%205' );
	} );

	test( 'appends both params when both selected', () => {
		expect( buildUrl( basePage, { includePassword: true, includeTable: true, tableName: 'Table 5' } ) )
			.toBe( 'https://example.com/wedding/?key=secret123&table=Table%205' );
	} );

	test( 'ignores table when tableName is empty', () => {
		expect( buildUrl( basePage, { includePassword: true, includeTable: true, tableName: '' } ) )
			.toBe( 'https://example.com/wedding/?key=secret123' );
	} );
} );
```

- [ ] **Step 2: Write failing tests for build-filename**

Create `src/admin/utils/__tests__/build-filename.test.js`:

```js
import { buildFilename } from '../build-filename';

describe( 'buildFilename', () => {
	test( 'generates filename from slug only', () => {
		expect( buildFilename( 'summer-wedding', '' ) )
			.toBe( 'event-qr-summer-wedding.png' );
	} );

	test( 'includes table name when provided', () => {
		expect( buildFilename( 'summer-wedding', 'Table 5' ) )
			.toBe( 'event-qr-summer-wedding-table-5.png' );
	} );

	test( 'lowercases and hyphenates table name', () => {
		expect( buildFilename( 'party', 'Head Table' ) )
			.toBe( 'event-qr-party-head-table.png' );
	} );

	test( 'handles empty slug', () => {
		expect( buildFilename( '', 'Table 1' ) )
			.toBe( 'event-qr-table-1.png' );
	} );
} );
```

- [ ] **Step 3: Write failing tests for build-qr-options**

Create `src/admin/utils/__tests__/build-qr-options.test.js`:

```js
import { buildQrOptions } from '../build-qr-options';

describe( 'buildQrOptions', () => {
	test( 'returns correct defaults', () => {
		const options = buildQrOptions( {
			data: 'https://example.com',
			fgColor: '#1d2327',
			bgColor: '#ffffff',
			cornerStyle: 'square',
			logoDataUrl: null,
		} );

		expect( options.data ).toBe( 'https://example.com' );
		expect( options.dotsOptions.type ).toBe( 'rounded' );
		expect( options.dotsOptions.color ).toBe( '#1d2327' );
		expect( options.backgroundOptions.color ).toBe( '#ffffff' );
		expect( options.cornersSquareOptions.type ).toBe( 'square' );
		expect( options.cornersDotOptions.type ).toBe( 'square' );
		expect( options.width ).toBe( 300 );
		expect( options.height ).toBe( 300 );
		expect( options.qrOptions.errorCorrectionLevel ).toBe( 'Q' );
		expect( options.image ).toBeUndefined();
	} );

	test( 'includes image and imageOptions when logoDataUrl is provided', () => {
		const options = buildQrOptions( {
			data: 'https://example.com',
			fgColor: '#000000',
			bgColor: '#ffffff',
			cornerStyle: 'dot',
			logoDataUrl: 'data:image/png;base64,abc',
		} );

		expect( options.image ).toBe( 'data:image/png;base64,abc' );
		expect( options.imageOptions.margin ).toBe( 4 );
		expect( options.imageOptions.imageSize ).toBe( 0.3 );
		expect( options.cornersSquareOptions.type ).toBe( 'dot' );
		expect( options.cornersDotOptions.type ).toBe( 'dot' );
	} );

	test( 'cornersDotOptions falls back to dot when cornerStyle is extra-rounded', () => {
		const options = buildQrOptions( {
			data: 'https://example.com',
			fgColor: '#000',
			bgColor: '#fff',
			cornerStyle: 'extra-rounded',
			logoDataUrl: null,
		} );

		expect( options.cornersSquareOptions.type ).toBe( 'extra-rounded' );
		expect( options.cornersDotOptions.type ).toBe( 'dot' );
	} );
} );
```

- [ ] **Step 4: Run all JS tests to verify they fail**

```bash
npm run test:unit -- --testPathPattern="src/admin/utils"
```

Expected: FAIL — modules not found.

- [ ] **Step 5: Implement build-url.js**

Create `src/admin/utils/build-url.js`:

```js
export function buildUrl( page, { includePassword, includeTable, tableName } ) {
	const params = [];

	if ( includePassword && page.password ) {
		params.push( 'key=' + encodeURIComponent( page.password ) );
	}

	if ( includeTable && tableName ) {
		params.push( 'table=' + encodeURIComponent( tableName ) );
	}

	return params.length
		? page.permalink + '?' + params.join( '&' )
		: page.permalink;
}
```

- [ ] **Step 6: Implement build-filename.js**

Create `src/admin/utils/build-filename.js`:

```js
export function buildFilename( slug, tableName ) {
	const parts = [ 'event-qr' ];

	if ( slug ) {
		parts.push( slug );
	}

	if ( tableName ) {
		parts.push(
			tableName
				.toLowerCase()
				.replace( /\s+/g, '-' )
		);
	}

	return parts.join( '-' ) + '.png';
}
```

- [ ] **Step 7: Implement build-qr-options.js**

Create `src/admin/utils/build-qr-options.js`:

```js
// cornersDotOptions only supports 'square' and 'dot' in qr-code-styling.
// When cornersSquareOptions is 'extra-rounded', fall back to 'dot' for cornersDot.
function getCornerDotType( cornerStyle ) {
	return cornerStyle === 'extra-rounded' ? 'dot' : cornerStyle;
}

export function buildQrOptions( { data, fgColor, bgColor, cornerStyle, logoDataUrl } ) {
	const options = {
		width: 300,
		height: 300,
		data,
		dotsOptions: {
			type: 'rounded',
			color: fgColor,
		},
		backgroundOptions: {
			color: bgColor,
		},
		cornersSquareOptions: {
			type: cornerStyle,
		},
		cornersDotOptions: {
			type: getCornerDotType( cornerStyle ),
		},
		qrOptions: {
			errorCorrectionLevel: 'Q',
		},
	};

	if ( logoDataUrl ) {
		options.image = logoDataUrl;
		options.imageOptions = {
			margin: 4,
			imageSize: 0.3,
		};
	}

	return options;
}
```

- [ ] **Step 8: Run JS tests to verify they pass**

```bash
npm run test:unit -- --testPathPattern="src/admin/utils"
```

Expected: All tests pass (3 suites, ~12 tests).

- [ ] **Step 9: Commit**

```bash
git add src/admin/utils/ src/admin/utils/__tests__/
git commit --no-gpg-sign -m "Add URL builder, filename builder, and QR options builder utilities with tests"
```

---

### Task 6: React Components — PageSelector and QrConfigPanel

**Files:**
- Create: `src/admin/components/PageSelector.js`
- Create: `src/admin/components/QrConfigPanel.js`

- [ ] **Step 1: Implement PageSelector component**

Create `src/admin/components/PageSelector.js`:

```js
import { SelectControl } from '@wordpress/components';

export function PageSelector( { pages, selectedPageId, onChange } ) {
	if ( ! pages.length ) {
		return null;
	}

	const options = pages.map( ( page ) => ( {
		label: page.title,
		value: String( page.id ),
	} ) );

	return (
		<div className="egps-qr-page-selector">
			<SelectControl
				label="Select Event Page"
				value={ String( selectedPageId ) }
				options={ options }
				onChange={ ( value ) => onChange( Number( value ) ) }
			/>
		</div>
	);
}
```

- [ ] **Step 2: Implement QrConfigPanel component**

Create `src/admin/components/QrConfigPanel.js`:

```js
import { CheckboxControl, TextControl, ColorPicker, Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const CORNER_STYLES = [
	{ value: 'square', label: __( 'Square', 'event-guest-photos-sharing' ) },
	{ value: 'dot', label: __( 'Dot', 'event-guest-photos-sharing' ) },
	{ value: 'extra-rounded', label: __( 'Extra Rounded', 'event-guest-photos-sharing' ) },
];

export function QrConfigPanel( { page, config, onConfigChange } ) {
	const update = ( key, value ) => onConfigChange( { ...config, [ key ]: value } );

	return (
		<div className="egps-qr-config-panel">
			<h2>{ __( 'QR Code Settings', 'event-guest-photos-sharing' ) }</h2>

			<fieldset>
				<legend>{ __( 'Include in URL', 'event-guest-photos-sharing' ) }</legend>

				{ page.password ? (
					<CheckboxControl
						label={ __( 'Password (skips password entry)', 'event-guest-photos-sharing' ) }
						checked={ config.includePassword }
						onChange={ ( v ) => update( 'includePassword', v ) }
					/>
				) : (
					<p className="egps-qr-no-password">
						{ __( 'No password set for this event.', 'event-guest-photos-sharing' ) }
					</p>
				) }

				{ page.enableTableNames && (
					<>
						<CheckboxControl
							label={ __( 'Table name', 'event-guest-photos-sharing' ) }
							checked={ config.includeTable }
							onChange={ ( v ) => update( 'includeTable', v ) }
						/>
						{ config.includeTable && (
							<TextControl
								label={ __( 'Table name', 'event-guest-photos-sharing' ) }
								value={ config.tableName }
								onChange={ ( v ) => update( 'tableName', v ) }
								placeholder={ __( 'e.g. Table 5', 'event-guest-photos-sharing' ) }
							/>
						) }
					</>
				) }
			</fieldset>

			{ page.logoDataUrl && (
				<fieldset>
					<legend>{ __( 'Logo', 'event-guest-photos-sharing' ) }</legend>
					<CheckboxControl
						label={ __( 'Include logo', 'event-guest-photos-sharing' ) }
						checked={ config.includeLogo }
						onChange={ ( v ) => update( 'includeLogo', v ) }
					/>
				</fieldset>
			) }

			<fieldset>
				<legend>{ __( 'Foreground Color', 'event-guest-photos-sharing' ) }</legend>
				<ColorPicker
					color={ config.fgColor }
					onChange={ ( v ) => update( 'fgColor', v ) }
					enableAlpha={ false }
				/>
			</fieldset>

			<fieldset>
				<legend>{ __( 'Background Color', 'event-guest-photos-sharing' ) }</legend>
				<ColorPicker
					color={ config.bgColor }
					onChange={ ( v ) => update( 'bgColor', v ) }
					enableAlpha={ false }
				/>
			</fieldset>

			<fieldset>
				<legend>{ __( 'Corner Style', 'event-guest-photos-sharing' ) }</legend>
				<div className="egps-qr-corner-styles">
					{ CORNER_STYLES.map( ( style ) => (
						<Button
							key={ style.value }
							variant={ config.cornerStyle === style.value ? 'primary' : 'secondary' }
							onClick={ () => update( 'cornerStyle', style.value ) }
						>
							{ style.label }
						</Button>
					) ) }
				</div>
			</fieldset>
		</div>
	);
}
```

- [ ] **Step 3: Run build to verify components compile**

```bash
npm run build
```

Expected: No build errors.

- [ ] **Step 4: Commit**

```bash
git add src/admin/components/PageSelector.js src/admin/components/QrConfigPanel.js
git commit --no-gpg-sign -m "Add PageSelector and QrConfigPanel React components"
```

---

### Task 7: React Components — QrPreview with qr-code-styling

**Files:**
- Create: `src/admin/components/QrPreview.js`

- [ ] **Step 1: Implement QrPreview component**

Create `src/admin/components/QrPreview.js`:

```js
import { useRef, useEffect, useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import QRCodeStyling from 'qr-code-styling';
import { buildQrOptions } from '../utils/build-qr-options';
import { buildFilename } from '../utils/build-filename';

export function QrPreview( { url, slug, tableName, config } ) {
	const containerRef = useRef( null );
	const qrRef = useRef( null );
	const [ isReady, setIsReady ] = useState( false );

	const qrOptions = buildQrOptions( {
		data: url,
		fgColor: config.fgColor,
		bgColor: config.bgColor,
		cornerStyle: config.cornerStyle,
		logoDataUrl: config.includeLogo ? config.logoDataUrl : null,
	} );

	useEffect( () => {
		if ( ! containerRef.current ) {
			return;
		}

		if ( ! qrRef.current ) {
			qrRef.current = new QRCodeStyling( qrOptions );
			// Clear existing content safely before appending QR code.
			while ( containerRef.current.firstChild ) {
				containerRef.current.removeChild( containerRef.current.firstChild );
			}
			qrRef.current.append( containerRef.current );
		} else {
			qrRef.current.update( qrOptions );
		}

		setIsReady( true );
	}, [ url, config.fgColor, config.bgColor, config.cornerStyle, config.includeLogo, config.logoDataUrl ] );

	const handleDownload = () => {
		if ( qrRef.current ) {
			const filename = buildFilename( slug, tableName );
			qrRef.current.download( { name: filename, extension: 'png' } );
		}
	};

	return (
		<div className="egps-qr-preview">
			<h2>{ __( 'Preview', 'event-guest-photos-sharing' ) }</h2>
			<div ref={ containerRef } className="egps-qr-preview-canvas" />
			<p className="egps-qr-preview-url">
				<code>{ url }</code>
			</p>
			<Button
				variant="primary"
				onClick={ handleDownload }
				disabled={ ! isReady }
			>
				{ __( 'Download PNG', 'event-guest-photos-sharing' ) }
			</Button>
		</div>
	);
}
```

- [ ] **Step 2: Run build to verify component compiles**

```bash
npm run build
```

Expected: No build errors.

- [ ] **Step 3: Commit**

```bash
git add src/admin/components/QrPreview.js
git commit --no-gpg-sign -m "Add QrPreview component with qr-code-styling integration"
```

---

### Task 8: React Components — AdminPage (top-level) and entry point

**Files:**
- Create: `src/admin/components/AdminPage.js`
- Modify: `src/admin/index.js`

- [ ] **Step 1: Implement AdminPage component**

Create `src/admin/components/AdminPage.js`:

```js
import { useState, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { PageSelector } from './PageSelector';
import { QrConfigPanel } from './QrConfigPanel';
import { QrPreview } from './QrPreview';
import { buildUrl } from '../utils/build-url';

const DEFAULT_CONFIG = {
	includePassword: true,
	includeTable: false,
	tableName: '',
	includeLogo: true,
	fgColor: '#1d2327',
	bgColor: '#ffffff',
	cornerStyle: 'square',
	logoDataUrl: null,
};

export function AdminPage() {
	/* global egpsQrAdmin */
	const { pages } = egpsQrAdmin;

	const [ selectedPageId, setSelectedPageId ] = useState(
		pages.length ? pages[ 0 ].id : null
	);
	const [ config, setConfig ] = useState( DEFAULT_CONFIG );

	const selectedPage = useMemo(
		() => pages.find( ( p ) => p.id === selectedPageId ),
		[ pages, selectedPageId ]
	);

	const handlePageChange = ( pageId ) => {
		setSelectedPageId( pageId );
		const page = pages.find( ( p ) => p.id === pageId );
		setConfig( {
			...DEFAULT_CONFIG,
			logoDataUrl: page?.logoDataUrl ?? null,
		} );
	};

	if ( ! pages.length ) {
		return (
			<div className="egps-qr-empty">
				<p>
					{ __( 'No pages with the Event Photo Album block were found.', 'event-guest-photos-sharing' ) }
				</p>
			</div>
		);
	}

	if ( ! selectedPage ) {
		return null;
	}

	// configWithLogo merges selectedPage.logoDataUrl into config so QrPreview
	// always has the logo available — even after config state updates from
	// QrConfigPanel which don't include logoDataUrl (it comes from the page,
	// not user input).
	const configWithLogo = {
		...config,
		logoDataUrl: selectedPage.logoDataUrl,
	};

	const url = buildUrl( selectedPage, config );

	return (
		<div className="egps-qr-admin">
			<PageSelector
				pages={ pages }
				selectedPageId={ selectedPageId }
				onChange={ handlePageChange }
			/>
			<div className="egps-qr-admin-columns">
				<QrConfigPanel
					page={ selectedPage }
					config={ configWithLogo }
					onConfigChange={ setConfig }
				/>
				<QrPreview
					url={ url }
					slug={ selectedPage.slug }
					tableName={ config.includeTable ? config.tableName : '' }
					config={ configWithLogo }
				/>
			</div>
		</div>
	);
}
```

- [ ] **Step 2: Update src/admin/index.js entry point**

Replace the placeholder content in `src/admin/index.js` with:

```js
import './style.css';
import { createRoot } from '@wordpress/element';
import { AdminPage } from './components/AdminPage';

const container = document.getElementById( 'egps-qr-admin' );
if ( container ) {
	const root = createRoot( container );
	root.render( <AdminPage /> );
}
```

Note: The `style.css` import is added here but the file will be created in Task 9. For now, comment out the import or create an empty `src/admin/style.css` placeholder.

- [ ] **Step 3: Create empty style.css placeholder**

Create `src/admin/style.css` with a single comment:

```css
/* Admin page styles — populated in Task 9. */
```

- [ ] **Step 4: Run build to verify everything compiles**

```bash
npm run build
```

Expected: No build errors. `build/admin/index.js` contains the full React app.

- [ ] **Step 5: Commit**

```bash
git add src/admin/components/AdminPage.js src/admin/index.js src/admin/style.css
git commit --no-gpg-sign -m "Add AdminPage component and wire up React entry point"
```

---

### Task 9: Admin page styles

**Files:**
- Modify: `src/admin/style.css`
- Modify: `src/class-admin.php`

- [ ] **Step 1: Write admin styles**

Replace contents of `src/admin/style.css`:

```css
.egps-qr-admin-columns {
	display: flex;
	gap: 16px;
	align-items: flex-start;
	margin-top: 16px;
}

.egps-qr-config-panel {
	flex: 1;
	background: #fff;
	border: 1px solid #c3c4c7;
	padding: 20px;
}

.egps-qr-config-panel h2 {
	font-size: 14px;
	font-weight: 600;
	margin: 0 0 16px;
	border-bottom: 1px solid #c3c4c7;
	padding-bottom: 8px;
}

.egps-qr-config-panel fieldset {
	border: none;
	padding: 0;
	margin: 0 0 20px;
}

.egps-qr-config-panel legend {
	font-size: 13px;
	font-weight: 600;
	color: #50575e;
	margin-bottom: 8px;
}

.egps-qr-corner-styles {
	display: flex;
	gap: 8px;
}

.egps-qr-no-password {
	font-style: italic;
	color: #50575e;
	font-size: 13px;
}

.egps-qr-preview {
	width: 320px;
	background: #fff;
	border: 1px solid #c3c4c7;
	padding: 20px;
	text-align: center;
}

.egps-qr-preview h2 {
	font-size: 14px;
	font-weight: 600;
	margin: 0 0 16px;
	border-bottom: 1px solid #c3c4c7;
	padding-bottom: 8px;
}

.egps-qr-preview-canvas {
	display: flex;
	justify-content: center;
	margin-bottom: 12px;
}

.egps-qr-preview-url code {
	font-size: 11px;
	word-break: break-all;
	display: block;
	margin-bottom: 16px;
}

.egps-qr-page-selector {
	background: #fff;
	border: 1px solid #c3c4c7;
	padding: 20px;
}

.egps-qr-empty {
	background: #fff;
	border: 1px solid #c3c4c7;
	padding: 40px;
	text-align: center;
}

@media ( max-width: 782px ) {
	.egps-qr-admin-columns {
		flex-direction: column;
	}

	.egps-qr-preview {
		width: 100%;
	}
}
```

- [ ] **Step 2: Enqueue the admin stylesheet in PHP**

Modify `src/class-admin.php` `enqueue_scripts` method — add after the `wp_enqueue_script` call:

```php
wp_enqueue_style(
	self::SCRIPT_HANDLE,
	EGPS_PLUGIN_URL . 'build/admin/style-index.css',
	array( 'wp-components' ),
	$asset['version']
);
```

- [ ] **Step 3: Run build and verify CSS is compiled**

```bash
npm run build
```

Expected: `build/admin/style-index.css` is generated. No errors.

- [ ] **Step 4: Commit**

```bash
git add src/admin/style.css src/class-admin.php
git commit --no-gpg-sign -m "Add admin page styles with responsive layout"
```

---

### Task 10: Lint, test, and final verification

**Files:** All files from previous tasks.

- [ ] **Step 1: Run PHP linting**

```bash
composer lint
```

Expected: No PHPCS violations (fix any that appear).

- [ ] **Step 2: Run PHP tests**

```bash
composer phpunit
```

Expected: All tests pass (existing + new AdminTest).

- [ ] **Step 3: Run JS linting**

```bash
npm run lint:js
```

Expected: No ESLint violations (fix any that appear).

- [ ] **Step 4: Run CSS linting**

```bash
npm run lint:css
```

Expected: No stylelint violations (fix any that appear).

- [ ] **Step 5: Run JS tests**

```bash
npm run test:unit
```

Expected: All JS tests pass.

- [ ] **Step 6: Run full build**

```bash
npm run build
```

Expected: Clean build with no warnings.

- [ ] **Step 7: Fix any issues found, commit fixes**

If any lint or test issues were found, fix them and commit:

```bash
git add -u
git commit --no-gpg-sign -m "Fix lint and test issues"
```

- [ ] **Step 8: Manual smoke test**

Start the dev environment and verify the admin page works:

```bash
npm run env:start
```

Navigate to `Tools > Event QR Codes` in wp-admin. Verify:
1. Page selector shows event pages (if any exist).
2. Empty state shows when no event pages exist.
3. QR code preview renders.
4. Changing colors/corners updates preview.
5. Download produces a valid PNG file.

- [ ] **Step 9: Final commit if any manual fixes needed**

```bash
git add -u
git commit --no-gpg-sign -m "Fix issues found during manual testing"
```

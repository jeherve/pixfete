# CLAUDE.md — Event Guest Photos Sharing

## Quick Reference

| What | Command |
|------|---------|
| PHP lint (PHPCS) | `composer run lint` |
| PHP lint autofix | `composer run lint:fix` |
| PHP compat check | `composer run compat` |
| PHPUnit tests | `composer run phpunit` |
| JS unit tests | `npm run test:unit` |
| E2E tests | `npm run test:e2e` |
| Build | `npm run build` |
| Dev (watch mode) | `npm run dev` |
| JS lint | `npm run lint:js` |
| CSS lint | `npm run lint:css` |
| Start local env | `npm run env:start` |

## Mandatory Before Committing

1. **Run `composer run phpunit`** — all PHP tests must pass.
2. **Run `npm run test:unit`** — all JS tests must pass.
3. **Run `composer run lint`** — zero PHPCS errors allowed. The ruleset is `Jetpack` (via `automattic/jetpack-codesniffer`), configured in `.phpcs.xml.dist`.
4. **Run `npm run lint:js` and `npm run lint:css`** — zero lint errors.
5. **Bug fixes must include a regression test** to prevent the issue from recurring.

## Architecture

- **Namespace**: `Jeherve\Event_Guest_Photos_Sharing` — all PHP classes use this namespace with `declare(strict_types=1)`.
- **No Composer autoloader for `src/`** — classes are manually `require_once`'d. When adding a new class, you must add it to both `event-guest-photos-sharing.php` and `tests/bootstrap.php`.
- **Hook prefix**: all custom filters and actions use the `egps_` prefix (e.g., `egps_allowed_mime_types`, `egps_after_photo_upload`).
- **Static class pattern**: `Block`, `Cookie`, and `Upload` use static methods. `REST` extends `WP_REST_Controller`.
- **ABSPATH guard**: every PHP file must include `defined( 'ABSPATH' ) || exit;` at the top.

## Testing Details

- PHPUnit tests live in `tests/src/` and follow the `*Test.php` naming convention.
- Tests use **Brain\Monkey** for WP function mocking — there is no real WordPress install in the test suite.
- A namespace-level `setcookie()` stub (`tests/stubs/setcookie-stub.php`) intercepts cookie calls during tests.
- E2E tests use **Playwright** against WP Playground on port 9400.

## Build & Frontend

- Build uses `--experimental-modules` — the frontend view script is an ES module (`viewScriptModule` in `block.json`), not a classic script.
- Block uses API version 3.

## Version Requirements

- **PHP**: 8.3+ (enforced in `composer.json`, plugin header, and PHPCS config).
- **WordPress**: 6.9+ (enforced in plugin header and `readme.txt`).

## PR Conventions

- Use the PR template (`.github/PULL_REQUEST_TEMPLATE.md`): reference the issue with `Fixes #`, describe changes, and include testing instructions.
- `readme.txt` is customer-facing (WordPress.org listing only). Developer docs go in `README.md`.

## Code Documentation

- **Every function and method must have a docblock** — be generous with documentation.
- Docblocks should explain **why** the code exists and what problem it solves, not just restate what the function does. The "what" is already in the code; the "why" is what future readers need.
- Include `@param`, `@return`, and `@throws` tags as appropriate.

## Files Excluded from Distribution

Dev files (tests, config, docs) are excluded from the WP.org release via `.distignore` and `.gitattributes`. If you add new dev-only files, add them to both.

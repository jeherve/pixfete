<?php
/**
 * Namespace-level setcookie stub for testing.
 *
 * Since the Cookie class calls setcookie() without a leading backslash,
 * PHP resolves it within the namespace first. This stub intercepts those
 * calls during tests and records the arguments for assertion.
 *
 * @package Jeherve\Pixfete
 */

declare( strict_types=1 );

namespace Jeherve\Pixfete;

/**
 * Captured setcookie call arguments (last call).
 *
 * @var array{name: string, value: string, options: array<string, mixed>}|null
 */
$GLOBALS['egps_setcookie_last_call'] = null;

/**
 * Namespace-level setcookie that records calls instead of sending headers.
 *
 * @param string               $name    Cookie name.
 * @param string               $value   Cookie value.
 * @param array<string, mixed> $options Cookie options array.
 * @return bool Always true.
 */
function setcookie( string $name, string $value = '', array $options = array() ): bool {
	$GLOBALS['egps_setcookie_last_call'] = array(
		'name'    => $name,
		'value'   => $value,
		'options' => $options,
	);
	return true;
}

<?php
/**
 * Tests for Pixfête PWA service worker routing.
 *
 * @package Jeherve\Pixfete
 */

declare( strict_types=1 );

namespace Jeherve\Pixfete;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Cover the PWA helper class.
 */
final class PwaTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The query var used to short-circuit the SW request.
	 */
	public function test_register_query_var_adds_pixfete_sw(): void {
		$result = PWA::register_query_var( array( 'foo' ) );
		$this->assertContains( 'pixfete_sw', $result );
	}

	/**
	 * The rewrite rule maps /pixfete-sw.js to the query var.
	 */
	public function test_register_rewrite_calls_add_rewrite_rule(): void {
		Functions\expect( 'add_rewrite_rule' )
			->once()
			->with( '^pixfete-sw\.js$', 'index.php?pixfete_sw=1', 'top' );

		PWA::register_rewrite();
		$this->assertTrue( true );
	}

	/**
	 * Non-matching requests fall through.
	 */
	public function test_maybe_serve_returns_early_when_query_var_absent(): void {
		Functions\when( 'get_query_var' )->justReturn( '' );
		Functions\expect( 'status_header' )->never();

		PWA::maybe_serve();
		$this->assertTrue( true );
	}
}

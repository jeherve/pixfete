<?php
/**
 * Tests for the Archive class.
 *
 * @package Jeherve\Event_Guest_Photos_Sharing
 */

declare( strict_types=1 );

namespace Jeherve\Event_Guest_Photos_Sharing\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Jeherve\Event_Guest_Photos_Sharing\Archive;
use PHPUnit\Framework\TestCase;

/**
 * Test archive ZIP generation and cron lifecycle.
 */
class ArchiveTest extends TestCase {

	/**
	 * Set up Brain Monkey before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	/**
	 * Tear down Brain Monkey after each test.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Test that get_archives returns the full option array.
	 */
	public function test_get_archives_returns_option(): void {
		$data = array(
			42 => array(
				'status'    => 'complete',
				'file_path' => '/var/www/uploads/egps-archives/egps-archive-42-abc123.zip',
				'url'       => 'https://example.com/uploads/egps-archives/egps-archive-42-abc123.zip',
				'token'     => 'abc123',
			),
		);
		Functions\expect( 'get_option' )
			->once()
			->with( 'egps_zip_archives', array() )
			->andReturn( $data );

		$this->assertSame( $data, Archive::get_archives() );
	}

	/**
	 * Test that get_archive returns a single entry by page ID.
	 */
	public function test_get_archive_returns_single_entry(): void {
		$entry = array(
			'status' => 'complete',
			'token'  => 'abc123',
		);
		Functions\expect( 'get_option' )
			->once()
			->with( 'egps_zip_archives', array() )
			->andReturn( array( 42 => $entry ) );

		$this->assertSame( $entry, Archive::get_archive( 42 ) );
	}

	/**
	 * Test that get_archive returns null for a missing page ID.
	 */
	public function test_get_archive_returns_null_for_missing(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'egps_zip_archives', array() )
			->andReturn( array() );

		$this->assertNull( Archive::get_archive( 99 ) );
	}

	/**
	 * Test that update_archive merges data into a single entry.
	 */
	public function test_update_archive_merges_data(): void {
		$existing = array(
			42 => array(
				'status' => 'pending',
				'token'  => 'abc123',
			),
		);

		Functions\expect( 'get_option' )
			->once()
			->with( 'egps_zip_archives', array() )
			->andReturn( $existing );

		Functions\expect( 'update_option' )
			->once()
			->withArgs(
				function ( $name, $value ) {
					return $name === 'egps_zip_archives'
						&& $value[42]['status'] === 'generating'
						&& $value[42]['token'] === 'abc123';
				}
			)
			->andReturn( true );

		Archive::update_archive( 42, array( 'status' => 'generating' ) );

		// Mockery enforces the ->once()->withArgs() expectations above; this confirms we reached here.
		$this->assertTrue( true );
	}

	/**
	 * Test that update_archive creates a new entry when none exists.
	 */
	public function test_update_archive_creates_new_entry(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'egps_zip_archives', array() )
			->andReturn( array() );

		Functions\expect( 'update_option' )
			->once()
			->withArgs(
				function ( $name, $value ) {
					return $name === 'egps_zip_archives'
						&& $value[42]['status'] === 'pending';
				}
			)
			->andReturn( true );

		Archive::update_archive( 42, array( 'status' => 'pending' ) );

		// Mockery enforces the ->once()->withArgs() expectations above; this confirms we reached here.
		$this->assertTrue( true );
	}

	/**
	 * Test that delete_archive removes an entry.
	 */
	public function test_delete_archive_removes_entry(): void {
		$existing = array(
			42 => array( 'status' => 'complete' ),
			87 => array( 'status' => 'complete' ),
		);

		Functions\expect( 'get_option' )
			->once()
			->with( 'egps_zip_archives', array() )
			->andReturn( $existing );

		Functions\expect( 'update_option' )
			->once()
			->withArgs(
				function ( $name, $value ) {
					return $name === 'egps_zip_archives'
						&& ! isset( $value[42] )
						&& isset( $value[87] )
						&& count( $value ) === 1;
				}
			)
			->andReturn( true );

		Archive::delete_archive( 42 );

		// Mockery enforces the ->once()->withArgs() expectations above; this confirms we reached here.
		$this->assertTrue( true );
	}
}

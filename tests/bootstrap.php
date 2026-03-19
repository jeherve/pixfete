<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package Jeherve\Event_Guest_Photos_Sharing
 */

declare( strict_types=1 );

// Define ABSPATH so source files don't bail.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

// WordPress constants used by the plugin.
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Minimal WP_Error stub so tests can construct instances without a full WP stack.
if ( ! class_exists( 'WP_Error' ) ) {
	// phpcs:ignore Generic.Files.OneClassPerFile.MultipleFound
	class WP_Error {
		/** @var string */
		private string $code;
		/** @var string */
		private string $message;

		/**
		 * Constructor.
		 *
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 */
		public function __construct( string $code = '', string $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
	}
}

// Load namespace-level stubs before source files so PHP resolves them
// within the plugin namespace during tests.
require_once __DIR__ . '/stubs/setcookie-stub.php';

// Load source files (no autoloader for src/).
require_once dirname( __DIR__ ) . '/src/class-cookie.php';
require_once dirname( __DIR__ ) . '/src/class-upload.php';

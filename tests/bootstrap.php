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

// WordPress constants used by the REST tests.
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

// Minimal WP_Error stub so tests can construct instances without a full WP stack.
if ( ! class_exists( 'WP_Error' ) ) {
	// phpcs:ignore Generic.Files.OneClassPerFile.MultipleFound
	class WP_Error {
		/** @var string */
		private string $code;
		/** @var string */
		private string $message;
		/** @var array */
		private array $error_data;

		/**
		 * Constructor.
		 *
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 * @param mixed  $data    Error data.
		 */
		public function __construct( string $code = '', string $message = '', mixed $data = array() ) {
			$this->code       = $code;
			$this->message    = $message;
			$this->error_data = is_array( $data ) ? $data : array();
		}

		/**
		 * Get error code.
		 *
		 * @return string
		 */
		public function get_error_code(): string {
			return $this->code;
		}

		/**
		 * Get error message.
		 *
		 * @return string
		 */
		public function get_error_message(): string {
			return $this->message;
		}

		/**
		 * Get error data.
		 *
		 * @return array
		 */
		public function get_error_data(): array {
			return $this->error_data;
		}
	}
}

// Minimal WP_REST_Controller stub for extending.
if ( ! class_exists( 'WP_REST_Controller' ) ) {
	// phpcs:ignore Generic.Files.OneClassPerFile.MultipleFound
	class WP_REST_Controller {}
}

// Minimal WP_REST_Request stub for tests.
if ( ! class_exists( 'WP_REST_Request' ) ) {
	// phpcs:ignore Generic.Files.OneClassPerFile.MultipleFound
	class WP_REST_Request {
		/** @var array */
		private array $params = array();
		/** @var array */
		private array $headers = array();

		/**
		 * Set a parameter.
		 *
		 * @param string $key   Parameter key.
		 * @param mixed  $value Parameter value.
		 */
		public function set_param( string $key, mixed $value ): void {
			$this->params[ $key ] = $value;
		}

		/**
		 * Get a parameter.
		 *
		 * @param string $key Parameter key.
		 * @return mixed|null
		 */
		public function get_param( string $key ): mixed {
			return $this->params[ $key ] ?? null;
		}

		/**
		 * Set a header.
		 *
		 * @param string $key   Header name.
		 * @param string $value Header value.
		 */
		public function set_header( string $key, string $value ): void {
			$this->headers[ strtolower( $key ) ] = $value;
		}

		/**
		 * Get a header.
		 *
		 * @param string $key Header name.
		 * @return string|null
		 */
		public function get_header( string $key ): ?string {
			return $this->headers[ strtolower( $key ) ] ?? null;
		}
	}
}

// Load namespace-level stubs before source files so PHP resolves them
// within the plugin namespace during tests.
require_once __DIR__ . '/stubs/setcookie-stub.php';

// Load source files (no autoloader for src/).
require_once dirname( __DIR__ ) . '/src/class-cookie.php';
require_once dirname( __DIR__ ) . '/src/class-upload.php';
require_once dirname( __DIR__ ) . '/src/class-rest.php';

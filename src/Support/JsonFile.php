<?php
/**
 * JSON file helper.
 *
 * The toolkit uses manifests for core and block operations. This small helper
 * provides consistent errors when a manifest or package.json is invalid.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Support;

use RuntimeException;

/**
 * Reads and writes pretty JSON files.
 */
final class JsonFile {
	/**
	 * Read a JSON file into an associative array.
	 *
	 * @return array<string,mixed>
	 */
	public function read( string $path ): array {
		if ( ! is_file( $path ) ) {
			throw new RuntimeException( sprintf( 'JSON file not found: %s', $path ) );
		}

		$decoded = json_decode( (string) file_get_contents( $path ), true );

		if ( ! is_array( $decoded ) ) {
			throw new RuntimeException(
				sprintf( 'Invalid JSON in %s: %s', $path, json_last_error_msg() )
			);
		}

		return $decoded;
	}

	/**
	 * Write an associative array as readable JSON.
	 *
	 * @param array<string,mixed> $data JSON data.
	 */
	public function write( string $path, array $data ): void {
		$json = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		if ( false === $json ) {
			throw new RuntimeException( sprintf( 'Could not encode JSON for %s.', $path ) );
		}

		file_put_contents( $path, $json . PHP_EOL );
	}
}

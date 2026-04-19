<?php
/**
 * Core manifest reader.
 *
 * The core updater writes only files declared by the manifest. This keeps
 * project-owned /inc/, templates, generic assets, and /blocks/ outside the
 * update surface.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Core;

use RuntimeException;
use SnailTheme\WPStarterToolkit\Support\JsonFile;
use SnailTheme\WPStarterToolkit\Support\PackagePaths;

/**
 * Provides typed access to resources/core/st-wp-core-manifest.json.
 */
final class CoreManifest {
	/**
	 * @param array<string,mixed> $data Raw manifest data.
	 */
	public function __construct(
		private readonly array $data,
		private readonly string $path,
		private readonly string $resourcesRoot
	) {}

	/**
	 * Load the bundled core manifest.
	 */
	public static function load(
		PackagePaths $paths = new PackagePaths(),
		JsonFile $json = new JsonFile()
	): self {
		$path = $paths->resources( 'core/st-wp-core-manifest.json' );

		return new self(
			$json->read( $path ),
			$path,
			$paths->resources( 'core/files' )
		);
	}

	/**
	 * Packaged core version.
	 */
	public function version(): string {
		return (string) ( $this->data['version'] ?? '0.0.0' );
	}

	/**
	 * Absolute path to the manifest file.
	 */
	public function path(): string {
		return $this->path;
	}

	/**
	 * Absolute path to packaged core files.
	 */
	public function filesRoot(): string {
		if ( ! is_dir( $this->resourcesRoot ) ) {
			throw new RuntimeException( sprintf( 'Core resource directory not found: %s', $this->resourcesRoot ) );
		}

		return $this->resourcesRoot;
	}

	/**
	 * Manifest-owned file patterns.
	 *
	 * @return string[]
	 */
	public function ownedPatterns(): array {
		return array_values( $this->data['owned_files'] ?? array() );
	}

	/**
	 * Files removed by the packaged version.
	 *
	 * @return string[]
	 */
	public function removedFiles(): array {
		return array_values( $this->data['removed_files'] ?? array() );
	}

	/**
	 * Public helpers expected by packaged resources.
	 *
	 * @return string[]
	 */
	public function requiredHelpers(): array {
		return array_values( $this->data['required_public_helpers'] ?? array() );
	}

	/**
	 * Declared file checksums.
	 *
	 * @return array<string,string>
	 */
	public function checksums(): array {
		return $this->data['checksums'] ?? array();
	}
}

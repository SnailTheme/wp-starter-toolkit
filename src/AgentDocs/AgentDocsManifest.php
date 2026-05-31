<?php
/**
 * Agent docs manifest reader.
 *
 * The toolkit owns the canonical development notes and can install them into a
 * theme as local-only files through the init command.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\AgentDocs;

use RuntimeException;
use SnailTheme\WPStarterToolkit\Support\JsonFile;
use SnailTheme\WPStarterToolkit\Support\PackagePaths;

/**
 * Provides typed access to resources/agent-docs/manifest.json.
 */
final class AgentDocsManifest {
	/**
	 * @param array<string,mixed> $data Raw manifest data.
	 */
	public function __construct(
		private readonly array $data,
		private readonly string $path,
		private readonly string $resourcesRoot
	) {}

	/**
	 * Load the bundled agent docs manifest.
	 */
	public static function load(
		PackagePaths $paths = new PackagePaths(),
		JsonFile $json = new JsonFile()
	): self {
		$path = $paths->resources( 'agent-docs/manifest.json' );

		return new self(
			$json->read( $path ),
			$path,
			$paths->resources( 'agent-docs/files' )
		);
	}

	/**
	 * Packaged docs version.
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
	 * Absolute path to packaged docs files.
	 */
	public function filesRoot(): string {
		if ( ! is_dir( $this->resourcesRoot ) ) {
			throw new RuntimeException( sprintf( 'Agent docs resource directory not found: %s', $this->resourcesRoot ) );
		}

		return $this->resourcesRoot;
	}

	/**
	 * Files managed by the agent docs installer.
	 *
	 * @return string[]
	 */
	public function files(): array {
		return array_values( $this->data['files'] ?? array() );
	}
}

<?php
/**
 * Component manifest reader.
 *
 * Components package reusable non-block behavior with one shared implementation
 * and a small style adapter selected from the theme's active UI profile.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Component;

use RuntimeException;
use SnailTheme\WPStarterToolkit\Support\JsonFile;
use SnailTheme\WPStarterToolkit\Support\PackagePaths;

/**
 * Provides typed access to a packaged component.
 */
final class ComponentManifest {
	/**
	 * @param array<string,mixed> $data Raw manifest data.
	 */
	public function __construct(
		private readonly array $data,
		private readonly string $root
	) {}

	/**
	 * Load a component package by slug.
	 */
	public static function load(
		string $component,
		PackagePaths $paths = new PackagePaths(),
		JsonFile $json = new JsonFile()
	): self {
		$root = $paths->resources( 'components/' . $component );
		$path = $root . DIRECTORY_SEPARATOR . 'st-component.json';

		if ( ! is_file( $path ) || ! is_dir( $root . DIRECTORY_SEPARATOR . 'files' ) ) {
			throw new RuntimeException( sprintf( 'Component "%s" was not found.', $component ) );
		}

		return new self( $json->read( $path ), $root );
	}

	public function slug(): string {
		return (string) ( $this->data['slug'] ?? '' );
	}

	public function title(): string {
		return (string) ( $this->data['title'] ?? $this->slug() );
	}

	public function description(): string {
		return (string) ( $this->data['description'] ?? '' );
	}

	public function version(): string {
		return (string) ( $this->data['version'] ?? '0.0.0' );
	}

	/**
	 * Minimum shared core version needed to load this component correctly.
	 */
	public function requiredCoreVersion(): string {
		return (string) ( $this->data['required_core_version'] ?? '' );
	}

	public function filesRoot(): string {
		return $this->root . DIRECTORY_SEPARATOR . 'files';
	}

}

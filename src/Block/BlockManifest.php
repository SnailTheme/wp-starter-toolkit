<?php
/**
 * Block manifest reader.
 *
 * Curated blocks are installed from resources/blocks/<slug>. Each block keeps
 * its own manifest so the installer does not guess dependencies or replacement
 * rules from file contents.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Block;

use RuntimeException;
use SnailTheme\WPStarterToolkit\Support\JsonFile;
use SnailTheme\WPStarterToolkit\Support\PackagePaths;

/**
 * Provides access to a packaged block manifest.
 */
final class BlockManifest {
	/**
	 * @param array<string,mixed> $data Raw manifest data.
	 */
	public function __construct(
		private readonly array $data,
		private readonly string $path,
		private readonly string $filesRoot
	) {}

	/**
	 * Load a block manifest by source slug.
	 */
	public static function load(
		string $sourceBlock,
		PackagePaths $paths = new PackagePaths(),
		JsonFile $json = new JsonFile()
	): self {
		$path      = $paths->resources( 'blocks/' . $sourceBlock . '/st-block.json' );
		$filesRoot = $paths->resources( 'blocks/' . $sourceBlock . '/files' );

		if ( ! is_dir( $filesRoot ) ) {
			throw new RuntimeException( sprintf( 'Block files not found for "%s".', $sourceBlock ) );
		}

		return new self( $json->read( $path ), $path, $filesRoot );
	}

	/**
	 * Raw manifest data.
	 *
	 * @return array<string,mixed>
	 */
	public function data(): array {
		return $this->data;
	}

	/**
	 * Source block slug.
	 */
	public function sourceSlug(): string {
		return (string) ( $this->data['source_slug'] ?? '' );
	}

	/**
	 * Default block title.
	 */
	public function defaultTitle(): string {
		return (string) ( $this->data['default_title'] ?? $this->sourceSlug() );
	}

	/**
	 * Absolute manifest path.
	 */
	public function path(): string {
		return $this->path;
	}

	/**
	 * Absolute packaged files root.
	 */
	public function filesRoot(): string {
		return $this->filesRoot;
	}

	/**
	 * Absolute path to theme-root files bundled with the block package.
	 */
	public function themeFilesRoot(): string {
		return dirname( $this->path ) . DIRECTORY_SEPARATOR . 'theme-files';
	}

	/**
	 * Required npm dependencies.
	 *
	 * @return array<string,string>
	 */
	public function npmDependencies(): array {
		return $this->data['npm_dependencies'] ?? array();
	}

	/**
	 * Shared asset declarations.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function sharedAssets(): array {
		return $this->data['shared_assets'] ?? array();
	}

	/**
	 * Required core version constraint.
	 */
	public function requiredCoreVersion(): string {
		return (string) ( $this->data['required_core_version'] ?? '' );
	}

	/**
	 * Required PHP helpers.
	 *
	 * @return string[]
	 */
	public function requiredHelpers(): array {
		return array_values( $this->data['required_php_helpers'] ?? array() );
	}
}

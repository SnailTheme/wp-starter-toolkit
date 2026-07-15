<?php
/**
 * UI profile manifest reader.
 *
 * Profiles are source scaffolds, not permanently toolkit-owned theme files.
 * Their manifests describe the initial files, build integration, and npm
 * dependencies needed to establish a visual starting point.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\UI;

use RuntimeException;
use SnailTheme\WPStarterToolkit\Support\JsonFile;
use SnailTheme\WPStarterToolkit\Support\PackagePaths;

/**
 * Provides typed access to a packaged UI profile.
 */
final class UIProfileManifest {
	/**
	 * @param array<string,mixed> $data Raw manifest data.
	 */
	public function __construct(
		private readonly array $data,
		private readonly string $path,
		private readonly string $filesRoot
	) {}

	/**
	 * Load one profile by slug.
	 */
	public static function load(
		string $profile,
		PackagePaths $paths = new PackagePaths(),
		JsonFile $json = new JsonFile()
	): self {
		$root      = $paths->resources( 'ui-profiles/' . $profile );
		$manifest  = $root . DIRECTORY_SEPARATOR . 'st-ui.json';
		$filesRoot = $root . DIRECTORY_SEPARATOR . 'files';

		if ( ! is_file( $manifest ) || ! is_dir( $filesRoot ) ) {
			throw new RuntimeException( sprintf( 'UI profile "%s" was not found.', $profile ) );
		}

		return new self( $json->read( $manifest ), $manifest, $filesRoot );
	}

	/**
	 * Machine-readable profile slug.
	 */
	public function slug(): string {
		return (string) ( $this->data['slug'] ?? '' );
	}

	/**
	 * Human-readable profile title.
	 */
	public function title(): string {
		return (string) ( $this->data['title'] ?? $this->slug() );
	}

	/**
	 * Short profile description for list output.
	 */
	public function description(): string {
		return (string) ( $this->data['description'] ?? '' );
	}

	/**
	 * Profile resource version.
	 */
	public function version(): string {
		return (string) ( $this->data['version'] ?? '0.0.0' );
	}

	/**
	 * Absolute root containing theme-relative profile files.
	 */
	public function filesRoot(): string {
		return $this->filesRoot;
	}

	/**
	 * Development-only npm dependencies needed by the profile.
	 *
	 * @return array<string,string>
	 */
	public function npmDevDependencies(): array {
		return $this->data['npm_dev_dependencies'] ?? array();
	}

	/**
	 * Native CSS entry points consumed by Vite.
	 *
	 * @return string[]
	 */
	public function cssEntries(): array {
		return array_values( $this->data['css_entries'] ?? array() );
	}

	/**
	 * Optional Vite integrations activated by the profile state.
	 *
	 * @return string[]
	 */
	public function vitePlugins(): array {
		return array_values( $this->data['vite_plugins'] ?? array() );
	}
}

<?php
/**
 * package.json inspector.
 *
 * Block installation reports missing npm dependencies but never edits
 * package.json and never runs npm install. That keeps the command safe across
 * npm, pnpm, yarn, and CI setups.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Block;

use SnailTheme\WPStarterToolkit\Support\JsonFile;
use SnailTheme\WPStarterToolkit\Theme\ThemeContext;

/**
 * Checks whether a theme already declares required npm dependencies.
 */
final class PackageJsonInspector {
	public function __construct(
		private readonly JsonFile $json = new JsonFile()
	) {}

	/**
	 * Return dependency names missing from package.json.
	 *
	 * @param array<string,string> $requiredDependencies Required package versions.
	 *
	 * @return array<string,string>
	 */
	public function missing( ThemeContext $theme, array $requiredDependencies ): array {
		$path = $theme->resolve( 'package.json' );

		if ( ! is_file( $path ) ) {
			return $requiredDependencies;
		}

		$package      = $this->json->read( $path );
		$dependencies = array_merge(
			$package['dependencies'] ?? array(),
			$package['devDependencies'] ?? array()
		);

		return array_filter(
			$requiredDependencies,
			static fn ( string $version, string $name ): bool => ! array_key_exists( $name, $dependencies ),
			ARRAY_FILTER_USE_BOTH
		);
	}
}

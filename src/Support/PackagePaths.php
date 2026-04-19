<?php
/**
 * Package path helper.
 *
 * Keeps command classes independent from the physical location of this package
 * inside a project. Composer can install it under vendor/, while local
 * development keeps it under ecosystem/st-toolkit.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Support;

/**
 * Resolves package-local resource paths.
 */
final class PackagePaths {
	/**
	 * Absolute package root path.
	 */
	public function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Resolve a package resource path.
	 *
	 * @param string $path Optional resource path relative to /resources.
	 */
	public function resources( string $path = '' ): string {
		$base = $this->root() . DIRECTORY_SEPARATOR . 'resources';

		if ( '' === $path ) {
			return $base;
		}

		return $base . DIRECTORY_SEPARATOR . ltrim( $path, '/\\' );
	}
}

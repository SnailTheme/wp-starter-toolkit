<?php
/**
 * Theme context value object.
 *
 * Commands use this object instead of global WordPress state. It contains only
 * static information parsed from files in the target theme.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Theme;

/**
 * Describes the target theme selected for a toolkit command.
 */
final class ThemeContext {
	/**
	 * @param array<string,string> $patterns Values parsed from ST_WP_CORE_THEME_PATTERNS.
	 */
	public function __construct(
		public readonly string $path,
		public readonly string $styleCssPath,
		public readonly string $bootstrapPath,
		public readonly string $coreVersion,
		public readonly array $patterns
	) {}

	/**
	 * Read a single theme pattern with a fallback.
	 */
	public function pattern( string $key, string $fallback = '' ): string {
		return $this->patterns[ $key ] ?? $fallback;
	}

	/**
	 * Resolve a path relative to the theme root.
	 */
	public function resolve( string $relativePath ): string {
		return $this->path . DIRECTORY_SEPARATOR . ltrim( $relativePath, '/\\' );
	}
}

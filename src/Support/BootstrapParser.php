<?php
/**
 * Static bootstrap parser.
 *
 * The updater must not bootstrap WordPress in v1. These parsers intentionally
 * support the simple define()/array shape used by ST WP Starter's core bootstrap.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Support;

use RuntimeException;

/**
 * Parses ST_WP_CORE_VERSION and ST_WP_CORE_THEME_PATTERNS from core/bootstrap.php.
 */
final class BootstrapParser {
	/**
	 * Parse the installed core version.
	 */
	public function parseCoreVersion( string $bootstrapPath ): string {
		$contents = $this->contents( $bootstrapPath );

		if ( preg_match( "/define\\(\\s*['\"]ST_WP_CORE_VERSION['\"]\\s*,\\s*['\"]([^'\"]+)['\"]\\s*\\)/", $contents, $matches ) ) {
			return $matches[1];
		}

		throw new RuntimeException( sprintf( 'Could not find ST_WP_CORE_VERSION in %s.', $bootstrapPath ) );
	}

	/**
	 * Parse string values from ST_WP_CORE_THEME_PATTERNS.
	 *
	 * @return array<string,string>
	 */
	public function parseThemePatterns( string $bootstrapPath ): array {
		$contents = $this->contents( $bootstrapPath );

		if ( ! str_contains( $contents, 'ST_WP_CORE_THEME_PATTERNS' ) ) {
			throw new RuntimeException( sprintf( 'Could not find ST_WP_CORE_THEME_PATTERNS in %s.', $bootstrapPath ) );
		}

		preg_match_all(
			"/['\"]([a-zA-Z0-9_]+)['\"]\\s*=>\\s*['\"]([^'\"]*)['\"]/",
			$contents,
			$matches,
			PREG_SET_ORDER
		);

		$patterns = array();

		foreach ( $matches as $match ) {
			$patterns[ $match[1] ] = $match[2];
		}

		foreach ( array( 'text_domain', 'function_names', 'constants' ) as $required ) {
			if ( empty( $patterns[ $required ] ) ) {
				throw new RuntimeException(
					sprintf( 'ST_WP_CORE_THEME_PATTERNS is missing required key "%s" in %s.', $required, $bootstrapPath )
				);
			}
		}

		$patterns['core_prefix']     = $patterns['core_prefix'] ?? 'st_wp_core_';
		$patterns['block_namespace'] = $patterns['block_namespace'] ?? 'stwp';
		$patterns['block_category']  = $patterns['block_category'] ?? $patterns['text_domain'];

		return $patterns;
	}

	/**
	 * Read a bootstrap file with a consistent error.
	 */
	private function contents( string $bootstrapPath ): string {
		if ( ! is_file( $bootstrapPath ) ) {
			throw new RuntimeException( sprintf( 'Bootstrap file not found: %s', $bootstrapPath ) );
		}

		return (string) file_get_contents( $bootstrapPath );
	}
}

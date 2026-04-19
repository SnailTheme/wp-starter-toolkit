<?php
/**
 * Minimal version constraint helper.
 *
 * Toolkit manifests currently need simple constraints such as >=1.0.0. This is
 * deliberately smaller than Composer's full constraint language.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Support;

/**
 * Evaluates simple version_compare() constraints.
 */
final class VersionConstraint {
	/**
	 * Return true when the installed version satisfies a simple constraint.
	 */
	public function satisfies( string $installedVersion, string $constraint ): bool {
		$constraint = trim( $constraint );

		if ( '' === $constraint ) {
			return true;
		}

		if ( ! preg_match( '/^(>=|>|<=|<|=|==)?\\s*(.+)$/', $constraint, $matches ) ) {
			return false;
		}

		$operator = $matches[1] ?: '>=';
		$version  = trim( $matches[2] );

		return version_compare( $installedVersion, $version, $operator );
	}
}

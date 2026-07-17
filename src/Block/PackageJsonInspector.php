<?php
/**
 * package.json inspector.
 *
 * Block installation uses this inspector before running npm. It keeps package
 * detection separate from package installation so dry runs can report exactly
 * what will happen before files are written.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Block;

use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;
use SnailTheme\WPStarterToolkit\Support\JsonFile;
use SnailTheme\WPStarterToolkit\Theme\ThemeContext;
use UnexpectedValueException;

/**
 * Checks whether a theme already declares required npm dependencies.
 */
final class PackageJsonInspector {
	public function __construct(
		private readonly JsonFile $json = new JsonFile(),
		private readonly VersionParser $versions = new VersionParser()
	) {}

	/**
	 * Return dependency requirements missing from or incompatible with package.json.
	 *
	 * A declared range must be contained by the toolkit requirement. For example,
	 * a profile requiring ^4.3.2 rejects ^3 and ^4.0 because either declaration
	 * may install a release that does not support the profile's integration.
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

		$unsatisfied = array();

		foreach ( $requiredDependencies as $name => $requiredVersion ) {
			$declaredVersion = $dependencies[ $name ] ?? null;

			if ( ! is_string( $declaredVersion ) || ! $this->supports( $declaredVersion, $requiredVersion ) ) {
				$unsatisfied[ $name ] = $requiredVersion;
			}
		}

		return $unsatisfied;
	}

	/**
	 * Check whether every version allowed locally is also allowed by the toolkit.
	 *
	 * Unsupported npm specifiers such as tags, URLs, or malformed ranges are
	 * treated as incompatible so installation can restore the manifest version.
	 */
	private function supports( string $declaredVersion, string $requiredVersion ): bool {
		$declaredVersion = trim( $declaredVersion );
		$requiredVersion = trim( $requiredVersion );

		if ( '' === $requiredVersion ) {
			return '' !== $declaredVersion;
		}

		if ( '' === $declaredVersion ) {
			return false;
		}

		try {
			return Intervals::isSubsetOf(
				$this->versions->parseConstraints( $declaredVersion ),
				$this->versions->parseConstraints( $requiredVersion )
			);
		} catch ( UnexpectedValueException ) {
			return false;
		}
	}
}

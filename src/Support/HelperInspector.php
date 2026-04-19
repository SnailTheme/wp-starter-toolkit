<?php
/**
 * Helper inspector.
 *
 * The toolkit cannot call theme functions because WordPress is not bootstrapped.
 * Instead, it performs a static scan for required helper declarations.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Support;

use SnailTheme\WPStarterToolkit\Theme\ThemeContext;
use Symfony\Component\Finder\Finder;

/**
 * Finds missing function declarations by static source scan.
 */
final class HelperInspector {
	/**
	 * Return helper names not declared in PHP files under the theme.
	 *
	 * @param string[] $helpers Required function names.
	 *
	 * @return string[]
	 */
	public function missing( ThemeContext $theme, array $helpers ): array {
		$found = array_fill_keys( $helpers, false );

		$finder = Finder::create()
			->files()
			->name( '*.php' )
			->exclude( array( 'vendor', 'node_modules' ) )
			->in( $theme->path );

		foreach ( $finder as $file ) {
			$contents = (string) file_get_contents( $file->getPathname() );

			foreach ( $found as $helper => $isFound ) {
				if ( $isFound ) {
					continue;
				}

				if ( preg_match( '/function\\s+' . preg_quote( $helper, '/' ) . '\\s*\\(/', $contents ) ) {
					$found[ $helper ] = true;
				}
			}
		}

		return array_keys(
			array_filter(
				$found,
				static fn ( bool $isFound ): bool => ! $isFound
			)
		);
	}
}

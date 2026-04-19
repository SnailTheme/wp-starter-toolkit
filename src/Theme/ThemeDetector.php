<?php
/**
 * Theme detector.
 *
 * Commands accept --theme, but local developers often run a tool from somewhere
 * inside the theme. This detector walks upward until it finds a WordPress theme
 * with the ST core bootstrap file.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Theme;

use RuntimeException;
use SnailTheme\WPStarterToolkit\Support\BootstrapParser;

/**
 * Finds and parses the target theme.
 */
final class ThemeDetector {
	public function __construct(
		private readonly BootstrapParser $parser = new BootstrapParser()
	) {}

	/**
	 * Detect a theme from --theme or by walking upward from the current directory.
	 */
	public function detect( ?string $themePath = null, ?string $startPath = null ): ThemeContext {
		$path = $themePath ?: ( $startPath ?: (string) getcwd() );

		if ( is_file( $path ) ) {
			$path = dirname( $path );
		}

		$path = realpath( $path );

		if ( false === $path ) {
			throw new RuntimeException( sprintf( 'Theme path does not exist: %s', (string) $themePath ) );
		}

		while ( true ) {
			$styleCss  = $path . DIRECTORY_SEPARATOR . 'style.css';
			$bootstrap = $path . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

			if ( is_file( $styleCss ) && is_file( $bootstrap ) ) {
				return new ThemeContext(
					$path,
					$styleCss,
					$bootstrap,
					$this->parser->parseCoreVersion( $bootstrap ),
					$this->parser->parseThemePatterns( $bootstrap )
				);
			}

			$parent = dirname( $path );

			if ( $parent === $path ) {
				break;
			}

			$path = $parent;
		}

		throw new RuntimeException( 'Could not locate a theme containing style.css and core/bootstrap.php.' );
	}
}

<?php
/**
 * PHP syntax validator.
 *
 * Core updates are prepared in temporary storage first. The toolkit validates
 * PHP before writing and again after writing so broken package resources fail
 * early and loudly.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Support;

use RuntimeException;
use Symfony\Component\Finder\Finder;

/**
 * Runs `php -l` without shell-specific command syntax.
 */
final class PhpValidator {
	/**
	 * Validate all PHP files inside a directory.
	 *
	 * @return string[] Validation messages.
	 */
	public function validateDirectory( string $directory ): array {
		if ( ! is_dir( $directory ) ) {
			return array();
		}

		$messages = array();
		$finder   = Finder::create()->files()->name( '*.php' )->in( $directory );

		foreach ( $finder as $file ) {
			$messages[] = $this->validateFile( $file->getPathname() );
		}

		return $messages;
	}

	/**
	 * Validate a single PHP file.
	 */
	public function validateFile( string $path ): string {
		$command = array( PHP_BINARY, '-l', $path );
		$pipes   = array();
		$process = proc_open(
			$command,
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);

		if ( ! is_resource( $process ) ) {
			throw new RuntimeException( sprintf( 'Could not start PHP validator for %s.', $path ) );
		}

		$output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );

		foreach ( $pipes as $pipe ) {
			fclose( $pipe );
		}

		$status = proc_close( $process );

		if ( 0 !== $status ) {
			throw new RuntimeException( trim( $output ) );
		}

		return trim( $output );
	}
}

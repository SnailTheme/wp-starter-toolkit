<?php
/**
 * Guard the Filesystem dependency floor and upstream path traversal fix.
 *
 * Runs offline without WordPress or writes outside the toolkit.
 *
 * @package ST_WP_Starter_Toolkit
 */

declare(strict_types=1);

// Offline CLI checks use local files and plain-text diagnostics, not WordPress APIs.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.Security.EscapeOutput.ExceptionNotEscaped

use Composer\Semver\Semver;
use Symfony\Component\Filesystem\Path;

require dirname( __DIR__ ) . '/vendor/autoload.php';

$root       = dirname( __DIR__ );
$composer   = json_decode( (string) file_get_contents( $root . '/composer.json' ), true, 512, JSON_THROW_ON_ERROR );
$lock       = json_decode( (string) file_get_contents( $root . '/composer.lock' ), true, 512, JSON_THROW_ON_ERROR );
$constraint = $composer['require']['symfony/filesystem'];
$checks     = 0;
$assert     = static function ( bool $condition, string $message ) use ( &$checks ): void {
	++$checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

// Both allowed Symfony branches must start at a patched release. Keep 8.x out.
foreach ( array( '6.0.0', '6.4.0', '6.4.49', '7.0.0', '7.4.8', '7.4.22', '8.0.0' ) as $version ) {
	$assert( ! Semver::satisfies( $version, $constraint ), 'Unsafe or unsupported Filesystem allowed: ' . $version );
}
foreach ( array( '6.4.50', '6.4.51', '7.4.23', '7.4.24' ) as $version ) {
	$assert( Semver::satisfies( $version, $constraint ), 'Patched Filesystem rejected: ' . $version );
}
$packages = array_column( $lock['packages'], null, 'name' );
$assert( isset( $packages['symfony/filesystem'] ), 'Filesystem missing from lock.' );
$assert( Semver::satisfies( $packages['symfony/filesystem']['version'], $constraint ), 'Locked Filesystem does not meet the patched floor.' );

// GHSA-6prc-h956-hxjq: an embedded :// must not hide traversal in a local path.
$assert( ! Path::isBasePath( '/base', '/base/../x://../secret.txt' ), 'Embedded scheme bypasses base-path validation.' );
$assert( '/secret.txt' === Path::canonicalize( '/base/../x://../secret.txt' ), 'Traversal path was not canonicalized as a local path.' );
$assert( Path::isBasePath( '/base', '/base/nested/file.txt' ), 'Valid child path rejected.' );
$assert( ! Path::isBasePath( '/base', '/base-other/file.txt' ), 'Sibling directory accepted.' );
$assert( 'phar:///archive/file.txt' === Path::canonicalize( 'phar:///archive/nested/../file.txt' ), 'Real stream scheme handling regressed.' );

fwrite( STDOUT, sprintf( "Filesystem security checks passed (%d assertions).\n", $checks ) );

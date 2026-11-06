<?php
/**
 * Offline toolkit PHP metadata, minimum-runtime and security-EOL regressions.
 *
 * @package ST_WP_Starter_Toolkit
 */

declare(strict_types=1);

// CLI fixtures deliberately use filesystem/JSON APIs and plain-text diagnostics.
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.ExceptionNotEscaped

use Symfony\Component\Filesystem\Filesystem;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require dirname( __DIR__ ) . '/.github/scripts/check-php-support.php';

// Fixed historical dates keep regressions stable when the live calendar grows.
$policy = array(
	'minimum_floor' => '8.3',
	'branches'      => array(
		'8.3' => array(
			'released'               => '2023-11-23',
			'security_support_until' => '2027-12-31',
		),
		'8.4' => array(
			'released'               => '2024-11-21',
			'security_support_until' => '2028-12-31',
		),
		'8.5' => array(
			'released'               => '2025-11-20',
			'security_support_until' => '2029-12-31',
		),
	),
);
$checks = 0;
$assert = static function ( bool $condition, string $message ) use ( &$checks ): void {
	++$checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$reject = static function ( callable $operation, string $message ) use ( $assert ): void {
	try {
		$operation();
	} catch ( Throwable $error ) {
		$assert( str_contains( $error->getMessage(), $message ), 'Unexpected rejection: ' . $error->getMessage() );
		return;
	}
	$assert( false, 'Expected rejection containing: ' . $message );
};

foreach ( array(
	'2026-11-07' => '8.3',
	'2027-12-31' => '8.3',
	'2028-01-01' => '8.4',
	'2028-12-31' => '8.4',
	'2029-01-01' => '8.5',
) as $date => $expected ) {
	$assert( st_toolkit_supported_php( $policy, $date ) === $expected, 'Incorrect baseline on ' . $date );
}
$reversed             = $policy;
$reversed['branches'] = array_reverse( $policy['branches'], true );
$assert( '8.3' === st_toolkit_supported_php( $reversed, '2026-11-07' ), 'Calendar order changed the baseline.' );
$higher_floor                  = $policy;
$higher_floor['minimum_floor'] = '8.4';
$assert( '8.4' === st_toolkit_supported_php( $higher_floor, '2026-11-07' ), 'Project floor was ignored.' );
$reject( static fn () => st_toolkit_supported_php( $policy, '2030-01-01' ), 'calendar exhausted' );
$reject( static fn () => st_toolkit_supported_php( $policy, '2026-02-30' ), 'Invalid PHP support policy' );
$reject( static fn () => st_toolkit_supported_php( array(), '2026-11-07' ), 'Invalid PHP support policy' );
foreach ( array( '8.3.0', null, array() ) as $floor ) {
	$invalid                  = $policy;
	$invalid['minimum_floor'] = $floor;
	$reject( static fn () => st_toolkit_supported_php( $invalid, '2026-11-07' ), 'Invalid PHP support policy' );
}
$invalid             = $policy;
$invalid['branches'] = '8.3';
$reject( static fn () => st_toolkit_supported_php( $invalid, '2026-11-07' ), 'Invalid PHP support policy' );
$invalid             = $policy;
$invalid['branches'] = array( 'broken' => array() );
$reject( static fn () => st_toolkit_supported_php( $invalid, '2026-11-07' ), 'Invalid PHP branch' );
foreach ( array(
	array(
		'released'               => '2023-11-23',
		'security_support_until' => '2027-02-30',
	),
	array(
		'released'               => '2023-02-30',
		'security_support_until' => '2027-12-31',
	),
	array(
		'released'               => '2028-01-01',
		'security_support_until' => '2027-12-31',
	),
	array(
		'released'               => null,
		'security_support_until' => '2027-12-31',
	),
) as $dates ) {
	$invalid                    = $policy;
	$invalid['branches']['8.3'] = $dates;
	$reject( static fn () => st_toolkit_supported_php( $invalid, '2026-11-07' ), 'Invalid PHP support dates' );
}
$future                                = $policy;
$future['branches']['8.3']['released'] = '2027-01-01';
$assert( '8.4' === st_toolkit_supported_php( $future, '2026-11-07' ), 'Unreleased branch selected.' );
$reject( static fn () => st_toolkit_supported_php( $policy, '2023-11-22' ), 'calendar exhausted' );
$assert( '8.3' === st_toolkit_supported_php( $policy, '2023-11-23' ), 'Release date was excluded.' );

$root = sys_get_temp_dir() . '/st-toolkit-php-support-' . bin2hex( random_bytes( 8 ) );
$fs   = new Filesystem();

$composer = array(
	'require' => array( 'php' => '>=8.3' ),
	'config'  => array( 'platform' => array( 'php' => '8.3.0' ) ),
);
$lock     = array(
	'platform'           => array( 'php' => '>=8.3' ),
	'platform-overrides' => array( 'php' => '8.3.0' ),
);

$readme_template = (string) file_get_contents( dirname( __DIR__ ) . '/README.md' );
$write_fixture   = static function () use ( $root, $fs, &$composer, &$lock, $policy, $readme_template ): void {
	$fs->dumpFile( $root . '/.github/php-support.json', json_encode( $policy, JSON_THROW_ON_ERROR ) );
	$fs->dumpFile( $root . '/composer.json', json_encode( $composer, JSON_THROW_ON_ERROR ) );
	$fs->dumpFile( $root . '/composer.lock', json_encode( $lock, JSON_THROW_ON_ERROR ) );
	$branch = substr( $composer['require']['php'], 2 );
	$fs->dumpFile( $root . '/README.md', (string) preg_replace( '/^Requires PHP: `[0-9]+\.[0-9]+\+`\./m', 'Requires PHP: `' . $branch . '+`.', $readme_template ) );
};

try {
	$write_fixture();
	foreach ( array( '8.3.0', '8.3.36', '8.3.99' ) as $runtime ) {
		$assert( 1 === count( st_toolkit_check_php_support( $root, '2026-11-07', $runtime ) ), 'Valid baseline runtime rejected: ' . $runtime );
	}
	foreach ( array(
		'2027-10-31' => 1,
		'2027-11-01' => 2,
		'2027-12-31' => 2,
	) as $date => $messages ) {
		$result = st_toolkit_check_php_support( $root, $date, '8.3.36' );
		$assert( count( $result ) === $messages, 'Wrong 60-day EOL warning boundary on ' . $date );
		if ( 2 === $messages ) {
			$assert( str_starts_with( $result[1], '::warning::' ), 'Missing GitHub warning annotation.' );
		}
	}
	$reject( static fn () => st_toolkit_check_php_support( $root, '2028-01-01', '8.3.36' ), 'require.php must be >=8.4' );
	foreach ( array( '8.1.99', '8.2.29', '8.4.1', '8.30.1', '' ) as $runtime ) {
		$reject( static fn () => st_toolkit_check_php_support( $root, '2026-11-07', $runtime ), 'Test on the minimum PHP branch 8.3' );
	}

	foreach ( array( '>=8.1', '>=8.2', '>=8.4', '^8.3' ) as $requirement ) {
		$bad                   = $composer;
		$bad['require']['php'] = $requirement;
		$fs->dumpFile( $root . '/composer.json', json_encode( $bad, JSON_THROW_ON_ERROR ) );
		$reject( static fn () => st_toolkit_check_php_support( $root, '2026-11-07', '8.3.36' ), 'require.php must be >=8.3' );
	}
	$bad                              = $composer;
	$bad['config']['platform']['php'] = '8.4.0';
	$fs->dumpFile( $root . '/composer.json', json_encode( $bad, JSON_THROW_ON_ERROR ) );
	$reject( static fn () => st_toolkit_check_php_support( $root, '2026-11-07', '8.3.36' ), 'composer.json platform.php' );
	unset( $bad['config'] );
	$fs->dumpFile( $root . '/composer.json', json_encode( $bad, JSON_THROW_ON_ERROR ) );
	$reject( static fn () => st_toolkit_check_php_support( $root, '2026-11-07', '8.3.36' ), 'composer.json platform.php' );
	$write_fixture();
	foreach ( array(
		'platform'           => '>=8.1',
		'platform-overrides' => '8.2.0',
	) as $key => $value ) {
		$bad                = $lock;
		$bad[ $key ]['php'] = $value;
		$fs->dumpFile( $root . '/composer.lock', json_encode( $bad, JSON_THROW_ON_ERROR ) );
		$reject( static fn () => st_toolkit_check_php_support( $root, '2026-11-07', '8.3.36' ), 'composer.lock platform' );
	}
	foreach ( array( '.github/php-support.json', 'composer.json', 'composer.lock', 'README.md' ) as $file ) {
		$write_fixture();
		$fs->remove( $root . '/' . $file );
		$reject( static fn () => st_toolkit_check_php_support( $root, '2026-11-07', '8.3.36' ), $file );
	}
	foreach ( array( '.github/php-support.json', 'composer.json', 'composer.lock' ) as $file ) {
		$write_fixture();
		$fs->dumpFile( $root . '/' . $file, 'null' );
		$reject( static fn () => st_toolkit_check_php_support( $root, '2026-11-07', '8.3.36' ), 'Expected JSON object' );
		$fs->dumpFile( $root . '/' . $file, '{' );
		$reject( static fn () => st_toolkit_check_php_support( $root, '2026-11-07', '8.3.36' ), 'Syntax error' );
	}
	$write_fixture();
	$fs->dumpFile( $root . '/README.md', 'Requires PHP: `8.2+`.' );
	$reject( static fn () => st_toolkit_check_php_support( $root, '2026-11-07', '8.3.36' ), 'README.md must declare' );
	foreach ( array(
		"Requires PHP: `8.2+`.\nRequires PHP: `8.3+`.\n",
		"Requires PHP: `8.3+`.\nRequires PHP: `8.3+`.\n",
		'Example only: Requires PHP: `8.3+`.',
	) as $readme ) {
		$fs->dumpFile( $root . '/README.md', $readme );
		$reject( static fn () => st_toolkit_check_php_support( $root, '2026-11-07', '8.3.36' ), 'README.md must declare' );
	}
	$fs->dumpFile( $root . '/README.md', "# Toolkit\r\n\r\nRequires PHP: `8.3+`.\r\n" );
	$assert( 1 === count( st_toolkit_check_php_support( $root, '2026-11-07', '8.3.36' ) ), 'CRLF README declaration rejected.' );

	foreach ( array(
		'2028-01-01' => '8.4',
		'2029-01-01' => '8.5',
	) as $date => $branch ) {
		$composer['require']['php']            = '>=' . $branch;
		$composer['config']['platform']['php'] = $branch . '.0';
		$lock['platform']['php']               = '>=' . $branch;
		$lock['platform-overrides']['php']     = $branch . '.0';
		$write_fixture();
		$assert( 1 === count( st_toolkit_check_php_support( $root, $date, $branch . '.1' ) ), 'Aligned future baseline rejected: ' . $branch );
	}
} finally {
	$fs->remove( $root );
}

fwrite( STDOUT, sprintf( "PHP support checks passed (%d assertions).\n", $checks ) );

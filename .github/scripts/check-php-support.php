<?php
/**
 * Check toolkit metadata and its CI runtime against the PHP support calendar.
 *
 * This is maintainer tooling, not a restriction on users running newer PHP.
 *
 * @package ST_WP_Starter_Toolkit
 */

declare(strict_types=1);

// Standalone CI tooling uses native filesystem APIs and plain-text diagnostics.
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * Select the oldest released, security-supported branch at the project floor.
 *
 * @param array<string,mixed> $policy Support calendar verified against php.net.
 * @param string              $today  UTC date in YYYY-MM-DD format.
 * @return string Required minimum branch.
 * @throws RuntimeException For invalid or exhausted calendars.
 */
function st_toolkit_supported_php( array $policy, string $today ): string {
	$valid_date = static function ( string $date ): bool {
		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, new DateTimeZone( 'UTC' ) );
		return false !== $parsed && $parsed->format( 'Y-m-d' ) === $date;
	};
	$floor      = $policy['minimum_floor'] ?? '';
	$branches   = $policy['branches'] ?? array();
	if ( ! is_string( $floor ) || ! preg_match( '/^\d+\.\d+$/D', $floor ) || ! is_array( $branches ) || ! $valid_date( $today ) ) {
		throw new RuntimeException( 'Invalid PHP support policy.' );
	}
	$eligible = array();
	foreach ( $branches as $branch => $dates ) {
		if ( ! preg_match( '/^\d+\.\d+$/D', (string) $branch ) || ! is_array( $dates ) ) {
			throw new RuntimeException( 'Invalid PHP branch.' );
		}
		$released = $dates['released'] ?? '';
		$end      = $dates['security_support_until'] ?? '';
		if ( ! is_string( $released ) || ! is_string( $end ) || ! $valid_date( $released ) || ! $valid_date( $end ) || $released > $end ) {
			throw new RuntimeException( 'Invalid PHP support dates for ' . $branch );
		}
		if ( version_compare( (string) $branch, $floor, '>=' ) && $released <= $today && $end >= $today ) {
			$eligible[] = (string) $branch;
		}
	}
	if ( empty( $eligible ) ) {
		throw new RuntimeException( 'PHP support calendar exhausted. Refresh it from https://www.php.net/supported-versions.php.' );
	}
	usort( $eligible, 'version_compare' );
	return $eligible[0];
}

/**
 * Validate the declared baseline and enforce testing on its actual PHP branch.
 *
 * @param string $root    Toolkit repository path.
 * @param string $today   UTC date.
 * @param string $runtime Actual PHP version used by CI.
 * @return string[] Human-readable status and optional EOL warning.
 * @throws RuntimeException For inconsistent metadata or the wrong CI runtime.
 */
function st_toolkit_check_php_support( string $root, string $today, string $runtime ): array {
	$read_json = static function ( string $path ): array {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			throw new RuntimeException( 'Missing or unreadable required file: ' . $path );
		}
		$value = json_decode( (string) file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
		if ( ! is_array( $value ) ) {
			throw new RuntimeException( 'Expected JSON object: ' . $path );
		}
		return $value;
	};
	$policy    = $read_json( $root . '/.github/php-support.json' );
	$expected  = st_toolkit_supported_php( $policy, $today );
	$composer  = $read_json( $root . '/composer.json' );
	$lock      = $read_json( $root . '/composer.lock' );
	$metadata  = array(
		'composer.json require.php'       => array( $composer['require']['php'] ?? '', '>=' . $expected ),
		'composer.json platform.php'      => array( $composer['config']['platform']['php'] ?? '', $expected . '.0' ),
		'composer.lock platform.php'      => array( $lock['platform']['php'] ?? '', '>=' . $expected ),
		'composer.lock platform override' => array( $lock['platform-overrides']['php'] ?? '', $expected . '.0' ),
	);
	foreach ( $metadata as $label => $values ) {
		if ( $values[0] !== $values[1] ) {
			throw new RuntimeException( $label . ' must be ' . $values[1] . '. Update the PHP baseline, Composer lock, CI and docs together.' );
		}
	}
	$readme       = $root . '/README.md';
	$declarations = array();
	if ( is_file( $readme ) && is_readable( $readme ) ) {
		preg_match_all( '/^Requires PHP: `([0-9]+\.[0-9]+)\+`\.\r?$/m', (string) file_get_contents( $readme ), $declarations );
	}
	if ( array( $expected ) !== ( $declarations[1] ?? array() ) ) {
		throw new RuntimeException( 'README.md must declare Requires PHP: `' . $expected . '+`.' );
	}
	if ( ! preg_match( '/^' . preg_quote( $expected, '/' ) . '\./', $runtime ) ) {
		throw new RuntimeException( 'Test on the minimum PHP branch ' . $expected . '; actual runtime is ' . $runtime );
	}
	$end    = $policy['branches'][ $expected ]['security_support_until'];
	$output = array( 'PHP ' . $expected . ' baseline is consistent; security support ends ' . $end . '.' );
	if ( ( new DateTimeImmutable( $today ) )->diff( new DateTimeImmutable( $end ) )->days <= 60 ) {
		$output[] = '::warning::PHP minimum reaches EOL within 60 days. Prepare the next supported baseline.';
	}
	return $output;
}

if ( 'cli' === PHP_SAPI && realpath( $argv[0] ?? '' ) === __FILE__ ) {
	try {
		foreach ( st_toolkit_check_php_support( dirname( __DIR__, 2 ), gmdate( 'Y-m-d' ), PHP_VERSION ) as $line ) {
			fwrite( STDOUT, $line . PHP_EOL );
		}
	} catch ( Throwable $error ) {
		fwrite( STDERR, $error->getMessage() . PHP_EOL );
		exit( 1 );
	}
}

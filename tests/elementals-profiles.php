<?php
/**
 * Build disposable Elemental themes without bootstrapping WordPress.
 *
 * Usage: php tests/elementals-profiles.php /abs/clean-source-theme /abs/output-root
 * Progress goes to stderr; stdout and output-root/fixtures.json contain JSON.
 * Existing profile directories are replaced only when this script owns them.
 */

declare(strict_types=1);

use SnailTheme\WPStarterToolkit\Block\BlockBundleInstaller;
use SnailTheme\WPStarterToolkit\Block\BlockBundleManifest;
use SnailTheme\WPStarterToolkit\Block\NpmRunner;
use SnailTheme\WPStarterToolkit\Block\PackageJsonInspector;
use SnailTheme\WPStarterToolkit\Support\JsonFile;
use SnailTheme\WPStarterToolkit\Theme\ThemeDetector;
use SnailTheme\WPStarterToolkit\UI\UIProfileInstaller;
use SnailTheme\WPStarterToolkit\UI\UIProfileManifest;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
$acceptance = ( static fn (): array => require __DIR__ . '/elementals-generated.php' )();

$fs          = new Filesystem();
$json        = new JsonFile();
$owner       = 'st-toolkit/tests/elementals-profiles.php';
$marker      = '.st-elemental-fixture';
$profiles    = array( 'bare', 'blueprint', 'tailwind' );
$lock        = null;
$owns_lock   = false;
$report      = array(
	'schema'   => 1,
	'status'   => 'failed',
	'fixtures' => array(),
);
$report_path = null;
$environment = array();
$exit_code   = 0;

/** Lock rejection must never replace a report written by another process. */
$write_report = static function () use ( &$owns_lock, &$report_path, &$report, $fs ): void {
	if ( $owns_lock && null !== $report_path ) {
		$fs->dumpFile( $report_path, json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . PHP_EOL );
	}
};

/** Resolve existing ancestors before comparing paths, including symlink aliases. */
$absolute_path = static function ( string $path ): string {
	if ( ! Path::isAbsolute( $path ) ) {
		throw new RuntimeException( 'Arguments must be absolute paths: ' . $path );
	}
	$path   = Path::canonicalize( $path );
	$parent = $path;
	while ( ! file_exists( $parent ) && ! is_link( $parent ) ) {
		$parent = dirname( $parent );
	}
	$resolved = realpath( $parent );
	if ( false === $resolved ) {
		throw new RuntimeException( 'Cannot resolve path: ' . $path );
	}
	return Path::canonicalize( $resolved . substr( $path, strlen( $parent ) ) );
};

$source_files  = $acceptance['files'];
$source_hashes = $acceptance['snapshot'];
$assert_owned  = static function ( string $path, string $profile, string $source ) use ( $json, $owner, $marker ): void {
	if ( ! file_exists( $path ) && ! is_link( $path ) ) {
		return;
	}
	if ( is_link( $path ) || ! is_dir( $path ) || is_link( $path . '/' . $marker ) || ! is_file( $path . '/' . $marker ) ) {
		throw new RuntimeException( 'Refusing to overwrite an unowned fixture: ' . $path );
	}
	$record = $json->read( $path . '/' . $marker );
	if ( 1 !== ( $record['schema'] ?? null ) || $owner !== ( $record['owner'] ?? null )
		|| $profile !== ( $record['profile'] ?? null ) || $source !== ( $record['source'] ?? null ) ) {
		throw new RuntimeException( 'Fixture ownership marker does not match: ' . $path );
	}
};

try {
	if ( 3 !== $argc ) {
		throw new RuntimeException( 'Usage: php tests/elementals-profiles.php /abs/clean-source-theme /abs/output-root' );
	}
	$source = $absolute_path( $argv[1] );
	$output = $absolute_path( $argv[2] );
	if ( $source === $output || str_starts_with( $output . '/', $source . '/' ) || str_starts_with( $source . '/', $output . '/' ) ) {
		throw new RuntimeException( 'Source and output must be separate, non-nested directories.' );
	}
	$toolkit = dirname( __DIR__ );
	if ( $output === $toolkit || str_starts_with( $output . '/', $toolkit . '/' ) || str_starts_with( $toolkit . '/', $output . '/' ) ) {
		throw new RuntimeException( 'Profile output must not overlap the toolkit source.' );
	}
	$before = $source_hashes( $source );
	$acceptance['clean_source']( $source );
	$detector     = new ThemeDetector();
	$source_theme = $detector->detect( $source );
	if ( $source_theme->path !== $source || 'stwp' !== $source_theme->pattern( 'block_namespace' ) ) {
		throw new RuntimeException( 'Source must be the root of a clean stwp theme.' );
	}
	$baseline = $json->read( $source . '/st-toolkit.json' );
	if ( false !== ( $baseline['ui_locked'] ?? null ) || 'bare' !== ( $baseline['ui_profile'] ?? null ) ) {
		throw new RuntimeException( 'Source must have the unlocked Bare baseline; it will not be modified.' );
	}
	if ( glob( $source . '/blocks/stwp-el-*' ) ) {
		throw new RuntimeException( 'Source already contains Elemental blocks. Use a clean origin/main archive.' );
	}
	$bundle  = BlockBundleManifest::load( 'elementals' );
	$spacing = $bundle->themeSettings()['blocks']['stwp/el-wrapper']['spacing'] ?? array();
	if ( true !== ( $spacing['margin'] ?? null ) || true !== ( $spacing['padding'] ?? null ) ) {
		throw new RuntimeException( 'Elementals bundle theme_settings are not ready: wrapper margin/padding must be enabled.' );
	}
	// Preflight every target before removing any previous owned fixture.
	foreach ( $profiles as $profile ) {
		$assert_owned( $output . '/' . $profile, $profile, $source );
		UIProfileManifest::load( $profile );
	}
	if ( file_exists( $output . '/' . $marker ) || is_link( $output . '/' . $marker ) || file_exists( $output . '/fixtures.json' ) ) {
		$assert_owned( $output, 'profiles', $source );
	}
	foreach ( array( 'fixtures.json', '.npm-cache' ) as $entry ) {
		if ( is_link( $output . '/' . $entry ) ) {
			throw new RuntimeException( 'Refusing a symlinked fixture output: ' . $output . '/' . $entry );
		}
	}
	if ( file_exists( $output . '/fixtures.json' ) && ! is_file( $output . '/fixtures.json' ) ) {
		throw new RuntimeException( 'Fixture report must be a regular file.' );
	}
	$fs->mkdir( $output );
	$lock_path = $output . '/.st-elemental-fixtures.lock';
	if ( is_link( $lock_path ) || is_dir( $lock_path ) ) {
		throw new RuntimeException( 'Unsafe fixture lock path: ' . $lock_path );
	}
	$lock = fopen( $lock_path, 'c' );
	if ( false === $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
		throw new RuntimeException( 'Another profile fixture build owns the output directory.' );
	}
	$owns_lock = true;
	$ownership = array(
		'schema' => 1,
		'owner'  => $owner,
		'source' => $source,
	);
	$json->write( $output . '/' . $marker, $ownership + array( 'profile' => 'profiles' ) );
	$report_path             = $output . '/fixtures.json';
	$report                 += array(
		'source'      => $source,
		'output_root' => $output,
		'namespace'   => 'stwp',
		'report_path' => $report_path,
	);
	$report['source_sha256'] = hash( 'sha256', json_encode( $before, JSON_THROW_ON_ERROR ) );
	$report['status']        = 'building';
	$write_report();

	// Keep npm's cache and logs inside the disposable output, not the source/home.
	foreach ( array(
		'npm_config_cache'           => $output . '/.npm-cache',
		'npm_config_audit'           => 'false',
		'npm_config_fund'            => 'false',
		'npm_config_update_notifier' => 'false',
	) as $name => $value ) {
		$environment[ $name ] = array( getenv( $name ), $_ENV[ $name ] ?? null, $_SERVER[ $name ] ?? null );
		putenv( $name . '=' . $value );
		$_ENV[ $name ] = $_SERVER[ $name ] = $value;
	}
	$npm      = new NpmRunner();
	$packages = new PackageJsonInspector();
	$ui       = new UIProfileInstaller();
	foreach ( $profiles as $profile ) {
		$path = $output . '/' . $profile;
		fwrite( STDERR, '[' . $profile . "] Mirroring clean theme and installing UI profile.\n" );
		$assert_owned( $path, $profile, $source );
		$fs->remove( $path );
		$fs->mkdir( $path );
		$json->write( $path . '/' . $marker, $ownership + array( 'profile' => $profile ) );
		$fs->mirror( $source, $path, $source_files( $source ) );
		$source_hashes( $path );
		$theme    = $detector->detect( $path );
		$manifest = UIProfileManifest::load( $profile );
		$ui->install( $theme, $manifest );
		fwrite( STDERR, '[' . $profile . "] Installing baseline npm dependencies.\n" );
		$baseline_command = array( 'npm', is_file( $path . '/package-lock.json' ) ? 'ci' : 'install' );
		$process          = new Process( $baseline_command, $path );
		$process->setTimeout( null );
		$process->mustRun();
		$missing      = $packages->missing( $theme, $manifest->npmDevDependencies() );
		$dependencies = $npm->installDevDependencies( $theme, $missing );
		$installer    = new BlockBundleInstaller();
		$plan         = $installer->plan( $theme, $bundle );
		fwrite( STDERR, '[' . $profile . "] Installing Elementals and running the single production build.\n" );
		$result = $installer->install( $plan );
		$log    = $path . '/.st-elemental-build.log';
		$fs->dumpFile(
			$log,
			$process->getOutput() . $process->getErrorOutput() . "\n"
			. ( $dependencies['output'] ?? '' ) . ( $dependencies['error_output'] ?? '' ) . "\n"
			. ( $result['build']['output'] ?? '' ) . ( $result['build']['error_output'] ?? '' ) . ( $result['build']['message'] ?? '' )
		);
		if ( 'completed' !== ( $result['build']['status'] ?? null ) ) {
			throw new RuntimeException( 'Bundle build failed for ' . $profile . '; inspect ' . $log );
		}

		$assets = array();
		foreach ( Finder::create()->files()->in( $path )->exclude( array( 'node_modules', '.st-toolkit' ) )->name( '/\.(?:css|js)$/' ) as $file ) {
			$relative = str_replace( '\\', '/', $file->getRelativePathname() );
			if ( preg_match( '#^(?:assets/(?:css|js)/|blocks/[^/]+/assets/(?:css|js)/)#', $relative ) ) {
				$assets[ $relative ] = hash_file( 'sha256', $file->getPathname() );
			}
		}
		ksort( $assets );
		foreach ( $bundle->memberSlugs() as $slug ) {
			$metadata = $json->read( $path . '/blocks/stwp-' . $slug . '/block.json' );
			if ( array_key_exists( 'mode', $metadata['supports'] ?? array() ) || array_key_exists( 'mode', $metadata['acf'] ?? array() )
				|| ( 'el-wrapper' !== $slug && false !== ( $metadata['supports']['align'] ?? null ) ) ) {
				throw new RuntimeException( 'Obsolete mode/alignment metadata in fixture: ' . $profile . '/' . $slug );
			}
			foreach ( array( 'view', 'editor' ) as $entry ) {
				$asset = 'blocks/stwp-' . $slug . '/assets/css/' . $entry . '-style.min.css';
				if ( ! isset( $assets[ $asset ] ) || 0 === filesize( $path . '/' . $asset ) ) {
					throw new RuntimeException( 'Missing or empty block CSS: ' . $path . '/' . $asset );
				}
			}
		}
		$theme_json = $json->read( $path . '/theme.json' );
		$acceptance['validate']( $path, 'stwp' );
		$spacing    = $theme_json['settings']['blocks']['stwp/el-wrapper']['spacing'] ?? array();
		$status     = $ui->status( $theme );
		if ( $profile !== $status['profile'] || ! $status['locked'] || $status['missing_files'] || $status['modified_files']
			|| true !== ( $spacing['margin'] ?? null ) || true !== ( $spacing['padding'] ?? null ) || file_exists( $path . '/vendor' ) ) {
			throw new RuntimeException( 'Profile, theme settings, or vendor-free runtime validation failed: ' . $profile );
		}
		$fixture                        = array(
			'path'                   => $path,
			'namespace'              => $theme->pattern( 'block_namespace' ),
			'profile_status'         => $status,
			'baseline_install'       => implode( ' ', $baseline_command ),
			'added_dev_dependencies' => $missing,
			'build_status'           => $result['build']['status'],
			'build_log'              => $log,
			'metadata_sha256'         => array_combine(
				array_map( static fn ( string $slug ): string => 'blocks/stwp-' . $slug . '/block.json', $bundle->memberSlugs() ),
				array_map( static fn ( string $slug ): string => (string) hash_file( 'sha256', $path . '/blocks/stwp-' . $slug . '/block.json' ), $bundle->memberSlugs() )
			),
			'counts'                 => array(
				'blocks'    => count( glob( $path . '/blocks/stwp-el-*/block.json' ) ?: array() ),
				'acf_json'  => count( glob( $path . '/acf-json/*.json' ) ?: array() ),
				'block_css' => count( glob( $path . '/blocks/stwp-el-*/assets/css/*.min.css' ) ?: array() ),
				'assets'    => count( $assets ),
			),
			'asset_sha256'           => $assets,
		);
		$report['fixtures'][ $profile ] = $fixture;
		$write_report();
		fwrite( STDERR, '[' . $profile . '] Ready: ' . $path . "\n" );
	}
	if ( $before !== $source_hashes( $source ) ) {
		throw new RuntimeException( 'The source changed during fixture construction.' );
	}
	$report['source_unchanged'] = true;
	$report['status']           = 'completed';
} catch ( Throwable $exception ) {
	$exit_code       = 1;
	$report['status'] = 'failed';
	$report['error'] = $exception->getMessage();
	fwrite( STDERR, $exception->getMessage() . PHP_EOL );
} finally {
	foreach ( $environment as $name => [ $value, $env, $server ] ) {
		putenv( false === $value ? $name : $name . '=' . $value );
		unset( $_ENV[ $name ], $_SERVER[ $name ] );
		if ( null !== $env ) {
			$_ENV[ $name ] = $env;
		}
		if ( null !== $server ) {
			$_SERVER[ $name ] = $server;
		}
	}
	$write_report();
	if ( is_resource( $lock ) ) {
		if ( $owns_lock ) {
			flock( $lock, LOCK_UN );
		}
		fclose( $lock );
	}
}
echo json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . PHP_EOL;
exit( $exit_code );

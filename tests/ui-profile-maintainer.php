<?php
/**
 * Maintainer exports use real installed profiles, but write only to /tmp packages.
 * CLI checks deliberately never approve an export against the real resources.
 */

declare(strict_types=1);

use SnailTheme\WPStarterToolkit\Application;
use SnailTheme\WPStarterToolkit\Component\ComponentInstaller;
use SnailTheme\WPStarterToolkit\Component\ComponentManifest;
use SnailTheme\WPStarterToolkit\Support\BootstrapParser;
use SnailTheme\WPStarterToolkit\Support\JsonFile;
use SnailTheme\WPStarterToolkit\Support\ReplacementEngine;
use SnailTheme\WPStarterToolkit\Theme\ThemeContext;
use SnailTheme\WPStarterToolkit\Theme\ThemeDetector;
use SnailTheme\WPStarterToolkit\UI\UIProfileInstaller;
use SnailTheme\WPStarterToolkit\UI\UIProfileMaintainer;
use SnailTheme\WPStarterToolkit\UI\UIProfileManifest;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

require dirname( __DIR__ ) . '/vendor/autoload.php';

$root = dirname( __DIR__ );
$temp = sys_get_temp_dir() . '/st-toolkit-ui-maintainer-' . bin2hex( random_bytes( 6 ) );
$fs = new Filesystem();
$json = new JsonFile();
$assertions = 0;

function ui_maintainer_assert( bool $condition, string $message ): void {
	global $assertions;
	++$assertions;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function ui_maintainer_throws( callable $action, string $message ): RuntimeException {
	try {
		$action();
	} catch ( RuntimeException $exception ) {
		ui_maintainer_assert( str_contains( strtolower( $exception->getMessage() ), strtolower( $message ) ), 'Unexpected failure: ' . $exception->getMessage() );
		return $exception;
	}
	throw new RuntimeException( 'Expected failure containing: ' . $message );
}

/** Hash files and record directories/links without following symlinks. */
function ui_maintainer_snapshot( string $path ): array {
	$result = array();
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST ) as $file ) {
		$relative = substr( $file->getPathname(), strlen( $path ) + 1 );
		$result[ $relative ] = $file->isLink() ? 'link:' . readlink( $file->getPathname() ) : ( $file->isDir() ? 'directory' : hash_file( 'sha256', $file->getPathname() ) );
	}
	ksort( $result );
	return $result;
}

/** Install through the public installer; do not synthesize installation receipts. */
function ui_maintainer_fixture( string $name, string $slug = 'bare', array $patterns = array() ): array {
	global $root, $temp, $fs;
	$case = $temp . '/' . $name;
	$themePath = $case . '/workbench';
	$fs->mirror( $temp . '/theme-template', $themePath );
	if ( array() !== $patterns ) {
		$contents = ( new ReplacementEngine() )->applyThemePatterns( (string) file_get_contents( $themePath . '/core/bootstrap.php' ), $patterns );
		$fs->dumpFile( $themePath . '/core/bootstrap.php', $contents );
	}
	$theme = ( new ThemeDetector() )->detect( $themePath );
	( new UIProfileInstaller() )->install( $theme, UIProfileManifest::load( $slug ) );
	$profiles = $case . '/package/resources/ui-profiles';
	$fs->mirror( $root . '/resources/ui-profiles/' . $slug, $profiles . '/' . $slug );
	return array(
		'case' => $case,
		'theme' => $theme,
		'profiles' => $profiles,
		'profile' => $profiles . '/' . $slug,
		'manifest' => $profiles . '/' . $slug . '/st-ui.json',
		'path' => 'tailwind' === $slug ? 'assets/styles/main.css' : 'assets/scss/main.scss',
		'service' => new UIProfileMaintainer( $profiles ),
	);
}

/** Invalid preflight must leave even backups and outside targets untouched. */
function ui_maintainer_reject( string $name, callable $prepare, string $message ): void {
	$fixture = ui_maintainer_fixture( $name );
	$prepare( $fixture );
	$before = ui_maintainer_snapshot( $fixture['case'] );
	ui_maintainer_throws( static fn () => $fixture['service']->plan( $fixture['theme'], 'bare', true ), $message );
	ui_maintainer_assert( $before === ui_maintainer_snapshot( $fixture['case'] ), $name . ': rejected preflight wrote files.' );
}

/** Run real commands against a fixture package, not this checkout's resources. */
function ui_maintainer_package_cli( string $package, array $arguments ): array {
	global $root;
	$code = 'require ' . var_export( $package . '/src/Support/PackagePaths.php', true ) . '; require ' . var_export( $root . '/vendor/autoload.php', true ) . ';'
		. '$app = new Symfony\Component\Console\Application(); $app->add(new SnailTheme\WPStarterToolkit\Command\UiProfileCommand()); $app->add(new SnailTheme\WPStarterToolkit\Command\UiProfileCommand(true)); exit($app->run());';
	$process = new Process( array_merge( array( PHP_BINARY, '-d', 'display_errors=1', '-r', $code, '--' ), $arguments ), $package );
	$process->setTimeout( 30 );
	$process->run();
	return array( 'status' => $process->getExitCode(), 'json' => json_decode( $process->getOutput(), true ), 'output' => $process->getOutput(), 'stderr' => $process->getErrorOutput() );
}

/** Fail the manifest write after a source write, optionally also fail recovery. */
final class UIProfileFailingFilesystem extends Filesystem {
	public bool $failed = false;
	public int $sourceWrites = 0;

	public function __construct( private readonly string $manifest, private readonly string $source, private readonly bool $failRecovery ) {}

	public function dumpFile( string $filename, $content ): void {
		if ( $filename === $this->manifest && ! $this->failed ) {
			$this->failed = true;
			throw new RuntimeException( 'Fixture manifest write failed' );
		}
		if ( $filename === $this->source ) {
			++$this->sourceWrites;
			if ( $this->failed && $this->failRecovery ) {
				throw new RuntimeException( 'Fixture recovery failed' );
			}
		}
		parent::dumpFile( $filename, $content );
	}
}

try {
	$bootstrap = $root . '/resources/core/files/core/bootstrap.php';
	$patterns = ( new BootstrapParser() )->parseThemePatterns( $bootstrap );
	$fs->mkdir( $temp . '/theme-template/core' );
	$fs->copy( $bootstrap, $temp . '/theme-template/core/bootstrap.php' );
	$fs->dumpFile( $temp . '/theme-template/style.css', '/* Theme Name: ' . $patterns['theme_name'] . " */\n" );

	foreach ( array( 'bare', 'blueprint', 'tailwind' ) as $slug ) {
		$f = ui_maintainer_fixture( 'roundtrip-' . $slug, $slug );
		$service = $f['service'];
		$theme = $f['theme'];
		$manifest = $json->read( $f['manifest'] );
		$before = ui_maintainer_snapshot( $f['case'] );
		$plan = $service->plan( $theme, $slug );
		ui_maintainer_assert( $theme->patterns === $patterns, $slug . ': fixture lost canonical bootstrap patterns.' );
		ui_maintainer_assert( true === $plan['clean'] && $slug === $plan['profile'] && $manifest['version'] === $plan['version'], $slug . ': identical installation is not clean.' );
		ui_maintainer_assert( count( $manifest['checksums'] ) === count( $plan['changes'] ) && array( 'matching' ) === array_values( array_unique( array_column( $plan['changes'], 'action' ) ) ), $slug . ': plan did not cover the checksum allowlist.' );
		ui_maintainer_assert( $before === ui_maintainer_snapshot( $f['case'] ), $slug . ': verification wrote files.' );
		$service->export( $theme, $slug, $service->plan( $theme, $slug, true ) );
		ui_maintainer_assert( $before === ui_maintainer_snapshot( $f['case'] ), $slug . ': clean export created files or backups.' );

		$source = $f['profile'] . '/files/' . $f['path'];
		$original = (string) file_get_contents( $source );
		$originalManifest = (string) file_get_contents( $f['manifest'] );
		$modified = $original . "\n/* Maintainer fixture edit. */\n";
		$fs->dumpFile( $theme->resolve( $f['path'] ), $modified );
		$receipt = $json->read( $theme->resolve( UIProfileInstaller::STATE_FILE ) );
		foreach ( array( 'assets/scss/project-only.scss', 'assets/styles/project-only.css', 'assets/scss/core/private.scss', 'functions.php', 'dist/build.css', 'node_modules/private.css' ) as $extra ) {
			$fs->dumpFile( $theme->resolve( $extra ), 'Project-owned file.' );
			$receipt['files'][ $extra ] = hash( 'sha256', 'Project-owned file.' );
		}
		$json->write( $theme->resolve( UIProfileInstaller::STATE_FILE ), $receipt );
		$before = ui_maintainer_snapshot( $f['case'] );
		$approved = $service->plan( $theme, $slug, true );
		ui_maintainer_assert( false === $approved['clean'] && 'modified' === array_column( $approved['changes'], 'action', 'path' )[ $f['path'] ], $slug . ': modified source was not reported.' );
		ui_maintainer_assert( $before === ui_maintainer_snapshot( $f['case'] ), $slug . ': drift planning wrote files.' );
		$themeBefore = ui_maintainer_snapshot( $theme->path );
		$result = $service->export( $theme, $slug, $approved );
		$backup = $result['backup_path'];
		ui_maintainer_assert( str_starts_with( $backup, $f['case'] . '/package/.st-toolkit/backups/' ) && is_dir( $backup ), $slug . ': backup escaped the fixture package.' );
		ui_maintainer_assert( $original === file_get_contents( $backup . '/files/' . $f['path'] ) && $originalManifest === file_get_contents( $backup . '/st-ui.json' ), $slug . ': backup lost original package bytes.' );
		ui_maintainer_assert( $modified === file_get_contents( $source ), $slug . ': export did not update packaged source.' );
		$afterManifest = $json->read( $f['manifest'] );
		ui_maintainer_assert( $manifest['version'] === $afterManifest['version'], $slug . ': export changed the profile version.' );
		$expectedManifest = $manifest;
		$expectedManifest['checksums'][ $f['path'] ] = hash( 'sha256', $modified );
		ui_maintainer_assert( $expectedManifest == $afterManifest, $slug . ': export changed unrelated manifest fields or allowlist entries.' );
		if ( 'tailwind' !== $slug ) {
			ui_maintainer_assert( json_decode( (string) file_get_contents( $f['manifest'] ), false, 512, JSON_THROW_ON_ERROR )->npm_dev_dependencies instanceof stdClass, $slug . ': empty dependency object became an array.' );
		}
		foreach ( $afterManifest['checksums'] as $path => $checksum ) {
			ui_maintainer_assert( hash_file( 'sha256', $f['profile'] . '/files/' . $path ) === $checksum, $slug . ': stale exported checksum for ' . $path );
		}
		ui_maintainer_assert( $themeBefore === ui_maintainer_snapshot( $theme->path ), $slug . ': export modified the workbench or receipt.' );
		foreach ( array_keys( $receipt['files'] ) as $path ) {
			if ( ! isset( $manifest['checksums'][ $path ] ) ) {
				ui_maintainer_assert( ! file_exists( $f['profile'] . '/files/' . $path ), $slug . ': receipt expanded the export allowlist: ' . $path );
			}
		}
		$before = ui_maintainer_snapshot( $f['case'] );
		$repeat = $service->export( $theme, $slug, $service->plan( $theme, $slug, true ) );
		ui_maintainer_assert( true === $repeat['clean'] && ! isset( $repeat['backup_path'] ) && $before === ui_maintainer_snapshot( $f['case'] ), $slug . ': repeated export was not a no-op.' );
	}

	// Default-theme replacements can still normalize repository URL casing.
	// Export compares raw package bytes; verification compares installed bytes.
	$f = ui_maintainer_fixture( 'normalized-source-export' );
	$raw = (string) file_get_contents( $f['profile'] . '/files/' . $f['path'] ) . "\n/* https://github.com/SnailTheme/wp-starter */\n";
	$installed = ( new ReplacementEngine() )->applyThemePatterns( $raw, $f['theme']->patterns );
	ui_maintainer_assert( $raw !== $installed, 'Repository normalization fixture must change installed bytes.' );
	$fs->dumpFile( $f['profile'] . '/files/' . $f['path'], $raw );
	$fs->dumpFile( $f['theme']->resolve( $f['path'] ), $installed );
	$manifest = $json->read( $f['manifest'] );
	$manifest['checksums'][ $f['path'] ] = hash( 'sha256', $raw );
	$json->write( $f['manifest'], $manifest );
	ui_maintainer_assert( $f['service']->plan( $f['theme'], 'bare' )['clean'], 'Verification must compare forward-transformed package bytes.' );
	$approved = $f['service']->plan( $f['theme'], 'bare', true );
	ui_maintainer_assert( ! $approved['clean'] && 'modified' === array_column( $approved['changes'], 'action', 'path' )[ $f['path'] ], 'Export must detect raw source drift even when verification matches.' );
	$secondPath = 'assets/scss/editor.scss';
	$fs->dumpFile( $f['theme']->resolve( $secondPath ), (string) file_get_contents( $f['theme']->resolve( $secondPath ) ) . "\n/* Another export edit. */\n" );
	$f['service']->export( $f['theme'], 'bare', $f['service']->plan( $f['theme'], 'bare', true ) );
	foreach ( $json->read( $f['manifest'] )['checksums'] as $path => $checksum ) {
		ui_maintainer_assert( hash_file( 'sha256', $f['profile'] . '/files/' . $path ) === $checksum, 'Export wrote a transformed checksum without copying its raw source: ' . $path );
	}
	ui_maintainer_assert( $f['service']->plan( $f['theme'], 'bare' )['clean'], 'Normalized export did not round-trip.' );

	$f = ui_maintainer_fixture( 'metadata-number-types' );
	$manifest = json_decode( (string) file_get_contents( $f['manifest'] ), false, 512, JSON_THROW_ON_ERROR );
	$manifest->future_metadata = (object) array( 'ratio' => 1.0, 'empty_object' => new stdClass(), 'empty_list' => array() );
	$fs->dumpFile( $f['manifest'], json_encode( $manifest, JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR ) );
	$fs->dumpFile( $f['theme']->resolve( $f['path'] ), "/* Change requiring manifest rewrite. */\n" );
	$f['service']->export( $f['theme'], 'bare', $f['service']->plan( $f['theme'], 'bare', true ) );
	$after = json_decode( (string) file_get_contents( $f['manifest'] ), false, 512, JSON_THROW_ON_ERROR );
	ui_maintainer_assert( serialize( $manifest->future_metadata ) === serialize( $after->future_metadata ), 'Export changed numeric/object/list types in unrelated metadata.' );

	foreach ( array( 'manifest', 'receipt' ) as $kind ) {
		$f = ui_maintainer_fixture( 'invalid-json-' . $kind );
		$path = 'manifest' === $kind ? $f['manifest'] : $f['theme']->resolve( UIProfileInstaller::STATE_FILE );
		$fs->dumpFile( $path, '{broken json' );
		$before = ui_maintainer_snapshot( $f['case'] );
		ui_maintainer_throws( static fn () => $f['service']->plan( $f['theme'], 'bare', true ), $path );
		ui_maintainer_assert( $before === ui_maintainer_snapshot( $f['case'] ), 'Invalid JSON preflight changed files.' );
	}

	$f = ui_maintainer_fixture( 'missing' );
	$fs->remove( $f['theme']->resolve( $f['path'] ) );
	$before = ui_maintainer_snapshot( $f['case'] );
	$plan = $f['service']->plan( $f['theme'], 'bare' );
	ui_maintainer_assert( false === $plan['clean'] && 'missing' === array_column( $plan['changes'], 'action', 'path' )[ $f['path'] ], 'Verification failed to report a missing workbench file.' );
	ui_maintainer_throws( static fn () => $f['service']->plan( $f['theme'], 'bare', true ), 'Missing workbench profile file' );
	ui_maintainer_assert( $before === ui_maintainer_snapshot( $f['case'] ), 'Missing source verification/export preflight wrote files.' );

	$f = ui_maintainer_fixture( 'stale-checksum' );
	$manifest = $json->read( $f['manifest'] );
	$manifest['checksums'][ $f['path'] ] = str_repeat( '0', 64 );
	$json->write( $f['manifest'], $manifest );
	$plan = $f['service']->plan( $f['theme'], 'bare', true );
	$change = array_column( $plan['changes'], null, 'path' )[ $f['path'] ];
	ui_maintainer_assert( ! $plan['clean'] && 'matching' === $change['action'] && true === $change['checksum_stale'], 'Stale checksum was hidden by matching source bytes.' );
	$f['service']->export( $f['theme'], 'bare', $plan );
	ui_maintainer_assert( $f['service']->plan( $f['theme'], 'bare' )['clean'], 'Checksum-only export did not repair the manifest.' );

	$f = ui_maintainer_fixture( 'missing-package' );
	$fs->remove( $f['profile'] . '/files/' . $f['path'] );
	$plan = $f['service']->plan( $f['theme'], 'bare', true );
	ui_maintainer_assert( 'add' === array_column( $plan['changes'], 'action', 'path' )[ $f['path'] ], 'Missing declared package file was not an addition.' );
	$result = $f['service']->export( $f['theme'], 'bare', $plan );
	ui_maintainer_assert( array( 'files/' . $f['path'] ) === $json->read( $result['backup_path'] . '/absent.json' ) && $f['service']->plan( $f['theme'], 'bare' )['clean'], 'Export did not restore a declared missing package file or record its absence.' );

	$f = ui_maintainer_fixture( 'custom-identity', 'bare', array( 'block_namespace' => 'acme', 'text_domain' => 'acme-theme', 'function_names' => 'acme_', 'constants' => 'ACME_' ) );
	$probe = (string) file_get_contents( $f['profile'] . '/files/' . $f['path'] ) . "\n/* stwp/example .stwp-example st_wp_starter_example */\n";
	$fs->dumpFile( $f['profile'] . '/files/' . $f['path'], $probe );
	$manifest = $json->read( $f['manifest'] );
	$manifest['checksums'][ $f['path'] ] = hash( 'sha256', $probe );
	$json->write( $f['manifest'], $manifest );
	$transformed = ( new ReplacementEngine() )->applyThemePatterns( $probe, $f['theme']->patterns );
	$fs->dumpFile( $f['theme']->resolve( $f['path'] ), $transformed );
	$before = ui_maintainer_snapshot( $f['case'] );
	ui_maintainer_assert( $probe !== $transformed && str_contains( $transformed, 'acme/example' ) && $f['service']->plan( $f['theme'], 'bare' )['clean'], 'Custom namespace verification did not apply forward replacements.' );
	ui_maintainer_throws( static fn () => $f['service']->plan( $f['theme'], 'bare', true ), 'starter-identity' );
	ui_maintainer_assert( $before === ui_maintainer_snapshot( $f['case'] ), 'Custom identity preflight wrote files.' );

	foreach ( array( '', '../bare', '/bare', 'Bare', 'bare/../../blueprint', 'bare\\other', 'bare:other', "bare\0" ) as $index => $slug ) {
		ui_maintainer_throws( static fn () => $f['service']->plan( $f['theme'], $slug ), 'Invalid UI profile slug' );
	}

	foreach ( array(
		'receipt-profile' => array( 'ui_profile', 'blueprint', 'does not match' ),
		'receipt-css' => array( 'css_entries', array( 'assets/styles/client.css' ), 'build configuration differs' ),
		'receipt-vite' => array( 'vite_plugins', array( 'client-plugin' ), 'build configuration differs' ),
	) as $name => [ $key, $value, $message ] ) {
		ui_maintainer_reject( $name, static function ( array &$f ) use ( $json, $key, $value ): void {
			$path = $f['theme']->resolve( UIProfileInstaller::STATE_FILE );
			$receipt = $json->read( $path );
			$receipt[ $key ] = $value;
			$json->write( $path, $receipt );
		}, $message );
	}
	ui_maintainer_reject( 'backup-inside-workbench', static function ( array &$f ) use ( $fs ): void {
		$workbench = dirname( $f['profiles'], 2 ) . '/.st-toolkit';
		$fs->mirror( $f['theme']->path, $workbench );
		$f['theme'] = ( new ThemeDetector() )->detect( $workbench );
	}, 'backup' );
	foreach ( array( 'css_entries', 'vite_plugins' ) as $key ) {
		foreach ( array( 'manifest', 'receipt', 'both' ) as $location ) {
			ui_maintainer_reject( 'object-list-' . $key . '-' . $location, static function ( array &$f ) use ( $fs, $key, $location ): void {
				$paths = array( 'manifest' => $f['manifest'], 'receipt' => $f['theme']->resolve( UIProfileInstaller::STATE_FILE ) );
				foreach ( $paths as $target => $path ) {
					if ( 'both' === $location || $target === $location ) {
						$data = json_decode( (string) file_get_contents( $path ), false, 512, JSON_THROW_ON_ERROR );
						$data->$key = new stdClass();
						$fs->dumpFile( $path, json_encode( $data, JSON_THROW_ON_ERROR ) );
					}
				}
			}, $key );
		}
		foreach ( array( array( 12 ), array( '' ), array( null ), array( new stdClass() ), array( array() ) ) as $index => $invalid ) {
			ui_maintainer_reject( 'invalid-list-values-' . $key . '-' . $index, static function ( array &$f ) use ( $fs, $key, $invalid ): void {
				foreach ( array( $f['manifest'], $f['theme']->resolve( UIProfileInstaller::STATE_FILE ) ) as $path ) {
					$data = json_decode( (string) file_get_contents( $path ), false, 512, JSON_THROW_ON_ERROR );
					$data->$key = $invalid;
					$fs->dumpFile( $path, json_encode( $data, JSON_THROW_ON_ERROR ) );
				}
			}, $key );
		}
	}
	foreach ( array(
		'/* $ui-profile: "bare"; */ $ui-profile: "blueprint";',
		"// \$ui-profile: \"bare\";\n\$ui-profile: \"blueprint\";",
		'$ui-profile: "bare"; $ui-profile: "blueprint";',
		'$example: \'$ui-profile: "bare";\'; $ui-profile: "blueprint";',
		'$ui-profile: "bare"; @if true { $ui-profile: "blueprint" !global; }',
		'$ui-profile: "ba/* not a comment inside a string */re";',
	) as $index => $contents ) {
		ui_maintainer_reject( 'false-marker-' . $index, static function ( array &$f ) use ( $fs, $contents ): void {
			$fs->dumpFile( $f['theme']->resolve( 'assets/scss/_st-toolkit-profile.scss' ), $contents );
			$componentPlan = ( new ComponentInstaller() )->plan( $f['theme'], ComponentManifest::load( 'mega-menu' ) );
			ui_maintainer_assert( ! $componentPlan['ui_profile_ready'], 'Component installer accepted a comment/string/overridden marker.' );
		}, 'marker' );
	}
	$f = ui_maintainer_fixture( 'literal-marker' );
	$fs->dumpFile( $f['theme']->resolve( 'assets/scss/_st-toolkit-profile.scss' ), "/* Literal managed value. */\n\$ui-profile : 'bare' !default; // trailing comment\n" );
	ui_maintainer_assert( ! $f['service']->plan( $f['theme'], 'bare' )['clean'], 'Valid literal marker whitespace/quotes should be accepted and reported as source drift.' );
	ui_maintainer_assert( ( new ComponentInstaller() )->plan( $f['theme'], ComponentManifest::load( 'mega-menu' ) )['ui_profile_ready'], 'Component installer disagrees with maintainer validation of a valid literal marker.' );
	ui_maintainer_reject( 'marker-mismatch', static function ( array &$f ) use ( $fs ): void {
		$fs->dumpFile( $f['theme']->resolve( 'assets/scss/_st-toolkit-profile.scss' ), '$ui-profile: "blueprint";' );
	}, 'marker disagrees' );
	ui_maintainer_reject( 'stale-context', static function ( array &$f ) use ( $fs ): void {
		$path = $f['theme']->resolve( 'core/bootstrap.php' );
		$fs->dumpFile( $path, ( new ReplacementEngine() )->applyThemePatterns( (string) file_get_contents( $path ), array( 'block_namespace' => 'changed' ) ) );
	}, 'bootstrap changed' );

	foreach ( array(
		'../outside.scss', '/assets/scss/absolute.scss', 'assets/scss/../escape.scss', 'assets/scss/./dot.scss', 'assets/scss//double.scss',
		'assets/scss/back\\slash.scss', 'assets/scss/colon:bad.scss', "assets/scss/null\0.scss", 'assets/scripts/execute.php', 'style.css',
		'assets/scss/core/private.scss', 'assets/scss/styles-enqueue/plugins/private.scss', 'assets/scss/styles-register/toolkit/private.scss',
		'assets/scss/components/toolkit/private.scss', 'assets/scss/components/mega-menu/_menu.scss', 'assets/scss/components/mega-menu.scss',
	) as $index => $unsafe ) {
		ui_maintainer_reject( 'unsafe-path-' . $index, static function ( array &$f ) use ( $json, $unsafe ): void {
			$manifest = $json->read( $f['manifest'] );
			$manifest['checksums'][ $unsafe ] = str_repeat( 'a', 64 );
			$json->write( $f['manifest'], $manifest );
		}, 'profile' );
	}
	foreach ( array( array(), null, array( 'assets/scss/main.scss' => 'invalid' ) ) as $index => $checksums ) {
		ui_maintainer_reject( 'bad-checksums-' . $index, static function ( array &$f ) use ( $json, $checksums ): void {
			$manifest = $json->read( $f['manifest'] );
			$manifest['checksums'] = $checksums;
			$json->write( $f['manifest'], $manifest );
		}, 2 === $index ? 'Invalid profile checksum' : 'allowlist' );
	}
	ui_maintainer_reject( 'extra-package-file', static function ( array &$f ) use ( $fs ): void {
		$fs->dumpFile( $f['profile'] . '/files/assets/scss/undeclared.scss', '/* Not declared. */' );
	}, 'Undeclared profile resource' );

	foreach ( array( 'source-file', 'destination-file', 'source-ancestor', 'destination-ancestor', 'workbench-root', 'profiles-root', 'package-ancestor', 'profile-root', 'files-root', 'manifest', 'receipt' ) as $kind ) {
		ui_maintainer_reject( 'symlink-' . $kind, static function ( array &$f ) use ( $fs, $kind ): void {
			$targets = array(
				'source-file' => $f['theme']->resolve( $f['path'] ),
				'destination-file' => $f['profile'] . '/files/' . $f['path'],
				'source-ancestor' => $f['theme']->path . '/assets',
				'destination-ancestor' => $f['profile'] . '/files/assets',
				'workbench-root' => $f['theme']->path,
				'profiles-root' => $f['profiles'],
				'package-ancestor' => $f['case'] . '/package',
				'profile-root' => $f['profile'],
				'files-root' => $f['profile'] . '/files',
				'manifest' => $f['manifest'],
				'receipt' => $f['theme']->resolve( UIProfileInstaller::STATE_FILE ),
			);
			$target = $targets[ $kind ];
			$outside = $f['case'] . '/outside';
			$fs->rename( $target, $outside );
			ui_maintainer_assert( symlink( $outside, $target ), 'Could not create symlink fixture.' );
		}, 'unsafe' );
	}
	ui_maintainer_reject( 'inventory-symlink', static function ( array &$f ) use ( $fs ): void {
		$fs->dumpFile( $f['case'] . '/outside.scss', '/* Outside. */' );
		ui_maintainer_assert( symlink( $f['case'] . '/outside.scss', $f['profile'] . '/files/extra.scss' ), 'Could not create inventory symlink.' );
	}, 'Symlink' );
	foreach ( array( '.st-toolkit', '.st-toolkit/backups' ) as $index => $ancestor ) {
		$f = ui_maintainer_fixture( 'backup-symlink-' . $index );
		$fs->dumpFile( $f['theme']->resolve( $f['path'] ), '/* Dirty source. */' );
		$approved = $f['service']->plan( $f['theme'], 'bare', true );
		$target = $f['case'] . '/package/' . $ancestor;
		$fs->mkdir( array( dirname( $target ), $f['case'] . '/outside-backups' ) );
		ui_maintainer_assert( symlink( $f['case'] . '/outside-backups', $target ), 'Could not create backup ancestor symlink.' );
		$before = ui_maintainer_snapshot( $f['case'] );
		ui_maintainer_throws( static fn () => $f['service']->export( $f['theme'], 'bare', $approved ), 'Unsafe profile directory' );
		ui_maintainer_assert( $before === ui_maintainer_snapshot( $f['case'] ), 'Export followed a symlinked backup ancestor.' );
	}
	foreach ( array( 'equal', 'workbench-inside', 'package-inside' ) as $kind ) {
		ui_maintainer_reject( 'overlap-' . $kind, static function ( array &$f ) use ( $fs, $kind ): void {
			$path = 'equal' === $kind ? $f['profile'] : ( 'workbench-inside' === $kind ? $f['profile'] . '/workbench' : $f['case'] . '/package' );
			$fs->mirror( $f['theme']->path . '/core', $path . '/core' );
			$fs->copy( $f['theme']->styleCssPath, $path . '/style.css' );
			$f['theme'] = ( new ThemeDetector() )->detect( $path );
		}, 'must not overlap' );
	}

	foreach ( array( 'workbench', 'package', 'manifest', 'bootstrap', 'receipt' ) as $kind ) {
		$f = ui_maintainer_fixture( 'stale-approval-' . $kind );
		$fs->dumpFile( $f['theme']->resolve( $f['path'] ), "/* Approved edit. */\n" );
		$approved = $f['service']->plan( $f['theme'], 'bare', true );
		$targets = array( 'workbench' => $f['theme']->resolve( $f['path'] ), 'package' => $f['profile'] . '/files/' . $f['path'], 'manifest' => $f['manifest'], 'bootstrap' => $f['theme']->bootstrapPath, 'receipt' => $f['theme']->resolve( UIProfileInstaller::STATE_FILE ) );
		$path = $targets[ $kind ];
		$fs->dumpFile( $path, (string) file_get_contents( $path ) . "\n" );
		$before = ui_maintainer_snapshot( $f['case'] );
		ui_maintainer_throws( static fn () => $f['service']->export( $f['theme'], 'bare', $approved ), 'changed after planning' );
		ui_maintainer_assert( $before === ui_maintainer_snapshot( $f['case'] ), $kind . ': stale approval wrote package files or backups.' );
	}

	foreach ( array( false, true ) as $failRecovery ) {
		$f = ui_maintainer_fixture( 'write-failure-' . (int) $failRecovery );
		$source = $f['profile'] . '/files/' . $f['path'];
		$originalSource = (string) file_get_contents( $source );
		$originalManifest = (string) file_get_contents( $f['manifest'] );
		$packageBefore = ui_maintainer_snapshot( $f['profile'] );
		$fs->dumpFile( $f['theme']->resolve( $f['path'] ), "/* Changed before failing export. */\n" );
		$themeBefore = ui_maintainer_snapshot( $f['theme']->path );
		$failing = new UIProfileFailingFilesystem( $f['manifest'], $source, $failRecovery );
		$service = new UIProfileMaintainer( $f['profiles'], $failing );
		$approved = $service->plan( $f['theme'], 'bare', true );
		$exception = ui_maintainer_throws( static fn () => $service->export( $f['theme'], 'bare', $approved ), $failRecovery ? 'manual recovery required' : 'package files restored' );
		ui_maintainer_assert( $failing->failed && 2 === $failing->sourceWrites, 'Failure injection did not follow a source write and attempt recovery.' );
		ui_maintainer_assert( 1 === preg_match( '/Backup: (.+)$/', $exception->getMessage(), $matches ), 'Export failure omitted backup path diagnostics.' );
		$backup = $matches[1];
		ui_maintainer_assert( is_dir( $backup ) && $originalSource === file_get_contents( $backup . '/files/' . $f['path'] ) && $originalManifest === file_get_contents( $backup . '/st-ui.json' ), 'Write failure lost recoverable backup bytes.' );
		ui_maintainer_assert( $exception->getPrevious()?->getMessage() === 'Fixture manifest write failed', 'Write failure lost its original cause.' );
		ui_maintainer_assert( $originalManifest === file_get_contents( $f['manifest'] ) && $themeBefore === ui_maintainer_snapshot( $f['theme']->path ), 'Write failure altered the manifest or workbench.' );
		ui_maintainer_assert( $failRecovery ? str_contains( $exception->getMessage(), $f['path'] ) : $packageBefore === ui_maintainer_snapshot( $f['profile'] ), 'Recovery did not restore the package or identify the unrecovered path.' );
	}

	// Exercise confirmed CLI writes only in a simulated toolkit/vendor checkout.
	$f = ui_maintainer_fixture( 'cli-package-write' );
	$package = dirname( $f['profiles'], 2 );
	$fs->copy( $root . '/src/Support/PackagePaths.php', $package . '/src/Support/PackagePaths.php' );
	$fs->mirror( $root . '/resources/core', $package . '/resources/core' );
	$fs->dumpFile( $f['theme']->resolve( $f['path'] ), "/* CLI confirmed export. */\n" );
	$arguments = array( 'ui:profile-export', 'bare', '--theme=' . $f['theme']->path, '--yes', '--json', '--no-interaction' );
	$before = ui_maintainer_snapshot( $f['case'] );
	$dryRun = ui_maintainer_package_cli( $package, array_merge( $arguments, array( '--dry-run' ) ) );
	ui_maintainer_assert( 0 === $dryRun['status'] && true === ( $dryRun['json']['dry_run'] ?? null ) && $before === ui_maintainer_snapshot( $f['case'] ), 'Vendor export dry-run should remain read-only.' );
	$vendor = ui_maintainer_package_cli( $package, $arguments );
	ui_maintainer_assert( 1 === $vendor['status'] && str_contains( $vendor['json']['error'] ?? '', 'Git checkout' ) && $before === ui_maintainer_snapshot( $f['case'] ), 'Vendor export was not rejected without writes.' );
	foreach ( array( 'directory', 'worktree-file' ) as $kind ) {
		if ( 'directory' === $kind ) {
			$fs->mkdir( $package . '/.git' );
		} else {
			$fs->dumpFile( $package . '/.git', 'gitdir: /fixture/toolkit' );
		}
		$sourceBefore = ui_maintainer_snapshot( $f['theme']->path );
		$write = ui_maintainer_package_cli( $package, $arguments );
		ui_maintainer_assert( 0 === $write['status'] && true === ( $write['json']['exported'] ?? null ) && '' === $write['stderr'], 'Confirmed CLI export failed: ' . $write['output'] . $write['stderr'] );
		ui_maintainer_assert( is_file( $write['json']['backup_path'] . '/st-ui.json' ), 'Confirmed CLI export did not back up its manifest.' );
		$verifyResult = ui_maintainer_package_cli( $package, array( 'ui:profile-verify', 'bare', '--theme=' . $f['theme']->path, '--json' ) );
		ui_maintainer_assert( 0 === $verifyResult['status'] && true === ( $verifyResult['json']['clean'] ?? null ), 'Confirmed CLI export left drift.' );
		ui_maintainer_assert( $sourceBefore === ui_maintainer_snapshot( $f['theme']->path ), 'Confirmed CLI export changed workbench bytes.' );
		$fs->remove( $package . '/.git' );
		$fs->dumpFile( $f['theme']->resolve( $f['path'] ), (string) file_get_contents( $f['theme']->resolve( $f['path'] ) ) . "/* Next export. */\n" );
	}
	$fs->symlink( $f['theme']->path, $package . '/.git' );
	$before = ui_maintainer_snapshot( $f['case'] );
	$linked = ui_maintainer_package_cli( $package, $arguments );
	ui_maintainer_assert( 1 === $linked['status'] && str_contains( $linked['json']['error'] ?? '', 'Git checkout' ) && $before === ui_maintainer_snapshot( $f['case'] ), 'Symlink checkout marker permitted writes.' );

	// Actual commands resolve the real package: never pass --yes without --dry-run.
	$f = ui_maintainer_fixture( 'cli' );
	$app = new Application();
	$verify = new CommandTester( $app->find( 'ui:profile-verify' ) );
	$export = new CommandTester( $app->find( 'ui:profile-export' ) );
	$realBefore = ui_maintainer_snapshot( $root . '/resources/ui-profiles' );
	$before = ui_maintainer_snapshot( $f['case'] );
	$arguments = array( 'profile' => 'bare', '--theme' => $f['theme']->path, '--json' => true );
	ui_maintainer_assert( 0 === $verify->execute( $arguments, array( 'interactive' => false ) ), 'CLI clean verify failed.' );
	$data = json_decode( $verify->getDisplay(), true, 512, JSON_THROW_ON_ERROR );
	ui_maintainer_assert( true === $data['clean'] && false === $data['exported'] && ! isset( $data['snapshot'] ) && ! isset( $data['checksums'] ), 'CLI verify JSON exposed approval internals or wrong state.' );
	ui_maintainer_assert( $before === ui_maintainer_snapshot( $f['case'] ), 'CLI verification wrote files.' );
	$fs->dumpFile( $f['theme']->resolve( $f['path'] ), "/* CLI drift. */\n" );
	$before = ui_maintainer_snapshot( $f['case'] );
	ui_maintainer_assert( 1 === $verify->execute( $arguments, array( 'interactive' => false ) ), 'CLI drift verification did not fail.' );
	$data = json_decode( $verify->getDisplay(), true, 512, JSON_THROW_ON_ERROR );
	ui_maintainer_assert( false === $data['clean'] && 'modified' === array_column( $data['changes'], 'action', 'path' )[ $f['path'] ], 'CLI drift JSON omitted the modified file.' );
	ui_maintainer_assert( 0 === $export->execute( $arguments + array( '--dry-run' => true, '--yes' => true ), array( 'interactive' => false ) ), 'CLI export dry run failed.' );
	$data = json_decode( $export->getDisplay(), true, 512, JSON_THROW_ON_ERROR );
	ui_maintainer_assert( true === $data['dry_run'] && false === $data['exported'] && ! isset( $data['backup_path'] ), 'CLI dry-run JSON claimed an export or backup.' );
	ui_maintainer_assert( 0 === $export->execute( array( 'profile' => 'bare', '--theme' => $f['theme']->path, '--dry-run' => true ), array( 'interactive' => false ) ) && str_contains( $export->getDisplay(), 'Dry run: no files written.' ), 'CLI text dry run failed or omitted its no-write result.' );
	ui_maintainer_assert( 1 === $export->execute( $arguments, array( 'interactive' => false ) ), 'Noninteractive export did not cancel.' );
	$data = json_decode( $export->getDisplay(), true, 512, JSON_THROW_ON_ERROR );
	ui_maintainer_assert( true === $data['cancelled'] && false === $data['exported'], 'Noninteractive cancellation JSON was not explicit.' );
	$export->setInputs( array( 'no' ) );
	ui_maintainer_assert( 1 === $export->execute( $arguments, array( 'interactive' => true ) ), 'JSON export did not cancel.' );
	$data = json_decode( $export->getDisplay(), true, 512, JSON_THROW_ON_ERROR );
	ui_maintainer_assert( true === $data['cancelled'] && false === $data['exported'], 'Interactive JSON export did not cancel without --yes.' );
	$export->setInputs( array( 'no' ) );
	ui_maintainer_assert( 1 === $export->execute( array( 'profile' => 'bare', '--theme' => $f['theme']->path ), array( 'interactive' => true ) ), 'Declined human export should fail without writes.' );
	$display = $export->getDisplay();
	ui_maintainer_assert( false !== strpos( $display, $f['path'] ) && strpos( $display, $f['path'] ) < strpos( $display, 'Replace the listed packaged profile files' ), 'Export confirmation must display paths before asking permission.' );
	foreach ( array( $verify, $export ) as $tester ) {
		$bad = $arguments;
		$bad['profile'] = '../bare';
		ui_maintainer_assert( 1 === $tester->execute( $bad, array( 'interactive' => false ) ), 'CLI invalid slug succeeded.' );
		$data = json_decode( $tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR );
		ui_maintainer_assert( str_contains( $data['error'], 'Invalid UI profile slug' ), 'CLI error output was not a JSON error.' );
	}
	ui_maintainer_assert( $before === ui_maintainer_snapshot( $f['case'] ), 'CLI dry-run/cancellation/error paths wrote files or backups.' );
	$fs->remove( $f['theme']->resolve( $f['path'] ) );
	$missingBefore = ui_maintainer_snapshot( $f['case'] );
	ui_maintainer_assert( 1 === $verify->execute( $arguments, array( 'interactive' => false ) ), 'CLI missing source verification succeeded.' );
	$data = json_decode( $verify->getDisplay(), true, 512, JSON_THROW_ON_ERROR );
	ui_maintainer_assert( 'missing' === array_column( $data['changes'], 'action', 'path' )[ $f['path'] ], 'CLI JSON did not report missing source.' );
	ui_maintainer_assert( 1 === $export->execute( $arguments + array( '--dry-run' => true ), array( 'interactive' => false ) ), 'CLI missing source export dry run succeeded.' );
	$data = json_decode( $export->getDisplay(), true, 512, JSON_THROW_ON_ERROR );
	ui_maintainer_assert( str_contains( $data['error'], 'Missing workbench profile file' ), 'CLI missing export source error was not JSON.' );
	ui_maintainer_assert( $missingBefore === ui_maintainer_snapshot( $f['case'] ), 'CLI missing-source paths wrote files or backups.' );

	foreach ( array( 'source', 'receipt', 'bootstrap' ) as $kind ) {
		$f = ui_maintainer_fixture( 'cli-unreadable-' . $kind );
		$paths = array( 'source' => $f['path'], 'receipt' => UIProfileInstaller::STATE_FILE, 'bootstrap' => 'core/bootstrap.php' );
		$path = $f['theme']->resolve( $paths[ $kind ] );
		$mode = fileperms( $path ) & 0777;
		$before = ui_maintainer_snapshot( $f['case'] );
		$process = null;
		try {
			$fs->chmod( $path, 0000 );
			clearstatcache( true, $path );
			if ( ! is_readable( $path ) ) {
				$process = new Process( array( PHP_BINARY, '-d', 'display_errors=1', $root . '/bin/st-toolkit', 'ui:profile-verify', 'bare', '--theme=' . $f['theme']->path, '--json', '--no-interaction' ), $root );
				$process->setTimeout( 30 );
				$process->run();
			}
		} finally {
			$fs->chmod( $path, $mode );
			clearstatcache( true, $path );
		}
		ui_maintainer_assert( $mode === ( fileperms( $path ) & 0777 ) && $before === ui_maintainer_snapshot( $f['case'] ), 'Unreadable ' . $kind . ': CLI wrote files or permissions were not restored.' );
		ui_maintainer_assert( $realBefore === ui_maintainer_snapshot( $root . '/resources/ui-profiles' ), 'Unreadable ' . $kind . ': CLI changed real package resources.' );
		if ( null === $process ) {
			fwrite( STDERR, 'Skipping unreadable ' . $kind . ": this user can read chmod 000 files.\n" );
			continue;
		}
		ui_maintainer_assert( 1 === $process->getExitCode(), 'Unreadable ' . $kind . ': CLI did not exit with failure.' );
		$stdout = $process->getOutput();
		$data = json_decode( $stdout, true );
		ui_maintainer_assert( JSON_ERROR_NONE === json_last_error() && is_array( $data ), 'Unreadable ' . $kind . ': CLI stdout was not parseable JSON: ' . $stdout );
		ui_maintainer_assert( is_string( $data['error'] ?? null ) && str_contains( $data['error'], $path ), 'Unreadable ' . $kind . ': JSON error did not identify the unreadable fixture file.' );
		ui_maintainer_assert( '' === $process->getErrorOutput(), 'Unreadable ' . $kind . ': CLI emitted stderr diagnostics: ' . $process->getErrorOutput() );
	}
	ui_maintainer_assert( $realBefore === ui_maintainer_snapshot( $root . '/resources/ui-profiles' ), 'CLI tests changed real packaged profile resources.' );
	fwrite( STDOUT, sprintf( "UI profile maintainer checks passed (%d assertions).\n", $assertions ) );
} finally {
	$fs->remove( $temp );
}

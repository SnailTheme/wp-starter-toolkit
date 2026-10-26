<?php
/**
 * Core manifest maintainer regression checks. All mutations use temp fixtures.
 */

declare(strict_types=1);

use SnailTheme\WPStarterToolkit\Command\CoreManifestCommand;
use SnailTheme\WPStarterToolkit\Core\CoreManifestGenerator;
use SnailTheme\WPStarterToolkit\Core\CoreManifest;
use SnailTheme\WPStarterToolkit\Core\CoreUpdater;
use SnailTheme\WPStarterToolkit\Theme\ThemeContext;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

require dirname( __DIR__ ) . '/vendor/autoload.php';

$fs = new Filesystem();
$temp = sys_get_temp_dir() . '/st-core-manifest-tests-' . bin2hex( random_bytes( 6 ) );
$assertions = 0;
$root = dirname( __DIR__ );

function core_manifest_assert( bool $condition, string $message ): void {
	global $assertions;
	++$assertions;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function core_manifest_throws( callable $action, string $message ): void {
	try {
		$action();
	} catch ( RuntimeException $exception ) {
		core_manifest_assert( str_contains( $exception->getMessage(), $message ), 'Unexpected error: ' . $exception->getMessage() );
		return;
	}
	throw new RuntimeException( 'Expected error containing: ' . $message );
}

/** @return array<string,mixed> Include inodes to detect same-byte rewrites. */
function core_manifest_snapshot( string $path ): array {
	clearstatcache();
	$entries = array();
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST ) as $file ) {
		$name = substr( $file->getPathname(), strlen( $path ) + 1 );
		$entries[ $name ] = $file->isLink() ? array( 'link', readlink( $file->getPathname() ) ) : ( $file->isDir() ? array( 'directory' ) : array( hash_file( 'sha256', $file->getPathname() ), $file->getInode(), $file->getMTime(), $file->getPerms() ) );
	}
	ksort( $entries );
	return $entries;
}

function core_manifest_write( string $root, stdClass $manifest ): void {
	( new Filesystem() )->dumpFile( $root . '/st-wp-core-manifest.json', json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR ) . "\n" );
}

function core_manifest_data( string $root ): stdClass {
	return json_decode( (string) file_get_contents( $root . '/st-wp-core-manifest.json' ), false, 512, JSON_THROW_ON_ERROR );
}

function core_manifest_fixture( string $root ): CoreManifestGenerator {
	$fs = new Filesystem();
	$fs->dumpFile( $root . '/files/core/bootstrap.php', "<?php\ndeclare(strict_types=1);\ndefine( 'ST_WP_CORE_VERSION', '1.2.0' );\n" );
	$fs->dumpFile( $root . '/files/core/helpers.php', "<?php\ndeclare(strict_types=1);\nif ( ! function_exists( 'st_wp_core_fixture' ) ) { function &st_wp_core_fixture() { static \$value; return \$value; } }\n" );
	$fs->dumpFile( $root . '/files/core/.hidden', 'hidden resource' );
	$fs->dumpFile( $root . '/files/core/image.bin', "\0\xff\x01" );
	$fs->dumpFile( $root . '/files/assets/scripts/core/example.js', "console.log('fixture');\n" );
	core_manifest_write( $root, (object) array(
		'schema' => 1,
		'name' => 'st-wp-core',
		'version' => '1.2.0',
		'files_root' => 'files',
		'owned_files' => array( 'core/**', 'assets/scripts/core/**' ),
		'removed_files' => array( 'assets/scripts/core-admin.js' ),
		'required_public_helpers' => array( 'st_wp_core_fixture' ),
		'replacement_profile' => 'fixture-profile',
		'future_metadata' => (object) array( 'empty_object' => new stdClass(), 'empty_list' => array(), 'flag' => false, 'float' => 1.0, 'nested' => array( (object) array( 'keep' => 'unchanged' ) ) ),
		'checksums' => new stdClass(),
	) );
	return new CoreManifestGenerator( $root );
}

/** Run an injected fixture service through the real command implementation. */
function core_manifest_command( CoreManifestGenerator $generator ): CommandTester {
	$app = new Application();
	$app->add( new CoreManifestCommand( $generator ) );
	return new CommandTester( $app->find( 'core:manifest' ) );
}

/** @return array{status:?int,json:?array,output:string} A simulated vendor install. */
function core_manifest_vendor_cli( string $package, array $arguments ): array {
	global $root;
	// Load the copied path resolver first, so the command resolves this package
	// rather than the maintainer checkout. There is no fixture-root CLI option.
	$code = 'require ' . var_export( $package . '/src/Support/PackagePaths.php', true ) . '; require ' . var_export( $root . '/vendor/autoload.php', true ) . ';'
		. '$app = new Symfony\Component\Console\Application(); $app->add(new SnailTheme\WPStarterToolkit\Command\CoreManifestCommand()); exit($app->run());';
	$process = new Process( array_merge( array( PHP_BINARY, '-r', $code, '--', 'core:manifest' ), $arguments ), $package );
	$process->setTimeout( 30 );
	$process->run();
	return array( 'status' => $process->getExitCode(), 'json' => json_decode( $process->getOutput(), true ), 'output' => $process->getOutput() . $process->getErrorOutput() );
}

$packageBefore = core_manifest_snapshot( $root . '/resources/core' );
try {
	// A namespaced native-function shim gives deterministic mid-write races
	// without adding test hooks or filesystem overrides to production code.
	$fs->dumpFile( $temp . '/flush-hook.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace SnailTheme\WPStarterToolkit\Core;
function fsync( $stream ): bool {
	$result = \fsync( $stream );
	$hook = $GLOBALS['st_core_manifest_flush_hook'] ?? null;
	unset( $GLOBALS['st_core_manifest_flush_hook'] );
	if ( null !== $hook ) {
		$hook();
	}
	return $result;
}
PHP
	);
	require $temp . '/flush-hook.php';
	$fixture = $temp . '/basic/resources/core';
	$generator = core_manifest_fixture( $fixture );
	$metadata = core_manifest_data( $fixture );
	unset( $metadata->checksums );
	$before = core_manifest_snapshot( $temp );
	$plan = $generator->plan();
	core_manifest_assert( $plan === $generator->plan(), 'Repeated plans are not stable.' );
	core_manifest_assert( $before === core_manifest_snapshot( $temp ), 'Planning created/modified resources.' );
	core_manifest_assert( true === $plan['drift'] && 5 === $plan['file_count'] && array( 'missing' ) === array_values( array_unique( array_column( $plan['changes'], 'action' ) ) ), 'Missing checksum entries were not detected.' );
	$manifestPath = $fixture . '/st-wp-core-manifest.json';
	chmod( $manifestPath, 0640 );
	$inode = fileinode( $manifestPath );
	$result = $generator->generate();
	clearstatcache();
	core_manifest_assert( $result['written'] && $inode !== fileinode( $manifestPath ) && 0640 === ( fileperms( $manifestPath ) & 0777 ), 'Manifest replacement was not atomic or lost permissions.' );
	$data = core_manifest_data( $fixture );
	foreach ( get_object_vars( $data->checksums ) as $path => $hash ) {
		core_manifest_assert( hash_file( 'sha256', $fixture . '/files/' . $path ) === $hash, 'Incorrect raw-byte SHA-256: ' . $path );
	}
	$keys = array_keys( get_object_vars( $data->checksums ) );
	$sorted = $keys;
	sort( $sorted, SORT_STRING );
	core_manifest_assert( $keys === $sorted && isset( $data->checksums->{'core/.hidden'} ), 'Checksums are unsorted or skipped a dotfile.' );
	unset( $data->checksums );
	core_manifest_assert( serialize( $metadata ) === serialize( $data ), 'Generation changed metadata, version, lists or object/list types.' );
	$before = core_manifest_snapshot( $temp );
	core_manifest_assert( ! $generator->plan()['drift'] && ! $generator->generate()['written'], 'An unchanged manifest is not a no-op.' );
	core_manifest_assert( $before === core_manifest_snapshot( $temp ), 'No-op generation rewrote a file.' );

	$data = core_manifest_data( $fixture );
	unset( $data->checksums->{'core/.hidden'} );
	$data->checksums->{'core/helpers.php'} = str_repeat( '0', 64 );
	$data->checksums->{'core/absent.php'} = str_repeat( 'a', 64 );
	core_manifest_write( $fixture, $data );
	$tester = core_manifest_command( $generator );
	$before = core_manifest_snapshot( $temp );
	core_manifest_assert( 1 === $tester->execute( array( '--check' => true, '--json' => true, '--yes' => true ) ), '--check drift must exit nonzero even with --yes.' );
	$checked = json_decode( $tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR );
	core_manifest_assert( array( 'missing', 'extra', 'stale' ) === array_column( $checked['changes'], 'action' ) && ! $checked['written'], 'Check did not report missing, extra and stale hashes in path order.' );
	core_manifest_assert( 0 === $tester->execute( array( '--dry-run' => true, '--json' => true, '--yes' => true ) ), 'Dry-run should succeed on valid drift.' );
	core_manifest_assert( $before === core_manifest_snapshot( $temp ), 'Check or dry-run wrote files.' );
	core_manifest_assert( 1 === $tester->execute( array( '--check' => true, '--dry-run' => true, '--yes' => true ) ), '--check must retain drift exit status with --dry-run.' );
	core_manifest_assert( 1 === $tester->execute( array(), array( 'interactive' => false ) ) && str_contains( $tester->getDisplay(), 'cancelled' ), 'Noninteractive unconfirmed write was not cancelled with failure status.' );
	$tester->setInputs( array( 'no' ) );
	core_manifest_assert( 1 === $tester->execute( array(), array( 'interactive' => true ) ), 'Declined write must exit nonzero.' );
	$display = $tester->getDisplay();
	core_manifest_assert( false !== strpos( $display, 'core/helpers.php' ) && strpos( $display, 'core/helpers.php' ) < strpos( $display, 'Write packaged core manifest checksums?' ), 'Core confirmation must display checksum changes before asking permission.' );
	core_manifest_assert( str_contains( $tester->getDisplay(), 'cancelled' ) && $before === core_manifest_snapshot( $temp ), 'Declined write changed resources.' );
	$tester->setInputs( array( 'yes' ) );
	core_manifest_assert( 1 === $tester->execute( array( '--json' => true ), array( 'interactive' => true ) ), 'Unconfirmed JSON write must exit nonzero.' );
	$cancelled = json_decode( $tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR );
	core_manifest_assert( $cancelled['cancelled'] && $before === core_manifest_snapshot( $temp ), 'JSON without --yes prompted or wrote files.' );
	core_manifest_assert( ! array_key_exists( 'snapshot', $cancelled ) && ! array_key_exists( 'contents', $cancelled ), 'Command leaked internal approval state.' );
	core_manifest_assert( 0 === $tester->execute( array( '--yes' => true, '--json' => true ) ), 'Confirmed command failed.' );
	core_manifest_assert( json_decode( $tester->getDisplay(), true )['written'], 'Confirmed command did not write.' );
	core_manifest_assert( 0 === $tester->execute( array( '--check' => true, '--json' => true ) ), 'Clean --check should exit zero.' );
	$before = core_manifest_snapshot( $temp );
	$tester->execute( array( '--yes' => true, '--json' => true ) );
	core_manifest_assert( ! json_decode( $tester->getDisplay(), true )['written'] && $before === core_manifest_snapshot( $temp ), 'Clean command rewrote manifest.' );
	$definition = ( new CoreManifestCommand() )->getDefinition();
	core_manifest_assert( ! $definition->hasOption( 'theme' ) && ! $definition->hasOption( 'root' ) && ! $definition->hasOption( 'resources-root' ), 'Maintainer command exposes a target override.' );

	foreach ( array( 'source', 'new-source', 'deleted-source', 'manifest' ) as $change ) {
		$dir = $temp . '/stale-approval-' . $change;
		$stale = core_manifest_fixture( $dir );
		$approved = $stale->plan()['snapshot'];
		if ( 'source' === $change ) {
			$fs->dumpFile( $dir . '/files/core/.hidden', 'changed after approval' );
		} elseif ( 'new-source' === $change ) {
			$fs->dumpFile( $dir . '/files/core/new.txt', 'added after approval' );
		} elseif ( 'deleted-source' === $change ) {
			unlink( $dir . '/files/core/.hidden' );
		} else {
			// Whitespace-only edits must invalidate approval too: bind raw bytes.
			$fs->dumpFile( $dir . '/st-wp-core-manifest.json', file_get_contents( $dir . '/st-wp-core-manifest.json' ) . "\n" );
		}
		$before = core_manifest_snapshot( $temp );
		core_manifest_throws( static fn () => $stale->generate( $approved ), 'changed since approval' );
		core_manifest_assert( $before === core_manifest_snapshot( $temp ), 'Stale approval wrote files: ' . $change );
		core_manifest_assert( $stale->generate( $stale->plan()['snapshot'] )['written'], 'A fresh approval did not permit generation.' );
	}
	foreach ( array( 'source', 'manifest' ) as $change ) {
		$dir = $temp . '/confirmation-race-' . $change;
		$racy = core_manifest_fixture( $dir );
		$afterMutation = array();
		$mutate = static function () use ( $dir, $change, &$afterMutation ): void {
			$target = 'source' === $change ? $dir . '/files/core/.hidden' : $dir . '/st-wp-core-manifest.json';
			( new Filesystem() )->dumpFile( $target, file_get_contents( $target ) . "\n" );
			$afterMutation = core_manifest_snapshot( $dir );
		};
		$app = new Application();
		$app->getHelperSet()->set( new class( $mutate ) extends QuestionHelper {
			public function __construct( private readonly Closure $mutate ) {}
			public function ask( InputInterface $input, OutputInterface $output, Question $question ): mixed {
				( $this->mutate )();
				return true;
			}
		} );
		$app->add( new CoreManifestCommand( $racy ) );
		$raceTester = new CommandTester( $app->find( 'core:manifest' ) );
		core_manifest_assert( 1 === $raceTester->execute( array(), array( 'interactive' => true ) ) && str_contains( $raceTester->getDisplay(), 'changed since approval' ), 'Command accepted a changed ' . $change . ' after confirmation.' );
		core_manifest_assert( $afterMutation === core_manifest_snapshot( $dir ), 'Command wrote a stale approved manifest.' );
	}
	foreach ( array( 'source', 'new-source', 'deleted-source', 'manifest' ) as $change ) {
		$dir = $temp . '/mid-write-' . $change;
		$midWrite = core_manifest_fixture( $dir );
		$afterMutation = array();
		$GLOBALS['st_core_manifest_flush_hook'] = static function () use ( $dir, $change, &$afterMutation ): void {
			$fs = new Filesystem();
			if ( 'deleted-source' === $change ) {
				unlink( $dir . '/files/core/.hidden' );
			} else {
				$target = match ( $change ) {
					'source' => $dir . '/files/core/.hidden',
					'new-source' => $dir . '/files/core/new.txt',
					default => $dir . '/st-wp-core-manifest.json',
				};
				$fs->dumpFile( $target, ( is_file( $target ) ? file_get_contents( $target ) : '' ) . "\n" );
			}
			$afterMutation = core_manifest_snapshot( $dir );
			foreach ( array_keys( $afterMutation ) as $path ) {
				if ( str_starts_with( $path, '.st-core-manifest-' ) ) {
					unset( $afterMutation[ $path ] );
				}
			}
		};
		core_manifest_throws( static fn () => $midWrite->generate(), 'changed during generation' );
		core_manifest_assert( $afterMutation === core_manifest_snapshot( $dir ), 'Mid-write change committed a manifest or left a temp file: ' . $change );
	}
	$dir = $temp . '/failed-flush';
	$failedFlush = core_manifest_fixture( $dir );
	$before = core_manifest_snapshot( $dir );
	$GLOBALS['st_core_manifest_flush_hook'] = static function (): void { throw new RuntimeException( 'Fixture flush failure' ); };
	core_manifest_throws( static fn () => $failedFlush->generate(), 'Fixture flush failure' );
	core_manifest_assert( $before === core_manifest_snapshot( $dir ), 'Interrupted write changed manifest or leaked a temp file.' );

	// Native read warnings must not precede JSON, even when PHP displays errors.
	foreach ( array( 'st-wp-core-manifest.json', 'files/core/bootstrap.php' ) as $relative ) {
		$dir = $temp . '/unreadable-' . basename( $relative );
		core_manifest_fixture( $dir );
		$unreadable = $dir . '/' . $relative;
		$before = core_manifest_snapshot( $dir );
		$permissions = fileperms( $unreadable ) & 0777;
		core_manifest_assert( chmod( $unreadable, 0000 ), 'Could not deny fixture read permissions: ' . $relative );
		try {
			$probe = @fopen( $unreadable, 'rb' );
			if ( false !== $probe ) {
				fclose( $probe );
				fwrite( STDOUT, 'Skipping unreadable ' . $relative . ': this user can read mode-000 files.' . "\n" );
				continue;
			}
			$code = 'require ' . var_export( $root . '/vendor/autoload.php', true ) . ';'
				. '$generator = new SnailTheme\WPStarterToolkit\Core\CoreManifestGenerator(' . var_export( $dir, true ) . ');'
				. '$tester = new Symfony\Component\Console\Tester\CommandTester(new SnailTheme\WPStarterToolkit\Command\CoreManifestCommand($generator));'
				. '$status = $tester->execute(array("--check" => true, "--json" => true), array("interactive" => false));'
				. 'fwrite(STDOUT, $tester->getDisplay()); exit($status);';
			$process = new Process( array( PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=-1', '-d', 'log_errors=0', '-r', $code ), $root );
			$process->setTimeout( 30 );
			$process->run();
			core_manifest_assert( 1 === $process->getExitCode(), 'Unreadable fixture check must exit 1: ' . $relative );
			$json = json_decode( $process->getOutput(), true );
			core_manifest_assert( JSON_ERROR_NONE === json_last_error() && is_array( $json ) && array( 'error' ) === array_keys( $json ), 'Unreadable fixture stdout is not a single JSON error: ' . $process->getOutput() );
			core_manifest_assert( 'Could not read core resource: ' . $unreadable === $json['error'], 'Unreadable fixture returned an unexpected error.' );
			core_manifest_assert( '' === $process->getErrorOutput(), 'Unreadable fixture leaked native warnings to stderr: ' . $process->getErrorOutput() );
			clearstatcache();
			core_manifest_assert( 0000 === ( fileperms( $unreadable ) & 0777 ), 'Read-only check changed fixture permissions.' );
		} finally {
			chmod( $unreadable, $permissions );
		}
		core_manifest_assert( $before === core_manifest_snapshot( $dir ), 'Unreadable fixture check wrote files: ' . $relative );
	}

	// Every malformed or unsafe fixture must fail before creating a temp file.
	$mutations = array(
		'json' => static function ( string $dir ): void { ( new Filesystem() )->dumpFile( $dir . '/st-wp-core-manifest.json', '{broken' ); },
		'list-json' => static function ( string $dir ): void { ( new Filesystem() )->dumpFile( $dir . '/st-wp-core-manifest.json', '[]' ); },
		'missing-manifest' => static function ( string $dir ): void { unlink( $dir . '/st-wp-core-manifest.json' ); },
		'missing-files-root' => static function ( string $dir ): void { ( new Filesystem() )->remove( $dir . '/files' ); },
		'missing-bootstrap' => static function ( string $dir ): void { unlink( $dir . '/files/core/bootstrap.php' ); },
		'missing-helper' => static function ( string $dir ): void { unlink( $dir . '/files/core/helpers.php' ); },
		'invalid-php' => static function ( string $dir ): void { ( new Filesystem() )->dumpFile( $dir . '/files/core/helpers.php', '<?php function broken(' ); },
		'unowned-file' => static function ( string $dir ): void { ( new Filesystem() )->dumpFile( $dir . '/files/inc/project.php', '<?php // project owned' ); },
		'removed-file' => static function ( string $dir ): void { ( new Filesystem() )->dumpFile( $dir . '/files/assets/scripts/core-admin.js', 'removed' ); },
		'version-mismatch' => static function ( string $dir ): void { ( new Filesystem() )->dumpFile( $dir . '/files/core/bootstrap.php', "<?php define('ST_WP_CORE_VERSION', '9.9.9');" ); },
		'comment-version' => static function ( string $dir ): void { ( new Filesystem() )->dumpFile( $dir . '/files/core/bootstrap.php', "<?php // define('ST_WP_CORE_VERSION', '1.2.0');\n" ); },
	);
	foreach ( $mutations as $name => $mutate ) {
		$dir = $temp . '/bad-' . $name;
		$bad = core_manifest_fixture( $dir );
		$mutate( $dir );
		$before = core_manifest_snapshot( $temp );
		core_manifest_throws( static fn () => $bad->plan(), '' );
		core_manifest_throws( static fn () => $bad->generate(), '' );
		core_manifest_assert( $before === core_manifest_snapshot( $temp ), 'Invalid fixture caused writes: ' . $name );
	}
	$invalidMetadata = array(
		array( 'files_root', '../outside' ), array( 'schema', 2 ), array( 'name', 'other' ), array( 'version', 42 ),
		array( 'owned_files', array() ), array( 'owned_files', array( 42 ) ), array( 'removed_files', new stdClass() ),
		array( 'required_public_helpers', array( 'invalid-helper()' ) ), array( 'required_public_helpers', array( 'st_wp_core_fixture', 'st_wp_core_fixture' ) ),
		array( 'checksums', array() ), array( 'checksums', (object) array( 'core/bootstrap.php' => 'not-a-hash' ) ),
		array( 'checksums', (object) array( 'inc/project.php' => str_repeat( 'a', 64 ) ) ),
	);
	foreach ( $invalidMetadata as $index => list( $key, $value ) ) {
		$dir = $temp . '/metadata-' . $index;
		$bad = core_manifest_fixture( $dir );
		$data = core_manifest_data( $dir );
		$data->$key = $value;
		core_manifest_write( $dir, $data );
		$before = core_manifest_snapshot( $temp );
		core_manifest_throws( static fn () => $bad->generate(), '' );
		core_manifest_assert( $before === core_manifest_snapshot( $temp ), 'Malformed metadata caused writes.' );
	}
	foreach ( array( 'owned_files', 'removed_files', 'required_public_helpers' ) as $key ) {
		foreach ( array( array( new stdClass(), 1 ), array( 1, new stdClass() ), array( array(), false, 'value' ) ) as $index => $mixed ) {
			$dir = $temp . '/mixed-list-' . $key . '-' . $index;
			core_manifest_fixture( $dir );
			$data = core_manifest_data( $dir );
			$data->$key = $mixed;
			core_manifest_write( $dir, $data );
			$before = core_manifest_snapshot( $dir );
			$code = 'require ' . var_export( $root . '/vendor/autoload.php', true ) . ';'
				. '$app = new Symfony\\Component\\Console\\Application();'
				. '$app->add(new SnailTheme\\WPStarterToolkit\\Command\\CoreManifestCommand(new SnailTheme\\WPStarterToolkit\\Core\\CoreManifestGenerator(' . var_export( $dir, true ) . '))); exit($app->run());';
			$process = new Process( array( PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=-1', '-d', 'log_errors=0', '-r', $code, '--', 'core:manifest', '--check', '--json' ), $root );
			$process->setTimeout( 30 );
			$process->run();
			$json = json_decode( $process->getOutput(), true );
			core_manifest_assert( 1 === $process->getExitCode(), 'Mixed list must exit 1: ' . $key );
			core_manifest_assert( JSON_ERROR_NONE === json_last_error() && is_array( $json ) && array( 'error' ) === array_keys( $json ), 'Mixed list polluted JSON: ' . $process->getOutput() );
			core_manifest_assert( '' === $process->getErrorOutput() && $before === core_manifest_snapshot( $dir ), 'Mixed list leaked warnings or changed files.' );
		}
	}

	// Metadata cannot grant ownership over client files, even through broad globs.
	foreach ( array(
		array( 'owned_files', '**' ), array( 'owned_files', 'assets/**' ), array( 'owned_files', 'assets/*/core/**' ),
		array( 'owned_files', 'core*' ), array( 'owned_files', 'inc/**' ), array( 'owned_files', 'assets/scripts/project.js' ),
		array( 'removed_files', 'inc' ), array( 'removed_files', 'inc/project.php' ), array( 'removed_files', 'assets' ),
		array( 'removed_files', 'assets/scripts/project.js' ), array( 'removed_files', 'assets/scripts/legacy.js' ),
		array( 'removed_files', 'assets/scripts/core-admin.js.bak' ), array( 'removed_files', 'st-toolkit.json' ),
	) as $index => list( $key, $path ) ) {
		$dir = $temp . '/ownership-' . $index;
		$guarded = core_manifest_fixture( $dir );
		$data = core_manifest_data( $dir );
		$data->$key = array_merge( $data->$key, array( $path ) );
		core_manifest_write( $dir, $data );
		$before = core_manifest_snapshot( $dir );
		core_manifest_throws( static fn () => $guarded->plan(), 'ownership boundary' );
		core_manifest_throws( static fn () => $guarded->generate(), 'ownership boundary' );
		core_manifest_assert( $before === core_manifest_snapshot( $dir ), 'Ownership rejection changed files.' );
	}
	$dir = $temp . '/canonical-ownership';
	$guarded = core_manifest_fixture( $dir );
	$data = core_manifest_data( $dir );
	$data->owned_files = array( 'core/**', 'assets/scss/core/**', 'assets/scripts/core/**', 'assets/css/core/**', 'assets/js/core/**' );
	$data->removed_files = array( 'core/retired.php', 'assets/scss/core/retired.scss', 'assets/scripts/core/retired.js', 'assets/css/core/retired.css', 'assets/js/core/retired.js', 'assets/scss/core-admin.scss', 'assets/scripts/core-admin.js', 'assets/scripts/core-customizer.js', 'assets/css/core-admin.min.css', 'assets/js/core-admin.min.js', 'assets/js/core-customizer.min.js' );
	foreach ( array( 'scss' => 'scss', 'scripts' => 'js', 'css' => 'css', 'js' => 'js' ) as $kind => $extension ) {
		$fs->dumpFile( $dir . '/files/assets/' . $kind . '/core/example.' . $extension, '/* packaged */' );
	}
	core_manifest_write( $dir, $data );
	$guarded->generate( $guarded->plan()['snapshot'] );
	$after = core_manifest_data( $dir );
	unset( $data->checksums, $after->checksums );
	core_manifest_assert( serialize( $data ) === serialize( $after ), 'Valid explicit ownership/removal metadata changed.' );
	$client = $temp . '/guarded-client';
	$fs->dumpFile( $client . '/inc/project.php', '<?php // Client customization.' );
	$fs->dumpFile( $client . '/assets/scripts/project.js', '// Client script.' );
	$fs->dumpFile( $client . '/assets/scripts/core-admin.js', '// Retired package script.' );
	$manifest = new CoreManifest( json_decode( file_get_contents( $dir . '/st-wp-core-manifest.json' ), true ), $dir . '/st-wp-core-manifest.json', $dir . '/files' );
	$updater = new CoreUpdater( $manifest );
	$theme = new ThemeContext( $client, $client . '/style.css', $client . '/core/bootstrap.php', '1.0.0', array() );
	$updater->update( $theme );
	core_manifest_assert( '<?php // Client customization.' === file_get_contents( $client . '/inc/project.php' ) && '// Client script.' === file_get_contents( $client . '/assets/scripts/project.js' ), 'Validated core update changed client files.' );
	core_manifest_assert( ! file_exists( $client . '/assets/scripts/core-admin.js' ) && is_file( $client . '/core/bootstrap.php' ), 'Validated core update did not install core/retire the explicit legacy file.' );
	$unsafe = array( '../outside', '/absolute', 'C:/escape', 'nested\\escape', 'core/../escape', 'core//escape', 'core/./escape', "core/bad\0name", 'core/CON.php', 'core/trailing.', 'core/trailing ', 'core/a:b', 'core/[ab]' );
	foreach ( $unsafe as $index => $path ) {
		foreach ( array( 'owned_files', 'removed_files', 'checksums' ) as $key ) {
			$dir = $temp . '/unsafe-' . $index . '-' . $key;
			$bad = core_manifest_fixture( $dir );
			$data = core_manifest_data( $dir );
			$data->$key = 'checksums' === $key ? (object) array( $path => str_repeat( 'a', 64 ) ) : array( $path );
			core_manifest_write( $dir, $data );
			$before = core_manifest_snapshot( $temp );
			core_manifest_throws( static fn () => $bad->generate(), 'Unsafe' );
			core_manifest_assert( $before === core_manifest_snapshot( $temp ), 'Unsafe manifest path caused writes.' );
		}
	}

	// PHP is inspected, never executed; false declarations cannot satisfy helpers.
	foreach ( array(
		"<?php // function st_wp_core_fixture() {}\n",
		'<?php $example = "function st_wp_core_fixture() {}";',
		'<?php class Fixture { public function st_wp_core_fixture() {} }',
		'<?php namespace Fixture; function st_wp_core_fixture() {}',
		'<?php $instance = new class(function () {}) { public function st_wp_core_fixture() {} };',
		'<?php use function st_wp_core_fixture as other;',
		'<?php use function Vendor\\st_wp_core_fixture;',
		'<?php use Vendor\\{function st_wp_core_fixture as other};',
		'<?php use function st_wp_core_fixture as other ?>',
		'<?php function installer() { function st_wp_core_fixture() {} }',
		'<?php $installer = function () { function st_wp_core_fixture() {} };',
		'<?php $installer = fn () => function () { function st_wp_core_fixture() {} };',
	) as $index => $php ) {
		$dir = $temp . '/false-helper-' . $index;
		$bad = core_manifest_fixture( $dir );
		$fs->dumpFile( $dir . '/files/core/helpers.php', $php );
		core_manifest_throws( static fn () => $bad->generate(), 'Missing required public helpers' );
	}
	foreach ( array(
		'<?php function installer() { define("ST_WP_CORE_VERSION", "1.2.0"); }',
		'<?php $installer = function () { define("ST_WP_CORE_VERSION", "1.2.0"); };',
		'<?php $installer = fn () => define("ST_WP_CORE_VERSION", "1.2.0");',
		'<?php class Fixture { function init() { define("ST_WP_CORE_VERSION", "1.2.0"); } }',
		'<?php new define("ST_WP_CORE_VERSION", "1.2.0");',
		'<?php new \\define("ST_WP_CORE_VERSION", "1.2.0");',
		'<?php use function Vendor\\fake_define as define; define("ST_WP_CORE_VERSION", "1.2.0");',
		'<?php define("ST_WP_CORE_VERSION", "1.2.0"); use function Vendor\\fake_define as define;',
		'<?php use function Vendor\\define; define("ST_WP_CORE_VERSION", "1.2.0");',
		'<?php use function Vendor\\{other, fake_define as define}; define("ST_WP_CORE_VERSION", "1.2.0");',
		'<?php use Vendor\\{Other, function fake_define as define}; define("ST_WP_CORE_VERSION", "1.2.0");',
		'<?php use function Vendor\\fake_define as define ?><?php define("ST_WP_CORE_VERSION", "1.2.0");',
		'<?php define("ST_WP_CORE_VERSION", "1.2.0"); define("ST_WP_CORE_VERSION", "1.2.0");',
		'<?php namespace Named { \\define("ST_WP_CORE_VERSION", "9.9.9"); } namespace { define("ST_WP_CORE_VERSION", "1.2.0"); }',
		'<?php #[Define("ST_WP_CORE_VERSION", "1.2.0")] function fixture() {}',
	) as $index => $php ) {
		$dir = $temp . '/false-version-' . $index;
		$bad = core_manifest_fixture( $dir );
		$fs->dumpFile( $dir . '/files/core/bootstrap.php', $php );
		$before = core_manifest_snapshot( $dir );
		core_manifest_throws( static fn () => $bad->generate(), 'ST_WP_CORE_VERSION' );
		core_manifest_assert( $before === core_manifest_snapshot( $dir ), 'False version declaration caused writes.' );
	}
	foreach ( array(
		'<?php if (!defined("ST_WP_CORE_VERSION")) { define("ST_WP_CORE_VERSION", "1.2.0"); }',
		'<?php if (!defined("ST_WP_CORE_VERSION")): \\define("ST_WP_CORE_VERSION", "1.2.0"); endif;',
		'<?php use function Vendor\\fake_define as define; \\define("ST_WP_CORE_VERSION", "1.2.0");',
		'<?php use Vendor\\{Fake as define}; define("ST_WP_CORE_VERSION", "1.2.0");',
		'<?php namespace Named { use function Vendor\\fake_define as define; } namespace { define("ST_WP_CORE_VERSION", "1.2.0"); }',
		'<?php $fn = fn () => 1; define("ST_WP_CORE_VERSION", "1.2.0");',
		'<?php $fn = [fn () => define("ST_WP_CORE_VERSION", "9.9.9"), define("ST_WP_CORE_VERSION", "1.2.0")];',
		'<?php namespace Named; \\define("ST_WP_CORE_VERSION", "1.2.0");',
		'<?php namespace Named ?><?php \\define("ST_WP_CORE_VERSION", "1.2.0");',
		'<?php use function Vendor\\other ?><?php define("ST_WP_CORE_VERSION", "1.2.0");',
		'<?php namespace { use function Vendor\\fake as define; } namespace { define("ST_WP_CORE_VERSION", "1.2.0"); }',
		'<?php class Deferred { function init() { define("ST_WP_CORE_VERSION", "9.9.9"); } } define("ST_WP_CORE_VERSION", "1.2.0");',
		'<?php $fn = fn () => match (1) { 1 => define("ST_WP_CORE_VERSION", "9.9.9"), default => null }; define("ST_WP_CORE_VERSION", "1.2.0");',
	) as $index => $php ) {
		$dir = $temp . '/valid-version-' . $index;
		$valid = core_manifest_fixture( $dir );
		$fs->dumpFile( $dir . '/files/core/bootstrap.php', $php );
		core_manifest_assert( $valid->generate()['written'], 'Valid global version declaration was rejected: ' . $index );
	}
	foreach ( array(
		'<?php use function Vendor\\other ?><?php function st_wp_core_fixture() {}',
		'<?php $type = Fixture::class; interface I { function ignored(); } trait T { function ignored() {} } function st_wp_core_fixture() {}',
		'<?php $fn = fn (): ?Closure => function () { function deferred() {} }; function st_wp_core_fixture() {}',
		'<?php #[FixtureAttribute(["{"])] function st_wp_core_fixture() {}',
	) as $index => $php ) {
		$dir = $temp . '/valid-helper-' . $index;
		$valid = core_manifest_fixture( $dir );
		$fs->dumpFile( $dir . '/files/core/helpers.php', $php );
		$data = core_manifest_data( $dir );
		$data->owned_files = array( 'core/*.php', 'core/.hidden', 'core/image.bin', 'assets/scripts/core/*.js' );
		core_manifest_write( $dir, $data );
		core_manifest_assert( $valid->generate()['written'], 'Valid immediate helper/narrow ownership was rejected: ' . $index );
		core_manifest_assert( $data->owned_files === core_manifest_data( $dir )->owned_files, 'Narrow explicit ownership patterns changed.' );
	}
	$staticDir = $temp . '/static-helpers';
	$staticGenerator = core_manifest_fixture( $staticDir );
	$fs->dumpFile( $staticDir . '/files/core/helpers.php', '<?php namespace Fixture { function ignored() {} } namespace { function ST_WP_CORE_FIXTURE() {} }' );
	$fs->dumpFile( $staticDir . '/files/core/bootstrap.php', '<?php \\define("ST_WP_CORE_VERSION", "1.2.0");' );
	core_manifest_assert( $staticGenerator->generate()['written'], 'Static parser rejected a bracketed global namespace, case-insensitive helper or fully qualified define.' );
	$fs->dumpFile( $fixture . '/files/core/never-execute.php', '<?php file_put_contents(' . var_export( $temp . '/executed', true ) . ', "unsafe");' );
	$generator->generate();
	core_manifest_assert( ! file_exists( $temp . '/executed' ), 'Resource PHP was executed.' );

	if ( '\\' !== DIRECTORY_SEPARATOR ) {
		$outside = $temp . '/outside';
		$fs->dumpFile( $outside . '/sentinel', 'outside bytes must survive' );
		$outsideBefore = core_manifest_snapshot( $outside );
		foreach ( array( 'root', 'ancestor', 'manifest', 'files-root', 'directory', 'file', 'dangling' ) as $name ) {
			$dir = $temp . '/symlink-' . $name . '/resources/core';
			$bad = core_manifest_fixture( $dir );
			if ( 'root' === $name || 'ancestor' === $name ) {
				$link = $temp . '/link-' . $name;
				$fs->symlink( 'root' === $name ? $dir : dirname( $dir ), $link );
				$bad = new CoreManifestGenerator( $link . ( 'ancestor' === $name ? '/core' : '' ) );
			} else {
				$target = match ( $name ) {
					'manifest' => $dir . '/st-wp-core-manifest.json',
					'files-root' => $dir . '/files',
					'directory' => $dir . '/files/core',
					default => $dir . '/files/core/helpers.php',
				};
				$fs->remove( $target );
				$fs->symlink( 'dangling' === $name ? $outside . '/absent' : ( in_array( $name, array( 'directory', 'files-root' ), true ) ? $outside : $outside . '/sentinel' ), $target );
			}
			$before = core_manifest_snapshot( $temp );
			core_manifest_throws( static fn () => $bad->plan(), 'symlink' );
			core_manifest_throws( static fn () => $bad->generate(), 'symlink' );
			core_manifest_assert( $before === core_manifest_snapshot( $temp ) && $outsideBefore === core_manifest_snapshot( $outside ), 'Symlink escaped resource safety: ' . $name );
		}
	}

	// Vendor installs remain read-only even with --yes. Normal/worktree checkout
	// markers enable writes; malformed resources still return JSON and fail.
	$vendor = $temp . '/vendor/snailtheme/wp-starter-toolkit';
	core_manifest_fixture( $vendor . '/resources/core' );
	$fs->copy( $root . '/src/Support/PackagePaths.php', $vendor . '/src/Support/PackagePaths.php' );
	$before = core_manifest_snapshot( $vendor );
	$vendorWrite = core_manifest_vendor_cli( $vendor, array( '--yes', '--json', '--no-interaction' ) );
	core_manifest_assert( 1 === $vendorWrite['status'] && str_contains( $vendorWrite['json']['error'] ?? '', 'Git checkout' ), 'Vendor write was not rejected: ' . $vendorWrite['output'] );
	foreach ( array( '--check', '--dry-run' ) as $mode ) {
		$vendorRead = core_manifest_vendor_cli( $vendor, array( $mode, '--json', '--yes' ) );
		core_manifest_assert( ( '--check' === $mode ? 1 : 0 ) === $vendorRead['status'] && isset( $vendorRead['json']['changes'] ), 'Vendor read-only mode failed: ' . $vendorRead['output'] );
	}
	core_manifest_assert( $before === core_manifest_snapshot( $vendor ), 'Vendor command wrote package resources.' );
	foreach ( array( 'directory', 'worktree-file' ) as $marker ) {
		if ( 'directory' === $marker ) {
			$fs->mkdir( $vendor . '/.git' );
		} else {
			$fs->dumpFile( $vendor . '/.git', 'gitdir: /fixture/worktrees/toolkit' );
		}
		$write = core_manifest_vendor_cli( $vendor, array( '--yes', '--json' ) );
		core_manifest_assert( 0 === $write['status'] && true === ( $write['json']['written'] ?? null ), 'Checkout marker did not permit generation: ' . $write['output'] );
		$fs->remove( $vendor . '/.git' );
		$fs->dumpFile( $vendor . '/resources/core/files/core/.hidden', 'changed after ' . $marker );
	}
	if ( '\\' !== DIRECTORY_SEPARATOR ) {
		$fs->symlink( $outside, $vendor . '/.git' );
		$linkedGit = core_manifest_vendor_cli( $vendor, array( '--yes', '--json' ) );
		core_manifest_assert( 1 === $linkedGit['status'] && str_contains( $linkedGit['json']['error'] ?? '', 'Git checkout' ), 'Symlink .git was accepted.' );
		$fs->remove( $vendor . '/.git' );
	}
	$fs->dumpFile( $vendor . '/resources/core/st-wp-core-manifest.json', '{}' );
	$invalid = core_manifest_vendor_cli( $vendor, array( '--check', '--json' ) );
	core_manifest_assert( 1 === $invalid['status'] && isset( $invalid['json']['error'] ), 'Invalid resource check did not return nonzero JSON.' );

	$packagePlan = ( new CoreManifestGenerator() )->plan();
	core_manifest_assert( $packagePlan['file_count'] > 0 && $packageBefore === core_manifest_snapshot( $root . '/resources/core' ), 'Bundled read-only validation changed resources.' );
	fwrite( STDOUT, sprintf( "Core manifest checks passed (%d assertions).\n", $assertions ) );
} finally {
	unset( $GLOBALS['st_core_manifest_flush_hook'] );
	$fs->remove( $temp );
}

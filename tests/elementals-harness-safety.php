<?php
/**
 * Offline safety/false-positive regressions for Elemental acceptance harnesses.
 * Uses staged real bundle files, fake failing Composer, and disposable themes.
 */

declare(strict_types=1);

use SnailTheme\WPStarterToolkit\Block\BlockBundleInstaller;
use SnailTheme\WPStarterToolkit\Block\BlockBundleManifest;
use SnailTheme\WPStarterToolkit\Support\JsonFile;
use SnailTheme\WPStarterToolkit\Support\ReplacementEngine;
use SnailTheme\WPStarterToolkit\Theme\ThemeDetector;
use SnailTheme\WPStarterToolkit\UI\UIProfileInstaller;
use SnailTheme\WPStarterToolkit\UI\UIProfileManifest;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

$root = dirname( __DIR__ );
$harness = ( static fn (): array => require __DIR__ . '/elementals-generated.php' )();
$fs = new Filesystem();
$json = new JsonFile();
$temp = sys_get_temp_dir() . '/st-elemental-harness-' . bin2hex( random_bytes( 6 ) );
$checks = 0;
$assert = static function ( bool $condition, string $message ) use ( &$checks ): void {
	++$checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$reject = static function ( callable $action, string $message ) use ( $assert ): void {
	try {
		$action();
	} catch ( RuntimeException $exception ) {
		$assert( str_contains( $exception->getMessage(), $message ), 'Unexpected rejection: ' . $exception->getMessage() );
		return;
	}
	throw new RuntimeException( 'Invalid fixture was accepted: ' . $message );
};
$run = static function ( string $script, array $arguments, array $env = array() ) use ( $root ): array {
	$process = new Process( array_merge( array( PHP_BINARY, $root . '/tests/' . $script ), $arguments ), $root, $env );
	$process->setTimeout( 30 );
	$process->run();
	return array( $process->getExitCode(), json_decode( $process->getOutput(), true, 512, JSON_THROW_ON_ERROR ) );
};

try {
	$source = $temp . '/source';
	$fs->mirror( $root . '/resources/core/files', $source );
	$fs->dumpFile( $source . '/style.css', "/* Theme Name: Harness Safety */\n" );
	$json->write( $source . '/composer.json', array( 'name' => 'snailtheme/wp-starter', 'require-dev' => array( 'snailtheme/wp-starter-toolkit' => '^1.1' ) ) );
	$json->write( $source . '/package.json', array( 'scripts' => array( 'build' => 'vite build' ) ) );
	$detector = new ThemeDetector();
	( new UIProfileInstaller() )->install( $detector->detect( $source ), UIProfileManifest::load( 'bare' ) );
	$state = $json->read( $source . '/st-toolkit.json' );
	$state['ui_locked'] = false;
	$json->write( $source . '/st-toolkit.json', $state );
	$harness['clean_source']( $source );
	$valid = $temp . '/valid';
	$fs->mirror( $source, $valid, $harness['files']( $source ) );
	$patterns = $detector->detect( $valid )->patterns;
	$patterns['block_namespace'] = 'acme';
	$fs->dumpFile( $valid . '/core/bootstrap.php', ( new ReplacementEngine() )->applyThemePatterns( (string) file_get_contents( $valid . '/core/bootstrap.php' ), $patterns ) );
	$installer = new BlockBundleInstaller();
	$plan = $installer->plan( $detector->detect( $valid ), BlockBundleManifest::load( 'elementals' ) );
	foreach ( $plan->files as $relative => $staged ) {
		$fs->mkdir( dirname( $valid . '/' . $relative ) );
		$fs->copy( $staged, $valid . '/' . $relative, true );
	}
	$validate = static fn (): array => $harness['validate']( $valid, 'acme' );
	$summary = $validate();
	$assert( 9 === $summary['blocks'] && 7 === $summary['acf_groups'] && 18 === $summary['block_css'], 'Valid staged fixture failed' );
	$mutate_json = static function ( string $path, callable $change, string $message ) use ( $json, $fs, $reject, $validate ): void {
		$before = file_get_contents( $path );
		try {
			$json->write( $path, $change( $json->read( $path ) ) );
			$reject( $validate, $message );
		} finally {
			$fs->dumpFile( $path, $before );
		}
	};
	$heading = $valid . '/blocks/acme-el-heading/block.json';
	$mutate_json( $heading, static function ( array $m ): array { $m['name'] = 'acme/el-unknown'; return $m; }, 'namespace/API' );
	$mutate_json( $heading, static function ( array $m ): array { $m['ancestor'] = array( 'stwp/el-wrapper' ); return $m; }, 'sibling reference' );
	$mutate_json( $heading, static function ( array $m ): array { $m['allowedBlocks'][] = 'acme/el-missing'; return $m; }, 'sibling reference' );
	$mutate_json( $heading, static function ( array $m ): array { $m['supports']['mode'] = false; return $m; }, 'namespace/API' );
	$mutate_json( $heading, static function ( array $m ): array { $m['supports']['align'] = true; return $m; }, 'namespace/API' );
	$mutate_json( $heading, static function ( array $m ): array { $m['textdomain'] = 'foreign-theme'; return $m; }, 'namespace/API' );
	$mutate_json( $valid . '/blocks/acme-el-column/block.json', static function ( array $m ): array { unset( $m['parent'] ); return $m; }, 'parent-only' );
	$css = $valid . '/blocks/acme-el-heading/assets/css/view-style.min.css';
	$before = file_get_contents( $css );
	$fs->dumpFile( $css, '' );
	$reject( $validate, 'Missing or empty block CSS' );
	$fs->dumpFile( $css, $before . '.stwp-el-heading { color: red; }' );
	$reject( $validate, 'Stale source namespace' );
	$fs->dumpFile( $css, $before );
	$groups = glob( $valid . '/acf-json/*.json' );
	$mutate_json( $groups[0], static function ( array $g ): array { $g['fields'][1]['name'] = 'stwp-el-heading-fields'; return $g; }, 'ACF root namespace' );
	$mutate_json( $groups[0], static function ( array $g ): array { $g['fields'][2]['key'] = $g['fields'][0]['key']; return $g; }, 'duplicate deterministic' );
	$mutate_json( $groups[0], static function ( array $g ): array { unset( $g['fields'][0]['key'] ); return $g; }, 'Missing deterministic' );
	$mutate_json( $groups[0], static function ( array $g ): array { $g['fields'][0]['conditional_logic'] = array( array( array( 'field' => 'field_0000000000000' ) ) ); return $g; }, 'resolvable field references' );
	$wrong_file = $valid . '/acf-json/group_0000000000000.json';
	$fs->rename( $groups[0], $wrong_file );
	$reject( $validate, 'filename or block location' );
	$fs->rename( $wrong_file, $groups[0] );
	$mutate_json( $valid . '/theme.json', static function ( array $m ): array { unset( $m['settings']['blocks']['acme/el-wrapper']['spacing']['margin'] ); return $m; }, 'wrapper controls' );
	$mutate_json( $valid . '/st-toolkit.json', static function ( array $m ): array { $m['files'] = array(); return $m; }, 'receipt' );
	$managed = array_key_first( $state['files'] );
	$before = file_get_contents( $valid . '/' . $managed );
	$fs->dumpFile( $valid . '/' . $managed, $before . "\n/* unexpected change */\n" );
	$reject( $validate, 'receipt' );
	$fs->dumpFile( $valid . '/' . $managed, $before );
	$assert( 9 === $validate()['blocks'], 'Restored fixture no longer valid' );

	// A previous passed receipt and dependency/source-control trees are not input.
	foreach ( array( 'vendor', 'node_modules', '.git', '.st-toolkit', '.agents', '.claude' ) as $excluded ) {
		$fs->dumpFile( $source . '/' . $excluded . '/sentinel.txt', 'source only' );
	}
	$fs->dumpFile( $source . '/AGENTS.md', 'Installed local stwp namespace instructions.' );
	$fs->dumpFile( $source . '/CLAUDE.md', 'Installed local Claude bridge.' );
	$json->write( $source . '/.st-elemental-generated-result.json', array( 'status' => 'passed' ) );
	$json->write( $source . '/.st-elemental-fixture', array( 'owner' => 'someone else' ) );
	$original = $harness['snapshot']( $source );
	$state_before = $harness['snapshot']( $source, false );
	$fs->dumpFile( $source . '/.st-toolkit/sentinel.txt', 'changed state' );
	$assert( $state_before !== $harness['snapshot']( $source, false ) && $original === $harness['snapshot']( $source ), 'Dry-run fingerprint ignores hidden toolkit state' );
	$docs_before = $harness['snapshot']( $source, false );
	$fs->dumpFile( $source . '/AGENTS.md', 'Changed local namespace instructions.' );
	$assert( $docs_before !== $harness['snapshot']( $source, false ) && $original === $harness['snapshot']( $source ), 'Root AGENTS.md must be source-only excluded, not dry-run excluded' );
	$docs_before = $harness['snapshot']( $source, false );
	$fs->dumpFile( $source . '/CLAUDE.md', 'Changed local Claude bridge.' );
	$assert( $docs_before !== $harness['snapshot']( $source, false ) && $original === $harness['snapshot']( $source ), 'Root CLAUDE.md must be source-only excluded, not dry-run excluded' );
	$docs_before = $harness['snapshot']( $source, false );
	$fs->dumpFile( $source . '/.claude/sentinel.txt', 'Changed local Claude skill.' );
	$assert( $docs_before !== $harness['snapshot']( $source, false ) && $original === $harness['snapshot']( $source ), 'Claude skills must be source-only excluded, not dry-run excluded' );
	$fs->dumpFile( $temp . '/bin/composer', '#!' . PHP_BINARY . "\n<?php exit(23);\n" );
	$fs->chmod( $temp . '/bin/composer', 0700 );
	// Only exercise the receipt lifecycle up to fake Composer's exit 23.
	$generator = __DIR__ . '/fixtures/generator-failure-interface.php';
	$failed = $temp . '/failed-generation';
	[ $code, $result ] = $run( 'elementals-generated.php', array( $source, $generator, $failed ), array(
		'PATH' => $temp . '/bin:' . getenv( 'PATH' ),
		'ST_ELEMENTAL_FAILURE_INTERFACE' => '1',
	) );
	$assert( 1 === $code && 'failed' === $result['status'] && 1 === $result['commands'], 'Failing Composer did not fail acceptance' );
	$receipt = $json->read( $failed . '/.st-elemental-generated-result.json' );
	$assert( 'failed' === $receipt['status'] && true === $receipt['source_unchanged'], 'Inherited passed receipt survived failure' );
	foreach ( array( 'vendor', 'node_modules', '.git', '.st-toolkit', '.agents', '.claude', 'AGENTS.md', 'CLAUDE.md', '.st-elemental-fixture' ) as $excluded ) {
		$assert( ! file_exists( $failed . '/' . $excluded ), 'Copied excluded source content: ' . $excluded );
	}
	$assert( $original === $harness['snapshot']( $source ), 'Generation changed clean source' );
	$receipt_hash = hash_file( 'sha256', $failed . '/.st-elemental-generated-result.json' );
	[ $code ] = $run( 'elementals-generated.php', array( $source, $generator, $failed ) );
	$assert( 1 === $code && $receipt_hash === hash_file( 'sha256', $failed . '/.st-elemental-generated-result.json' ), 'Existing output receipt was overwritten' );
	$fs->symlink( $source, $temp . '/source-alias' );
	foreach ( array( 'elementals-generated.php', 'elementals-profiles.php' ) as $script ) {
		$args = 'elementals-generated.php' === $script ? array( $source, $generator, $temp . '/source-alias/nested' ) : array( $source, $temp . '/source-alias/nested' );
		[ $code ] = $run( $script, $args );
		$assert( 1 === $code && ! file_exists( $source . '/nested' ), 'Symlink alias bypassed overlap isolation' );
	}
	$fs->symlink( $source . '/style.css', $source . '/unsafe-link.css' );
	foreach ( array( 'elementals-generated.php', 'elementals-profiles.php' ) as $script ) {
		$target = $temp . '/' . $script;
		$args = 'elementals-generated.php' === $script ? array( $source, $generator, $target ) : array( $source, $target );
		[ $code, $result ] = $run( $script, $args );
		$assert( 1 === $code && str_contains( $result['error'], 'symlinks' ) && ! file_exists( $target ), 'Source symlink was not rejected before output writes' );
	}
	$fs->remove( $source . '/unsafe-link.css' );
	$output = $temp . '/profiles';
	$ownership = array( 'schema' => 1, 'owner' => 'st-toolkit/tests/elementals-profiles.php', 'source' => $source );
	foreach ( array( 'profiles' => $output, 'bare' => $output . '/bare', 'blueprint' => $output . '/blueprint', 'tailwind' => $output . '/tailwind' ) as $profile => $path ) {
		$fs->mkdir( $path );
		$json->write( $path . '/.st-elemental-fixture', $ownership + array( 'profile' => $profile ) );
	}
	$fs->dumpFile( $output . '/fixtures.json', '{"status":"completed","sentinel":"keep"}' );
	$hash = hash_file( 'sha256', $output . '/fixtures.json' );
	$mtime = filemtime( $output . '/fixtures.json' );
	$lock = fopen( $output . '/.st-elemental-fixtures.lock', 'c' );
	$assert( flock( $lock, LOCK_EX | LOCK_NB ), 'Could not take regression lock' );
	try {
		[ $code, $result ] = $run( 'elementals-profiles.php', array( $source, $output ) );
		clearstatcache();
		$assert( 1 === $code && str_contains( $result['error'], 'Another profile' ), 'Lock contender did not fail' );
		$assert( $hash === hash_file( 'sha256', $output . '/fixtures.json' ) && $mtime === filemtime( $output . '/fixtures.json' ), 'Lock rejection changed shared receipt' );
	} finally {
		flock( $lock, LOCK_UN );
		fclose( $lock );
	}
	$unowned = $temp . '/unowned';
	$fs->dumpFile( $unowned . '/bare/keep.txt', 'keep' );
	[ $code ] = $run( 'elementals-profiles.php', array( $source, $unowned ) );
	$assert( 1 === $code && 'keep' === file_get_contents( $unowned . '/bare/keep.txt' ), 'Unowned output was overwritten' );
	$dangling = $temp . '/dangling';
	$fs->mkdir( $dangling );
	$fs->symlink( $temp . '/must-not-be-created', $dangling . '/.st-elemental-fixture' );
	[ $code ] = $run( 'elementals-profiles.php', array( $source, $dangling ) );
	$assert( 1 === $code && ! file_exists( $temp . '/must-not-be-created' ), 'Dangling ownership marker redirected a write' );
	[ $code, $result ] = $run( 'elementals-profiles.php', array( $source, $root . '/tests/must-not-be-created' ) );
	$assert( 1 === $code && str_contains( $result['error'], 'toolkit source' ) && ! file_exists( $root . '/tests/must-not-be-created' ), 'Toolkit source overlap accepted' );
	echo 'Elemental harness safety passed (' . $checks . ' assertions).' . PHP_EOL;
} finally {
	$fs->remove( $temp );
}

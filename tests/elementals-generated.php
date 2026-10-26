<?php
/**
 * Local generator plus Composer-installed Elemental acceptance.
 *
 * CLI: clean theme, generator functions.php, NEW disposable output directory.
 * Requiring this file exposes shared harness validators without running builds.
 */

declare(strict_types=1);

use SnailTheme\WPStarterToolkit\Support\JsonFile;
use SnailTheme\WPStarterToolkit\Theme\ThemeDetector;
use SnailTheme\WPStarterToolkit\UI\UIProfileInstaller;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$members = array( 'el-wrapper', 'el-container', 'el-heading', 'el-text', 'el-columns', 'el-column', 'el-grid', 'el-grid-cell', 'el-content-with-image' );
$files = static function ( string $root, bool $source_only = true ): Finder {
	$excluded = array_merge( array( 'vendor', 'node_modules', '.git' ), $source_only ? array( '.st-toolkit', '.agents', '.claude' ) : array() );
	return Finder::create()->in( $root )->exclude( $excluded )
		->notName( array_merge( $excluded, array( '/^\.st-elemental-/' ) ) )
		->notPath( $source_only ? array( '/^(?:AGENTS|CLAUDE)\.md$/' ) : array() )
		->ignoreDotFiles( false )->ignoreVCS( false )->sortByName();
};
// Include directories in the fingerprint; never silently omit a selected symlink.
$snapshot = static function ( string $root, bool $source_only = true ) use ( $files ): array {
	$hashes = array();
	foreach ( $files( $root, $source_only ) as $file ) {
		if ( $file->isLink() ) {
			throw new RuntimeException( 'Source fixture must not contain symlinks: ' . $file->getPathname() );
		}
		$hashes[ str_replace( '\\', '/', $file->getRelativePathname() ) ] = $file->isDir() ? 'directory' : hash_file( 'sha256', $file->getPathname() );
	}
	ksort( $hashes );
	return $hashes;
};
$clean_source = static function ( string $source ): void {
	$theme = ( new ThemeDetector() )->detect( $source );
	$state = ( new JsonFile() )->read( $source . '/st-toolkit.json' );
	if ( $theme->path !== $source || 'stwp' !== $theme->pattern( 'block_namespace' )
		|| 'bare' !== ( $state['ui_profile'] ?? null ) || false !== ( $state['ui_locked'] ?? null )
		|| glob( $source . '/blocks/*-el-*/block.json' ) ) {
		throw new RuntimeException( 'Source must be a clean stwp theme with an unlocked Bare baseline.' );
	}
};
$validate = static function ( string $target, string $namespace ) use ( $members ): array {
	$json = new JsonFile();
	$theme = ( new ThemeDetector() )->detect( $target );
	if ( $theme->path !== realpath( $target ) || $namespace !== $theme->pattern( 'block_namespace' ) ) {
		throw new RuntimeException( 'Generated runtime namespace does not match the fixture.' );
	}
	$expected = array_map( static fn ( string $slug ): string => $namespace . '/' . $slug, $members );
	$paths = glob( $target . '/blocks/*/block.json' ) ?: array();
	$actual = array();
	$assets = array();
	foreach ( $paths as $path ) {
		$block = $json->read( $path );
		$name = $block['name'] ?? '';
		if ( ! in_array( $name, $expected, true ) || basename( dirname( $path ) ) !== str_replace( '/', '-', $name )
			|| 3 !== ( $block['apiVersion'] ?? null ) || 3 !== ( $block['acf']['blockVersion'] ?? null )
			|| $theme->pattern( 'text_domain', 'st-wp-starter' ) !== ( $block['textdomain'] ?? null )
			|| $theme->pattern( 'block_category', $theme->pattern( 'text_domain', 'st-wp-starter' ) ) !== ( $block['category'] ?? null )
			|| true !== ( $block['supports']['jsx'] ?? null )
			|| array_key_exists( 'mode', $block['acf'] ?? array() ) || array_key_exists( 'mode', $block['supports'] ?? array() )
			|| ( $namespace . '/el-wrapper' !== $name && false !== ( $block['supports']['align'] ?? null ) ) ) {
			throw new RuntimeException( 'Wrong installed namespace/API metadata: ' . $path );
		}
		$actual[] = $name;
		$parent = match ( $name ) {
			$namespace . '/el-column' => array( $namespace . '/el-columns' ),
			$namespace . '/el-grid-cell' => array( $namespace . '/el-grid' ),
			default => array(),
		};
		if ( $parent !== ( $block['parent'] ?? array() ) ) {
			throw new RuntimeException( 'Incorrect parent-only metadata: ' . $name );
		}
		foreach ( array_merge( $block['parent'] ?? array(), $block['ancestor'] ?? array(), $block['allowedBlocks'] ?? array() ) as $reference ) {
			if ( ! str_starts_with( $reference, 'core/' ) && ! in_array( $reference, $expected, true ) ) {
				throw new RuntimeException( 'Stale or unknown sibling reference: ' . $reference );
			}
		}
		foreach ( array( 'view', 'editor' ) as $entry ) {
			$relative = 'blocks/' . str_replace( '/', '-', $name ) . '/assets/css/' . $entry . '-style.min.css';
			if ( ! is_file( $target . '/' . $relative ) || 0 === filesize( $target . '/' . $relative ) || is_link( $target . '/' . $relative ) ) {
				throw new RuntimeException( 'Missing or empty block CSS: ' . $relative );
			}
			$assets[ $relative ] = hash_file( 'sha256', $target . '/' . $relative );
		}
		foreach ( Finder::create()->files()->in( dirname( $path ) )->name( '/\.(?:php|json|scss|css)$/' ) as $file ) {
			$contents = str_replace( '\\/', '/', (string) file_get_contents( $file->getPathname() ) );
			if ( 'stwp' !== $namespace && preg_match( '#(?<![A-Za-z0-9_])stwp(?:/|-)el-#', $contents ) ) {
				throw new RuntimeException( 'Stale source namespace in block source/assets: ' . $file->getPathname() );
			}
		}
	}
	sort( $actual );
	$sorted = $expected;
	sort( $sorted );
	if ( $actual !== $sorted ) {
		throw new RuntimeException( 'Expected exactly the nine distinct Elemental members.' );
	}
	$keys = $references = $locations = array();
	$walk = static function ( array $node ) use ( &$walk, &$keys, &$references ): void {
		if ( isset( $node['type'] ) && ! isset( $node['key'] ) ) {
			throw new RuntimeException( 'Missing deterministic ACF field key.' );
		}
		if ( isset( $node['key'] ) ) {
			$key = $node['key'];
			$pattern = isset( $node['type'] ) ? '/^field_[a-f0-9]{13}$/D' : '/^group_[a-f0-9]{13}$/D';
			if ( ! is_string( $key ) || ! preg_match( $pattern, $key ) || isset( $keys[ $key ] ) ) {
				throw new RuntimeException( 'Invalid or duplicate deterministic ACF key.' );
			}
			$keys[ $key ] = true;
		}
		if ( isset( $node['field'] ) && is_string( $node['field'] ) ) {
			$references[] = $node['field'];
		}
		if ( 'clone' === ( $node['type'] ?? null ) ) {
			throw new RuntimeException( 'Elemental ACF groups must be self-contained.' );
		}
		foreach ( $node as $child ) {
			if ( is_array( $child ) ) {
				$walk( $child );
			}
		}
	};
	$groups = glob( $target . '/acf-json/*.json' ) ?: array();
	foreach ( $groups as $path ) {
		$group = $json->read( $path );
		$walk( $group );
		$name = $group['location'][0][0]['value'] ?? '';
		if ( ! in_array( $name, $expected, true ) || in_array( $name, array( $namespace . '/el-wrapper', $namespace . '/el-text' ), true )
			|| basename( $path, '.json' ) !== ( $group['key'] ?? null )
			|| array( array( array( 'param' => 'block', 'operator' => '==', 'value' => $name ) ) ) !== ( $group['location'] ?? null ) ) {
			throw new RuntimeException( 'Incorrect ACF filename or block location: ' . $path );
		}
		$locations[] = $name;
		$fields = $group['fields'] ?? array();
		if ( array( 'tab', 'group', 'tab', 'group' ) !== array_column( $fields, 'type' )
			|| array( 'Fields', 'Settings' ) !== array( $fields[0]['label'] ?? '', $fields[2]['label'] ?? '' ) ) {
			throw new RuntimeException( 'Incorrect ACF Fields/Settings structure: ' . $path );
		}
		foreach ( array( 1 => 'fields', 3 => 'settings' ) as $index => $kind ) {
			$root = str_replace( '/', '-', $name ) . '-' . $kind;
			if ( $root !== ( $fields[ $index ]['name'] ?? null ) ) {
				throw new RuntimeException( 'Incorrect ACF root namespace: ' . $path );
			}
			foreach ( $fields[ $index ]['sub_fields'] ?? array() as $field ) {
				if ( ! str_starts_with( $field['name'] ?? '', $root . '__' ) ) {
					throw new RuntimeException( 'Incorrect ACF subfield namespace: ' . $path );
				}
			}
		}
	}
	$expected_groups = array_values( array_diff( $expected, array( $namespace . '/el-wrapper', $namespace . '/el-text' ) ) );
	sort( $locations );
	sort( $expected_groups );
	if ( $locations !== $expected_groups || array_diff( $references, array_keys( $keys ) ) ) {
		throw new RuntimeException( 'Expected seven distinct ACF groups with resolvable field references.' );
	}
	$settings = $json->read( $target . '/theme.json' );
	$spacing = $settings['settings']['blocks'][ $namespace . '/el-wrapper' ]['spacing'] ?? array();
	if ( true !== ( $spacing['margin'] ?? null ) || true !== ( $spacing['padding'] ?? null )
		|| ( 'stwp' !== $namespace && isset( $settings['settings']['blocks']['stwp/el-wrapper'] ) ) ) {
		throw new RuntimeException( 'Native wrapper controls were not opted in for the fixture namespace.' );
	}
	$status = ( new UIProfileInstaller() )->status( $theme );
	$receipt = $json->read( $target . '/st-toolkit.json' );
	if ( empty( $receipt['files'] ) || ! is_array( $receipt['files'] ) || $status['modified_files'] || $status['missing_files'] ) {
		throw new RuntimeException( 'Generated UI-profile receipt does not match scaffold files.' );
	}
	foreach ( $receipt['files'] as $path => $checksum ) {
		if ( ! is_string( $path ) || Path::isAbsolute( $path ) || in_array( '..', explode( '/', str_replace( '\\', '/', $path ) ), true )
			|| ! is_string( $checksum ) || ! preg_match( '/^[a-f0-9]{64}$/D', $checksum ) ) {
			throw new RuntimeException( 'Invalid UI-profile receipt path/checksum.' );
		}
	}
	foreach ( array( 'core/template-tags.php' => 'st_wp_core_generate_img', 'core/template-functions.php' => 'st_wp_core_get_block_wrapper_attributes' ) as $path => $helper ) {
		if ( ! str_contains( (string) file_get_contents( $target . '/' . $path ), 'function ' . $helper ) ) {
			throw new RuntimeException( 'Stable core helper was renamed: ' . $helper );
		}
	}
	ksort( $assets );
	return array( 'blocks' => count( $paths ), 'acf_groups' => count( $groups ), 'block_css' => count( $assets ), 'block_asset_sha256' => $assets, 'profile_status' => $status );
};
$harness = array( 'files' => $files, 'snapshot' => $snapshot, 'clean_source' => $clean_source, 'validate' => $validate, 'members' => $members );
if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) !== __FILE__ ) {
	return $harness;
}

$fs = new Filesystem();
$json = new JsonFile();
$receipt = null;
$summary = array( 'status' => 'failed', 'namespace' => 'acme', 'commands' => 0, 'local_unreleased_toolkit' => true );
$source = $before = null;
$log = array();
try {
	if ( 4 !== $argc || ! is_file( $argv[2] ) || ! Path::isAbsolute( $argv[1] ) || ! Path::isAbsolute( $argv[3] ) ) {
		throw new RuntimeException( 'Usage: php tests/elementals-generated.php /clean/theme /generator/includes/functions.php /NEW/disposable/output' );
	}
	$source = realpath( $argv[1] );
	if ( false === $source ) {
		throw new RuntimeException( 'Clean source theme does not exist.' );
	}
	$clean_source( $source );
	$before = $snapshot( $source );
	$target = Path::canonicalize( $argv[3] );
	if ( file_exists( $target ) || is_link( $target ) ) {
		throw new RuntimeException( 'Choose a new absolute fixture directory; existing directories are never overwritten.' );
	}
	$ancestor = $target;
	while ( ! file_exists( $ancestor ) && ! is_link( $ancestor ) ) {
		$ancestor = dirname( $ancestor );
	}
	$resolved = realpath( $ancestor );
	if ( false === $resolved ) {
		throw new RuntimeException( 'Cannot resolve the fixture output parent.' );
	}
	$target = Path::canonicalize( $resolved . substr( $target, strlen( $ancestor ) ) );
	foreach ( array( $source, dirname( __DIR__ ), dirname( (string) realpath( $argv[2] ) ) ) as $protected ) {
		if ( $target === $protected || str_starts_with( $target . '/', $protected . '/' ) || str_starts_with( $protected . '/', $target . '/' ) ) {
			throw new RuntimeException( 'Fixture output must not overlap source, toolkit, or generator functions.' );
		}
	}
	require $argv[2];
	$fs->mkdir( dirname( $target ) );
	// Unlike mirror/mkdir helpers, this reservation refuses a racing second owner.
	if ( ! @mkdir( $target ) ) {
		throw new RuntimeException( 'Could not exclusively create the new fixture directory.' );
	}
	$receipt = $target . '/.st-elemental-generated-result.json';
	$summary += array( 'path' => $target, 'source' => $source, 'source_sha256' => hash( 'sha256', json_encode( $before, JSON_THROW_ON_ERROR ) ) );
	$summary['status'] = 'running';
	$fs->dumpFile( $receipt, json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . PHP_EOL );
	$fs->mirror( $source, $target, $files( $source ) );
	$snapshot( $target );
	$validation = theme_generator_validate_inputs( array(
		'theme_name' => 'Acme Elemental Acceptance', 'block_namespace' => 'acme',
		'git_repo' => 'Acme-Inc/Elemental-Acceptance', 'theme_uri' => 'https://example.com/elementals/',
		'author_name' => 'Acme Inc', 'author_uri' => 'https://example.com/',
	) );
	if ( $validation['errors'] ) {
		throw new RuntimeException( json_encode( $validation['errors'], JSON_THROW_ON_ERROR ) );
	}
	$data = array_merge( $validation['validated'], theme_generator_get_name_variations( 'Acme Elemental Acceptance', 'acme' ) );
	theme_generator_update_composer_package( $target, $data['composer_package'] );
	theme_generator_process_files( $target, theme_generator_create_replacement_map( $data ), 'stwp', 'acme' );
	theme_generator_remove_source_only_files( $target );
	$fs->remove( $target . '/.github' );
	$source_receipt = $json->read( $source . '/st-toolkit.json' );
	$generated_receipt = $json->read( $target . '/st-toolkit.json' );
	$source_coverage = array_keys( $source_receipt['files'] ?? array() );
	$generated_coverage = array_keys( $generated_receipt['files'] ?? array() );
	sort( $source_coverage );
	sort( $generated_coverage );
	if ( empty( $source_coverage ) || $source_coverage !== $generated_coverage ) {
		throw new RuntimeException( 'Generation lost the UI-profile receipt coverage.' );
	}
	$manifest = $json->read( $target . '/composer.json' );
	if ( 'acme-inc/elemental-acceptance' !== ( $manifest['name'] ?? null ) || '^1.1' !== ( $manifest['require-dev']['snailtheme/wp-starter-toolkit'] ?? null ) ) {
		throw new RuntimeException( 'Generated Composer identity/package references were corrupted.' );
	}
	$json->write( $target . '/.st-elemental-generated-composer.json', $manifest );
	$manifest['repositories'] = array( array( 'type' => 'path', 'url' => dirname( __DIR__ ), 'options' => array( 'symlink' => true, 'versions' => array( 'snailtheme/wp-starter-toolkit' => 'dev-next' ) ) ) );
	$manifest['require-dev']['snailtheme/wp-starter-toolkit'] = 'dev-next';
	$json->write( $target . '/composer.json', $manifest );
	foreach ( array(
		array( 'composer', 'install', '--prefer-dist', '--no-progress', '--no-interaction' ),
		array( 'composer', 'validate', '--strict' ), array( 'npm', 'ci', '--no-fund' ),
		array( 'composer', 'st-toolkit', '--', 'init', '--yes' ),
		array( 'composer', 'st-toolkit', '--', 'blocks:install', 'elementals', '--dry-run', '--json' ),
		array( 'composer', 'st-toolkit', '--', 'blocks:install', 'elementals', '--yes', '--json' ),
		array( 'composer', 'toolkit:doctor' ), array( 'composer', 'toolkit:core-check' ),
		array( 'composer', 'lint:php' ), array( 'composer', 'lint:wpcs' ), array( 'composer', 'make-pot' ), array( 'npm', 'run', 'build' ),
	) as $command ) {
		fwrite( STDERR, implode( ' ', $command ) . PHP_EOL );
		$dry_run_before = in_array( '--dry-run', $command, true ) ? $snapshot( $target, false ) : null;
		$process = new Process( $command, $target, array( 'COMPOSER_ROOT_VERSION' => 'dev-next' ) );
		$process->setTimeout( 300 );
		$process->run();
		$log[] = array( 'command' => $command, 'exit_code' => $process->getExitCode(), 'stdout' => $process->getOutput(), 'stderr' => $process->getErrorOutput() );
		$summary['commands'] = count( $log );
		$json->write( $target . '/.st-elemental-generated-log.json', $log );
		if ( ! $process->isSuccessful() ) {
			throw new RuntimeException( 'Generated fixture command failed: ' . implode( ' ', $command ) . PHP_EOL . $process->getErrorOutput() . $process->getOutput() );
		}
		if ( null !== $dry_run_before && $dry_run_before !== $snapshot( $target, false ) ) {
			throw new RuntimeException( 'Bundle dry-run changed the generated theme.' );
		}
		if ( in_array( 'blocks:install', $command, true ) ) {
			$result = json_decode( $process->getOutput(), true, 512, JSON_THROW_ON_ERROR );
			if ( 'bundle' !== ( $result['type'] ?? null ) || 'acme' !== ( $result['target_namespace'] ?? null )
				|| $members !== ( $result['members'] ?? null )
				|| ( null === $dry_run_before && 'completed' !== ( $result['build']['status'] ?? null ) ) ) {
				throw new RuntimeException( 'Bundle JSON did not confirm the expected namespace, members, or successful build.' );
			}
		}
	}
	$summary += $validate( $target, 'acme' );
	$summary['status'] = 'passed';
} catch ( Throwable $exception ) {
	$summary['status'] = 'failed';
	$summary['error'] = $exception->getMessage();
	fwrite( STDERR, $exception->getMessage() . PHP_EOL );
} finally {
	if ( is_array( $before ) && is_string( $source ) ) {
		try {
			$summary['source_unchanged'] = $before === $snapshot( $source );
		} catch ( Throwable $exception ) {
			$summary['source_unchanged'] = false;
		}
		if ( ! $summary['source_unchanged'] ) {
			$summary['status'] = 'failed';
			$summary['error'] = 'Source changed during generated acceptance.';
		}
	}
	if ( null !== $receipt ) {
		$fs->dumpFile( $receipt, json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . PHP_EOL );
	}
}
fwrite( STDOUT, json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . PHP_EOL );
exit( 'passed' === $summary['status'] ? 0 : 1 );

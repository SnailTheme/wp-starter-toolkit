<?php
/**
 * Bundle transactions use temporary package/theme fixtures and a PHP fake npm.
 * No network, WordPress bootstrap, or installed Node dependencies are required.
 */

declare(strict_types=1);

use SnailTheme\WPStarterToolkit\Block\BlockBundleInstaller;
use SnailTheme\WPStarterToolkit\Block\BlockBundleManifest;
use SnailTheme\WPStarterToolkit\Block\BlockBundleThemeSettings;
use SnailTheme\WPStarterToolkit\Block\BlockInstaller;
use SnailTheme\WPStarterToolkit\Block\BlockManifest;
use SnailTheme\WPStarterToolkit\Support\JsonFile;
use SnailTheme\WPStarterToolkit\Support\BackupManager;
use SnailTheme\WPStarterToolkit\Support\ReplacementEngine;
use SnailTheme\WPStarterToolkit\Theme\ThemeContext;
use SnailTheme\WPStarterToolkit\Theme\ThemeDetector;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

require dirname( __DIR__ ) . '/vendor/autoload.php';

$root = dirname( __DIR__ );
$fs = new Filesystem();
$json = new JsonFile();
$temp = sys_get_temp_dir() . '/st-toolkit-bundles-' . bin2hex( random_bytes( 6 ) );
$assertions = 0;
$originalPath = getenv( 'PATH' );
$originalLog = getenv( 'ST_BUNDLE_NPM_LOG' );
$originalFail = getenv( 'ST_BUNDLE_NPM_FAIL' );
$originalEnv = $_ENV;
$originalServer = $_SERVER;
$git = ( new ExecutableFinder() )->find( 'git' );

/** Keep Symfony Process's inherited environment consistent with getenv(). */
function bundle_env( string $name, string|false $value ): void {
	putenv( false === $value ? $name : $name . '=' . $value );
	if ( false === $value ) {
		unset( $_ENV[ $name ], $_SERVER[ $name ] );
	} else {
		$_ENV[ $name ] = $_SERVER[ $name ] = $value;
	}
}

function bundle_assert( bool $condition, string $message ): void {
	global $assertions;
	++$assertions;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function bundle_throws( callable $action, string $message ): void {
	try {
		$action();
	} catch ( Throwable $exception ) {
		bundle_assert( str_contains( $exception->getMessage(), $message ), 'Unexpected exception: ' . $exception->getMessage() );
		return;
	}
	throw new RuntimeException( 'Expected exception containing: ' . $message );
}

/** @return array<string,string> Include directories to detect target writes. */
function bundle_snapshot( string $root ): array {
	$result = array();
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST ) as $file ) {
		$relative = substr( $file->getPathname(), strlen( $root ) + 1 );
		$result[ $relative ] = $file->isLink() ? 'link:' . readlink( $file->getPathname() ) : ( $file->isDir() ? 'directory' : (string) hash_file( 'sha256', $file->getPathname() ) );
	}
	ksort( $result );
	return $result;
}

/** @return array{status:int,output:string,json:?array} */
function bundle_cli( string $runner, array $arguments ): array {
	$process = new Process( array_merge( array( PHP_BINARY, $runner ), $arguments ) );
	$process->setTimeout( 120 );
	$process->run();
	$output = $process->getOutput();
	return array( 'status' => $process->getExitCode(), 'output' => $output . $process->getErrorOutput(), 'json' => json_decode( $output, true ) );
}

/** A failed second copy must restore only owned files from the single backup. */
final class BundleFailingFilesystem extends Filesystem {
	private int $copies = 0;

	public function copy( string $originFile, string $targetFile, bool $overwriteNewerFiles = false ): void {
		++$this->copies;
		if ( 2 === $this->copies ) {
			$this->dumpFile( dirname( $targetFile ) . '/project-added-during-install.txt', 'keep' );
			throw new RuntimeException( 'Fixture write failure' );
		}
		parent::copy( $originFile, $targetFile, $overwriteNewerFiles );
	}
}

/** Fail once after theme.json was actually replaced, exercising its backup. */
final class BundleThemeJsonFailingFilesystem extends Filesystem {
	private bool $failed = false;

	public function rename( string $originFile, string $targetFile, bool $overwrite = false ): void {
		parent::rename( $originFile, $targetFile, $overwrite );
		if ( ! $this->failed && 'theme.json' === basename( $targetFile ) ) {
			$this->failed = true;
			throw new RuntimeException( 'Fixture theme JSON write failure' );
		}
	}
}

/** Mutate a later owned target while the installer is making its backup. */
final class BundleBackupRaceFilesystem extends Filesystem {
	private bool $changed = false;

	public function __construct( private readonly string $target, private readonly string $contents ) {}

	public function copy( string $originFile, string $targetFile, bool $overwriteNewerFiles = false ): void {
		parent::copy( $originFile, $targetFile, $overwriteNewerFiles );
		if ( ! $this->changed ) {
			$this->changed = true;
			$this->dumpFile( $this->target, $this->contents );
		}
	}
}

/** A failed early write must not delete/replace a concurrently created target. */
final class BundleUnattemptedRaceFilesystem extends Filesystem {
	public function __construct( private readonly string $laterTarget, private readonly string $contents ) {}

	public function copy( string $originFile, string $targetFile, bool $overwriteNewerFiles = false ): void {
		$this->dumpFile( $this->laterTarget, $this->contents );
		throw new RuntimeException( 'Fixture failure before the later target was attempted' );
	}
}

/** A later failure must preserve concurrent edits and still recover other files. */
final class BundleRecoveryRaceFilesystem extends Filesystem {
	private array $committed = array();
	private bool $failed = false;

	public function rename( string $originFile, string $targetFile, bool $overwrite = false ): void {
		if ( ! str_starts_with( basename( $originFile ), '.st-toolkit-bundle-' ) ) {
			parent::rename( $originFile, $targetFile, $overwrite );
			return;
		}
		if ( ! $this->failed && 3 === count( $this->committed ) ) {
			$this->failed = true;
			( new Filesystem() )->dumpFile( $this->committed[2], 'concurrent edit after bundle write' );
			throw new RuntimeException( 'Fixture later rename failure' );
		}
		parent::rename( $originFile, $targetFile, $overwrite );
		$this->committed[] = $targetFile;
	}
}

try {
	$fs->mkdir( $temp );
	$package = $temp . '/package';
	$fs->mirror( $root . '/src', $package . '/src' );
	$fs->mirror( $root . '/resources/core', $package . '/resources/core' );
	$fs->mirror( $root . '/resources/blocks/slider', $package . '/resources/blocks/slider' );
	$bundleData = array(
		'schema' => 1,
		'type' => 'bundle',
		'source_slug' => 'elementals',
		'default_title' => 'Elemental Foundation',
		'members' => array( 'el-wrapper', 'el-heading' ),
		'required_core_version' => '>=1.1.0',
		'theme_settings' => array( 'blocks' => array( 'stwp/el-wrapper' => array( 'spacing' => array( 'margin' => true, 'padding' => true ) ) ) ),
	);
	$manifestPath = $package . '/resources/blocks/elementals/st-bundle.json';
	$fs->mkdir( dirname( $manifestPath ) );
	$json->write( $manifestPath, $bundleData );
	foreach ( $bundleData['members'] as $slug ) {
		$memberRoot = $package . '/resources/blocks/' . $slug;
		$fs->mkdir( $memberRoot . '/files' );
		$json->write( $memberRoot . '/st-block.json', array(
			'schema' => 1,
			'source_slug' => $slug,
			'default_title' => 'Original ' . $slug,
			'source_namespace' => 'stwp',
			'bundle_only' => true,
			'required_core_version' => '>=1.1.0',
			'required_php_helpers' => array( 'st_wp_core_get_block_wrapper_attributes' ),
		) );
		$json->write( $memberRoot . '/files/block.json', array(
			'apiVersion' => 3,
			'name' => 'stwp/' . $slug,
			'title' => 'Original ' . $slug,
			'category' => 'st-wp-starter',
			'textdomain' => 'st-wp-starter',
			'parent' => array( 'stwp/el-wrapper' ),
			'ancestor' => array( 'stwp/el-wrapper' ),
			'allowedBlocks' => array( 'stwp/el-heading', 'core/paragraph' ),
			'acf' => array( 'renderTemplate' => 'block-render.php' ),
			'viewScript' => 'file:./assets/view.js',
		) );
		$fs->dumpFile( $memberRoot . '/files/block-render.php', "<?php\ndeclare(strict_types=1);\n\$template = array( array( 'stwp/el-heading' ) );\n// st_wp_core_get_block_wrapper_attributes remains stable.\n" );
		$fs->dumpFile( $memberRoot . '/files/assets/view.js', "const allowed = ['stwp/el-heading', 'stwp/el-wrapper'];\n" );
		$fs->dumpFile( $memberRoot . '/files/.hidden.json', "{\"reference\":\"stwp/el-heading\"}\n" );
		$fs->mkdir( $memberRoot . '/theme-files/acf-json' );
		$symbol = str_replace( '-', '_', $slug );
		$json->write( $memberRoot . '/theme-files/acf-json/group_stwp_' . $symbol . '.json', array(
			'key' => 'group_stwp_' . $symbol,
			'fields' => array( array( 'key' => 'field_stwp_' . $symbol . '_text', 'conditional_logic' => array( array( array( 'field' => 'field_stwp_' . $symbol . '_text' ) ) ) ) ),
			'location' => array( array( array( 'param' => 'block', 'operator' => '==', 'value' => 'stwp/' . $slug ) ) ),
		) );
	}

	$themePath = $temp . '/theme';
	$fs->mirror( $root . '/resources/core/files', $themePath );
	$fs->dumpFile( $themePath . '/style.css', "/* Theme Name: Bundle Fixture */\n" );
	$patterns = array( 'block_namespace' => 'acme', 'block_category' => 'acme-blocks', 'text_domain' => 'acme-theme', 'function_names' => 'acme_', 'constants' => 'ACME_' );
	$bootstrap = $themePath . '/core/bootstrap.php';
	$fs->dumpFile( $bootstrap, "<?php\ndeclare(strict_types=1);\ndefine( 'ST_WP_CORE_VERSION', '1.1.0' );\ndefine( 'ST_WP_CORE_THEME_PATTERNS', " . var_export( $patterns, true ) . " );\n" );
	$fs->dumpFile( $themePath . '/package.json', '{"scripts":{"build":"fixture-build"}}' );
	$projectIgnore = "/node_modules\n/vendor\n# Project-owned ignore rules\n/private-build/\n";
	$fs->dumpFile( $themePath . '/.gitignore', $projectIgnore );
	if ( null !== $git ) {
		( new Process( array( $git, 'init', '--quiet', $themePath ) ) )->mustRun();
	}
	$theme = ( new ThemeDetector() )->detect( $themePath );
	bundle_assert( 'acme' === $theme->pattern( 'block_namespace' ), 'Theme patterns did not resolve from bootstrap.' );
	$bundle = new BlockBundleManifest( $manifestPath );
	$installer = new BlockBundleInstaller();

	// Merge only native wrapper controls, preserving every other JSON property
	// and retaining object/array distinctions throughout the project document.
	$projectThemeJson = <<<'JSON'
{
  "$schema": "https://schemas.wp.org/wp/6.8/theme.json",
  "version": 2,
  "settings": {
    "spacing": { "units": ["px", "rem"], "blockGap": true },
    "layout": { "contentSize": "67rem", "wideSize": "90rem" },
    "custom": { "emptyObject": {}, "emptyArray": [], "ratio": 1.0 },
    "blocks": {
      "core/paragraph": { "color": { "text": false } },
      "stwp/el-wrapper": { "spacing": { "padding": false } },
      "acme/el-wrapper": { "spacing": { "margin": false, "padding": false, "blockGap": true }, "custom": {} }
    }
  },
  "styles": { "spacing": { "padding": { "top": "2rem" } } },
  "customRoot": {}
}
JSON;
	$fs->dumpFile( $themePath . '/theme.json', $projectThemeJson );
	$settingsBefore = bundle_snapshot( $themePath );
	$settingsPlan = $installer->plan( $theme, $bundle );
	$merged = json_decode( (string) file_get_contents( $settingsPlan->files['theme.json'] ), false, 512, JSON_THROW_ON_ERROR );
	$expected = json_decode( $projectThemeJson, false, 512, JSON_THROW_ON_ERROR );
	$expected->settings->blocks->{'acme/el-wrapper'}->spacing->margin = true;
	$expected->settings->blocks->{'acme/el-wrapper'}->spacing->padding = true;
	bundle_assert( json_encode( $expected, JSON_PRESERVE_ZERO_FRACTION ) === json_encode( $merged, JSON_PRESERVE_ZERO_FRACTION ), 'Theme settings overlay changed unrelated properties, schema/version, layout, or object/array types.' );
	bundle_assert( $settingsBefore === bundle_snapshot( $themePath ), 'Staging theme settings wrote into the target theme.' );
	bundle_assert( ! property_exists( $merged->settings->spacing, 'margin' ) && ! property_exists( $merged->settings->spacing, 'padding' ), 'Bundle enabled global spacing controls instead of wrapper-only controls.' );
	$settingsChanges = array_column( $settingsPlan->toArray()['changes'], 'action', 'path' );
	bundle_assert( 'replace' === $settingsChanges['theme.json'] && in_array( 'theme.json', $settingsPlan->toArray()['conflicts'], true ), 'Theme JSON replacement did not join the complete conflict plan.' );
	$settingsRaceFilesystem = new class( $themePath . '/theme.json' ) extends Filesystem {
		public function __construct( private readonly string $target ) {}
		public function dumpFile( string $filename, $content ): void {
			parent::dumpFile( $filename, $content );
			if ( str_contains( $filename, 'st-toolkit-bundle-settings-' ) ) {
				parent::dumpFile( $this->target, '{"version":3,"custom":"edited while staging"}' );
			}
		}
	};
	$settingsRaceStage = new BlockBundleThemeSettings( $settingsRaceFilesystem );
	bundle_throws( static fn () => $settingsRaceStage->stage( $theme, $bundle ), 'changed while staging' );
	bundle_assert( '{"version":3,"custom":"edited while staging"}' === file_get_contents( $themePath . '/theme.json' ), 'Settings staging lost a concurrent project edit.' );
	$matchingJson = json_encode( $expected, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ) . "\n\n";
	$fs->dumpFile( $themePath . '/theme.json', $matchingJson );
	$matchingPlan = ( new BlockBundleInstaller() )->plan( $theme, $bundle );
	$settingsStagePath = $matchingPlan->files['theme.json'];
	bundle_assert( is_file( $settingsStagePath ) && $matchingJson === file_get_contents( $settingsStagePath ), 'Already enabled native controls caused a formatting-only rewrite or lost plan-owned staging.' );
	bundle_assert( 'unchanged' === array_column( $matchingPlan->toArray()['changes'], 'action', 'path' )['theme.json'], 'An already-enabled theme JSON was not unchanged.' );
	unset( $matchingPlan );
	bundle_assert( ! file_exists( $settingsStagePath ), 'Plan-owned theme settings stage was not cleaned up.' );
	$fs->dumpFile( $themePath . '/theme.json', '{malformed' );
	$malformedBefore = bundle_snapshot( $themePath );
	bundle_throws( static fn () => $installer->plan( $theme, $bundle ), 'Invalid JSON in target theme.json' );
	bundle_assert( $malformedBefore === bundle_snapshot( $themePath ), 'Malformed theme JSON preflight created files or backups.' );
	$fs->dumpFile( $themePath . '/theme.json', '[]' );
	bundle_throws( static fn () => $installer->plan( $theme, $bundle ), 'must contain a JSON object' );
	$fs->dumpFile( $themePath . '/theme.json', '{"settings":{"blocks":[]}}' );
	bundle_throws( static fn () => $installer->plan( $theme, $bundle ), 'Cannot merge bundle settings into a non-object' );
	$unsafeBundleData = $bundleData;
	$unsafeBundleData['theme_settings']['spacing'] = array( 'padding' => true );
	$json->write( $manifestPath, $unsafeBundleData );
	bundle_throws( static fn () => new BlockBundleManifest( $manifestPath ), 'not allowlisted' );
	$unsafeBundleData = $bundleData;
	$unsafeBundleData['theme_settings']['blocks']['stwp/el-wrapper']['spacing']['margin'] = false;
	$json->write( $manifestPath, $unsafeBundleData );
	bundle_throws( static fn () => new BlockBundleManifest( $manifestPath ), 'may only be enabled' );
	$withoutSettings = $bundleData;
	unset( $withoutSettings['theme_settings'] );
	$json->write( $manifestPath, $withoutSettings );
	$withoutSettingsPlan = $installer->plan( $theme, new BlockBundleManifest( $manifestPath ) );
	bundle_assert( ! isset( $withoutSettingsPlan->files['theme.json'] ), 'Optional theme settings changed bundles that do not declare an overlay.' );
	$json->write( $manifestPath, $bundleData );
	$fs->remove( $themePath . '/theme.json' );

	$before = bundle_snapshot( $themePath );
	$plan = $installer->plan( $theme, $bundle );
	$summary = $plan->toArray();
	bundle_assert( $before === bundle_snapshot( $themePath ), 'Planning wrote into the theme.' );
	bundle_assert( array( 'add' ) === array_values( array_unique( array_column( $summary['changes'], 'action' ) ) ), 'Fresh plan is not all additions.' );
	bundle_assert( 11 === count( $summary['changes'] ), 'Plan did not include all members, ACF, hidden files, and theme JSON.' );
	$newThemeJson = $json->read( $plan->files['theme.json'] );
	bundle_assert( 3 === $newThemeJson['version'] && true === $newThemeJson['settings']['blocks']['acme/el-wrapper']['spacing']['margin'] && true === $newThemeJson['settings']['blocks']['acme/el-wrapper']['spacing']['padding'], 'Missing theme JSON was not created as version 3 with namespace-transformed wrapper controls.' );
	bundle_assert( ! isset( $newThemeJson['settings']['blocks']['stwp/el-wrapper'] ), 'Theme JSON overlay did not transform the structural block-name key.' );
	bundle_assert( array() === $summary['missing_dependencies'] && array() === $summary['shared_assets'], 'Bundle acquired dependencies/shared assets.' );
	foreach ( $bundleData['members'] as $slug ) {
		$metadata = $json->read( $plan->files[ 'blocks/acme-' . $slug . '/block.json' ] );
		bundle_assert( 'acme/' . $slug === $metadata['name'] && 'Original ' . $slug === $metadata['title'], 'Fixed member identity/title was renamed.' );
		bundle_assert( 'acme-blocks' === $metadata['category'], 'Theme category was not transformed.' );
		bundle_assert( 'acme-theme' === $metadata['textdomain'], 'A distinct block category overwrote the theme text domain.' );
		bundle_assert( array( 'acme/el-wrapper' ) === $metadata['parent'] && array( 'acme/el-wrapper' ) === $metadata['ancestor'], 'Sibling metadata references were not transformed.' );
		bundle_assert( array( 'acme/el-heading', 'core/paragraph' ) === $metadata['allowedBlocks'], 'Allowed-block references were not transformed safely.' );
		$render = (string) file_get_contents( $plan->files[ 'blocks/acme-' . $slug . '/block-render.php' ] );
		bundle_assert( str_contains( $render, 'acme/el-heading' ) && str_contains( $render, 'st_wp_core_get_block_wrapper_attributes' ), 'Template reference/helper transformation failed.' );
	}
	$acfFiles = array_filter( $plan->files, static fn ( string $path ): bool => str_contains( $path, '/acf-json/' ) );
	foreach ( $acfFiles as $relative => $source ) {
		$acf = $json->read( $source );
		bundle_assert( 1 === preg_match( '/^group_[a-f0-9]{13}$/', $acf['key'] ) && basename( $relative, '.json' ) === $acf['key'], 'ACF group key/filename mismatch.' );
		bundle_assert( $acf['fields'][0]['key'] === $acf['fields'][0]['conditional_logic'][0][0]['field'], 'ACF field reference was not regenerated consistently.' );
		$slug = substr( $acf['location'][0][0]['value'], strlen( 'acme/' ) );
		$symbol = str_replace( '-', '_', $slug );
		bundle_assert( 'group_' . substr( sha1( 'acme/' . $slug . '|group_acme_' . $symbol ), 0, 13 ) === $acf['key'], 'Bundle ACF group key diverged from the existing standalone algorithm.' );
		bundle_assert( 'field_' . substr( sha1( 'acme/' . $slug . '|field_acme_' . $symbol . '_text' ), 0, 13 ) === $acf['fields'][0]['key'], 'Bundle field key diverged from the existing standalone algorithm.' );
	}
	bundle_throws( static fn () => ( new BlockInstaller() )->plan( $theme, $bundle->members()[0], 'renamed' ), 'Bundle-only members' );

	// Fake npm is an executable PHP fixture on Unix and a cmd wrapper on Windows.
	$bin = $temp . '/bin';
	$fs->mkdir( $bin );
	$fakeNpm = <<<'PHP'
<?php
declare(strict_types=1);
file_put_contents( (string) getenv( 'ST_BUNDLE_NPM_LOG' ), implode( ' ', array_slice( $argv, 1 ) ) . "\n", FILE_APPEND );
if ( getenv( 'ST_BUNDLE_NPM_FAIL' ) ) {
    fwrite( STDERR, "Fixture build failure\n" );
    exit( 23 );
}
echo "Fixture build completed\n";
PHP;
	$fs->dumpFile( $bin . '/npm-fixture.php', $fakeNpm );
	$fs->dumpFile( $bin . '/npm', '#!' . PHP_BINARY . "\n" . $fakeNpm );
	$fs->chmod( $bin . '/npm', 0755 );
	$fs->dumpFile( $bin . '/npm.cmd', '@"' . PHP_BINARY . '" "%~dp0npm-fixture.php" %*' . "\r\n" );
	$log = $temp . '/npm.log';
	bundle_env( 'ST_BUNDLE_NPM_LOG', $log );
	bundle_env( 'ST_BUNDLE_NPM_FAIL', false );
	bundle_env( 'PATH', $bin );

	$result = $installer->install( $plan );
	bundle_assert( 'completed' === $result['build']['status'], 'Bundle build did not complete: ' . json_encode( $result['build'] ) );
	bundle_assert( "run build\n" === file_get_contents( $log ), 'Bundle must execute one build and no npm install.' );
	bundle_assert( 1 === count( glob( $themePath . '/.st-toolkit/backups/*block-bundle-elementals-*' ) ?: array() ), 'Bundle did not create exactly one named backup.' );
	bundle_assert( $projectIgnore === file_get_contents( $themePath . '/.gitignore' ), 'Bundle installation changed project-owned ignore rules.' );
	if ( null !== $git ) {
		$runtimeStatus = new Process( array( $git, 'status', '--short', '--untracked-files=all', '--', '.st-toolkit' ), $themePath );
		$runtimeStatus->mustRun();
		bundle_assert( '' === trim( $runtimeStatus->getOutput() ), 'Bundle runtime files leaked into Git status: ' . $runtimeStatus->getOutput() );
	}
	foreach ( $plan->files as $relative => $source ) {
		bundle_assert( hash_file( 'sha256', $source ) === hash_file( 'sha256', $theme->resolve( $relative ) ), 'Installed bytes differ from confirmed stage.' );
	}

	$custom = $themePath . '/blocks/acme-el-wrapper/custom/project.txt';
	$fs->dumpFile( $custom, 'project-owned' );
	$mtimes = array();
	foreach ( $plan->files as $relative => $source ) {
		$fs->touch( $theme->resolve( $relative ), 1000000000 );
		$mtimes[ $relative ] = filemtime( $theme->resolve( $relative ) );
	}
	$repeat = $installer->plan( $theme, $bundle );
	bundle_assert( array( 'unchanged' ) === array_values( array_unique( array_column( $repeat->toArray()['changes'], 'action' ) ) ), 'Repeat plan is not byte-identical, including deterministic ACF.' );
	$installer->install( $repeat );
	clearstatcache();
	foreach ( $mtimes as $relative => $mtime ) {
		bundle_assert( $mtime === filemtime( $theme->resolve( $relative ) ), 'Repeat install overwrote equal files: ' . $relative );
	}
	bundle_assert( 'project-owned' === file_get_contents( $custom ), 'Reinstall removed custom files.' );
	$fs->dumpFile( $themePath . '/blocks/acme-el-wrapper/block-render.php', "<?php // custom wrapper\n" );
	$fs->dumpFile( $themePath . '/blocks/acme-el-heading/block.json', '{"name":"custom/heading"}' );
	$disabledWrapper = $json->read( $themePath . '/theme.json' );
	$disabledWrapper['settings']['blocks']['acme/el-wrapper']['spacing']['padding'] = false;
	$json->write( $themePath . '/theme.json', $disabledWrapper );
	$conflicting = $installer->plan( $theme, $bundle );
	bundle_assert( 3 === count( $conflicting->toArray()['conflicts'] ) && in_array( 'theme.json', $conflicting->toArray()['conflicts'], true ), 'Plan did not collect all replacement conflicts, including theme JSON.' );

	// CLI uses a disposable package copy so canonical real resources stay untouched.
	$autoload = '$loader = require ' . var_export( $root . '/vendor/autoload.php', true ) . ';' . "\n"
		. '$loader->addPsr4( "SnailTheme\\\\WPStarterToolkit\\\\", __DIR__ . "/src", true );' . "\n";
	$runner = $package . '/runner.php';
	$fs->dumpFile( $runner, "<?php\ndeclare(strict_types=1);\n" . $autoload . 'exit( ( new SnailTheme\WPStarterToolkit\Application() )->run() );' );
	$list = bundle_cli( $runner, array( 'blocks:list', '--json' ) );
	bundle_assert( 0 === $list['status'] && array( 'elementals', 'slider' ) === array_column( $list['json']['blocks'], 'slug' ), 'Fixture list must include bundles and hide bundle-only members.' );
	$doctor = bundle_cli( $runner, array( 'doctor', '--theme=' . $themePath, '--json' ) );
	bundle_assert( 0 === $doctor['status'] && array( 'slider' ) === array_column( $doctor['json']['blocks'], 'slug' ), 'Doctor no longer receives standalone BlockManifest instances only.' );
	$cliArgs = array( 'blocks:install', 'elementals', '--theme=' . $themePath, '--json', '--no-interaction' );
	$before = bundle_snapshot( $themePath );
	$logBefore = (string) file_get_contents( $log );
	$dry = bundle_cli( $runner, array_merge( $cliArgs, array( '--dry-run' ) ) );
	bundle_assert( 0 === $dry['status'] && true === $dry['json']['dry_run'] && 3 === count( $dry['json']['conflicts'] ), 'CLI dry-run did not expose full bundle conflicts.' );
	$cancel = bundle_cli( $runner, $cliArgs );
	bundle_assert( 0 !== $cancel['status'] && 'cancelled' === $cancel['json']['status'], 'Noninteractive install without --yes must refuse writes.' );
	bundle_assert( $before === bundle_snapshot( $themePath ) && $logBefore === file_get_contents( $log ), 'Dry-run/noninteractive cancellation caused writes, backups, or npm.' );
	$interactive = $package . '/interactive.php';
	$fs->dumpFile( $interactive, "<?php\ndeclare(strict_types=1);\n" . $autoload . <<<'PHP'
$app = new SnailTheme\WPStarterToolkit\Application();
$tester = new Symfony\Component\Console\Tester\CommandTester( $app->find( 'blocks:install' ) );
$tester->setInputs( array( 'no' ) );
$status = $tester->execute( array( 'source-block' => 'elementals', '--theme' => $argv[1] ), array( 'interactive' => true ) );
echo $tester->getDisplay();
exit( $status );
PHP
	);
	$decline = bundle_cli( $interactive, array( $themePath ) );
	bundle_assert( str_contains( $decline['output'], 'cancelled' ) && str_contains( $decline['output'], 'replace blocks/acme-el-wrapper/block-render.php' ) && str_contains( $decline['output'], 'replace blocks/acme-el-heading/block.json' ) && str_contains( $decline['output'], 'replace theme.json' ), 'Interactive cancellation did not show every conflict before confirmation.' );
	bundle_assert( $before === bundle_snapshot( $themePath ) && $logBefore === file_get_contents( $log ), 'Declining wrote files/backup or invoked npm.' );
	$override = bundle_cli( $runner, array_merge( $cliArgs, array( 'renamed', '--yes' ) ) );
	bundle_assert( 0 !== $override['status'] && str_contains( $override['output'], 'no destination override' ), 'Bundle accepted a destination override.' );
	$memberInstall = bundle_cli( $runner, array( 'blocks:install', 'el-heading', 'heading', '--theme=' . $themePath, '--dry-run' ) );
	bundle_assert( 0 !== $memberInstall['status'] && str_contains( $memberInstall['output'], 'bundle-only' ), 'Bundle-only member installed standalone.' );
	$missingDestination = bundle_cli( $runner, array( 'blocks:install', 'slider', '--theme=' . $themePath, '--dry-run' ) );
	bundle_assert( 0 !== $missingDestination['status'] && str_contains( $missingDestination['output'], 'destination-block' ), 'Standalone destination became optional.' );
	$slider = bundle_cli( $runner, array( 'blocks:install', 'slider', 'hero-slider', '--theme=' . $themePath, '--dry-run', '--json' ) );
	bundle_assert( 0 === $slider['status'] && 'hero-slider' === $slider['json']['destination_block'], 'Standalone slider source/destination interface regressed.' );
	$invalidTheme = bundle_cli( $runner, array( 'blocks:install', 'elementals', '--theme=' . $temp . '/absent-theme', '--json', '--yes' ) );
	bundle_assert( 0 !== $invalidTheme['status'] && 'failed' === ( $invalidTheme['json']['status'] ?? null ), 'Bundle --json emitted console text for an invalid theme.' );
	$missingStandaloneDestination = bundle_cli( $runner, array( 'blocks:install', 'slider', '--theme=' . $themePath, '--json' ) );
	bundle_assert( 0 !== $missingStandaloneDestination['status'] && 'failed' === ( $missingStandaloneDestination['json']['status'] ?? null ), 'Missing destination did not produce a structured CLI failure.' );
	$validThemeJson = (string) file_get_contents( $themePath . '/theme.json' );
	$fs->dumpFile( $themePath . '/theme.json', '{malformed' );
	$malformedBefore = bundle_snapshot( $themePath );
	$malformedCli = bundle_cli( $runner, array_merge( $cliArgs, array( '--yes' ) ) );
	bundle_assert( 0 !== $malformedCli['status'] && 'failed' === $malformedCli['json']['status'] && str_contains( $malformedCli['json']['message'], 'Invalid JSON in target theme.json' ), 'Malformed target theme JSON did not fail CLI preflight.' );
	bundle_assert( $malformedBefore === bundle_snapshot( $themePath ) && $logBefore === file_get_contents( $log ), 'Malformed target theme JSON caused writes, a backup, or npm.' );
	$fs->dumpFile( $themePath . '/theme.json', $validThemeJson );

	$badFile = $package . '/resources/blocks/el-heading/files/block.json';
	$validMetadata = (string) file_get_contents( $badFile );
	$fs->dumpFile( $badFile, '{invalid' );
	bundle_throws( static fn () => $installer->plan( $theme, $bundle ), 'Invalid JSON' );
	$badCli = bundle_cli( $runner, array_merge( $cliArgs, array( '--yes' ) ) );
	bundle_assert( 0 !== $badCli['status'] && 'failed' === $badCli['json']['status'], 'Bad second member did not fail CLI preflight.' );
	bundle_assert( $before === bundle_snapshot( $themePath ) && $logBefore === file_get_contents( $log ), 'Bad second member mutated the target before full preflight.' );
	$fs->dumpFile( $badFile, $validMetadata );
	$metadata = json_decode( $validMetadata, true );
	$metadata['parent'] = array( 'stwp/el-absent' );
	$json->write( $badFile, $metadata );
	bundle_throws( static fn () => $installer->plan( $theme, $bundle ), 'Invalid internal bundle block reference' );
	$metadata['parent'] = array( 'stwp/el-wrapper' );
	$metadata['acf']['renderTemplate'] = '../escape.php';
	$json->write( $badFile, $metadata );
	bundle_throws( static fn () => $installer->plan( $theme, $bundle ), 'Unsafe bundle path' );
	$metadata['acf']['renderTemplate'] = 'missing.php';
	$json->write( $badFile, $metadata );
	bundle_throws( static fn () => $installer->plan( $theme, $bundle ), 'Missing bundle metadata file reference' );
	$fs->dumpFile( $badFile, $validMetadata );
	$badPhp = $package . '/resources/blocks/el-heading/files/broken.php';
	$fs->dumpFile( $badPhp, '<?php function broken(' );
	bundle_throws( static fn () => $installer->plan( $theme, $bundle ), 'Parse error' );
	$fs->remove( $badPhp );
	$metadata = json_decode( $validMetadata, true );
	$metadata['acf']['renderTemplate'] = 'broken-template.inc';
	$json->write( $badFile, $metadata );
	$fs->dumpFile( dirname( $badFile ) . '/broken-template.inc', '<?php function broken(' );
	bundle_throws( static fn () => $installer->plan( $theme, $bundle ), 'Parse error' );
	$fs->remove( dirname( $badFile ) . '/broken-template.inc' );
	$fs->dumpFile( $badFile, $validMetadata );
	$templatePath = $package . '/resources/blocks/el-heading/files/block-render.php';
	$validTemplate = (string) file_get_contents( $templatePath );
	$fs->dumpFile( $templatePath, '<?php $template = array( "{$namespace}/el-absent" );' );
	bundle_throws( static fn () => $installer->plan( $theme, $bundle ), 'Invalid internal bundle template reference' );
	$fs->dumpFile( $templatePath, $validTemplate );
	$missingHelperManifest = $package . '/resources/blocks/el-heading/st-block.json';
	$validManifest = (string) file_get_contents( $missingHelperManifest );
	$memberData = json_decode( $validManifest, true );
	$memberData['required_php_helpers'] = array( 'st_wp_core_nonexistent_fixture' );
	$json->write( $missingHelperManifest, $memberData );
	bundle_throws( static fn () => $installer->plan( $theme, $bundle ), 'Missing required bundle PHP helpers' );
	$memberData['required_php_helpers'] = array();
	$memberData['npm_dependencies'] = array( 'not-allowed' => '*' );
	$json->write( $missingHelperManifest, $memberData );
	bundle_throws( static fn () => $installer->plan( $theme, $bundle ), 'dependencies/shared assets are not supported' );
	$fs->dumpFile( $missingHelperManifest, $validManifest );
	$oldCore = new ThemeContext( $theme->path, $theme->styleCssPath, $theme->bootstrapPath, '1.0.0', $theme->patterns );
	bundle_throws( static fn () => $installer->plan( $oldCore, $bundle ), 'requires core >=1.1.0' );
	$unsafePatterns = $theme->patterns;
	$unsafePatterns['block_namespace'] = '../../outside';
	$unsafeTheme = new ThemeContext( $theme->path, $theme->styleCssPath, $theme->bootstrapPath, $theme->coreVersion, $unsafePatterns );
	bundle_throws( static fn () => $installer->plan( $unsafeTheme, $bundle ), 'Unsafe bundle block namespace' );
	bundle_assert( $before === bundle_snapshot( $themePath ), 'Failed validation touched target files.' );

	if ( '\\' !== DIRECTORY_SEPARATOR ) {
		$outside = $temp . '/outside';
		$fs->mkdir( $outside );
		$fs->symlink( $outside, $themePath . '/acf-json' . '-linked' );
		$acfRoot = $themePath . '/acf-json';
		$fs->rename( $acfRoot, $acfRoot . '-saved' );
		$fs->symlink( $outside, $acfRoot );
		bundle_throws( static fn () => $installer->plan( $theme, $bundle ), 'Unsafe bundle write target' );
		bundle_assert( array( '.', '..' ) === scandir( $outside ), 'Symlink preflight wrote outside theme.' );
		$fs->remove( array( $acfRoot, $themePath . '/acf-json-linked' ) );
		$fs->rename( $acfRoot . '-saved', $acfRoot );
		$fs->rename( $themePath . '/.st-toolkit', $themePath . '/.st-toolkit-saved' );
		$fs->symlink( $outside, $themePath . '/.st-toolkit' );
		bundle_throws( static fn () => $installer->plan( $theme, $bundle ), 'Unsafe bundle write target' );
		bundle_assert( array( '.', '..' ) === scandir( $outside ), 'Backup preflight wrote outside theme.' );
		$fs->remove( $themePath . '/.st-toolkit' );
		$fs->rename( $themePath . '/.st-toolkit-saved', $themePath . '/.st-toolkit' );
		// A source symlink, even to a local file, cannot become package-owned output.
		$sourceLink = $package . '/resources/blocks/el-heading/files/linked.php';
		$fs->symlink( $package . '/resources/blocks/el-heading/files/block-render.php', $sourceLink );
		bundle_throws( static fn () => $installer->plan( $theme, $bundle ), 'Unsafe bundle source' );
		$fs->remove( $sourceLink );
		$fs->dumpFile( $outside . '/theme.json', '{"outside":true}' );
		$fs->rename( $themePath . '/theme.json', $themePath . '/theme.json-saved' );
		$fs->symlink( $outside . '/theme.json', $themePath . '/theme.json' );
		bundle_throws( static fn () => $installer->plan( $theme, $bundle ), 'Unsafe bundle write target: theme.json' );
		bundle_assert( '{"outside":true}' === file_get_contents( $outside . '/theme.json' ), 'Settings staging wrote through a symlink outside the theme.' );
		$fs->remove( $themePath . '/theme.json' );
		$fs->rename( $themePath . '/theme.json-saved', $themePath . '/theme.json' );
	}

	$before = bundle_snapshot( $themePath );
	$noNpmPlan = $installer->plan( $theme, $bundle );
	bundle_env( 'PATH', $temp . '/empty' );
	bundle_throws( static fn () => $installer->install( $noNpmPlan ), 'not found in PATH' );
	bundle_env( 'PATH', $bin );
	bundle_assert( $before === bundle_snapshot( $themePath ), 'Missing npm preflight created a backup or wrote members.' );
	$fs->dumpFile( $themePath . '/package.json', '{}' );
	$noBuildPlan = $installer->plan( $theme, $bundle );
	$noBuildBefore = bundle_snapshot( $themePath );
	bundle_throws( static fn () => $installer->install( $noBuildPlan ), 'must define an npm run build' );
	bundle_assert( $noBuildBefore === bundle_snapshot( $themePath ), 'Missing build script caused target writes.' );
	$fs->dumpFile( $themePath . '/package.json', '{"scripts":{"build":"fixture-build"}}' );
	$configPlan = $installer->plan( $theme, $bundle );
	$bootstrapBefore = (string) file_get_contents( $bootstrap );
	$fs->dumpFile( $bootstrap, $bootstrapBefore . "\n// Concurrent namespace/core change.\n" );
	$configBefore = bundle_snapshot( $themePath );
	bundle_throws( static fn () => $installer->install( $configPlan ), 'changed after confirmation' );
	bundle_assert( $configBefore === bundle_snapshot( $themePath ), 'A stale core/pattern snapshot caused writes or a backup.' );
	$fs->dumpFile( $bootstrap, $bootstrapBefore );
	$packagePlan = $installer->plan( $theme, $bundle );
	$packageBefore = (string) file_get_contents( $themePath . '/package.json' );
	$fs->dumpFile( $themePath . '/package.json', '{"scripts":{"build":"different-build-command"}}' );
	$packageSnapshot = bundle_snapshot( $themePath );
	bundle_throws( static fn () => $installer->install( $packagePlan ), 'changed after confirmation' );
	bundle_assert( $packageSnapshot === bundle_snapshot( $themePath ), 'A changed build configuration was used without a new plan.' );
	$fs->dumpFile( $themePath . '/package.json', $packageBefore );

	$stale = $installer->plan( $theme, $bundle );
	$changedPath = $themePath . '/blocks/acme-el-wrapper/assets/view.js';
	$fs->dumpFile( $changedPath, '// changed after confirmation' );
	$staleBefore = bundle_snapshot( $themePath );
	bundle_throws( static fn () => $installer->install( $stale ), 'changed after confirmation' );
	bundle_assert( $staleBefore === bundle_snapshot( $themePath ), 'Stale plan modified theme or backed it up.' );

	$failing = new BlockBundleInstaller( filesystem: new BundleFailingFilesystem() );
	$failurePlan = $failing->plan( $theme, $bundle );
	$ownedBefore = array();
	foreach ( $failurePlan->files as $relative => $source ) {
		$ownedBefore[ $relative ] = file_get_contents( $theme->resolve( $relative ) );
	}
	$backupsBefore = count( glob( $themePath . '/.st-toolkit/backups/*block-bundle-elementals-*' ) ?: array() );
	$logBefore = (string) file_get_contents( $log );
	bundle_throws( static fn () => $failing->install( $failurePlan ), 'owned files restored' );
	foreach ( $ownedBefore as $relative => $contents ) {
		bundle_assert( $contents === file_get_contents( $theme->resolve( $relative ) ), 'Write failure failed to restore owned file: ' . $relative );
	}
	bundle_assert( $backupsBefore + 1 === count( glob( $themePath . '/.st-toolkit/backups/*block-bundle-elementals-*' ) ?: array() ), 'Write failure must use a single backup.' );
	bundle_assert( $logBefore === file_get_contents( $log ) && 'project-owned' === file_get_contents( $custom ), 'Write failure ran npm or removed unrelated files.' );
	$sentinels = glob( $themePath . '/blocks/*/assets/project-added-during-install.txt' ) ?: array();
	bundle_assert( 1 === count( $sentinels ), 'Recovery removed unrelated files added inside a member directory.' );

	$freshPath = $temp . '/fresh-theme';
	$fs->mirror( $root . '/resources/core/files', $freshPath );
	$fs->copy( $bootstrap, $freshPath . '/core/bootstrap.php', true );
	$fs->copy( $themePath . '/style.css', $freshPath . '/style.css' );
	$fs->copy( $themePath . '/package.json', $freshPath . '/package.json' );
	$freshTheme = ( new ThemeDetector() )->detect( $freshPath );
	$firstLockPath = $freshPath . '/.st-toolkit/backups/block-bundle.lock';
	$fs->mkdir( dirname( $firstLockPath ) );
	$firstLock = fopen( $firstLockPath, 'c+' );
	bundle_assert( false !== $firstLock && flock( $firstLock, LOCK_EX | LOCK_NB ), 'Could not acquire the fresh-theme fixture lock.' );
	try {
		if ( null !== $git ) {
			( new Process( array( $git, 'init', '--quiet', $freshPath ) ) )->mustRun();
		}
		$firstLockPlan = $installer->plan( $freshTheme, $bundle );
		bundle_throws( static fn () => $installer->install( $firstLockPlan ), 'Another bundle installation is running' );
		bundle_assert( 0 === count( glob( $freshPath . '/.st-toolkit/backups/*block-bundle-elementals-*' ) ?: array() ), 'Failed first lock acquisition created a backup.' );
		bundle_assert( '*' . PHP_EOL === file_get_contents( $freshPath . '/.st-toolkit/backups/.gitignore' ), 'Failed first lock acquisition left runtime state unignored.' );
		if ( null !== $git ) {
			$runtimeStatus = new Process( array( $git, 'status', '--short', '--untracked-files=all', '--', '.st-toolkit' ), $freshPath );
			$runtimeStatus->mustRun();
			bundle_assert( '' === trim( $runtimeStatus->getOutput() ), 'Failed first lock acquisition leaked runtime files into Git status: ' . $runtimeStatus->getOutput() );
		}
	} finally {
		flock( $firstLock, LOCK_UN );
		fclose( $firstLock );
	}
	$freshInstaller = new BlockBundleInstaller( filesystem: new BundleFailingFilesystem() );
	$freshPlan = $freshInstaller->plan( $freshTheme, $bundle );
	bundle_throws( static fn () => $freshInstaller->install( $freshPlan ), 'owned files restored' );
	foreach ( $freshPlan->files as $relative => $source ) {
		bundle_assert( ! file_exists( $freshTheme->resolve( $relative ) ), 'Recovery left a newly added owned file: ' . $relative );
	}
	bundle_assert( 'keep' === file_get_contents( $freshPath . '/acf-json/project-added-during-install.txt' ), 'Recovery removed an unrelated file from a newly created directory.' );
	bundle_assert( 1 === count( glob( $freshPath . '/.st-toolkit/backups/*block-bundle-elementals-*' ) ?: array() ), 'Fresh write recovery did not use one backup.' );
	bundle_assert( $logBefore === file_get_contents( $log ), 'Fresh write failure ran npm.' );

	// Race fixtures have independent target themes and never mutate real resources.
	$racePath = $temp . '/race-theme';
	$fs->mirror( $freshPath, $racePath );
	$fs->remove( $racePath . '/.st-toolkit' );
	$raceTheme = ( new ThemeDetector() )->detect( $racePath );
	$raceTarget = $racePath . '/theme.json';
	$raceContents = '{"version":3,"settings":{"custom":{"concurrent":true}}}';
	$unattemptedInstaller = new BlockBundleInstaller( filesystem: new BundleUnattemptedRaceFilesystem( $raceTarget, $raceContents ) );
	$unattemptedPlan = $unattemptedInstaller->plan( $raceTheme, $bundle );
	bundle_throws( static fn () => $unattemptedInstaller->install( $unattemptedPlan ), 'owned files restored' );
	bundle_assert( $raceContents === file_get_contents( $raceTarget ), 'Rollback deleted a concurrently created target that was never attempted.' );
	$fs->remove( $racePath . '/.st-toolkit' );
	$baselineInstaller = new BlockBundleInstaller();
	$baselineInstaller->install( $baselineInstaller->plan( $raceTheme, $bundle ) );
	$fs->dumpFile( $racePath . '/blocks/acme-el-heading/block.json', '{"name":"custom/heading"}' );
	$fs->dumpFile( $raceTarget, '{"version":3}' );
	$raceTargetContents = '{"version":3,"settings":{"custom":{"editedDuringBackup":true}}}';
	$raceInstaller = new BlockBundleInstaller( backups: new BackupManager( new BundleBackupRaceFilesystem( $raceTarget, $raceTargetContents ) ) );
	$backupRacePlan = $raceInstaller->plan( $raceTheme, $bundle );
	$raceLogBefore = (string) file_get_contents( $log );
	bundle_throws( static fn () => $raceInstaller->install( $backupRacePlan ), 'changed after confirmation' );
	bundle_assert( $raceTargetContents === file_get_contents( $raceTarget ) && '{"name":"custom/heading"}' === file_get_contents( $racePath . '/blocks/acme-el-heading/block.json' ), 'A backup-time edit was overwritten or other members were written.' );
	bundle_assert( $raceLogBefore === file_get_contents( $log ), 'Backup-time race invoked npm.' );
	$fs->remove( $raceTarget );
	$externalFile = $temp . '/external-hardlink.json';
	$fs->dumpFile( $externalFile, '{"version":3,"custom":"external"}' );
	if ( link( $externalFile, $raceTarget ) ) {
		$baselineInstaller->install( $baselineInstaller->plan( $raceTheme, $bundle ) );
		bundle_assert( '{"version":3,"custom":"external"}' === file_get_contents( $externalFile ), 'Bundle writes followed a hard link and modified a file outside the theme.' );
	}
	$lockPlan = $baselineInstaller->plan( $raceTheme, $bundle );
	$lock = fopen( $racePath . '/.st-toolkit/backups/block-bundle.lock', 'c+' );
	bundle_assert( false !== $lock && flock( $lock, LOCK_EX | LOCK_NB ), 'Could not acquire fixture bundle lock.' );
	try {
		$lockBefore = bundle_snapshot( $racePath );
		$lockLogBefore = (string) file_get_contents( $log );
		bundle_throws( static fn () => $baselineInstaller->install( $lockPlan ), 'Another bundle installation is running' );
		bundle_assert( $lockBefore === bundle_snapshot( $racePath ) && $lockLogBefore === file_get_contents( $log ), 'A second bundle writer created a backup, wrote files, or ran npm.' );
	} finally {
		flock( $lock, LOCK_UN );
		fclose( $lock );
	}
	$sourcePlan = $baselineInstaller->plan( $raceTheme, $bundle );
	$stageFile = array_values( $sourcePlan->files )[0];
	$stageContents = (string) file_get_contents( $stageFile );
	$fs->dumpFile( $stageFile, 'changed staged bytes' );
	$stageBefore = bundle_snapshot( $racePath );
	bundle_throws( static fn () => $baselineInstaller->install( $sourcePlan ), 'source changed after confirmation' );
	bundle_assert( $stageBefore === bundle_snapshot( $racePath ), 'Tampered stage caused writes or a backup.' );
	$fs->dumpFile( $stageFile, $stageContents );
	if ( '\\' !== DIRECTORY_SEPARATOR ) {
		$rootPlan = $baselineInstaller->plan( $raceTheme, $bundle );
		$outsideRoot = $temp . '/redirected-root';
		$fs->mkdir( $outsideRoot );
		$fs->rename( $racePath, $racePath . '-saved' );
		$fs->symlink( $outsideRoot, $racePath );
		try {
			bundle_throws( static fn () => $baselineInstaller->install( $rootPlan ), 'Unsafe bundle theme root' );
			bundle_assert( array( '.', '..' ) === scandir( $outsideRoot ), 'A replaced theme root redirected writes outside the theme.' );
		} finally {
			$fs->remove( $racePath );
			$fs->rename( $racePath . '-saved', $racePath );
		}
	}
	$recoveryPath = $temp . '/recovery-race-theme';
	$fs->mirror( $racePath, $recoveryPath );
	$fs->remove( $recoveryPath . '/.st-toolkit' );
	$recoveryTheme = ( new ThemeDetector() )->detect( $recoveryPath );
	$recoveryBaseline = $baselineInstaller->plan( $recoveryTheme, $bundle );
	$recoveryTargets = array_slice( array_keys( $recoveryBaseline->files ), 0, 4 );
	foreach ( $recoveryTargets as $index => $relative ) {
		$fs->dumpFile( $recoveryTheme->resolve( $relative ), 'project original ' . $index );
	}
	$recoveryInstaller = new BlockBundleInstaller( filesystem: new BundleRecoveryRaceFilesystem() );
	$recoveryPlan = $recoveryInstaller->plan( $recoveryTheme, $bundle );
	bundle_throws( static fn () => $recoveryInstaller->install( $recoveryPlan ), 'Concurrent edit preserved during recovery' );
	foreach ( $recoveryTargets as $index => $relative ) {
		$expectedContents = 2 === $index ? 'concurrent edit after bundle write' : 'project original ' . $index;
		bundle_assert( $expectedContents === file_get_contents( $recoveryTheme->resolve( $relative ) ), 'Recovery clobbered a concurrent edit or stopped before restoring other writes: ' . $relative );
	}
	$logBefore = (string) file_get_contents( $log );

	$settingsFailureInstaller = new BlockBundleInstaller( filesystem: new BundleThemeJsonFailingFilesystem() );
	$settingsFailurePlan = $settingsFailureInstaller->plan( $theme, $bundle );
	$backupsBefore = count( glob( $themePath . '/.st-toolkit/backups/*block-bundle-elementals-*' ) ?: array() );
	bundle_throws( static fn () => $settingsFailureInstaller->install( $settingsFailurePlan ), 'owned files restored' );
	foreach ( $ownedBefore as $relative => $contents ) {
		bundle_assert( $contents === file_get_contents( $theme->resolve( $relative ) ), 'Failure after writing theme JSON did not restore the complete bundle: ' . $relative );
	}
	$settingsBackups = glob( $themePath . '/.st-toolkit/backups/*block-bundle-elementals-*/theme.json' ) ?: array();
	bundle_assert( $backupsBefore + 1 === count( glob( $themePath . '/.st-toolkit/backups/*block-bundle-elementals-*' ) ?: array() ) && array() !== $settingsBackups, 'Theme JSON did not share the single bundle backup.' );
	bundle_assert( $validThemeJson === file_get_contents( $themePath . '/theme.json' ) && $logBefore === file_get_contents( $log ), 'Theme JSON recovery lost original bytes or ran npm.' );

	bundle_env( 'ST_BUNDLE_NPM_FAIL', '1' );
	$failure = bundle_cli( $runner, array_merge( $cliArgs, array( '--yes' ) ) );
	bundle_assert( 0 !== $failure['status'] && 'failed' === $failure['json']['build']['status'] && is_dir( $failure['json']['backup_path'] ), 'Build failure must return failure and retain a backup.' );
	bundle_assert( str_contains( $failure['json']['recovery'], 'npm run build' ), 'Build failure omitted recovery instruction.' );
	bundle_assert( $logBefore . "run build\n" === file_get_contents( $log ), 'Build failure ran more than one build/npm command.' );
	foreach ( $failurePlan->files as $relative => $source ) {
		bundle_assert( hash_file( 'sha256', $source ) === hash_file( 'sha256', $theme->resolve( $relative ) ), 'Build failure rolled back installed member files.' );
	}
	bundle_env( 'ST_BUNDLE_NPM_FAIL', false );

	// When the main-owned resources exist, validate the actual complete pack too.
	$realBundle = BlockBundleManifest::load( 'elementals' );
	bundle_assert( 10 === count( $realBundle->memberSlugs() ), 'Elementals must declare all ten fixed members.' );
	$realReady = true;
	foreach ( $realBundle->memberSlugs() as $slug ) {
		$realReady = $realReady && is_file( $root . '/resources/blocks/' . $slug . '/st-block.json' );
	}
	if ( $realReady ) {
		foreach ( array( 'acme', 'stwp-studio', 'stwp-core', 'stwp-starter' ) as $namespace ) {
			$namespacePath = $temp . '/bundle-' . $namespace;
			$namespacePatterns = $patterns;
			$namespacePatterns['block_namespace'] = $namespace;
			$fs->mirror( $root . '/resources/core/files', $namespacePath );
			$fs->copy( $themePath . '/style.css', $namespacePath . '/style.css' );
			$fs->copy( $themePath . '/package.json', $namespacePath . '/package.json' );
			$fs->dumpFile(
				$namespacePath . '/core/bootstrap.php',
				( new ReplacementEngine() )->applyThemePatterns( (string) file_get_contents( $root . '/resources/core/files/core/bootstrap.php' ), $namespacePatterns )
			);
			$namespaceTheme = ( new ThemeDetector() )->detect( $namespacePath );
			bundle_assert( $namespace === $namespaceTheme->pattern( 'block_namespace' ), 'Bundle fixture bootstrap has the wrong namespace: ' . $namespace );
			$realInstaller = new BlockBundleInstaller();
			$realPlan = $realInstaller->plan( $namespaceTheme, $realBundle );
			bundle_assert( 10 === count( $realPlan->toArray()['members'] ), 'Actual bundle did not stage every member.' );
			$realResult = $realInstaller->install( $realPlan );
			bundle_assert( 'completed' === $realResult['build']['status'], 'Actual bundle did not install/build.' );
			foreach ( $realBundle->memberSlugs() as $slug ) {
				$metadata = $json->read( $namespacePath . '/blocks/' . $namespace . '-' . $slug . '/block.json' );
				bundle_assert( $namespace . '/' . $slug === $metadata['name'], 'Bundle member namespace was transformed more than once: ' . $namespace . '/' . $slug );
				$style = (string) file_get_contents( $namespacePath . '/blocks/' . $namespace . '-' . $slug . '/assets/css/view-style.min.css' );
				bundle_assert( str_contains( $style, '.' . $namespace . '-' . $slug ), 'Bundle styles have the wrong namespace: ' . $namespace . '/' . $slug );
			}
			foreach ( glob( $namespacePath . '/acf-json/*.json' ) ?: array() as $acfPath ) {
				$acf = $json->read( $acfPath );
				$slug = substr( $acf['location'][0][0]['value'], strlen( $namespace ) + 1 );
				bundle_assert( in_array( $slug, $realBundle->memberSlugs(), true ), 'Bundle ACF location has the wrong namespace: ' . $namespace );
				foreach ( $acf['fields'] as $field ) {
					if ( 'group' === $field['type'] ) {
						bundle_assert( in_array( $field['name'], array( $namespace . '-' . $slug . '-fields', $namespace . '-' . $slug . '-settings' ), true ), 'Bundle ACF field root has the wrong namespace: ' . $namespace );
					}
				}
			}
			$settings = $json->read( $namespacePath . '/theme.json' );
			bundle_assert( true === $settings['settings']['blocks'][ $namespace . '/el-wrapper' ]['spacing']['padding'], 'Bundle theme settings have the wrong namespace: ' . $namespace );
			$realRepeat = $realInstaller->plan( $namespaceTheme, $realBundle );
			bundle_assert( array( 'unchanged' ) === array_values( array_unique( array_column( $realRepeat->toArray()['changes'], 'action' ) ) ), 'Actual pack reinstall is not deterministic.' );
			if ( 'acme' === $namespace ) {
				$faqManifest = BlockManifest::load( 'el-faq' );
				$standalone = new BlockInstaller();
				$otherBefore = bundle_snapshot( $namespacePath . '/blocks/acme-el-heading' );
				$faqArgs = array( 'blocks:install', 'el-faq', 'el-faq', '--theme=' . $namespacePath, '--json', '--no-interaction' );
				$beforeFaq = bundle_snapshot( $namespacePath );
				$faqDry = bundle_cli( $root . '/bin/st-toolkit', array_merge( $faqArgs, array( '--dry-run' ) ) );
				bundle_assert( 0 === $faqDry['status'] && true === $faqDry['json']['core_version_satisfied'], 'Standalone FAQ dry run failed.' );
				bundle_assert( $beforeFaq === bundle_snapshot( $namespacePath ), 'Standalone FAQ dry run wrote files.' );
				$faqCancel = bundle_cli( $root . '/bin/st-toolkit', $faqArgs );
				bundle_assert( str_contains( $faqCancel['output'], 'cancelled' ) && $beforeFaq === bundle_snapshot( $namespacePath ), 'Unconfirmed standalone FAQ wrote files.' );
				$customFaq = $namespacePath . '/blocks/acme-el-faq/block-render.php';
				$fs->dumpFile( $customFaq, "<?php // custom FAQ before replacement\n" );
				$faqInstall = bundle_cli( $root . '/bin/st-toolkit', array_merge( $faqArgs, array( '--yes' ) ) );
				bundle_assert( 0 === $faqInstall['status'] && 'completed' === $faqInstall['json']['build']['status'], 'Standalone FAQ install/build failed.' );
				$backup = $faqInstall['json']['backup_path'];
				bundle_assert( str_contains( (string) file_get_contents( $backup . '/blocks/acme-el-faq/block-render.php' ), 'custom FAQ' ), 'FAQ conflict was not backed up.' );
				bundle_assert( $otherBefore === bundle_snapshot( $namespacePath . '/blocks/acme-el-heading' ), 'Standalone FAQ changed another Elemental.' );
				bundle_assert( 'Elemental FAQ' === $json->read( $namespacePath . '/blocks/acme-el-faq/block.json' )['title'], 'Same-slug FAQ install renamed the canonical title.' );
				$old = new ThemeContext( $namespaceTheme->path, $namespaceTheme->styleCssPath, $namespaceTheme->bootstrapPath, '1.1.0', $namespaceTheme->patterns );
				bundle_assert( ! $standalone->plan( $old, $faqManifest, 'el-faq' )['core_version_satisfied'], 'FAQ accepted pre-schema core.' );
				bundle_throws( static fn () => $standalone->install( $old, $faqManifest, 'el-faq' ), 'requires core >=1.2.0' );
				bundle_env( 'ST_BUNDLE_NPM_FAIL', '1' );
				$faqFailed = bundle_cli( $root . '/bin/st-toolkit', array_merge( $faqArgs, array( '--yes' ) ) );
				bundle_assert( 0 !== $faqFailed['status'] && 'failed' === $faqFailed['json']['build']['status'] && is_dir( $faqFailed['json']['backup_path'] ), 'Standalone FAQ build failure omitted backup/reporting.' );
				bundle_env( 'ST_BUNDLE_NPM_FAIL', false );
			}
		}
	} else {
		fwrite( STDOUT, "Real pack resources pending; synthetic transaction fixtures passed.\n" );
	}
	fwrite( STDOUT, sprintf( "Bundle checks passed (%d assertions).\n", $assertions ) );
} finally {
	foreach ( array( 'PATH' => $originalPath, 'ST_BUNDLE_NPM_LOG' => $originalLog, 'ST_BUNDLE_NPM_FAIL' => $originalFail ) as $name => $value ) {
		putenv( false === $value ? $name : $name . '=' . $value );
	}
	$_ENV = $originalEnv;
	$_SERVER = $originalServer;
	$fs->remove( $temp );
}

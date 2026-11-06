<?php
/**
 * Reinstallation preserves project scaffolding; it is not automatic retirement.
 */

declare(strict_types=1);

use SnailTheme\WPStarterToolkit\Component\ComponentInstaller;
use SnailTheme\WPStarterToolkit\Component\ComponentManifest;
use SnailTheme\WPStarterToolkit\Support\BackupManager;
use SnailTheme\WPStarterToolkit\Support\JsonFile;
use SnailTheme\WPStarterToolkit\Theme\ThemeDetector;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

require dirname( __DIR__ ) . '/vendor/autoload.php';

$root = dirname( __DIR__ );
$temp = sys_get_temp_dir() . '/st-component-scaffolding-' . bin2hex( random_bytes( 6 ) );
$fs = new Filesystem();
$json = new JsonFile();
$assertions = 0;

function component_assert( bool $condition, string $message ): void {
	global $assertions;
	++$assertions;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function component_snapshot( string $root ): array {
	$files = array();
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST ) as $file ) {
		$files[ substr( $file->getPathname(), strlen( $root ) + 1 ) ] = $file->isDir() ? 'directory' : hash_file( 'sha256', $file->getPathname() );
	}
	ksort( $files );
	return $files;
}

try {
	$fs->mkdir( $temp );
	$loader = $temp . '/load.php';
	$fs->dumpFile( $loader, <<<'PHP'
<?php
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
$GLOBALS['theme'] = $argv[1];
$GLOBALS['markers'] = [];
function get_template_directory(): string { return $GLOBALS['theme']; }
function apply_filters(string $name, mixed $value, mixed ...$args): mixed { return $value; }
require $argv[2];
st_wp_core_load_components();
echo json_encode($GLOBALS['markers'], JSON_THROW_ON_ERROR);
PHP
	);
	$markers = static function ( string $theme ) use ( $loader, $root ): array {
		$process = new Process( array( PHP_BINARY, $loader, $theme, $root . '/resources/core/files/core/components.php' ) );
		$process->setTimeout( 10 );
		$process->mustRun();
		return json_decode( $process->getOutput(), true, 512, JSON_THROW_ON_ERROR );
	};
	foreach ( array( false, true ) as $conflict ) {
		foreach ( array( 'unchanged', 'edited', 'custom' ) as $scenario ) {
			$base = $temp . '/' . (int) $conflict . '-' . $scenario;
			$themePath = $base . '/theme';
			$oldPath = 'inc/components/toolkit/probe/old.php';
			$newPath = 'inc/components/toolkit/probe/new.php';
			$commonPath = 'inc/components/toolkit/probe/common.php';
			$oldCode = "<?php \$GLOBALS['markers'][] = 'old-v1';\n";
			$commonCode = "<?php \$GLOBALS['markers'][] = 'common-v1';\n";
			$fs->dumpFile( $themePath . '/style.css', '/* Theme Name: Component Fixture */' );
			$fs->dumpFile( $themePath . '/core/bootstrap.php', "<?php\ndefine('ST_WP_CORE_VERSION', '1.2.1');\ndefine('ST_WP_CORE_THEME_PATTERNS', ['text_domain'=>'fixture','function_names'=>'fixture_','constants'=>'FIXTURE_']);\n" );
			$fs->dumpFile( $themePath . '/assets/scss/_st-toolkit-profile.scss', '$ui-profile: "bare";' );
			$json->write( $themePath . '/st-toolkit.json', array( 'schema' => 1, 'ui_profile' => 'bare', 'ui_locked' => true, 'project_setting' => 'preserved' ) );
			$theme = ( new ThemeDetector() )->detect( $themePath );
			$fs->dumpFile( $base . '/v1/files/' . $oldPath, $oldCode );
			$fs->dumpFile( $base . '/v1/files/' . $commonPath, $commonCode );
			$v1 = new ComponentManifest( array( 'slug' => 'probe', 'version' => '1.0.0' ), $base . '/v1' );
			$installer = new ComponentInstaller();
			$installer->install( $theme, $v1 );
			if ( 'edited' === $scenario ) {
				$oldCode = "<?php \$GLOBALS['markers'][] = 'old-project-edit';\n";
				$fs->dumpFile( $themePath . '/' . $oldPath, $oldCode );
			}
			if ( 'custom' === $scenario ) {
				$fs->dumpFile( $themePath . '/inc/components/custom.php', "<?php \$GLOBALS['markers'][] = 'custom-project';\n" );
			}
			$before = component_snapshot( $themePath );
			$receipt = file_get_contents( $themePath . '/st-toolkit.json' );
			$fs->dumpFile( $base . '/v2/files/' . $newPath, "<?php \$GLOBALS['markers'][] = 'new-v2';\n" );
			$fs->dumpFile( $base . '/v2/files/' . $commonPath, $conflict ? str_replace( 'common-v1', 'common-v2', $commonCode ) : $commonCode );
			$v2 = new ComponentManifest( array( 'slug' => 'probe', 'version' => '2.0.0' ), $base . '/v2' );
			$plan = $installer->plan( $theme, $v2 );
			component_assert( $before === component_snapshot( $themePath ), 'Planning modified the target.' );
			component_assert( array( $commonPath, $newPath ) === array_column( $plan['changes'], 'path' ), 'Plan is not incoming-file scaffolding.' );
			component_assert( ( $conflict ? array( $commonPath ) : array() ) === $plan['conflicts'], 'Retirement was incorrectly treated as an incoming conflict.' );
			if ( $conflict ) {
				try {
					$installer->install( $theme, $v2 );
					throw new RuntimeException( 'Incoming conflict was not rejected.' );
				} catch ( RuntimeException $exception ) {
					component_assert( str_contains( $exception->getMessage(), '--force' ), 'Wrong conflict error.' );
				}
				component_assert( $before === component_snapshot( $themePath ), 'Rejected conflict changed project files/backups.' );
			}
			$result = $installer->install( $theme, $v2, $conflict );
			component_assert( $oldCode === file_get_contents( $themePath . '/' . $oldPath ), 'Retired project file was modified/deleted.' );
			component_assert( $receipt === file_get_contents( $result['backup_path'] . '/st-toolkit.json' ), 'Previous receipt history was not backed up.' );
			component_assert( ! is_file( $result['backup_path'] . '/' . $oldPath ), 'Backup scope unexpectedly includes untouched retired files.' );
			$state = $json->read( $themePath . '/st-toolkit.json' );
			component_assert( array( $commonPath, $newPath ) === array_keys( $state['components']['probe']['files'] ), 'Latest receipt must describe incoming package files.' );
			component_assert( 'preserved' === $state['project_setting'], 'Unrelated state was lost.' );
			$expected = array( $conflict ? 'common-v2' : 'common-v1', 'new-v2', 'edited' === $scenario ? 'old-project-edit' : 'old-v1' );
			if ( 'custom' === $scenario ) {
				array_unshift( $expected, 'custom-project' );
			}
			component_assert( $expected === $markers( $themePath ), 'Loader did not retain retired/custom project PHP, or duplicated requires.' );
			component_assert( array( 'matching', 'matching' ) === array_column( $installer->plan( $theme, $v2 )['changes'], 'action' ), 'Repeated install planning lost its incoming-file contract.' );
			( new BackupManager() )->restoreLatest( $themePath, $result['backup_path'] );
			component_assert( $receipt === file_get_contents( $themePath . '/st-toolkit.json' ), 'Named backup did not restore the previous receipt.' );
			component_assert( ! file_exists( $themePath . '/' . $newPath ), 'Named backup did not remove the added destination.' );
			component_assert( $commonCode === file_get_contents( $themePath . '/' . $commonPath ), 'Named backup did not restore an overwritten destination.' );
			component_assert( $oldCode === file_get_contents( $themePath . '/' . $oldPath ), 'Restore modified a retired file outside its backup scope.' );
		}
	}

	// Exercise the public command too, without depending on Node or network access.
	$bin = $temp . '/bin';
	$fakeNpm = <<<'PHP'
<?php
declare(strict_types=1);
file_put_contents((string) getenv('ST_COMPONENT_NPM_LOG'), implode(' ', array_slice($argv, 1)) . "\n", FILE_APPEND);
if (getenv('ST_COMPONENT_NPM_FAIL')) {
    fwrite(STDERR, "Fixture component build failure\n");
    exit(23);
}
echo "Fixture component build completed\n";
PHP;
	$fs->dumpFile( $bin . '/npm-fixture.php', $fakeNpm );
	$fs->dumpFile( $bin . '/npm', '#!' . PHP_BINARY . "\n" . $fakeNpm );
	$fs->chmod( $bin . '/npm', 0755 );
	$fs->dumpFile( $bin . '/npm.cmd', '@"' . PHP_BINARY . '" "%~dp0npm-fixture.php" %*' . "\r\n" );
	$log = $temp . '/npm.log';
	$fs->dumpFile( $log, '' );
	$cliTheme = $temp . '/cli-theme';
	$fs->dumpFile( $cliTheme . '/style.css', '/* Theme Name: Component CLI Fixture */' );
	$fs->dumpFile( $cliTheme . '/core/bootstrap.php', "<?php\ndefine('ST_WP_CORE_VERSION', '1.2.1');\ndefine('ST_WP_CORE_THEME_PATTERNS', ['text_domain'=>'fixture','function_names'=>'fixture_','constants'=>'FIXTURE_']);\n" );
	$fs->dumpFile( $cliTheme . '/assets/scss/_st-toolkit-profile.scss', '$ui-profile: "bare";' );
	$json->write( $cliTheme . '/st-toolkit.json', array( 'schema' => 1, 'ui_profile' => 'bare', 'ui_locked' => true ) );
	$cli = static function ( array $options, bool $failBuild = false ) use ( $root, $cliTheme, $bin, $log ): array {
		$process = new Process(
			array_merge( array( PHP_BINARY, $root . '/bin/st-toolkit', 'components:install', 'mega-menu', '--theme=' . $cliTheme ), $options ),
			$root,
			array( 'PATH' => $bin, 'ST_COMPONENT_NPM_LOG' => $log, 'ST_COMPONENT_NPM_FAIL' => $failBuild ? '1' : false )
		);
		$process->setTimeout( 30 );
		$process->run();
		return array( 'status' => $process->getExitCode(), 'output' => $process->getOutput() . $process->getErrorOutput(), 'json' => json_decode( $process->getOutput(), true ) );
	};
	$before = component_snapshot( $cliTheme );
	$dryRun = $cli( array( '--dry-run', '--json', '--no-interaction' ) );
	component_assert( 0 === $dryRun['status'] && true === $dryRun['json']['dry_run'], 'Public component dry run failed.' );
	component_assert( 'npm run build' === $dryRun['json']['build_command'] && array() === $dryRun['json']['conflicts'], 'Public dry run lost its incoming/build plan.' );
	$cancel = $cli( array( '--no-interaction' ) );
	component_assert( 0 !== $cancel['status'] && str_contains( $cancel['output'], 'cancelled' ), 'Unconfirmed public component install was not cancelled.' );
	component_assert( $before === component_snapshot( $cliTheme ) && '' === file_get_contents( $log ), 'Dry run/cancellation wrote files, backups, or ran npm.' );
	$installed = $cli( array( '--yes', '--json' ) );
	component_assert( 0 === $installed['status'] && 'completed' === $installed['json']['build']['status'], 'Public component install did not complete.' );
	component_assert( "run build\n" === file_get_contents( $log ), 'Public install must run exactly one production build.' );
	$integration = 'inc/components/toolkit/mega-menu.php';
	$editedCode = file_get_contents( $cliTheme . '/' . $integration ) . "\n// Project edit.\n";
	$fs->dumpFile( $cliTheme . '/' . $integration, $editedCode );
	$fs->dumpFile( $cliTheme . '/inc/components/toolkit/retired.php', '<?php // Retired project integration.' );
	$fs->dumpFile( $cliTheme . '/inc/components/project.php', '<?php // Unrelated project integration.' );
	$before = component_snapshot( $cliTheme );
	$logBefore = file_get_contents( $log );
	$receipt = file_get_contents( $cliTheme . '/st-toolkit.json' );
	$conflict = $cli( array( '--yes' ) );
	component_assert( 0 !== $conflict['status'] && str_contains( $conflict['output'], '--force' ), '--yes bypassed public incoming-file conflict protection.' );
	$decline = $cli( array( '--force', '--no-interaction' ) );
	component_assert( 0 !== $decline['status'] && str_contains( $decline['output'], 'cancelled' ), '--force bypassed public confirmation.' );
	component_assert( $before === component_snapshot( $cliTheme ) && $logBefore === file_get_contents( $log ), 'Refused public reinstall changed files, backups, or ran npm.' );
	$failed = $cli( array( '--force', '--yes', '--json' ), true );
	component_assert( 0 !== $failed['status'] && 'failed' === $failed['json']['build']['status'], 'Public build failure did not return a failed JSON result.' );
	component_assert( str_contains( $failed['json']['build']['message'], 'Fixture component build failure' ), 'Build failure lost npm diagnostics.' );
	$backup = $failed['json']['backup_path'];
	component_assert( $receipt === file_get_contents( $backup . '/st-toolkit.json' ) && $editedCode === file_get_contents( $backup . '/' . $integration ), 'Failed-build backup lost previous receipt/project edits.' );
	component_assert( is_file( $cliTheme . '/' . $integration ) && $editedCode !== file_get_contents( $cliTheme . '/' . $integration ), 'Build failure unexpectedly rolled back installed files.' );
	component_assert( '<?php // Retired project integration.' === file_get_contents( $cliTheme . '/inc/components/toolkit/retired.php' ) && '<?php // Unrelated project integration.' === file_get_contents( $cliTheme . '/inc/components/project.php' ), 'Public reinstall altered retired/unrelated project files.' );
	( new BackupManager() )->restoreLatest( $cliTheme, $backup );
	component_assert( $receipt === file_get_contents( $cliTheme . '/st-toolkit.json' ) && $editedCode === file_get_contents( $cliTheme . '/' . $integration ), 'Explicit failed-build backup recovery did not restore incoming destinations.' );
	component_assert( $logBefore . "run build\n" === file_get_contents( $log ), 'Failed public reinstall ran more than one production build.' );
	echo "Component scaffolding checks passed ($assertions assertions).\n";
} finally {
	$fs->remove( $temp );
}

<?php
/**
 * ST WP Starter Toolkit acceptance checks.
 *
 * These tests intentionally use temporary fixture themes and public services
 * directly. They do not bootstrap WordPress, run npm, or require network
 * access, which keeps the release-critical checks deterministic in CI.
 */

declare(strict_types=1);

use SnailTheme\WPStarterToolkit\Block\BlockInstaller;
use SnailTheme\WPStarterToolkit\Block\BlockManifest;
use SnailTheme\WPStarterToolkit\Block\NpmRunner;
use SnailTheme\WPStarterToolkit\Core\CoreUpdater;
use SnailTheme\WPStarterToolkit\Support\BackupManager;
use SnailTheme\WPStarterToolkit\Support\ReplacementEngine;
use SnailTheme\WPStarterToolkit\Theme\ThemeContext;
use SnailTheme\WPStarterToolkit\Theme\ThemeDetector;
use Symfony\Component\Filesystem\Filesystem;

require dirname( __DIR__ ) . '/vendor/autoload.php';

$root       = dirname( __DIR__ );
$filesystem = new Filesystem();
$temp       = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'st-toolkit-acceptance-' . bin2hex( random_bytes( 6 ) );
$assertions = 0;

/**
 * Stop immediately when an acceptance invariant fails.
 */
function acceptance_assert( bool $condition, string $message ): void {
	global $assertions;

	++$assertions;

	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Hash every package-owned core file so rollback can be compared exactly.
 *
 * @return array<string,string>
 */
function acceptance_core_hashes( string $themePath ): array {
	$hashes = array();
	$roots  = array(
		'core',
		'assets/scss/core',
		'assets/scripts/core',
		'assets/css/core',
		'assets/js/core',
	);

	foreach ( $roots as $relativeRoot ) {
		$root = $themePath . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relativeRoot );

		if ( ! is_dir( $root ) ) {
			continue;
		}

		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $files as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$relative            = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $themePath ) + 1 ) );
			$hashes[ $relative ] = (string) hash_file( 'sha256', $file->getPathname() );
		}
	}

	ksort( $hashes );

	return $hashes;
}

try {
	$patterns = array(
		'git_repo'         => 'acme-inc/acme-acceptance',
		'text_domain'      => 'acme-acceptance',
		'function_names'   => 'acme_acceptance_',
		'doc_block'        => 'Acme_Acceptance',
		'prefix_handlers'  => 'acme-acceptance-',
		'constants'        => 'ACME_ACCEPTANCE_',
		'block_namespace'  => 'acme',
		'block_category'   => 'acme-acceptance',
		'theme_name'       => 'Acme Acceptance',
		'theme_uri'        => 'https://example.com/acme-acceptance/',
		'github_theme_uri' => 'acme-inc/acme-acceptance',
		'primary_branch'   => 'main',
		'author_name'      => 'Acme Inc',
		'author_uri'       => 'https://example.com/',
	);
	$source = implode(
		PHP_EOL,
		array(
			'https://github.com/SnailTheme/wp-starter',
			'https://github.com/snailtheme/wp-starter/',
			'https://github.com/snailtheme/wp-starter-toolkit.git',
			'Author: SnailTheme',
			'Helper: st_wp_core_generate_img',
		)
	);
	$replacementEngine = new ReplacementEngine();
	$replaced          = $replacementEngine->applyThemePatterns( $source, $patterns );

	acceptance_assert( str_contains( $replaced, 'https://github.com/acme-inc/acme-acceptance' ), 'Repository URL was not replaced from git_repo.' );
	acceptance_assert( str_contains( $replaced, 'https://example.com/acme-acceptance/' ), 'Theme URI was not preserved as a separate pattern.' );
	acceptance_assert( str_contains( $replaced, 'https://github.com/snailtheme/wp-starter-toolkit.git' ), 'Toolkit package URL was incorrectly whitelabeled.' );
	acceptance_assert( ! str_contains( $replaced, 'github.com/Acme Inc' ), 'Author replacement corrupted a GitHub organization path.' );
	acceptance_assert( str_contains( $replaced, 'st_wp_core_generate_img' ), 'Stable core helper was unexpectedly whitelabeled.' );

	$themePath = $temp . DIRECTORY_SEPARATOR . 'theme';
	$filesystem->mirror( $root . '/resources/core/files', $themePath );
	$filesystem->dumpFile(
		$themePath . '/style.css',
		"/*\nTheme Name: ST Toolkit Acceptance\nVersion: 0.9.0\nText Domain: st-wp-starter\n*/\n"
	);

	$bootstrap         = $themePath . '/core/bootstrap.php';
	$coreFile          = $themePath . '/core/core.php';
	$bootstrapContents = $replacementEngine->applyThemePatterns( (string) file_get_contents( $bootstrap ), $patterns );
	$filesystem->dumpFile(
		$bootstrap,
		str_replace( "define( 'ST_WP_CORE_VERSION', '1.0.0' );", "define( 'ST_WP_CORE_VERSION', '0.9.0' );", $bootstrapContents )
	);
	$filesystem->dumpFile( $coreFile, "<?php\n// Acceptance rollback marker.\n" . substr( (string) file_get_contents( $coreFile ), 6 ) );
	$filesystem->remove( $themePath . '/core/customizer/animatecss.php' );
	$filesystem->dumpFile( $themePath . '/inc/acceptance-sentinel.php', "<?php\n// Project-owned sentinel.\n" );
	$filesystem->dumpFile( $themePath . '/assets/scss/acceptance-sentinel.scss', ".acceptance-sentinel { display: block; }\n" );
	$filesystem->dumpFile( $themePath . '/acceptance-template.php', "<?php\n// Project template sentinel.\n" );
	$filesystem->dumpFile( $themePath . '/blocks/stwp-sentinel/block.json', "{\"apiVersion\":3,\"name\":\"stwp/sentinel\"}\n" );
	$coreHashesBefore = acceptance_core_hashes( $themePath );

	$theme   = ( new ThemeDetector() )->detect( $themePath );
	$updated = ( new CoreUpdater() )->update( $theme );

	acceptance_assert( is_file( $themePath . '/core/customizer/animatecss.php' ), 'Core update did not add a missing owned file.' );
	acceptance_assert( str_contains( (string) file_get_contents( $bootstrap ), "'1.0.0'" ), 'Core update did not install the packaged version.' );
	acceptance_assert( is_file( $themePath . '/inc/acceptance-sentinel.php' ), 'Core update touched /inc/.' );
	acceptance_assert( is_file( $themePath . '/assets/scss/acceptance-sentinel.scss' ), 'Core update touched a generic asset.' );
	acceptance_assert( is_file( $themePath . '/acceptance-template.php' ), 'Core update touched a template.' );
	acceptance_assert( is_file( $themePath . '/blocks/stwp-sentinel/block.json' ), 'Core update touched a block.' );
	acceptance_assert( str_contains( (string) file_get_contents( $themePath . '/core/tools/normalize-pot.php' ), 'https://github.com/acme-inc/acme-acceptance' ), 'Core update corrupted the generated repository URL.' );

	( new BackupManager() )->restoreLatest( $themePath, $updated['backup_path'], 'core' );

	acceptance_assert( ! is_file( $themePath . '/core/customizer/animatecss.php' ), 'Rollback left a core file that was absent before update.' );
	acceptance_assert( str_contains( (string) file_get_contents( $bootstrap ), "'0.9.0'" ), 'Rollback did not restore the previous core version.' );
	acceptance_assert( str_contains( (string) file_get_contents( $coreFile ), 'Acceptance rollback marker' ), 'Rollback did not restore a modified core file exactly.' );
	acceptance_assert( is_file( $themePath . '/inc/acceptance-sentinel.php' ), 'Rollback touched /inc/.' );
	acceptance_assert( is_file( $themePath . '/assets/scss/acceptance-sentinel.scss' ), 'Rollback touched a generic asset.' );
	acceptance_assert( is_file( $themePath . '/acceptance-template.php' ), 'Rollback touched a template.' );
	acceptance_assert( is_file( $themePath . '/blocks/stwp-sentinel/block.json' ), 'Rollback touched a block.' );
	acceptance_assert( $coreHashesBefore === acceptance_core_hashes( $themePath ), 'Rollback did not restore the exact package-owned core file set and hashes.' );

	$filesystem->dumpFile(
		$bootstrap,
		str_replace( "define( 'ST_WP_CORE_VERSION', '0.9.0' );", "define( 'ST_WP_CORE_VERSION', '1.0.0' );", (string) file_get_contents( $bootstrap ) )
	);
	$filesystem->dumpFile(
		$themePath . '/package.json',
		"{\"dependencies\":{\"@splidejs/splide\":\"^4.1.4\"}}\n"
	);
	$theme     = ( new ThemeDetector() )->detect( $themePath );
	$manifest  = BlockManifest::load( 'slider' );
	$installer = new BlockInstaller();
	$blockPlan = $installer->plan( $theme, $manifest, 'hero-slider' );

	acceptance_assert( array() === $blockPlan['missing_dependencies'], 'Block plan did not recognize the declared Splide dependency.' );
	acceptance_assert( str_ends_with( $blockPlan['destination_path'], 'blocks' . DIRECTORY_SEPARATOR . 'acme-hero-slider' ), 'Block plan used the wrong namespace-prefixed destination.' );

	$installer->install( $theme, $manifest, 'hero-slider' );

	$blockPath = $themePath . '/blocks/acme-hero-slider';
	$acfFiles  = glob( $themePath . '/acf-json/*.json' ) ?: array();
	$blockJson = (string) file_get_contents( $blockPath . '/block.json' );
	$renderPhp = (string) file_get_contents( $blockPath . '/block-render.php' );
	$acfJson   = isset( $acfFiles[0] ) ? (string) file_get_contents( $acfFiles[0] ) : '';

	acceptance_assert( is_dir( $blockPath ), 'Block installer did not create the namespace-prefixed block directory.' );
	acceptance_assert( str_contains( $blockJson, '"name": "acme/hero-slider"' ), 'Installed block.json used the wrong block name.' );
	acceptance_assert( str_contains( $acfJson, 'acme/hero-slider' ) && str_contains( $acfJson, 'acme-hero-slider-fields' ), 'Installed ACF JSON used the wrong namespace or field root.' );
	acceptance_assert( str_contains( $renderPhp, 'st_wp_core_get_block_wrapper_attributes' ) && str_contains( $renderPhp, 'st_wp_core_generate_img' ), 'Block install renamed stable core helpers.' );
	acceptance_assert( is_file( $themePath . '/assets/scss/styles-register/plugins/splidejs/core.scss' ), 'Block install did not add the shared Splide SCSS source.' );
	acceptance_assert( is_file( $themePath . '/assets/scripts/scripts-register/plugins/splidejs/splide.min.js' ), 'Block install did not add the shared Splide JavaScript source.' );
	acceptance_assert( 0 === preg_match( '#(?<![A-Za-z0-9])stwp(?:/|\\\\/|-|_)#', $blockJson . $renderPhp . $acfJson ), 'Installed block retained an explicit source namespace token.' );
	acceptance_assert( is_file( $themePath . '/inc/acceptance-sentinel.php' ), 'Block install touched /inc/.' );

	$originalPath = getenv( 'PATH' );
	putenv( 'PATH=' . $temp . DIRECTORY_SEPARATOR . 'missing-bin' );

	try {
		( new NpmRunner() )->build(
			new ThemeContext( $themePath, $themePath . '/style.css', $bootstrap, '0.9.0', $patterns )
		);
		acceptance_assert( false, 'Missing npm unexpectedly executed successfully.' );
	} catch ( RuntimeException $exception ) {
		acceptance_assert( str_contains( $exception->getMessage(), 'npm' ) && str_contains( $exception->getMessage(), 'PATH' ), 'Missing npm did not produce an actionable error.' );
	} finally {
		putenv( false === $originalPath ? 'PATH' : 'PATH=' . $originalPath );
	}

	fwrite( STDOUT, sprintf( "Toolkit acceptance checks passed (%d assertions).\n", $assertions ) );
} finally {
	$filesystem->remove( $temp );
}

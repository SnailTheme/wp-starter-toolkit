<?php
/**
 * ST WP Starter Toolkit acceptance checks.
 *
 * These tests intentionally use temporary fixture themes and public services
 * directly. They do not bootstrap WordPress, run npm, or require network
 * access, which keeps the release-critical checks deterministic in CI.
 */

declare(strict_types=1);

use SnailTheme\WPStarterToolkit\AgentDocs\AgentDocsInstaller;
use SnailTheme\WPStarterToolkit\AgentDocs\AgentDocsManifest;
use SnailTheme\WPStarterToolkit\Block\BlockInstaller;
use SnailTheme\WPStarterToolkit\Block\BlockManifest;
use SnailTheme\WPStarterToolkit\Block\NpmRunner;
use SnailTheme\WPStarterToolkit\Block\PackageJsonInspector;
use SnailTheme\WPStarterToolkit\Component\ComponentInstaller;
use SnailTheme\WPStarterToolkit\Component\ComponentManifest;
use SnailTheme\WPStarterToolkit\Core\CoreUpdater;
use SnailTheme\WPStarterToolkit\Support\BackupManager;
use SnailTheme\WPStarterToolkit\Support\ReplacementEngine;
use SnailTheme\WPStarterToolkit\Theme\ThemeContext;
use SnailTheme\WPStarterToolkit\Theme\ThemeDetector;
use SnailTheme\WPStarterToolkit\UI\UIProfileInstaller;
use SnailTheme\WPStarterToolkit\UI\UIProfileManifest;
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
			'https://github.com/SnailTheme/wp-starter/',
			'https://github.com/snailtheme/wp-starter/',
			'https://github.com/snailtheme/wp-starter/issues',
			'https://github.com/snailtheme/wp-starter/#readme',
			'https://github.com/snailtheme/wp-starter/?tab=readme-ov-file',
			'https://github.com/SnailTheme/wp-starter.git',
			'https://github.com/snailtheme/wp-starter.git',
			'https://github.com/snailtheme/wp-starter-toolkit.git',
			'Author: SnailTheme',
			'Helper: st_wp_core_generate_img',
		)
	);
	$replacementEngine = new ReplacementEngine();
	$replaced          = $replacementEngine->applyThemePatterns( $source, $patterns );

	acceptance_assert( str_contains( $replaced, 'https://github.com/acme-inc/acme-acceptance' ), 'Repository URL was not replaced from git_repo.' );
	acceptance_assert( str_contains( $replaced, 'https://github.com/acme-inc/acme-acceptance/' ), 'Cased repository URL with a trailing slash was not replaced safely.' );
	acceptance_assert( str_contains( $replaced, 'https://example.com/acme-acceptance/' ), 'Theme URI was not preserved as a separate pattern.' );
	acceptance_assert( str_contains( $replaced, 'https://github.com/acme-inc/acme-acceptance/issues' ), 'Lowercase repository URL with a path was replaced as the Theme URI.' );
	acceptance_assert( str_contains( $replaced, 'https://github.com/acme-inc/acme-acceptance/#readme' ), 'Lowercase repository URL with a slash fragment was replaced as the Theme URI.' );
	acceptance_assert( str_contains( $replaced, 'https://github.com/acme-inc/acme-acceptance/?tab=readme-ov-file' ), 'Lowercase repository URL with a slash query was replaced as the Theme URI.' );
	acceptance_assert( 2 === substr_count( $replaced, 'https://github.com/acme-inc/acme-acceptance.git' ), '.git repository URLs were not replaced for both owner casings.' );
	acceptance_assert( str_contains( $replaced, 'https://github.com/snailtheme/wp-starter-toolkit.git' ), 'Toolkit package URL was incorrectly whitelabeled.' );
	acceptance_assert( ! str_contains( $replaced, 'github.com/Acme Inc' ), 'Author replacement corrupted a GitHub organization path.' );
	acceptance_assert( str_contains( $replaced, 'st_wp_core_generate_img' ), 'Stable core helper was unexpectedly whitelabeled.' );

	$legacyPatterns = $patterns;
	unset( $legacyPatterns['git_repo'] );
	$legacyPatterns['github_theme_uri'] = 'legacy-org/legacy-theme';
	$legacyReplaced = $replacementEngine->applyThemePatterns(
		"https://github.com/SnailTheme/wp-starter\n'github_theme_uri' => 'snailtheme/wp-starter'\n",
		$legacyPatterns
	);

	acceptance_assert( str_contains( $legacyReplaced, 'https://github.com/legacy-org/legacy-theme' ), 'Repository URL did not fall back to github_theme_uri.' );
	acceptance_assert( str_contains( $legacyReplaced, "'github_theme_uri' => 'legacy-org/legacy-theme'" ), 'Legacy github_theme_uri was reset to the starter repository.' );

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
		str_replace( "define( 'ST_WP_CORE_VERSION', '1.1.0' );", "define( 'ST_WP_CORE_VERSION', '1.0.0' );", $bootstrapContents )
	);
	$filesystem->dumpFile( $coreFile, "<?php\n// Acceptance rollback marker.\n" . substr( (string) file_get_contents( $coreFile ), 6 ) );
	$filesystem->remove( $themePath . '/core/customizer/animatecss.php' );
	$filesystem->dumpFile( $themePath . '/inc/acceptance-sentinel.php', "<?php\n// Project-owned sentinel.\n" );
	$filesystem->dumpFile( $themePath . '/assets/scss/acceptance-sentinel.scss', ".acceptance-sentinel { display: block; }\n" );
	$filesystem->dumpFile( $themePath . '/acceptance-template.php', "<?php\n// Project template sentinel.\n" );
	$filesystem->dumpFile( $themePath . '/blocks/stwp-sentinel/block.json', "{\"apiVersion\":3,\"name\":\"stwp/sentinel\"}\n" );
	$filesystem->dumpFile(
		$themePath . '/st-toolkit.json',
		"{\n  \"schema\": 1,\n  \"ui_profile\": \"bare\",\n  \"ui_version\": \"1.0.0\",\n  \"ui_locked\": false,\n  \"css_entries\": [],\n  \"vite_plugins\": [],\n  \"files\": {}\n}\n"
	);
	$projectStateBefore = (string) file_get_contents( $themePath . '/st-toolkit.json' );
	$coreHashesBefore = acceptance_core_hashes( $themePath );

	$theme        = ( new ThemeDetector() )->detect( $themePath );
	$docsManifest = AgentDocsManifest::load();

	acceptance_assert( '1.1.0' === $docsManifest->fileVersion( 'AGENTS.md' ), 'Changed agent guide did not receive its own 1.1.0 content version.' );
	acceptance_assert( '1.0.0' === $docsManifest->fileVersion( '.agents/template_tags_and_helpers.md' ), 'Unchanged helper guide was unnecessarily versioned as changed.' );

	$filesystem->dumpFile( $themePath . '/AGENTS.md', "<!-- st-toolkit-agent-doc-version: 1.0.0 -->\n# Old Agent Guide\n" );
	$filesystem->dumpFile(
		$themePath . '/.agents/template_tags_and_helpers.md',
		"<!-- st-toolkit-agent-doc-version: 1.0.0 -->\n# Current Helper Guide Sentinel\n"
	);

	$docsInstaller = new AgentDocsInstaller( $docsManifest );
	$docsPlan      = $docsInstaller->plan( $theme );
	$docsChanges   = array_column( $docsPlan['changes'], null, 'path' );

	acceptance_assert( 'update' === $docsChanges['AGENTS.md']['action'], 'Changed agent guide was not planned for update.' );
	acceptance_assert( '1.1.0' === $docsChanges['AGENTS.md']['target_version'], 'Agent guide plan did not expose its file-specific target version.' );
	acceptance_assert( 'skip' === $docsChanges['.agents/template_tags_and_helpers.md']['action'], 'Unchanged current-version helper guide was planned for update.' );
	acceptance_assert( '1.0.0' === $docsChanges['.agents/template_tags_and_helpers.md']['target_version'], 'Helper guide plan used the package-wide version instead of its content version.' );

	$docsInstaller->install( $theme );
	acceptance_assert( str_contains( (string) file_get_contents( $themePath . '/AGENTS.md' ), '1.1.0' ), 'Changed agent guide did not update.' );
	acceptance_assert( str_contains( (string) file_get_contents( $themePath . '/.agents/template_tags_and_helpers.md' ), 'Current Helper Guide Sentinel' ), 'Unchanged helper guide was rewritten.' );

	$updated = ( new CoreUpdater() )->update( $theme );

	acceptance_assert( is_file( $themePath . '/core/customizer/animatecss.php' ), 'Core update did not add a missing owned file.' );
	acceptance_assert( str_contains( (string) file_get_contents( $bootstrap ), "'1.1.0'" ), 'Core update did not install the packaged version.' );
	acceptance_assert( is_file( $themePath . '/core/components.php' ), 'Core update did not install the shared component loader.' );
	acceptance_assert( is_file( $themePath . '/inc/acceptance-sentinel.php' ), 'Core update touched /inc/.' );
	acceptance_assert( is_file( $themePath . '/assets/scss/acceptance-sentinel.scss' ), 'Core update touched a generic asset.' );
	acceptance_assert( is_file( $themePath . '/acceptance-template.php' ), 'Core update touched a template.' );
	acceptance_assert( is_file( $themePath . '/blocks/stwp-sentinel/block.json' ), 'Core update touched a block.' );
	acceptance_assert( $projectStateBefore === file_get_contents( $themePath . '/st-toolkit.json' ), 'Core update touched root project/toolkit state.' );
	acceptance_assert( str_contains( (string) file_get_contents( $themePath . '/core/tools/normalize-pot.php' ), 'https://github.com/acme-inc/acme-acceptance' ), 'Core update corrupted the generated repository URL.' );

	( new BackupManager() )->restoreLatest( $themePath, $updated['backup_path'], 'core' );

	acceptance_assert( ! is_file( $themePath . '/core/customizer/animatecss.php' ), 'Rollback left a core file that was absent before update.' );
	acceptance_assert( str_contains( (string) file_get_contents( $bootstrap ), "'1.0.0'" ), 'Rollback did not restore the previous core version.' );
	acceptance_assert( str_contains( (string) file_get_contents( $coreFile ), 'Acceptance rollback marker' ), 'Rollback did not restore a modified core file exactly.' );
	acceptance_assert( is_file( $themePath . '/inc/acceptance-sentinel.php' ), 'Rollback touched /inc/.' );
	acceptance_assert( is_file( $themePath . '/assets/scss/acceptance-sentinel.scss' ), 'Rollback touched a generic asset.' );
	acceptance_assert( is_file( $themePath . '/acceptance-template.php' ), 'Rollback touched a template.' );
	acceptance_assert( is_file( $themePath . '/blocks/stwp-sentinel/block.json' ), 'Rollback touched a block.' );
	acceptance_assert( $projectStateBefore === file_get_contents( $themePath . '/st-toolkit.json' ), 'Rollback touched root project/toolkit state.' );
	acceptance_assert( $coreHashesBefore === acceptance_core_hashes( $themePath ), 'Rollback did not restore the exact package-owned core file set and hashes.' );

	$filesystem->dumpFile(
		$bootstrap,
		str_replace( "define( 'ST_WP_CORE_VERSION', '1.0.0' );", "define( 'ST_WP_CORE_VERSION', '1.1.0' );", (string) file_get_contents( $bootstrap ) )
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

	$filesystem->dumpFile(
		$themePath . '/package.json',
		'{"dependencies":{"@splidejs/splide":"^4.1.4"},"devDependencies":{"tailwindcss":"^3.4.0","@tailwindcss/vite":"^4.3.2"}}' . "\n"
	);
	$tailwindDependencies = UIProfileManifest::load( 'tailwind' )->npmDevDependencies();
	$dependencyIssues     = ( new PackageJsonInspector() )->missing( $theme, $tailwindDependencies );

	acceptance_assert( '^4.3.2' === ( $dependencyIssues['tailwindcss'] ?? null ), 'Tailwind v3 was accepted for the Tailwind v4 UI profile.' );
	acceptance_assert( ! isset( $dependencyIssues['@tailwindcss/vite'] ), 'A compatible Tailwind Vite plugin declaration was reported as incompatible.' );

	$filesystem->dumpFile(
		$themePath . '/package.json',
		'{"dependencies":{"@splidejs/splide":"^4.1.4"},"devDependencies":{"tailwindcss":"^4.0.0","@tailwindcss/vite":"^4.3.2"}}' . "\n"
	);
	$dependencyIssues = ( new PackageJsonInspector() )->missing( $theme, $tailwindDependencies );
	acceptance_assert( isset( $dependencyIssues['tailwindcss'] ), 'A range permitting unsupported early Tailwind v4 releases was accepted.' );

	$filesystem->dumpFile(
		$themePath . '/package.json',
		'{"dependencies":{"@splidejs/splide":"^4.1.4"},"devDependencies":{"tailwindcss":"^4.4.0","@tailwindcss/vite":"^4.3.2"}}' . "\n"
	);
	$dependencyIssues = ( new PackageJsonInspector() )->missing( $theme, $tailwindDependencies );
	acceptance_assert( array() === $dependencyIssues, 'A stricter compatible Tailwind dependency range was reported as incompatible.' );

	$blockTempBefore = array_merge(
		glob( sys_get_temp_dir() . '/st-toolkit-block-hero-slider-*' ) ?: array(),
		glob( sys_get_temp_dir() . '/st-toolkit-theme-files-hero-slider-*' ) ?: array()
	);
	sort( $blockTempBefore );

	$installer->install( $theme, $manifest, 'hero-slider' );
	$blockTempDuring = array_merge(
		glob( sys_get_temp_dir() . '/st-toolkit-block-hero-slider-*' ) ?: array(),
		glob( sys_get_temp_dir() . '/st-toolkit-theme-files-hero-slider-*' ) ?: array()
	);
	acceptance_assert( count( $blockTempDuring ) > count( $blockTempBefore ), 'Block installer did not prepare transformed files in isolated temporary storage.' );

	unset( $installer );
	$blockTempAfter = array_merge(
		glob( sys_get_temp_dir() . '/st-toolkit-block-hero-slider-*' ) ?: array(),
		glob( sys_get_temp_dir() . '/st-toolkit-theme-files-hero-slider-*' ) ?: array()
	);
	sort( $blockTempAfter );
	acceptance_assert( $blockTempBefore === $blockTempAfter, 'Block installer left transformed files in temporary storage.' );

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

	$uiInstaller = new UIProfileInstaller();
	$bareProfile = UIProfileManifest::load( 'bare' );
	$uiInstaller->install( $theme, $bareProfile, true );

	acceptance_assert( is_file( $themePath . '/st-toolkit.json' ), 'Bare profile did not create committed profile state.' );
	acceptance_assert( is_file( $themePath . '/assets/scss/foundation/_media.scss' ), 'Bare profile did not install its media foundation.' );
	acceptance_assert( ( new UIProfileInstaller() )->status( $theme )['locked'], 'First UI profile selection was not locked.' );

	$bareMain = $themePath . '/assets/scss/main.scss';
	file_put_contents( $bareMain, (string) file_get_contents( $bareMain ) . "\n// Local acceptance modification.\n" );
	$blueprintProfile = UIProfileManifest::load( 'blueprint' );
	$blueprintPlan    = $uiInstaller->plan( $theme, $blueprintProfile );

	acceptance_assert( in_array( 'assets/scss/main.scss', $blueprintPlan['conflicts'], true ), 'Profile switch did not detect a modified scaffold file.' );
	acceptance_assert( $blueprintPlan['replacement_required'], 'Changing a locked UI profile did not require explicit replacement.' );

	try {
		$uiInstaller->install( $theme, $blueprintProfile, true );
		acceptance_assert( false, 'Locked UI profile changed without explicit replacement.' );
	} catch ( RuntimeException $exception ) {
		acceptance_assert( str_contains( $exception->getMessage(), '--replace' ), 'Locked UI profile failure did not explain the replacement flag.' );
	}

	$uiInstaller->install( $theme, $blueprintProfile, true, true );
	acceptance_assert( is_file( $themePath . '/assets/scss/woocommerce.scss' ), 'Blueprint profile did not install WooCommerce source styles.' );

	$component             = ComponentManifest::load( 'mega-menu' );
	$profileStatePath      = $themePath . '/st-toolkit.json';
	$profileMarkerPath     = $themePath . '/assets/scss/_st-toolkit-profile.scss';
	$profileStateContents  = (string) file_get_contents( $profileStatePath );
	$profileMarkerContents = (string) file_get_contents( $profileMarkerPath );
	$filesystem->remove( array( $profileStatePath, $profileMarkerPath ) );

	$unmanagedComponentPlan = ( new ComponentInstaller() )->plan( $theme, $component );
	acceptance_assert( ! $unmanagedComponentPlan['ui_profile_ready'], 'Component plan accepted a theme without installed UI profile state.' );
	acceptance_assert( 'unmanaged' === $unmanagedComponentPlan['ui_profile'], 'Component plan silently defaulted an unmanaged theme to Bare.' );

	try {
		( new ComponentInstaller() )->install( $theme, $component );
		acceptance_assert( false, 'Component installed before a UI profile marker existed.' );
	} catch ( RuntimeException $exception ) {
		acceptance_assert( str_contains( $exception->getMessage(), 'ui:install' ), 'Missing UI profile failure did not explain the required setup command.' );
	}

	acceptance_assert( ! is_file( $themePath . '/inc/components/mega-menu.php' ), 'Failed component preflight wrote project files.' );
	$filesystem->dumpFile( $profileStatePath, $profileStateContents );
	$filesystem->dumpFile( $profileMarkerPath, $profileMarkerContents );
	$filesystem->dumpFile( $profileMarkerPath, '$ui-profile: "bare";' . "\n" );
	$staleMarkerPlan = ( new ComponentInstaller() )->plan( $theme, $component );
	acceptance_assert( ! $staleMarkerPlan['ui_profile_ready'], 'Component plan accepted a profile marker that disagreed with committed state.' );
	$filesystem->dumpFile( $profileMarkerPath, $profileMarkerContents );

	$oldCoreTheme = new ThemeContext(
		$theme->path,
		$theme->styleCssPath,
		$theme->bootstrapPath,
		'1.0.0',
		$theme->patterns
	);
	$oldCoreComponentPlan = ( new ComponentInstaller() )->plan( $oldCoreTheme, $component );
	acceptance_assert( ! $oldCoreComponentPlan['core_version_satisfied'], 'Component plan accepted a core version without the shared loader.' );
	acceptance_assert( ! is_dir( $oldCoreComponentPlan['prepared_path'] ), 'Released component planner left transformed files in temporary storage.' );

	try {
		( new ComponentInstaller() )->install( $oldCoreTheme, $component );
		acceptance_assert( false, 'Component installed against an unsupported core version.' );
	} catch ( RuntimeException $exception ) {
		acceptance_assert( str_contains( $exception->getMessage(), 'core:update' ), 'Component core-version failure did not provide update guidance.' );
	}

	( new ComponentInstaller() )->install( $theme, $component );

	acceptance_assert( is_file( $themePath . '/inc/components/mega-menu.php' ), 'Mega-menu component did not install its PHP integration.' );
	acceptance_assert( is_file( $themePath . '/assets/scss/components/mega-menu/_profile-tailwind.scss' ), 'Mega-menu component did not include all profile adapters.' );
	$componentPhp = (string) file_get_contents( $themePath . '/inc/components/mega-menu.php' );
	acceptance_assert( ! str_contains( $componentPhp, 'asset_exists' ) && ! str_contains( $componentPhp, 'asset_has_content' ), 'Mega-menu integration retained optional project asset helper calls.' );
	acceptance_assert( str_contains( $componentPhp, 'get_template_directory()' ) && str_contains( $componentPhp, 'filemtime(' ), 'Mega-menu integration did not implement self-contained asset checks and versioning.' );

	$uiInstaller->install( $theme, $bareProfile, true, true );
	$uiState = json_decode( (string) file_get_contents( $themePath . '/st-toolkit.json' ), true, 512, JSON_THROW_ON_ERROR );

	acceptance_assert( 'bare' === $uiState['ui_profile'], 'Profile switch did not return to Bare.' );
	acceptance_assert( isset( $uiState['components']['mega-menu'] ), 'Profile switch discarded installed component state.' );
	acceptance_assert( is_file( $themePath . '/inc/components/mega-menu.php' ), 'Profile switch removed project component files.' );
	acceptance_assert( str_contains( (string) file_get_contents( $themePath . '/assets/scss/_st-toolkit-profile.scss' ), '"bare"' ), 'Bare profile did not activate component adapters.' );

	$tailwindPlan = $uiInstaller->plan( $theme, UIProfileManifest::load( 'tailwind' ) );
	$tailwindChanges = array_column( $tailwindPlan['changes'], 'path' );
	acceptance_assert( in_array( 'assets/styles/main.css', $tailwindChanges, true ), 'Tailwind profile did not plan its front-end CSS entry.' );
	acceptance_assert( in_array( 'assets/styles/editor.css', $tailwindChanges, true ), 'Tailwind profile did not plan its editor CSS entry.' );
	acceptance_assert( in_array( 'assets/styles/theme/_theme.css', $tailwindChanges, true ), 'Tailwind profile did not include its import-only theme module.' );
	acceptance_assert( in_array( 'assets/styles/styles-enqueue/auto-enqueued-style.css', $tailwindChanges, true ), 'Tailwind profile did not include its native auto-enqueued entry.' );
	acceptance_assert( in_array( 'assets/styles/styles-register/auto-registered-style.css', $tailwindChanges, true ), 'Tailwind profile did not include its native auto-registered entry.' );
	acceptance_assert( '^4.3.2' === $tailwindPlan['npm_dev_dependencies']['tailwindcss'], 'Tailwind profile did not require the tested Tailwind release.' );
	acceptance_assert( '^4.3.2' === $tailwindPlan['npm_dev_dependencies']['@tailwindcss/vite'], 'Tailwind profile did not require the tested official Vite plugin.' );
	acceptance_assert( is_dir( $tailwindPlan['prepared_path'] ), 'UI planner did not prepare transformed files in isolated temporary storage.' );
	$tailwindPreparedPath = $tailwindPlan['prepared_path'];
	$tailwindMain = (string) file_get_contents( $tailwindPreparedPath . '/assets/styles/main.css' );
	$tailwindAutoEntry = (string) file_get_contents( $tailwindPreparedPath . '/assets/styles/styles-enqueue/auto-enqueued-style.css' );
	acceptance_assert( str_contains( $tailwindMain, '@source not "../../assets/js"' ), 'Tailwind profile did not exclude generated JavaScript from source detection.' );
	acceptance_assert( str_contains( $tailwindMain, '@import "./theme/_theme.css"' ), 'Tailwind profile did not import its modular theme source.' );
	acceptance_assert( str_contains( $tailwindAutoEntry, '@reference "../main.css"' ), 'Tailwind auto-enqueued entry did not reference the main Tailwind context.' );
	unset( $uiInstaller );
	acceptance_assert( ! is_dir( $tailwindPreparedPath ), 'UI profile installer left transformed files in temporary storage.' );

	$tailwindInstaller = new UIProfileInstaller();
	$tailwindInstaller->install( $theme, UIProfileManifest::load( 'tailwind' ), true, true );
	$tailwindStatus = $tailwindInstaller->status( $theme );
	$tailwindState = json_decode( (string) file_get_contents( $themePath . '/st-toolkit.json' ), true, 512, JSON_THROW_ON_ERROR );
	acceptance_assert( 'tailwind' === $tailwindStatus['profile'], 'Tailwind profile did not become the active selection.' );
	acceptance_assert( array() === $tailwindStatus['modified_files'], 'Fresh Tailwind profile files were immediately reported as modified.' );
	acceptance_assert( array() === $tailwindStatus['missing_files'], 'Fresh Tailwind profile files were immediately reported as missing.' );
	acceptance_assert( is_file( $themePath . '/assets/styles/styles-enqueue/auto-enqueued-style.css' ), 'Tailwind profile did not install its native auto-enqueued source.' );
	acceptance_assert( ! is_file( $themePath . '/assets/scss/main.scss' ), 'Tailwind profile left the obsolete managed Sass main entry behind.' );
	acceptance_assert( ! is_file( $themePath . '/assets/scss/styles-enqueue/auto-enqueued-style.scss' ), 'Tailwind profile left the conflicting Sass auto-enqueued entry behind.' );
	acceptance_assert( ! is_file( $themePath . '/assets/scss/styles-register/auto-registered-style.scss' ), 'Tailwind profile left the conflicting Sass auto-registered entry behind.' );
	acceptance_assert( is_file( $themePath . '/assets/scss/acceptance-sentinel.scss' ), 'Tailwind profile removed an unmanaged project Sass entry.' );
	acceptance_assert( isset( $tailwindState['components']['mega-menu'] ), 'Tailwind profile switch discarded installed component state.' );

	$coreCleanupUpdater = new CoreUpdater();
	$coreCleanupPlan    = $coreCleanupUpdater->plan( $theme );
	acceptance_assert( is_dir( $coreCleanupPlan['prepared_path'] ), 'Core updater did not prepare transformed files in isolated temporary storage.' );
	$corePreparedPath = $coreCleanupPlan['prepared_path'];
	unset( $coreCleanupUpdater );
	acceptance_assert( ! is_dir( $corePreparedPath ), 'Core updater left transformed files in temporary storage.' );

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

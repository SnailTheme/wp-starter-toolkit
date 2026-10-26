<?php
/**
 * Managed agent guidance and native Claude adapters, using temporary fixtures.
 */

declare(strict_types=1);

use SnailTheme\WPStarterToolkit\AgentDocs\AgentDocsInstaller;
use SnailTheme\WPStarterToolkit\AgentDocs\AgentDocsManifest;
use SnailTheme\WPStarterToolkit\Application;
use SnailTheme\WPStarterToolkit\Theme\ThemeContext;
use SnailTheme\WPStarterToolkit\Theme\ThemeDetector;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

require dirname( __DIR__ ) . '/vendor/autoload.php';

$root = dirname( __DIR__ );
$fs = new Filesystem();
$temp = sys_get_temp_dir() . '/st-toolkit-agent-docs-' . bin2hex( random_bytes( 6 ) );
$assertions = 0;
$marker = '<!-- st-toolkit-agent-doc-version: 1.0.0 -->';
$rules = array( '/AGENTS.md', '/.agents/', '/CLAUDE.md', '/.claude/skills/wp-starter-*/' );

function agent_docs_assert( bool $condition, string $message ): void {
	global $assertions;
	++$assertions;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function agent_docs_throws( callable $action, string $message ): void {
	try {
		$action();
	} catch ( Throwable $exception ) {
		agent_docs_assert( str_contains( $exception->getMessage(), $message ), 'Unexpected failure: ' . $exception->getMessage() );
		return;
	}
	throw new RuntimeException( 'Expected failure containing: ' . $message );
}

/** @return array<string,string> Include directories and links without following them. */
function agent_docs_snapshot( string $path ): array {
	$result = array();
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST ) as $file ) {
		$relative = substr( $file->getPathname(), strlen( $path ) + 1 );
		$result[ $relative ] = $file->isLink() ? 'link:' . readlink( $file->getPathname() ) : ( $file->isDir() ? 'directory' : (string) hash_file( 'sha256', $file->getPathname() ) );
	}
	ksort( $result );
	return $result;
}

function agent_docs_theme( string $path ): ThemeContext {
	$fs = new Filesystem();
	$fs->dumpFile( $path . '/style.css', '/* Theme Name: Agent Docs Fixture */' );
	$fs->dumpFile( $path . '/core/bootstrap.php', "<?php\ndeclare(strict_types=1);\ndefine( 'ST_WP_CORE_VERSION', '1.1.0' );\ndefine( 'ST_WP_CORE_THEME_PATTERNS', array( 'block_namespace' => 'acme', 'text_domain' => 'acme-theme', 'function_names' => 'acme_', 'constants' => 'ACME_' ) );\n" );
	return ( new ThemeDetector() )->detect( $path );
}

/** @param array<mixed> $paths */
function agent_docs_manifest( string $sources, array $paths ): AgentDocsManifest {
	$versions = array();
	foreach ( $paths as $path ) {
		if ( is_string( $path ) ) {
			$versions[ $path ] = '1.0.0';
		}
	}
	return new AgentDocsManifest( array( 'version' => '1.2.0', 'files' => $paths, 'file_versions' => $versions ), $sources . '/manifest.json', $sources );
}

/** @return array{status:int,output:string,json:?array} */
function agent_docs_cli( array $arguments ): array {
	global $root;
	$process = new Process( array_merge( array( PHP_BINARY, $root . '/bin/st-toolkit' ), $arguments ), $root );
	$process->setTimeout( 30 );
	$process->run();
	return array( 'status' => $process->getExitCode(), 'output' => $process->getOutput() . $process->getErrorOutput(), 'json' => json_decode( $process->getOutput(), true ) );
}

/** An interrupted install must already have installed the ignore rules. */
final class AgentDocsFailingFilesystem extends Filesystem {
	public function dumpFile( string $filename, $content ): void {
		if ( '.gitignore' !== basename( $filename ) ) {
			throw new RuntimeException( 'Fixture documentation write failure' );
		}
		parent::dumpFile( $filename, $content );
	}
}

try {
	$theme = agent_docs_theme( $temp . '/theme' );
	$manifest = AgentDocsManifest::load();
	$installer = new AgentDocsInstaller( $manifest );
	agent_docs_assert( 20 === count( $manifest->files() ) && '1.2.0' === $manifest->version(), 'Expected the unchanged manifest version and all 20 managed files.' );
	$ignoreBefore = "# Project rules\n/vendor/\n/AGENTS.md\n";
	$fs->dumpFile( $theme->resolve( '.gitignore' ), $ignoreBefore );
	$customClaude = array( '.claude/settings.json' => '{"project":true}', '.claude/skills/project-only/SKILL.md' => '# Project-owned skill', '.claude/CLAUDE.md' => '# Project-owned Claude notes' );
	foreach ( $customClaude as $path => $contents ) {
		$fs->dumpFile( $theme->resolve( $path ), $contents );
	}
	$before = agent_docs_snapshot( $theme->path );
	$plan = $installer->plan( $theme );
	agent_docs_assert( $before === agent_docs_snapshot( $theme->path ), 'Dry-run planning wrote target files/directories.' );
	agent_docs_assert( array( 'add' ) === array_values( array_unique( array_column( $plan['changes'], 'action' ) ) ), 'Fresh docs were not planned as additions.' );
	$installer->install( $theme );
	foreach ( $manifest->files() as $relative ) {
		$source = (string) file_get_contents( $manifest->filesRoot() . '/' . $relative );
		$installed = (string) file_get_contents( $theme->resolve( $relative ) );
		agent_docs_assert( str_replace( '<current_block_namespace>', 'acme', $source ) === $installed, 'Installed bytes/namespace differ from the managed source: ' . $relative );
		agent_docs_assert( ! str_contains( $installed, '<current_block_namespace>' ), 'Namespace placeholder survived installation: ' . $relative );
		if ( str_ends_with( $relative, '/SKILL.md' ) ) {
			agent_docs_assert( '1.0.0' === $manifest->fileVersion( $relative ), 'New skill version was not 1.0.0.' );
			agent_docs_assert( 1 === preg_match( '/\A---\r?\n(.*?)\r?\n---\r?\n/s', $source, $header ) && str_starts_with( $installed, $header[0] . $marker ), 'YAML is not first, or the version marker is not immediately after unchanged frontmatter: ' . $relative );
			agent_docs_assert( 1 === preg_match( '/^name: wp-starter-[a-z-]+$/m', $header[1] ) && 1 === preg_match( '/^description: \S.+$/m', $header[1] ), 'Skill name/description is missing from YAML frontmatter.' );
			// These resources use plain, single-line YAML scalars. Exclude YAML
			// mapping/comment separators which would silently change their type.
			preg_match( '/^description: (.+)$/m', $header[1], $description );
			agent_docs_assert( ! preg_match( '/:\s|\s#/', $description[1] ), 'Plain YAML description contains an unquoted mapping/comment separator.' );
			preg_match_all( '/\[[^\]]+\]\(([^)]+)\)/', $installed, $links );
			foreach ( $links[1] as $link ) {
				agent_docs_assert( false !== realpath( dirname( $theme->resolve( $relative ) ) . '/' . $link ), 'Installed skill/adapter link does not resolve: ' . $relative . ' -> ' . $link );
			}
			if ( str_starts_with( $relative, '.claude/' ) ) {
				$canonical = '../../../.agents/skills/' . basename( dirname( $relative ) ) . '/SKILL.md';
				agent_docs_assert( str_contains( $installed, '(' . $canonical . ')' ), 'Claude adapter does not link to its canonical workflow.' );
			}
		}
	}
	agent_docs_assert( str_contains( (string) file_get_contents( $theme->resolve( 'CLAUDE.md' ) ), "\n@AGENTS.md\n" ), 'Native CLAUDE.md import is missing.' );
	$ignore = (string) file_get_contents( $theme->resolve( '.gitignore' ) );
	agent_docs_assert( str_starts_with( $ignore, $ignoreBefore ), 'Existing ignore rules were replaced.' );
	foreach ( $rules as $rule ) {
		agent_docs_assert( 1 === substr_count( $ignore, $rule . "\n" ), 'Missing/duplicated managed ignore rule: ' . $rule );
	}
	agent_docs_assert( ! in_array( '/.claude/', explode( "\n", $ignore ), true ) && ! in_array( '/.claude/skills/', explode( "\n", $ignore ), true ), 'Installer broadly ignores project-owned Claude configuration.' );
	$before = agent_docs_snapshot( $theme->path );
	$repeat = $installer->install( $theme );
	agent_docs_assert( $before === agent_docs_snapshot( $theme->path ) && array( 'skip' ) === array_values( array_unique( array_column( $repeat['changes'], 'action' ) ) ), 'Equal-version repeat install did not skip docs/ignore rules.' );
	if ( null !== ( new ExecutableFinder() )->find( 'git' ) ) {
		$fs->dumpFile( $temp . '/empty-global-ignore', '' );
		$git = new Process( array( 'git', '-c', 'init.templateDir=', 'init', '--quiet', $theme->path ) );
		$git->mustRun();
		$git = new Process( array( 'git', '-c', 'core.excludesFile=' . $temp . '/empty-global-ignore', 'check-ignore', '--no-index', '--stdin' ), $theme->path );
		$git->setInput( "CLAUDE.md\n.claude/skills/wp-starter-assets/SKILL.md\n.claude/settings.json\n.claude/skills/project-only/SKILL.md\n" );
		$git->mustRun();
		agent_docs_assert( array( 'CLAUDE.md', '.claude/skills/wp-starter-assets/SKILL.md' ) === preg_split( '/\R/', trim( $git->getOutput() ) ), 'Native Git ignore matching hid unrelated Claude files.' );
	}

	$skill = '.agents/skills/wp-starter-assets/SKILL.md';
	$skillSource = str_replace( '<current_block_namespace>', 'acme', (string) file_get_contents( $manifest->filesRoot() . '/' . $skill ) );
	$fs->dumpFile( $theme->resolve( 'AGENTS.md' ), "<!-- st-toolkit-agent-doc-version: 0.1.0 -->\n# Older normal guide\n" );
	$fs->dumpFile( $theme->resolve( $skill ), str_replace( $marker, '<!-- st-toolkit-agent-doc-version: 0.9.0 -->', $skillSource ) );
	$upgrades = array_column( $installer->plan( $theme )['changes'], 'action', 'path' );
	agent_docs_assert( 'update' === $upgrades['AGENTS.md'] && 'update' === $upgrades[ $skill ], 'Legacy first-line/frontmatter version upgrades were not detected.' );
	$installer->install( $theme );
	agent_docs_assert( $skillSource === file_get_contents( $theme->resolve( $skill ) ), 'Skill upgrade changed YAML or missed its managed version.' );
	$fs->dumpFile( $theme->resolve( $skill ), $skillSource . "\n# Same-version local edits\n" );
	$installer->install( $theme );
	agent_docs_assert( str_ends_with( (string) file_get_contents( $theme->resolve( $skill ) ), "# Same-version local edits\n" ), 'Equal-version managed edits were overwritten without force.' );
	$installer->install( $theme, true );
	agent_docs_assert( $skillSource === file_get_contents( $theme->resolve( $skill ) ), 'Force did not retain existing managed-refresh behavior.' );
	$fs->dumpFile( $theme->resolve( 'AGENTS.md' ), "<!-- st-toolkit-agent-doc-version: 99.0.0 -->\n# Future guide\n" );
	$installer->install( $theme );
	agent_docs_assert( str_contains( (string) file_get_contents( $theme->resolve( 'AGENTS.md' ) ), '# Future guide' ), 'Newer guide was downgraded without force.' );
	$customDocs = array(
		'# Custom doc without a marker',
		"# Project guide\n" . $marker,
		"```markdown\n" . $marker . "\n```\n",
		"---\nname: custom\n---\n# Project guide\n" . $marker,
		"---\nname: custom\n---\n\n" . $marker,
		"---\ndescription: |\n  " . $marker . "\n---\n# Custom body\n",
		"---\nname: custom\n" . $marker,
		"---\n" . str_repeat( "# header\n", 130 ) . "---\n" . $marker,
		"---\ndescription: " . str_repeat( 'x', 33000 ) . "\n---\n" . $marker,
		"\n" . $marker,
		"---\nname: custom\n---\n```markdown\n" . $marker . "\n```\n",
	);
	foreach ( $customDocs as $contents ) {
		$fs->dumpFile( $theme->resolve( $skill ), $contents );
		foreach ( array( false, true ) as $force ) {
			$change = array_column( $installer->plan( $theme, $force )['changes'], null, 'path' )[ $skill ];
			agent_docs_assert( 'skip' === $change['action'] && 'customized-no-version-marker' === $change['reason'], 'A body/fence/oversized header marker claimed ownership of customized docs.' );
			$installer->install( $theme, $force );
			agent_docs_assert( $contents === file_get_contents( $theme->resolve( $skill ) ), 'Custom docs were overwritten, including under --force.' );
		}
	}
	$fs->dumpFile( $theme->resolve( 'CLAUDE.md' ), '# Project-owned Claude entrypoint' );
	$installer->install( $theme, true );
	agent_docs_assert( '# Project-owned Claude entrypoint' === file_get_contents( $theme->resolve( 'CLAUDE.md' ) ), 'Force overwrote a markerless native Claude entrypoint.' );
	foreach ( $customClaude as $path => $contents ) {
		agent_docs_assert( $contents === file_get_contents( $theme->resolve( $path ) ), 'Unrelated Claude file was changed: ' . $path );
	}

	// A valid first file must not get written before a later unsafe path/source.
	$sources = $temp . '/sources';
	$fs->dumpFile( $sources . '/First.md', $marker . "\n# First source\n" );
	$fs->dumpFile( $sources . '/Later.md', $marker . "\n# Later source\n" );
	$safeTheme = agent_docs_theme( $temp . '/safety-theme' );
	$unsafePaths = array( '../escape.md', '/absolute.md', 'C:\\escape.md', 'nested\\escape.md', 'nested/../escape.md', 'nested//escape.md', 'nested/./escape.md', "bad\0name.md", 'nested/CON.md', 'nested/trailing.', '.gitignore', 'First.md', 42 );
	foreach ( $unsafePaths as $unsafe ) {
		$bad = new AgentDocsInstaller( agent_docs_manifest( $sources, array( 'First.md', $unsafe ) ) );
		$before = agent_docs_snapshot( $safeTheme->path );
		agent_docs_throws( static fn () => $bad->install( $safeTheme, true ), is_string( $unsafe ) && in_array( $unsafe, array( '.gitignore', 'First.md' ), true ) ? 'reserved' : ( is_string( $unsafe ) ? 'Unsafe' : 'must be strings' ) );
		agent_docs_assert( $before === agent_docs_snapshot( $safeTheme->path ), 'Unsafe manifest caused partial docs/ignore writes.' );
	}
	$safeManifest = agent_docs_manifest( $sources, array( 'First.md', 'Later.md' ) );
	$fs->mkdir( $safeTheme->resolve( 'Later.md' ) );
	$before = agent_docs_snapshot( $safeTheme->path );
	agent_docs_throws( static fn () => ( new AgentDocsInstaller( $safeManifest ) )->install( $safeTheme ), 'Unsafe' );
	agent_docs_assert( $before === agent_docs_snapshot( $safeTheme->path ), 'A directory target was discovered after writing earlier docs.' );
	$fs->remove( $safeTheme->resolve( 'Later.md' ) );
	$fs->mkdir( $safeTheme->resolve( '.gitignore' ) );
	$before = agent_docs_snapshot( $safeTheme->path );
	agent_docs_throws( static fn () => ( new AgentDocsInstaller( $safeManifest ) )->install( $safeTheme ), 'Unsafe' );
	agent_docs_assert( $before === agent_docs_snapshot( $safeTheme->path ), 'Directory .gitignore caused a partial install.' );
	$fs->remove( $safeTheme->resolve( '.gitignore' ) );
	$fs->dumpFile( $sources . '/Later.md', "# Missing source marker\n" );
	$before = agent_docs_snapshot( $safeTheme->path );
	agent_docs_throws( static fn () => ( new AgentDocsInstaller( $safeManifest ) )->install( $safeTheme ), 'version mismatch' );
	agent_docs_assert( $before === agent_docs_snapshot( $safeTheme->path ), 'A bad later source version caused earlier target writes.' );
	$fs->dumpFile( $sources . '/Later.md', $marker . "\n# Later source\n" );
	if ( '\\' !== DIRECTORY_SEPARATOR ) {
		$outside = $temp . '/outside';
		$fs->dumpFile( $outside . '/sentinel.md', $marker . "\n# Outside sentinel\n" );
		foreach ( array( 'Later.md', '.gitignore' ) as $relative ) {
			$fs->symlink( $outside . '/sentinel.md', $safeTheme->resolve( $relative ) );
			$before = agent_docs_snapshot( $safeTheme->path );
			$outsideBefore = agent_docs_snapshot( $outside );
			agent_docs_throws( static fn () => ( new AgentDocsInstaller( $safeManifest ) )->install( $safeTheme, true ), 'Unsafe' );
			agent_docs_assert( $before === agent_docs_snapshot( $safeTheme->path ) && $outsideBefore === agent_docs_snapshot( $outside ), 'Leaf/.gitignore symlink escaped preflight.' );
			$fs->remove( $safeTheme->resolve( $relative ) );
		}
		$fs->dumpFile( $sources . '/linked/Later.md', $marker . "\n# Linked source\n" );
		$linkManifest = agent_docs_manifest( $sources, array( 'First.md', 'linked/Later.md' ) );
		$fs->symlink( $outside, $safeTheme->resolve( 'linked' ) );
		$before = agent_docs_snapshot( $safeTheme->path );
		agent_docs_throws( static fn () => ( new AgentDocsInstaller( $linkManifest ) )->install( $safeTheme ), 'Unsafe' );
		agent_docs_assert( $before === agent_docs_snapshot( $safeTheme->path ), 'Linked target directory caused partial docs writes.' );
		$fs->remove( $safeTheme->resolve( 'linked' ) );
		$fs->remove( $sources . '/Later.md' );
		$fs->symlink( $outside . '/sentinel.md', $sources . '/Later.md' );
		$before = agent_docs_snapshot( $safeTheme->path );
		agent_docs_throws( static fn () => ( new AgentDocsInstaller( $safeManifest ) )->install( $safeTheme ), 'Unsafe' );
		agent_docs_assert( $before === agent_docs_snapshot( $safeTheme->path ), 'Linked source caused target writes.' );
		$fs->remove( $sources . '/Later.md' );
		$fs->dumpFile( $sources . '/Later.md', $marker . "\n# Later source\n" );
	}
	$failedTheme = agent_docs_theme( $temp . '/failure-theme' );
	$failingInstaller = new AgentDocsInstaller( $safeManifest, new AgentDocsFailingFilesystem() );
	agent_docs_throws( static fn () => $failingInstaller->install( $failedTheme ), 'Fixture documentation write failure' );
	agent_docs_assert( ! file_exists( $failedTheme->resolve( 'First.md' ) ), 'Failed doc write unexpectedly created its target.' );
	foreach ( $rules as $rule ) {
		agent_docs_assert( str_contains( (string) file_get_contents( $failedTheme->resolve( '.gitignore' ) ), $rule . PHP_EOL ), 'Interrupted installation left local docs unignored.' );
	}

	// Existing init/docs:update semantics, using the real binary and real resources.
	$cliTheme = agent_docs_theme( $temp . '/cli-theme' );
	$before = agent_docs_snapshot( $cliTheme->path );
	$dry = agent_docs_cli( array( 'init', '--theme=' . $cliTheme->path, '--dry-run', '--json' ) );
	agent_docs_assert( 0 === $dry['status'] && true === $dry['json']['dry_run'] && 20 === count( $dry['json']['changes'] ), 'Init dry-run JSON interface regressed.' );
	agent_docs_assert( $before === agent_docs_snapshot( $cliTheme->path ), 'CLI dry-run wrote files.' );
	$cancel = agent_docs_cli( array( 'docs:update', '--theme=' . $cliTheme->path, '--no-interaction' ) );
	agent_docs_assert( 0 === $cancel['status'] && str_contains( $cancel['output'], 'cancelled' ) && $before === agent_docs_snapshot( $cliTheme->path ), 'Unconfirmed noninteractive docs update wrote files.' );
	$app = new Application();
	$tester = new CommandTester( $app->find( 'docs:update' ) );
	$tester->setInputs( array( 'no' ) );
	$tester->execute( array( '--theme' => $cliTheme->path ), array( 'interactive' => true ) );
	agent_docs_assert( $before === agent_docs_snapshot( $cliTheme->path ) && str_contains( $tester->getDisplay(), 'cancelled' ), 'Declined interactive docs update wrote files.' );
	$init = agent_docs_cli( array( 'init', '--theme=' . $cliTheme->path, '--yes', '--json' ) );
	agent_docs_assert( 0 === $init['status'] && 'acme' === $init['json']['namespace'], 'Confirmed init failed or did not render the detected namespace.' );
	$before = agent_docs_snapshot( $cliTheme->path );
	$dry = agent_docs_cli( array( 'docs:update', '--theme=' . $cliTheme->path, '--force', '--dry-run', '--json' ) );
	agent_docs_assert( 0 === $dry['status'] && true === $dry['json']['force'] && $before === agent_docs_snapshot( $cliTheme->path ), 'Forced CLI dry-run was not read-only.' );
	$fs->dumpFile( $cliTheme->resolve( 'CLAUDE.md' ), '# Customized Claude entrypoint' );
	$update = agent_docs_cli( array( 'docs:update', '--theme=' . $cliTheme->path, '--force', '--yes', '--json' ) );
	agent_docs_assert( 0 === $update['status'] && 'skip' === array_column( $update['json']['changes'], 'action', 'path' )['CLAUDE.md'] && '# Customized Claude entrypoint' === file_get_contents( $cliTheme->resolve( 'CLAUDE.md' ) ), 'Forced CLI docs update overwrote customized Claude guidance.' );
	fwrite( STDOUT, sprintf( "Agent docs checks passed (%d assertions).\n", $assertions ) );
} finally {
	$fs->remove( $temp );
}

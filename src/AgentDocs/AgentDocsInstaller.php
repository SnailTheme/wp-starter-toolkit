<?php
/**
 * Agent docs installer.
 *
 * Installs local-only Markdown guidance into a target theme. Files without the
 * toolkit version marker are treated as developer-owned and are left untouched.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\AgentDocs;

use RuntimeException;
use SnailTheme\WPStarterToolkit\Theme\ThemeContext;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Plans and writes toolkit-managed agent documentation files.
 */
final class AgentDocsInstaller {
	private const VERSION_MARKER_PATTERN = '/\A<!--[ \t]*st-toolkit-agent-doc-version:[ \t]*([^>\s]+)[ \t]*-->\z/';
	private const SEMANTIC_VERSION_PATTERN = '/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)'
		. '(?:-(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*)?'
		. '(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?\z/';
	private const HEADER_SCAN_BYTES = 32768;
	private const HEADER_SCAN_LINES = 128;

	private const GITIGNORE_LINES = array(
		'/AGENTS.md',
		'/.agents/',
		'/CLAUDE.md',
		'/.claude/skills/wp-starter-elemental-sections/',
		'/.claude/skills/wp-starter-block-development/',
		'/.claude/skills/wp-starter-assets/',
		'/.claude/skills/wp-starter-core-overrides/',
		'/.claude/skills/wp-starter-toolkit/',
		'/.claude/skills/wp-starter-validation/',
	);
	private const OBSOLETE_GITIGNORE_LINES = array( '/.claude/skills/wp-starter-*/' );

	public function __construct(
		?AgentDocsManifest $manifest = null,
		private readonly Filesystem $filesystem = new Filesystem()
	) {
		$this->manifest = $manifest ?? AgentDocsManifest::load();
	}

	private readonly AgentDocsManifest $manifest;

	/**
	 * Create an installer with the bundled manifest.
	 */
	public static function create(): self {
		return new self();
	}

	/**
	 * Plan all writes for the target theme.
	 *
	 * @return array<string,mixed>
	 */
	public function plan( ThemeContext $theme, bool $force = false ): array {
		return $this->prepare( $theme, $force )['plan'];
	}

	/**
	 * Validate and render the entire manifest before any target file is written.
	 *
	 * @return array<string,mixed> Public plan, rendered bytes and target preimages.
	 */
	private function prepare( ThemeContext $theme, bool $force ): array {
		$changes        = array();
		$rendered       = array();
		$previous       = array();
		$seen           = array();
		$rootIdentity   = $this->rootIdentity( $theme->path );
		$blockNamespace = $theme->pattern( 'block_namespace', 'stwp' );

		foreach ( $this->manifest->files() as $relativePath ) {
			if ( ! is_string( $relativePath ) ) {
				throw new RuntimeException( 'Agent docs manifest paths must be strings.' );
			}
			$this->assertRelativePath( $relativePath );
			if ( '.gitignore' === $relativePath || isset( $seen[ $relativePath ] ) ) {
				throw new RuntimeException( 'Duplicate or reserved agent docs target: ' . $relativePath );
			}
			$seen[ $relativePath ] = true;
			$source        = $this->sourcePath( $relativePath );
			$target        = $theme->resolve( $relativePath );
			$this->assertPath( $theme->path, $relativePath );
			$sourceContents = $this->readFile( $source );
			$targetContents = is_file( $target ) ? $this->readFile( $target ) : null;
			$previous[ $relativePath ] = null === $targetContents ? null : hash( 'sha256', $targetContents );
			$targetVersion = $this->manifest->fileVersion( $relativePath );
			$sourceVersion = $this->installedVersion( $sourceContents );
			$action        = 'add';
			$reason        = 'missing';

			if ( $targetVersion !== $sourceVersion ) {
				throw new RuntimeException(
					sprintf(
						'Agent docs version mismatch for %s: manifest declares %s, source declares %s.',
						$relativePath,
						$targetVersion,
						null === $sourceVersion ? 'no marker' : $sourceVersion
					)
				);
			}

			if ( null !== $targetContents ) {
				$installedVersion = $this->installedVersion( $targetContents );

				if ( null === $installedVersion ) {
					$action = 'skip';
					$reason = 'customized-no-version-marker';
				} elseif ( version_compare( $installedVersion, $targetVersion, '<' ) ) {
					$action = 'update';
					$reason = sprintf( 'version-%s-to-%s', $installedVersion, $targetVersion );
				} elseif ( $force ) {
					$action = 'update';
					$reason = sprintf( 'forced-version-%s', $installedVersion );
				} else {
					$action = 'skip';
					$reason = sprintf( 'version-%s-current', $installedVersion );
				}
			}
			if ( in_array( $action, array( 'add', 'update' ), true ) ) {
				$this->assertPath( $theme->path, $relativePath, true );
				$rendered[ $relativePath ] = $this->renderSource( $sourceContents, $theme );
			}

			$changes[] = array(
				'path'           => $relativePath,
				'action'         => $action,
				'reason'         => $reason,
				'target_version' => $targetVersion,
				'source'         => $source,
			);
		}

		$this->assertPath( $theme->path, '.gitignore' );
		$ignorePath = $theme->resolve( '.gitignore' );
		$ignoreContents = is_file( $ignorePath ) ? $this->readFile( $ignorePath ) : null;
		$previous['.gitignore'] = null === $ignoreContents ? null : hash( 'sha256', $ignoreContents );
		$gitignore = $this->gitignorePlan( $ignoreContents );
		if ( array() !== $gitignore['missing_lines'] || array() !== $gitignore['obsolete_lines'] ) {
			$this->assertPath( $theme->path, '.gitignore', true );
			// Once every path passes preflight, ignore local docs before creating
			// any of them, including when a subsequent documentation write fails.
			$rendered = array( '.gitignore' => $this->renderGitignore( $ignoreContents ?? '', $gitignore['missing_lines'], $gitignore['obsolete_lines'] ) ) + $rendered;
		}
		$plan = array(
			'theme_path' => $theme->path,
			'version'    => $this->manifest->version(),
			'namespace'  => $blockNamespace,
			'force'      => $force,
			'manifest'   => $this->manifest->path(),
			'changes'    => $changes,
			'gitignore'  => $gitignore,
		);
		$this->assertPreimages( $theme, $rootIdentity, $previous );
		return array( 'plan' => $plan, 'rendered' => $rendered, 'previous' => $previous, 'root' => $rootIdentity );
	}

	/**
	 * Install or update managed docs and local ignore rules.
	 *
	 * @return array<string,mixed>
	 */
	public function install( ThemeContext $theme, bool $force = false ): array {
		$prepared = $this->prepare( $theme, $force );
		$this->assertPreimages( $theme, $prepared['root'], $prepared['previous'] );
		foreach ( $prepared['rendered'] as $relativePath => $contents ) {
			$this->assertPreimages( $theme, $prepared['root'], array( $relativePath => $prepared['previous'][ $relativePath ] ) );
			$this->assertPath( $theme->path, $relativePath, true );
			$target = $theme->resolve( $relativePath );
			$this->filesystem->mkdir( dirname( $target ) );
			$this->assertPreimages( $theme, $prepared['root'], array( $relativePath => $prepared['previous'][ $relativePath ] ) );
			$this->filesystem->dumpFile( $target, $contents );
		}
		return $prepared['plan'];
	}

	/**
	 * Resolve a packaged docs source file.
	 */
	private function sourcePath( string $relativePath ): string {
		$root = $this->manifest->filesRoot();
		$this->assertPath( $root, $relativePath );
		$source = $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relativePath );

		if ( ! is_file( $source ) ) {
			throw new RuntimeException( sprintf( 'Agent docs source file not found: %s', $source ) );
		}

		return $source;
	}

	/**
	 * Render install-time placeholders in packaged docs.
	 */
	private function renderSource( string $contents, ThemeContext $theme ): string {
		return str_replace(
			'<current_block_namespace>',
			$theme->pattern( 'block_namespace', 'stwp' ),
			$contents
		);
	}

	/**
	 * Recognize only the first line, or the line immediately after bounded,
	 * leading YAML frontmatter. Never search Markdown prose or fenced examples.
	 */
	private function installedVersion( string $contents ): ?string {
		$header = substr( $contents, 0, self::HEADER_SCAN_BYTES );
		$lines = preg_split( '/\r\n|\n|\r/', $header, self::HEADER_SCAN_LINES + 1 ) ?: array();
		if ( strlen( $contents ) > self::HEADER_SCAN_BYTES ) {
			// A truncated line can hide trailing prose after an apparent marker.
			array_pop( $lines );
		}
		$lines = array_slice( $lines, 0, self::HEADER_SCAN_LINES );
		$line = $lines[0] ?? '';
		if ( '---' === rtrim( $line, " \t" ) ) {
			$line = '';
			foreach ( array_slice( $lines, 1, null, true ) as $index => $candidate ) {
				if ( '---' === rtrim( $candidate, " \t" ) ) {
					$line = $lines[ $index + 1 ] ?? '';
					break;
				}
			}
		}
		if ( 1 !== preg_match( self::VERSION_MARKER_PATTERN, trim( $line ), $matches ) ) {
			return null;
		}
		// version_compare() accepts arbitrary words; they must not grant ownership.
		return 1 === preg_match( self::SEMANTIC_VERSION_PATTERN, $matches[1] ) ? $matches[1] : null;
	}

	/**
	 * Plan required .gitignore additions and exact legacy-rule removals.
	 *
	 * @return array<string,mixed>
	 */
	private function gitignorePlan( ?string $contents ): array {
		$lines    = preg_split( '/\r\n|\n|\r/', $contents ?? '' ) ?: array();
		$missing  = array();
		$obsolete = array();

		foreach ( self::GITIGNORE_LINES as $line ) {
			if ( ! in_array( $line, $lines, true ) ) {
				$missing[] = $line;
			}
		}
		foreach ( self::OBSOLETE_GITIGNORE_LINES as $line ) {
			if ( in_array( $line, $lines, true ) ) {
				$obsolete[] = $line;
			}
		}

		return array(
			'path'          => '.gitignore',
			'action'        => array() === $missing && array() === $obsolete ? 'unchanged' : ( null !== $contents ? 'update' : 'add' ),
			'missing_lines' => $missing,
			'obsolete_lines' => $obsolete,
		);
	}

	/**
	 * Migrate only exact toolkit-owned rules, leaving unrelated bytes alone.
	 *
	 * @param string[] $missingLines Lines to append.
	 * @param string[] $obsoleteLines Exact legacy lines to remove.
	 */
	private function renderGitignore( string $contents, array $missingLines, array $obsoleteLines ): string {
		if ( array() !== $obsoleteLines ) {
			$lines = preg_split( '/(\r\n|\n|\r)/', $contents, -1, PREG_SPLIT_DELIM_CAPTURE ) ?: array();
			$contents = '';
			// Retain original line endings, comments, and user-owned ignore rules.
			for ( $index = 0, $count = count( $lines ); $index < $count; $index += 2 ) {
				if ( ! in_array( $lines[ $index ], $obsoleteLines, true ) ) {
					$contents .= $lines[ $index ] . ( $lines[ $index + 1 ] ?? '' );
				}
			}
		}
		if ( array() === $missingLines ) {
			return $contents;
		}
		if ( '' !== $contents && ! str_ends_with( $contents, "\n" ) && ! str_ends_with( $contents, "\r" ) ) {
			$contents .= PHP_EOL;
		}

		$contents .= implode( PHP_EOL, $missingLines ) . PHP_EOL;

		return $contents;
	}

	private function readFile( string $path ): string {
		$contents = @file_get_contents( $path );
		if ( false === $contents ) {
			throw new RuntimeException( 'Could not read agent docs file: ' . $path );
		}
		return $contents;
	}

	private function assertRelativePath( string $path ): void {
		if ( '' === $path || str_starts_with( $path, '/' ) || preg_match( '/[\\\\:\x00-\x1f]/', $path ) ) {
			throw new RuntimeException( 'Unsafe agent docs path: ' . $path );
		}
		foreach ( explode( '/', $path ) as $part ) {
			if ( in_array( $part, array( '', '.', '..' ), true ) || preg_match( '/[ .]$/', $part )
				|| preg_match( '/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $part ) ) {
				throw new RuntimeException( 'Unsafe agent docs path: ' . $path );
			}
		}
	}

	/** Reject both leaf links and directory links before reading or writing them. */
	private function assertPath( string $root, string $relative, bool $forWrite = false ): void {
		$this->assertRelativePath( $relative );
		$this->rootIdentity( $root );
		$path = $root;
		$parts = explode( '/', $relative );
		foreach ( $parts as $index => $part ) {
			$path .= DIRECTORY_SEPARATOR . $part;
			$leaf = $index === count( $parts ) - 1;
			if ( is_link( $path ) || ( file_exists( $path ) && ( $leaf ? ! is_file( $path ) : ! is_dir( $path ) ) ) ) {
				throw new RuntimeException( 'Unsafe agent docs file target/source: ' . $relative );
			}
		}
		if ( ! $forWrite ) {
			return;
		}
		$parent = dirname( $path );
		while ( ! file_exists( $parent ) ) {
			$parent = dirname( $parent );
		}
		if ( ! is_writable( $parent ) || ( is_file( $path ) && ! is_writable( $path ) ) ) {
			throw new RuntimeException( 'Agent docs target is not writable: ' . $relative );
		}
	}

	/** @return array<string,int|string> */
	private function rootIdentity( string $root ): array {
		clearstatcache( true );
		$stat = @stat( $root );
		$real = realpath( $root );
		if ( false === $stat || false === $real || is_link( $root ) || ! is_dir( $root ) ) {
			throw new RuntimeException( 'Unsafe agent docs root: ' . $root );
		}
		return array( 'path' => $real, 'dev' => $stat['dev'], 'ino' => $stat['ino'] );
	}

	/** @param array<string,?string> $previous Target preimages, including skipped docs. */
	private function assertPreimages( ThemeContext $theme, array $root, array $previous ): void {
		if ( $root !== $this->rootIdentity( $theme->path ) ) {
			throw new RuntimeException( 'Agent docs theme root changed during installation.' );
		}
		foreach ( $previous as $relative => $checksum ) {
			$this->assertPath( $theme->path, $relative );
			$path = $theme->resolve( $relative );
			$current = is_file( $path ) ? hash( 'sha256', $this->readFile( $path ) ) : null;
			if ( $current !== $checksum ) {
				throw new RuntimeException( 'Agent docs target changed during installation: ' . $relative );
			}
		}
	}
}

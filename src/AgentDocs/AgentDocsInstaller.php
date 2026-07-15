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
	private const VERSION_MARKER_PATTERN = '/^<!--\s*st-toolkit-agent-doc-version:\s*([^>\s]+)\s*-->/';

	private const GITIGNORE_LINES = array(
		'/AGENTS.md',
		'/.agents/',
	);

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
		$changes        = array();
		$blockNamespace = $theme->pattern( 'block_namespace', 'stwp' );

		foreach ( $this->manifest->files() as $relativePath ) {
			$source        = $this->sourcePath( $relativePath );
			$target        = $theme->resolve( $relativePath );
			$targetVersion = $this->manifest->fileVersion( $relativePath );
			$sourceVersion = $this->installedVersion( $source );
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

			if ( is_file( $target ) ) {
				$installedVersion = $this->installedVersion( $target );

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

			$changes[] = array(
				'path'           => $relativePath,
				'action'         => $action,
				'reason'         => $reason,
				'target_version' => $targetVersion,
				'source'         => $source,
			);
		}

		return array(
			'theme_path' => $theme->path,
			'version'    => $this->manifest->version(),
			'namespace'  => $blockNamespace,
			'force'      => $force,
			'manifest'   => $this->manifest->path(),
			'changes'    => $changes,
			'gitignore'  => $this->gitignorePlan( $theme ),
		);
	}

	/**
	 * Install or update managed docs and local ignore rules.
	 *
	 * @return array<string,mixed>
	 */
	public function install( ThemeContext $theme, bool $force = false ): array {
		$plan = $this->plan( $theme, $force );

		foreach ( $plan['changes'] as $change ) {
			if ( ! in_array( $change['action'], array( 'add', 'update' ), true ) ) {
				continue;
			}

			$target = $theme->resolve( $change['path'] );

			$this->filesystem->mkdir( dirname( $target ) );
			$this->filesystem->dumpFile( $target, $this->renderSource( $change['source'], $theme ) );
		}

		$this->ensureGitignore( $theme, $plan['gitignore']['missing_lines'] );

		return $plan;
	}

	/**
	 * Resolve a packaged docs source file.
	 */
	private function sourcePath( string $relativePath ): string {
		$source = $this->manifest->filesRoot() . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relativePath );

		if ( ! is_file( $source ) ) {
			throw new RuntimeException( sprintf( 'Agent docs source file not found: %s', $source ) );
		}

		return $source;
	}

	/**
	 * Render install-time placeholders in packaged docs.
	 */
	private function renderSource( string $source, ThemeContext $theme ): string {
		$contents = (string) file_get_contents( $source );

		return str_replace(
			'<current_block_namespace>',
			$theme->pattern( 'block_namespace', 'stwp' ),
			$contents
		);
	}

	/**
	 * Read the installed marker version from a Markdown file.
	 */
	private function installedVersion( string $path ): ?string {
		$handle = fopen( $path, 'rb' );

		if ( false === $handle ) {
			return null;
		}

		$line = fgets( $handle );
		fclose( $handle );

		if ( false === $line || 1 !== preg_match( self::VERSION_MARKER_PATTERN, trim( $line ), $matches ) ) {
			return null;
		}

		return $matches[1];
	}

	/**
	 * Plan required .gitignore additions.
	 *
	 * @return array<string,mixed>
	 */
	private function gitignorePlan( ThemeContext $theme ): array {
		$path     = $theme->resolve( '.gitignore' );
		$contents = is_file( $path ) ? (string) file_get_contents( $path ) : '';
		$lines    = preg_split( '/\R/', $contents ) ?: array();
		$missing  = array();

		foreach ( self::GITIGNORE_LINES as $line ) {
			if ( ! in_array( $line, $lines, true ) ) {
				$missing[] = $line;
			}
		}

		return array(
			'path'          => '.gitignore',
			'action'        => array() === $missing ? 'unchanged' : ( is_file( $path ) ? 'update' : 'add' ),
			'missing_lines' => $missing,
		);
	}

	/**
	 * Ensure local-only docs are ignored by Git.
	 *
	 * @param string[] $missingLines Lines to append.
	 */
	private function ensureGitignore( ThemeContext $theme, array $missingLines ): void {
		if ( array() === $missingLines ) {
			return;
		}

		$path     = $theme->resolve( '.gitignore' );
		$contents = is_file( $path ) ? (string) file_get_contents( $path ) : '';

		if ( '' !== $contents && ! str_ends_with( $contents, PHP_EOL ) ) {
			$contents .= PHP_EOL;
		}

		$contents .= implode( PHP_EOL, $missingLines ) . PHP_EOL;

		file_put_contents( $path, $contents );
	}
}

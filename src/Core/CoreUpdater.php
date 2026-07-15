<?php
/**
 * Core updater service.
 *
 * The updater prepares transformed files in a temporary directory before it
 * writes to the theme. It only touches manifest-owned paths.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Core;

use SnailTheme\WPStarterToolkit\Support\BackupManager;
use SnailTheme\WPStarterToolkit\Support\PhpValidator;
use SnailTheme\WPStarterToolkit\Support\ReplacementEngine;
use SnailTheme\WPStarterToolkit\Support\TextFiles;
use SnailTheme\WPStarterToolkit\Theme\ThemeContext;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Performs dry-run and write operations for packaged core files.
 */
final class CoreUpdater {
	private readonly CoreManifest $manifest;
	private readonly Filesystem $filesystem;
	private readonly ReplacementEngine $replacements;
	private readonly TextFiles $textFiles;
	private readonly BackupManager $backups;
	private readonly PhpValidator $phpValidator;

	/**
	 * Temporary core directories created during this service lifetime.
	 *
	 * @var string[]
	 */
	private array $temporaryDirectories = array();

	public function __construct(
		?CoreManifest $manifest = null,
		?Filesystem $filesystem = null,
		?ReplacementEngine $replacements = null,
		?TextFiles $textFiles = null,
		?BackupManager $backups = null,
		?PhpValidator $phpValidator = null
	) {
		$this->manifest     = $manifest ?? CoreManifest::load();
		$this->filesystem   = $filesystem ?? new Filesystem();
		$this->replacements = $replacements ?? new ReplacementEngine();
		$this->textFiles    = $textFiles ?? new TextFiles();
		$this->backups      = $backups ?? new BackupManager();
		$this->phpValidator = $phpValidator ?? new PhpValidator();
	}

	/**
	 * Remove transformed core files after the updater is released.
	 */
	public function __destruct() {
		foreach ( $this->temporaryDirectories as $directory ) {
			try {
				$this->filesystem->remove( $directory );
			} catch ( \Throwable ) {
				// Cleanup must never replace the command's real result with a destructor error.
			}
		}
	}

	/**
	 * Summarize the installed and packaged versions.
	 *
	 * @return array<string,mixed>
	 */
	public function check( ThemeContext $theme ): array {
		return array(
			'theme_path'        => $theme->path,
			'installed_core'    => $theme->coreVersion,
			'packaged_core'     => $this->manifest->version(),
			'update_available'  => version_compare( $theme->coreVersion, $this->manifest->version(), '<' ),
			'manifest'          => $this->manifest->path(),
			'owned_file_count'  => count( $this->resourceFiles() ),
			'removed_file_count' => count( $this->manifest->removedFiles() ),
		);
	}

	/**
	 * Prepare transformed files and return the planned changes.
	 *
	 * @return array<string,mixed>
	 */
	public function plan( ThemeContext $theme ): array {
		$prepared = $this->prepare( $theme );
		$files    = $this->resourceFiles();
		$changes  = array();

		foreach ( $files as $relativePath ) {
			$target = $theme->resolve( $relativePath );
			$source = $prepared . DIRECTORY_SEPARATOR . $relativePath;

			$changes[] = array(
				'path'   => $relativePath,
				'action' => ! is_file( $target ) ? 'add' : ( hash_file( 'sha256', $target ) === hash_file( 'sha256', $source ) ? 'unchanged' : 'replace' ),
			);
		}

		foreach ( $this->manifest->removedFiles() as $relativePath ) {
			$changes[] = array(
				'path'   => $relativePath,
				'action' => file_exists( $theme->resolve( $relativePath ) ) ? 'remove' : 'absent',
			);
		}

		return array(
			'prepared_path' => $prepared,
			'changes'       => $changes,
		);
	}

	/**
	 * Apply the packaged core update.
	 *
	 * @return array<string,mixed>
	 */
	public function update( ThemeContext $theme ): array {
		$plan   = $this->plan( $theme );
		$backup = $this->backups->create( $theme->path, 'core' );

		foreach ( $this->resourceFiles() as $relativePath ) {
			$this->backups->backupPath( $theme->path, $backup, $relativePath );
		}

		foreach ( $this->manifest->removedFiles() as $relativePath ) {
			$this->backups->backupPath( $theme->path, $backup, $relativePath );
		}

		foreach ( $this->resourceFiles() as $relativePath ) {
			$source = $plan['prepared_path'] . DIRECTORY_SEPARATOR . $relativePath;
			$target = $theme->resolve( $relativePath );

			$this->filesystem->mkdir( dirname( $target ) );
			$this->filesystem->copy( $source, $target, true );
		}

		foreach ( $this->manifest->removedFiles() as $relativePath ) {
			$target = $theme->resolve( $relativePath );

			if ( file_exists( $target ) ) {
				$this->filesystem->remove( $target );
			}
		}

		$this->phpValidator->validateDirectory( $theme->resolve( 'core' ) );

		return array(
			'backup_path' => $backup,
			'changes'     => $plan['changes'],
		);
	}

	/**
	 * Prepare transformed files in the system temp directory.
	 */
	private function prepare( ThemeContext $theme ): string {
		$temp = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR )
			. DIRECTORY_SEPARATOR
			. 'st-toolkit-core-' . bin2hex( random_bytes( 6 ) );

		$this->filesystem->mkdir( $temp );
		$this->temporaryDirectories[] = $temp;

		foreach ( $this->resourceFiles() as $relativePath ) {
			$source = $this->manifest->filesRoot() . DIRECTORY_SEPARATOR . $relativePath;
			$target = $temp . DIRECTORY_SEPARATOR . $relativePath;

			$this->filesystem->mkdir( dirname( $target ) );

			if ( $this->textFiles->isTextFile( $source ) ) {
				$contents = (string) file_get_contents( $source );
				$contents = $this->replacements->applyThemePatterns( $contents, $theme->patterns );
				file_put_contents( $target, $contents );
				continue;
			}

			$this->filesystem->copy( $source, $target, true );
		}

		$this->phpValidator->validateDirectory( $temp );

		return $temp;
	}

	/**
	 * Return all packaged resource files relative to the files root.
	 *
	 * @return string[]
	 */
	private function resourceFiles(): array {
		$files  = array();
		$finder = Finder::create()->files()->in( $this->manifest->filesRoot() );

		foreach ( $finder as $file ) {
			$files[] = str_replace( '\\', '/', $file->getRelativePathname() );
		}

		sort( $files );

		return $files;
	}
}

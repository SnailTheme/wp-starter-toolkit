<?php
/**
 * UI profile installer.
 *
 * Profile files are scaffolded once and become project-owned. The committed
 * state file records their initial checksums and locks the first selection.
 * Exceptional replacements can then distinguish untouched scaffold files from
 * developer customizations before removing the previous managed UI.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\UI;

use RuntimeException;
use SnailTheme\WPStarterToolkit\Support\BackupManager;
use SnailTheme\WPStarterToolkit\Support\JsonFile;
use SnailTheme\WPStarterToolkit\Support\ReplacementEngine;
use SnailTheme\WPStarterToolkit\Support\TextFiles;
use SnailTheme\WPStarterToolkit\Theme\ThemeContext;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Plans, validates, and writes UI profile source files.
 */
final class UIProfileInstaller {
	public const STATE_FILE = 'st-toolkit.json';

	/**
	 * Temporary profile directories created during this service lifetime.
	 *
	 * @var string[]
	 */
	private array $temporaryDirectories = array();

	public function __construct(
		private readonly Filesystem $filesystem = new Filesystem(),
		private readonly BackupManager $backups = new BackupManager(),
		private readonly JsonFile $json = new JsonFile(),
		private readonly ReplacementEngine $replacements = new ReplacementEngine(),
		private readonly TextFiles $textFiles = new TextFiles()
	) {}

	/**
	 * Remove prepared profile files after the command or test releases the service.
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
	 * Build a profile installation plan without changing the theme.
	 *
	 * @return array<string,mixed>
	 */
	public function plan( ThemeContext $theme, UIProfileManifest $profile ): array {
		$prepared     = $this->prepareFiles( $theme, $profile );
		$targetFiles  = $this->fileChecksums( $prepared );
		$currentState = $this->readState( $theme );
		$currentFiles = is_array( $currentState['files'] ?? null ) ? $currentState['files'] : array();
		$currentProfile = (string) ( $currentState['ui_profile'] ?? 'unmanaged' );
		$uiLocked      = (bool) ( $currentState['ui_locked'] ?? false );
		$profileSwitch = 'unmanaged' !== $currentProfile && $currentProfile !== $profile->slug();
		$changes       = array();
		$conflicts     = array();

		foreach ( $currentFiles as $relativePath => $recordedChecksum ) {
			if ( isset( $targetFiles[ $relativePath ] ) ) {
				continue;
			}

			$target = $theme->resolve( (string) $relativePath );

			if ( ! file_exists( $target ) ) {
				continue;
			}

			$modified = ! is_file( $target ) || hash_file( 'sha256', $target ) !== $recordedChecksum;
			$changes[] = array( 'path' => $relativePath, 'action' => 'remove', 'modified' => $modified );

			if ( $modified ) {
				$conflicts[] = (string) $relativePath;
			}
		}

		foreach ( $targetFiles as $relativePath => $checksum ) {
			$target = $theme->resolve( $relativePath );

			if ( ! is_file( $target ) ) {
				$changes[] = array( 'path' => $relativePath, 'action' => 'add', 'modified' => false );
				continue;
			}

			$actualChecksum = hash_file( 'sha256', $target );

			if ( $actualChecksum === $checksum ) {
				$changes[] = array( 'path' => $relativePath, 'action' => 'matching', 'modified' => false );
				continue;
			}

			$recordedChecksum = $currentFiles[ $relativePath ] ?? null;
			$modified         = ! is_string( $recordedChecksum ) || $actualChecksum !== $recordedChecksum;
			$changes[]        = array( 'path' => $relativePath, 'action' => 'replace', 'modified' => $modified );

			if ( $modified ) {
				$conflicts[] = $relativePath;
			}
		}

		sort( $conflicts );

		return array(
			'current_profile'         => $currentProfile,
			'target_profile'          => $profile->slug(),
			'target_version'          => $profile->version(),
			'ui_locked'               => $uiLocked,
			'first_selection'          => ! $uiLocked,
			'profile_switch'          => $profileSwitch,
			'replacement_required'    => $uiLocked && $profileSwitch,
			'changes'                 => $changes,
			'conflicts'               => array_values( array_unique( $conflicts ) ),
			'npm_dev_dependencies'    => $profile->npmDevDependencies(),
			'css_entries'              => $profile->cssEntries(),
			'vite_plugins'             => $profile->vitePlugins(),
			'prepared_path'            => $prepared,
			'target_file_checksums'    => $targetFiles,
		);
	}

	/**
	 * Install a planned profile and return backup information.
	 *
	 * @return array<string,mixed>
	 */
	public function install(
		ThemeContext $theme,
		UIProfileManifest $profile,
		bool $force = false,
		bool $replace = false
	): array {
		$plan         = $this->plan( $theme, $profile );
		$currentState = $this->readState( $theme );

		if ( $plan['replacement_required'] && ! $replace ) {
			throw new RuntimeException(
				'The active UI profile is locked. Re-run with --replace only after reviewing the destructive dry run.'
			);
		}

		if ( array() !== $plan['conflicts'] && ! $force ) {
			throw new RuntimeException(
				'Modified profile files would be replaced or removed. Re-run with --force after reviewing the dry run.'
			);
		}

		$backup = $this->backups->create( $theme->path, 'ui-' . $profile->slug() );
		$this->backups->backupPath( $theme->path, $backup, self::STATE_FILE );
		$removedDirectories = array();

		foreach ( $plan['changes'] as $change ) {
			if ( 'matching' === $change['action'] ) {
				continue;
			}

			$relativePath = (string) $change['path'];
			$this->backups->backupPath( $theme->path, $backup, $relativePath );

			if ( 'remove' === $change['action'] ) {
				$this->filesystem->remove( $theme->resolve( $relativePath ) );
				$removedDirectories[] = dirname( $theme->resolve( $relativePath ) );
				continue;
			}

			$source = $plan['prepared_path'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relativePath );
			$target = $theme->resolve( $relativePath );
			$this->filesystem->mkdir( dirname( $target ) );
			$this->filesystem->copy( $source, $target, true );
		}

		$this->removeEmptyDirectories( $theme, $removedDirectories );

		$state = array(
			'schema'       => 1,
			'ui_profile'   => $profile->slug(),
			'ui_version'   => $profile->version(),
			'ui_locked'    => true,
			'css_entries'  => $profile->cssEntries(),
			'vite_plugins' => $profile->vitePlugins(),
			'files'        => $plan['target_file_checksums'],
		);

		if ( isset( $currentState['components'] ) && is_array( $currentState['components'] ) ) {
			$state['components'] = $currentState['components'];
		}

		$this->json->write( $theme->resolve( self::STATE_FILE ), $state );
		$plan['backup_path'] = $backup;
		$plan['ui_locked']   = true;

		unset( $plan['prepared_path'], $plan['target_file_checksums'] );

		return $plan;
	}

	/**
	 * Report the active profile and whether managed scaffold files changed.
	 *
	 * @return array<string,mixed>
	 */
	public function status( ThemeContext $theme ): array {
		$state    = $this->readState( $theme );
		$files    = is_array( $state['files'] ?? null ) ? $state['files'] : array();
		$modified = array();
		$missing  = array();

		foreach ( $files as $relativePath => $checksum ) {
			$target = $theme->resolve( (string) $relativePath );

			if ( ! is_file( $target ) ) {
				$missing[] = (string) $relativePath;
				continue;
			}

			if ( hash_file( 'sha256', $target ) !== $checksum ) {
				$modified[] = (string) $relativePath;
			}
		}

		return array(
			'profile'       => (string) ( $state['ui_profile'] ?? 'unmanaged' ),
			'version'       => (string) ( $state['ui_version'] ?? '' ),
			'locked'        => (bool) ( $state['ui_locked'] ?? false ),
			'css_entries'   => array_values( $state['css_entries'] ?? array() ),
			'vite_plugins'  => array_values( $state['vite_plugins'] ?? array() ),
			'modified_files' => $modified,
			'missing_files' => $missing,
		);
	}

	/**
	 * Read the committed profile state, returning an unmanaged default.
	 *
	 * @return array<string,mixed>
	 */
	private function readState( ThemeContext $theme ): array {
		$path = $theme->resolve( self::STATE_FILE );

		return is_file( $path ) ? $this->json->read( $path ) : array();
	}

	/**
	 * Copy and whitelabel packaged profile files in temporary storage.
	 */
	private function prepareFiles( ThemeContext $theme, UIProfileManifest $profile ): string {
		$temp = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR )
			. DIRECTORY_SEPARATOR
			. 'st-toolkit-ui-' . $profile->slug() . '-' . bin2hex( random_bytes( 6 ) );

		$this->filesystem->mkdir( $temp );
		$this->temporaryDirectories[] = $temp;

		foreach ( $this->resourceFiles( $profile ) as $relativePath ) {
			$source = $profile->filesRoot() . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relativePath );
			$target = $temp . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relativePath );
			$this->filesystem->mkdir( dirname( $target ) );

			if ( $this->textFiles->isTextFile( $source ) ) {
				$contents = $this->replacements->applyThemePatterns( (string) file_get_contents( $source ), $theme->patterns );
				file_put_contents( $target, $contents );
				continue;
			}

			$this->filesystem->copy( $source, $target, true );
		}

		return $temp;
	}

	/**
	 * Return packaged files relative to the profile files root.
	 *
	 * @return string[]
	 */
	private function resourceFiles( UIProfileManifest $profile ): array {
		$files  = array();
		$finder = Finder::create()->files()->in( $profile->filesRoot() );

		foreach ( $finder as $file ) {
			$files[] = str_replace( '\\', '/', $file->getRelativePathname() );
		}

		sort( $files );

		return $files;
	}

	/**
	 * Hash prepared files relative to their temporary root.
	 *
	 * @return array<string,string>
	 */
	private function fileChecksums( string $root ): array {
		$checksums = array();
		$finder    = Finder::create()->files()->in( $root );

		foreach ( $finder as $file ) {
			$relative               = str_replace( '\\', '/', $file->getRelativePathname() );
			$checksums[ $relative ] = (string) hash_file( 'sha256', $file->getPathname() );
		}

		ksort( $checksums );

		return $checksums;
	}

	/**
	 * Remove directories made empty by obsolete managed profile files.
	 *
	 * The profile system never removes a complete shared asset root. It walks
	 * upward only while a directory is empty, preserving project files, core
	 * assets, block resources, and installed component sources beside it.
	 *
	 * @param string[] $directories Absolute candidate directories.
	 */
	private function removeEmptyDirectories( ThemeContext $theme, array $directories ): void {
		$themeRoot = rtrim( $theme->path, DIRECTORY_SEPARATOR );
		$directories = array_values( array_unique( $directories ) );

		usort(
			$directories,
			static fn ( string $left, string $right ): int => strlen( $right ) <=> strlen( $left )
		);

		foreach ( $directories as $directory ) {
			$current = $directory;

			while ( str_starts_with( $current, $themeRoot . DIRECTORY_SEPARATOR ) && is_dir( $current ) ) {
				$entries = scandir( $current );

				if ( false === $entries || array( '.', '..' ) !== $entries ) {
					break;
				}

				$this->filesystem->remove( $current );
				$current = dirname( $current );
			}
		}
	}
}

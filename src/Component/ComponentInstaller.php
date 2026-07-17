<?php
/**
 * Component installer.
 *
 * Components are installed after the project UI is selected so their source
 * files can build through the theme's committed asset pipeline.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Component;

use RuntimeException;
use SnailTheme\WPStarterToolkit\Support\BackupManager;
use SnailTheme\WPStarterToolkit\Support\JsonFile;
use SnailTheme\WPStarterToolkit\Support\ReplacementEngine;
use SnailTheme\WPStarterToolkit\Support\TextFiles;
use SnailTheme\WPStarterToolkit\Support\VersionConstraint;
use SnailTheme\WPStarterToolkit\Theme\ThemeContext;
use SnailTheme\WPStarterToolkit\UI\UIProfileInstaller;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Plans and writes toolkit component packages.
 */
final class ComponentInstaller {
	private const PROFILE_MARKER = 'assets/scss/_st-toolkit-profile.scss';

	/**
	 * Temporary component directories created during this service lifetime.
	 *
	 * @var string[]
	 */
	private array $temporaryDirectories = array();

	public function __construct(
		private readonly Filesystem $filesystem = new Filesystem(),
		private readonly BackupManager $backups = new BackupManager(),
		private readonly JsonFile $json = new JsonFile(),
		private readonly ReplacementEngine $replacements = new ReplacementEngine(),
		private readonly TextFiles $textFiles = new TextFiles(),
		private readonly VersionConstraint $versions = new VersionConstraint()
	) {}

	/**
	 * Remove prepared component files after the command or test releases the service.
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
	 * Build an install plan for one component.
	 *
	 * @return array<string,mixed>
	 */
	public function plan( ThemeContext $theme, ComponentManifest $component ): array {
		$profile      = $this->activeProfile( $theme );
		$profileReady = $this->profileMarkerMatches( $theme, $profile );
		$prepared     = $this->prepareFiles( $theme, $component );
		$files        = $this->fileChecksums( $prepared );
		$changes      = array();
		$conflicts    = array();

		foreach ( $files as $relativePath => $checksum ) {
			$target = $theme->resolve( $relativePath );

			if ( ! is_file( $target ) ) {
				$changes[] = array( 'path' => $relativePath, 'action' => 'add' );
				continue;
			}

			if ( hash_file( 'sha256', $target ) === $checksum ) {
				$changes[] = array( 'path' => $relativePath, 'action' => 'matching' );
				continue;
			}

			$changes[]  = array( 'path' => $relativePath, 'action' => 'conflict' );
			$conflicts[] = $relativePath;
		}

		return array(
			'component'              => $component->slug(),
			'version'                => $component->version(),
			'required_core_version' => $component->requiredCoreVersion(),
			'core_version_satisfied' => $this->versions->satisfies( $theme->coreVersion, $component->requiredCoreVersion() ),
			'ui_profile'            => $profile,
			'ui_profile_ready'      => $profileReady,
			'ui_profile_marker'     => self::PROFILE_MARKER,
			'changes'               => $changes,
			'conflicts'             => $conflicts,
			'prepared_path'         => $prepared,
			'file_checksums'        => $files,
			'build_command'         => 'npm run build',
		);
	}

	/**
	 * Install component files after conflict confirmation.
	 *
	 * @return array<string,mixed>
	 */
	public function install( ThemeContext $theme, ComponentManifest $component, bool $force = false ): array {
		$plan = $this->plan( $theme, $component );

		if ( ! $plan['ui_profile_ready'] ) {
			throw new RuntimeException(
				'Component installation requires an installed UI profile. Run ui:install PROFILE first.'
			);
		}

		if ( ! $plan['core_version_satisfied'] ) {
			throw new RuntimeException(
				sprintf(
					'Component "%s" requires core %s; the theme has %s. Run core:update first.',
					$component->slug(),
					$component->requiredCoreVersion(),
					$theme->coreVersion
				)
			);
		}

		if ( array() !== $plan['conflicts'] && ! $force ) {
			throw new RuntimeException( 'Component files already differ. Re-run with --force after reviewing the dry run.' );
		}

		$backup = $this->backups->create( $theme->path, 'component-' . $component->slug() );
		$this->backups->backupPath( $theme->path, $backup, UIProfileInstaller::STATE_FILE );

		foreach ( $plan['changes'] as $change ) {
			if ( 'matching' === $change['action'] ) {
				continue;
			}

			$relativePath = (string) $change['path'];
			$source       = $plan['prepared_path'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relativePath );
			$target       = $theme->resolve( $relativePath );
			$this->backups->backupPath( $theme->path, $backup, $relativePath );
			$this->filesystem->mkdir( dirname( $target ) );
			$this->filesystem->copy( $source, $target, true );
		}

		$statePath = $theme->resolve( UIProfileInstaller::STATE_FILE );
		$state     = is_file( $statePath ) ? $this->json->read( $statePath ) : array( 'schema' => 1 );
		$state['components'][ $component->slug() ] = array(
			'version'    => $component->version(),
			'ui_profile' => $plan['ui_profile'],
			'files'      => $plan['file_checksums'],
		);
		$this->json->write( $statePath, $state );

		$plan['backup_path'] = $backup;
		unset( $plan['prepared_path'], $plan['file_checksums'] );

		return $plan;
	}

	/**
	 * Read the active profile from committed theme state.
	 *
	 * Missing state is unmanaged rather than implicitly Bare. Component Sass
	 * must never assume an adapter when ui:install has not written its marker.
	 */
	private function activeProfile( ThemeContext $theme ): string {
		$statePath = $theme->resolve( UIProfileInstaller::STATE_FILE );

		if ( ! is_file( $statePath ) ) {
			return 'unmanaged';
		}

		$state = $this->json->read( $statePath );

		return (string) ( $state['ui_profile'] ?? 'unmanaged' );
	}

	/**
	 * Confirm the Sass profile marker exists and selects the committed profile.
	 */
	private function profileMarkerMatches( ThemeContext $theme, string $profile ): bool {
		if ( 'unmanaged' === $profile ) {
			return false;
		}

		$path = $theme->resolve( self::PROFILE_MARKER );

		if ( ! is_file( $path ) ) {
			return false;
		}

		$contents = file_get_contents( $path );

		return is_string( $contents ) && str_contains( $contents, '$ui-profile: "' . $profile . '";' );
	}

	/**
	 * Prepare component files in temporary storage.
	 */
	private function prepareFiles( ThemeContext $theme, ComponentManifest $component ): string {
		$temp = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR )
			. DIRECTORY_SEPARATOR
			. 'st-toolkit-component-' . $component->slug() . '-' . bin2hex( random_bytes( 6 ) );

		$this->filesystem->mkdir( $temp );
		$this->temporaryDirectories[] = $temp;
		$this->copyResourceTree( $component->filesRoot(), $temp, $theme );

		return $temp;
	}

	/**
	 * Copy one theme-relative resource tree with whitelabel replacements.
	 */
	private function copyResourceTree( string $sourceRoot, string $targetRoot, ThemeContext $theme ): void {
		$finder = Finder::create()->files()->in( $sourceRoot );

		foreach ( $finder as $file ) {
			$relativePath = $file->getRelativePathname();
			$source       = $file->getPathname();
			$target       = $targetRoot . DIRECTORY_SEPARATOR . $relativePath;
			$this->filesystem->mkdir( dirname( $target ) );

			if ( $this->textFiles->isTextFile( $source ) ) {
				$contents = $this->replacements->applyThemePatterns( (string) file_get_contents( $source ), $theme->patterns );
				file_put_contents( $target, $contents );
				continue;
			}

			$this->filesystem->copy( $source, $target, true );
		}
	}

	/**
	 * Hash prepared files by theme-relative path.
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
}

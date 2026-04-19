<?php
/**
 * Block installer.
 *
 * Installs a curated block into <theme>/blocks/<destination>. Shared assets are
 * managed separately so packages like Splide can be reused by multiple blocks.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Block;

use RuntimeException;
use SnailTheme\WPStarterToolkit\Support\BackupManager;
use SnailTheme\WPStarterToolkit\Support\JsonFile;
use SnailTheme\WPStarterToolkit\Support\PackagePaths;
use SnailTheme\WPStarterToolkit\Support\PhpValidator;
use SnailTheme\WPStarterToolkit\Support\ReplacementEngine;
use SnailTheme\WPStarterToolkit\Support\TextFiles;
use SnailTheme\WPStarterToolkit\Support\VersionConstraint;
use SnailTheme\WPStarterToolkit\Theme\ThemeContext;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Prepares and writes block package files.
 */
final class BlockInstaller {
	public function __construct(
		private readonly Filesystem $filesystem = new Filesystem(),
		private readonly ReplacementEngine $replacements = new ReplacementEngine(),
		private readonly TextFiles $textFiles = new TextFiles(),
		private readonly BackupManager $backups = new BackupManager(),
		private readonly PackagePaths $paths = new PackagePaths(),
		private readonly PackageJsonInspector $packageJson = new PackageJsonInspector(),
		private readonly PhpValidator $phpValidator = new PhpValidator(),
		private readonly JsonFile $json = new JsonFile(),
		private readonly VersionConstraint $versions = new VersionConstraint()
	) {}

	/**
	 * Create a dry-run install plan.
	 *
	 * @return array<string,mixed>
	 */
	public function plan( ThemeContext $theme, BlockManifest $manifest, string $destinationSlug ): array {
		$this->assertDestinationSlug( $destinationSlug );

		$destination = 'blocks/' . $destinationSlug;
		$files       = $this->blockFiles( $manifest );
		$changes     = array();

		foreach ( $files as $relativePath ) {
			$targetRelative = $destination . '/' . $relativePath;
			$target         = $theme->resolve( $targetRelative );
			$changes[]      = array(
				'path'   => $targetRelative,
				'action' => file_exists( $target ) ? 'replace' : 'add',
			);
		}

		foreach ( $this->themeFiles( $manifest ) as $relativePath ) {
			$targetRelative = $this->targetThemeFilePath( $theme, $manifest, $relativePath, $destinationSlug );
			$target         = $theme->resolve( $targetRelative );
			$changes[]      = array(
				'path'   => $targetRelative,
				'action' => file_exists( $target ) ? 'replace' : 'add',
			);
		}

		$shared              = $this->sharedAssetPlan( $theme, $manifest );
		$missingDependencies = $this->packageJson->missing( $theme, $manifest->npmDependencies() );

		return array(
			'source_block'         => $manifest->sourceSlug(),
			'destination_block'    => $destinationSlug,
			'destination_path'     => $theme->resolve( $destination ),
			'target_namespace'     => $theme->pattern( 'block_namespace', 'stwp' ),
			'target_category'      => $theme->pattern( 'block_category', $theme->pattern( 'text_domain', 'st-wp-starter' ) ),
			'required_core_version' => $manifest->requiredCoreVersion(),
			'core_version_satisfied' => $this->versions->satisfies( $theme->coreVersion, $manifest->requiredCoreVersion() ),
			'changes'              => $changes,
			'shared_assets'        => $shared,
			'missing_dependencies' => $missingDependencies,
		);
	}

	/**
	 * Install a block and declared shared assets.
	 *
	 * @return array<string,mixed>
	 */
	public function install(
		ThemeContext $theme,
		BlockManifest $manifest,
		string $destinationSlug,
		bool $replaceSharedAssets = false
	): array {
		$plan        = $this->plan( $theme, $manifest, $destinationSlug );
		$destination = 'blocks/' . $destinationSlug;

		if ( ! $plan['core_version_satisfied'] ) {
			throw new RuntimeException(
				sprintf(
					'Block "%s" requires core %s. Installed core is %s.',
					$manifest->sourceSlug(),
					$manifest->requiredCoreVersion(),
					$theme->coreVersion
				)
			);
		}

		foreach ( $plan['shared_assets'] as $asset ) {
			if ( 'conflict' === $asset['action'] && ! $replaceSharedAssets ) {
				throw new RuntimeException(
					sprintf(
						'Shared asset differs and was not replaced: %s. Re-run with --replace-shared-assets if that is intentional.',
						$asset['path']
					)
				);
			}
		}

		$backup             = $this->backups->create( $theme->path, 'block-' . $destinationSlug );
		$prepared           = $this->prepareBlockFiles( $theme, $manifest, $destinationSlug );
		$preparedThemeFiles = $this->prepareThemeFiles( $theme, $manifest, $destinationSlug );

		$this->backups->backupPath( $theme->path, $backup, $destination );

		foreach ( $this->blockFiles( $manifest ) as $relativePath ) {
			$source = $prepared . DIRECTORY_SEPARATOR . $relativePath;
			$target = $theme->resolve( $destination . '/' . $relativePath );

			$this->filesystem->mkdir( dirname( $target ) );
			$this->filesystem->copy( $source, $target, true );
		}

		foreach ( $this->themeFiles( $manifest ) as $relativePath ) {
			$targetRelative = $this->targetThemeFilePath( $theme, $manifest, $relativePath, $destinationSlug );
			$source         = $preparedThemeFiles . DIRECTORY_SEPARATOR . $targetRelative;
			$target         = $theme->resolve( $targetRelative );

			$this->backups->backupPath( $theme->path, $backup, $targetRelative );
			$this->filesystem->mkdir( dirname( $target ) );
			$this->filesystem->copy( $source, $target, true );
		}

		foreach ( $plan['shared_assets'] as $asset ) {
			if ( 'matching' === $asset['action'] ) {
				continue;
			}

			$this->backups->backupPath( $theme->path, $backup, $asset['path'] );
			$this->filesystem->mkdir( dirname( $theme->resolve( $asset['path'] ) ) );
			$this->filesystem->copy( $asset['source'], $theme->resolve( $asset['path'] ), true );
		}

		$plan['backup_path'] = $backup;

		return $plan;
	}

	/**
	 * Prepare transformed theme-root files in temporary storage.
	 */
	private function prepareThemeFiles( ThemeContext $theme, BlockManifest $manifest, string $destinationSlug ): string {
		$temp = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR )
			. DIRECTORY_SEPARATOR
			. 'st-toolkit-theme-files-' . $destinationSlug . '-' . bin2hex( random_bytes( 6 ) );

		$this->filesystem->mkdir( $temp );

		foreach ( $this->themeFiles( $manifest ) as $relativePath ) {
			$source = $manifest->themeFilesRoot() . DIRECTORY_SEPARATOR . $relativePath;
			$target = $temp . DIRECTORY_SEPARATOR . $this->targetThemeFilePath( $theme, $manifest, $relativePath, $destinationSlug );

			$this->filesystem->mkdir( dirname( $target ) );

			if ( $this->textFiles->isTextFile( $source ) ) {
				$contents = (string) file_get_contents( $source );
				$contents = $this->replacements->applyBlockPatterns( $contents, $manifest->data(), $destinationSlug, $theme->patterns );
				file_put_contents( $target, $contents );
				continue;
			}

			$this->filesystem->copy( $source, $target, true );
		}

		foreach ( glob( $temp . DIRECTORY_SEPARATOR . 'acf-json' . DIRECTORY_SEPARATOR . '*.json' ) ?: array() as $jsonPath ) {
			$this->json->read( $jsonPath );
		}

		$this->phpValidator->validateDirectory( $temp );

		return $temp;
	}

	/**
	 * Transform a theme-root package path for the destination block.
	 */
	private function targetThemeFilePath( ThemeContext $theme, BlockManifest $manifest, string $relativePath, string $destinationSlug ): string {
		$manifestData    = $manifest->data();
		$sourceSlug      = $manifest->sourceSlug();
		$sourceNamespace = (string) ( $manifestData['source_namespace'] ?? 'stwp' );
		$targetNamespace = $theme->pattern( 'block_namespace', $sourceNamespace );
		$sourceSlugSnake = str_replace( '-', '_', $sourceSlug );
		$targetSlugSnake = str_replace( '-', '_', $destinationSlug );
		$originalPath     = $relativePath;

		$relativePath = str_replace(
			$sourceNamespace . '_' . $sourceSlugSnake,
			$targetNamespace . '_' . $targetSlugSnake,
			$relativePath
		);
		$relativePath = str_replace(
			$sourceNamespace . '-' . $sourceSlug,
			$targetNamespace . '-' . $destinationSlug,
			$relativePath
		);

		if ( $relativePath !== $originalPath ) {
			return $relativePath;
		}

		return str_replace( $sourceSlug, $destinationSlug, $relativePath );
	}

	/**
	 * Prepare transformed block files in temporary storage.
	 */
	private function prepareBlockFiles( ThemeContext $theme, BlockManifest $manifest, string $destinationSlug ): string {
		$temp = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR )
			. DIRECTORY_SEPARATOR
			. 'st-toolkit-block-' . $destinationSlug . '-' . bin2hex( random_bytes( 6 ) );

		$this->filesystem->mkdir( $temp );

		foreach ( $this->blockFiles( $manifest ) as $relativePath ) {
			$source = $manifest->filesRoot() . DIRECTORY_SEPARATOR . $relativePath;
			$target = $temp . DIRECTORY_SEPARATOR . $relativePath;

			$this->filesystem->mkdir( dirname( $target ) );

			if ( $this->textFiles->isTextFile( $source ) ) {
				$contents = (string) file_get_contents( $source );
				$contents = $this->replacements->applyBlockPatterns( $contents, $manifest->data(), $destinationSlug, $theme->patterns );
				file_put_contents( $target, $contents );
				continue;
			}

			$this->filesystem->copy( $source, $target, true );
		}

		if ( is_file( $temp . DIRECTORY_SEPARATOR . 'block.json' ) ) {
			$this->json->read( $temp . DIRECTORY_SEPARATOR . 'block.json' );
		}

		$this->phpValidator->validateDirectory( $temp );

		return $temp;
	}

	/**
	 * Validate destination block slug.
	 */
	public function assertDestinationSlug( string $destinationSlug ): void {
		if ( 1 !== preg_match( '/^[a-z0-9-]+$/', $destinationSlug ) ) {
			throw new RuntimeException( 'Destination block slug must match ^[a-z0-9-]+$.' );
		}
	}

	/**
	 * Return block package files relative to the block files root.
	 *
	 * @return string[]
	 */
	private function blockFiles( BlockManifest $manifest ): array {
		$files  = array();
		$finder = Finder::create()->files()->in( $manifest->filesRoot() );

		foreach ( $finder as $file ) {
			$files[] = str_replace( '\\', '/', $file->getRelativePathname() );
		}

		sort( $files );

		return $files;
	}

	/**
	 * Return package files that should be written relative to the theme root.
	 *
	 * @return string[]
	 */
	private function themeFiles( BlockManifest $manifest ): array {
		if ( ! is_dir( $manifest->themeFilesRoot() ) ) {
			return array();
		}

		$files  = array();
		$finder = Finder::create()->files()->in( $manifest->themeFilesRoot() );

		foreach ( $finder as $file ) {
			$files[] = str_replace( '\\', '/', $file->getRelativePathname() );
		}

		sort( $files );

		return $files;
	}

	/**
	 * Plan shared asset writes and conflicts.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function sharedAssetPlan( ThemeContext $theme, BlockManifest $manifest ): array {
		$plan = array();

		foreach ( $manifest->sharedAssets() as $asset ) {
			$assetName = (string) ( $asset['name'] ?? '' );

			foreach ( $asset['files'] ?? array() as $relativePath => $checksum ) {
				$source = $this->paths->resources( 'shared-assets/' . $assetName . '/files/' . $relativePath );
				$target = $theme->resolve( $relativePath );

				$action = 'add';

				if ( is_file( $target ) ) {
					$action = hash_file( 'sha256', $target ) === $checksum ? 'matching' : 'conflict';
				}

				$plan[] = array(
					'name'     => $assetName,
					'path'     => (string) $relativePath,
					'source'   => $source,
					'action'   => $action,
					'checksum' => (string) $checksum,
				);
			}
		}

		return $plan;
	}
}

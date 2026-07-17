<?php
/**
 * Block installer.
 *
 * Installs a curated block into a namespace-prefixed directory, for example
 * <theme>/blocks/<namespace>-hero-slider. Shared assets are managed separately so
 * packages like Splide can be reused by multiple blocks.
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
	/**
	 * Temporary block directories created during this service lifetime.
	 *
	 * @var string[]
	 */
	private array $temporaryDirectories = array();

	public function __construct(
		private readonly Filesystem $filesystem = new Filesystem(),
		private readonly ReplacementEngine $replacements = new ReplacementEngine(),
		private readonly TextFiles $textFiles = new TextFiles(),
		private readonly BackupManager $backups = new BackupManager(),
		private readonly PackagePaths $paths = new PackagePaths(),
		private readonly PackageJsonInspector $packageJson = new PackageJsonInspector(),
		private readonly NpmRunner $npm = new NpmRunner(),
		private readonly PhpValidator $phpValidator = new PhpValidator(),
		private readonly JsonFile $json = new JsonFile(),
		private readonly VersionConstraint $versions = new VersionConstraint()
	) {}

	/**
	 * Remove transformed package files after the installer is released.
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
	 * Create a dry-run install plan.
	 *
	 * @return array<string,mixed>
	 */
	public function plan( ThemeContext $theme, BlockManifest $manifest, string $destinationSlug ): array {
		$this->assertDestinationSlug( $destinationSlug );

		$destination = $this->targetBlockDirectory( $theme, $destinationSlug );
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
			'npm_install_command'  => array() === $missingDependencies ? '' : $this->npm->installCommand( $missingDependencies ),
			'build_command'        => $this->npm->buildCommand(),
			'build_required'       => true,
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
		$destination = $this->targetBlockDirectory( $theme, $destinationSlug );

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
		$this->temporaryDirectories[] = $temp;

		foreach ( $this->themeFiles( $manifest ) as $relativePath ) {
			$source = $manifest->themeFilesRoot() . DIRECTORY_SEPARATOR . $relativePath;
			$target = $temp . DIRECTORY_SEPARATOR . $this->targetThemeFilePath( $theme, $manifest, $relativePath, $destinationSlug );

			$this->filesystem->mkdir( dirname( $target ) );

			if ( $this->textFiles->isTextFile( $source ) ) {
				$contents = (string) file_get_contents( $source );
				$contents = $this->replacements->applyBlockPatterns( $contents, $manifest->data(), $destinationSlug, $theme->patterns );
				if ( $this->isAcfJsonPath( $relativePath ) ) {
					$contents = $this->generateAcfJsonKeys( $contents, $theme, $destinationSlug );
				}
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

		if ( $this->isAcfJsonPath( $relativePath ) ) {
			$sourceGroupKey = pathinfo( $relativePath, PATHINFO_FILENAME );
			if ( str_starts_with( $sourceGroupKey, 'group_' ) ) {
				$targetGroupKey = $this->replacements->applyBlockPatterns( $sourceGroupKey, $manifestData, $destinationSlug, $theme->patterns );

				return dirname( $relativePath ) . '/' . $this->generateAcfKey( $targetGroupKey, $theme, $destinationSlug ) . '.json';
			}
		}

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
	 * Build the destination directory used for a block package install.
	 */
	private function targetBlockDirectory( ThemeContext $theme, string $destinationSlug ): string {
		$namespace = trim( $theme->pattern( 'block_namespace', 'stwp' ) );
		$directory = $namespace ? $namespace . '-' . $destinationSlug : $destinationSlug;

		return 'blocks/' . $directory;
	}

	/**
	 * Check whether a package theme file is an ACF local JSON field group.
	 */
	private function isAcfJsonPath( string $relativePath ): bool {
		return str_starts_with( $relativePath, 'acf-json/' ) && str_ends_with( $relativePath, '.json' );
	}

	/**
	 * Rewrite symbolic ACF source keys to deterministic ACF-style keys.
	 *
	 * The package keeps readable source keys such as `field_stwp_slider_title`.
	 * Installed blocks receive stable keys shaped like ACF Pro output, such as
	 * `field_a1b2c3d4e5f67`. Reinstalling the same destination slug generates
	 * the same keys, which keeps saved field references stable.
	 */
	private function generateAcfJsonKeys( string $contents, ThemeContext $theme, string $destinationSlug ): string {
		$data = json_decode( $contents, true );

		if ( ! is_array( $data ) ) {
			return $contents;
		}

		$keys = array();
		$this->collectAcfKeys( $data, $keys );

		if ( array() === $keys ) {
			return $contents;
		}

		$map = array();

		foreach ( array_unique( $keys ) as $key ) {
			$map[ $key ] = $this->generateAcfKey( $key, $theme, $destinationSlug );
		}

		$data = $this->replaceAcfKeyReferences( $data, $map );

		return json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
	}

	/**
	 * Collect ACF group and field keys from decoded local JSON.
	 *
	 * @param mixed    $value Decoded JSON node.
	 * @param string[] $keys  Collected ACF keys.
	 */
	private function collectAcfKeys( mixed $value, array &$keys ): void {
		if ( is_string( $value ) && 1 === preg_match( '/^(?:group|field)_[A-Za-z0-9_-]+$/', $value ) ) {
			$keys[] = $value;
			return;
		}

		if ( ! is_array( $value ) ) {
			return;
		}

		foreach ( $value as $child ) {
			$this->collectAcfKeys( $child, $keys );
		}
	}

	/**
	 * Replace ACF key values anywhere they are referenced in local JSON.
	 *
	 * @param mixed                $value Decoded JSON node.
	 * @param array<string,string> $map   Source key to generated key map.
	 * @return mixed
	 */
	private function replaceAcfKeyReferences( mixed $value, array $map ): mixed {
		if ( is_string( $value ) ) {
			return $map[ $value ] ?? $value;
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		foreach ( $value as $key => $child ) {
			$value[ $key ] = $this->replaceAcfKeyReferences( $child, $map );
		}

		return $value;
	}

	/**
	 * Generate a stable ACF-style key for an installed block destination.
	 */
	private function generateAcfKey( string $sourceKey, ThemeContext $theme, string $destinationSlug ): string {
		$prefix    = str_starts_with( $sourceKey, 'group_' ) ? 'group_' : 'field_';
		$namespace = $theme->pattern( 'block_namespace', 'stwp' );
		$seed      = $namespace . '/' . $destinationSlug . '|' . $sourceKey;

		return $prefix . substr( sha1( $seed ), 0, 13 );
	}

	/**
	 * Prepare transformed block files in temporary storage.
	 */
	private function prepareBlockFiles( ThemeContext $theme, BlockManifest $manifest, string $destinationSlug ): string {
		$temp = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR )
			. DIRECTORY_SEPARATOR
			. 'st-toolkit-block-' . $destinationSlug . '-' . bin2hex( random_bytes( 6 ) );

		$this->filesystem->mkdir( $temp );
		$this->temporaryDirectories[] = $temp;

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

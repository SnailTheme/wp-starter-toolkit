<?php
/**
 * Atomic fixed-member bundle planning and file-scoped recovery.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Block;

use RuntimeException;
use SnailTheme\WPStarterToolkit\Support\BackupManager;
use SnailTheme\WPStarterToolkit\Support\HelperInspector;
use SnailTheme\WPStarterToolkit\Support\JsonFile;
use SnailTheme\WPStarterToolkit\Support\PhpValidator;
use SnailTheme\WPStarterToolkit\Support\TextFiles;
use SnailTheme\WPStarterToolkit\Support\VersionConstraint;
use SnailTheme\WPStarterToolkit\Theme\ThemeContext;
use Symfony\Component\Filesystem\Filesystem;

final class BlockBundleInstaller {
	public function __construct(
		private readonly BlockInstaller $blocks = new BlockInstaller(),
		private readonly Filesystem $filesystem = new Filesystem(),
		private readonly BackupManager $backups = new BackupManager(),
		private readonly NpmRunner $npm = new NpmRunner(),
		private readonly JsonFile $json = new JsonFile(),
		private readonly VersionConstraint $versions = new VersionConstraint(),
		private readonly HelperInspector $helpers = new HelperInspector(),
		private readonly TextFiles $textFiles = new TextFiles(),
		private readonly PhpValidator $phpValidator = new PhpValidator()
	) {}

	/**
	 * Stage and validate every member before any target-theme mutation.
	 */
	public function plan( ThemeContext $theme, BlockBundleManifest $bundle ): BlockBundlePlan {
		$rootIdentity = BlockBundlePaths::rootIdentity( $theme );
		$inputHashes = $this->inputHashes( $theme );
		$namespace = $theme->pattern( 'block_namespace', 'stwp' );
		if ( 1 !== preg_match( '/^[a-z][a-z0-9-]*$/', $namespace ) ) {
			throw new RuntimeException( 'Unsafe bundle block namespace: ' . $namespace );
		}
		$this->assertCore( $theme, $bundle->sourceSlug(), $bundle->requiredCoreVersion() );
		$members = $bundle->members();
		$names = array_map( static fn ( BlockManifest $member ): string => $namespace . '/' . $member->sourceSlug(), $members );
		$files = array();

		foreach ( $members as $member ) {
			$this->assertCore( $theme, $member->sourceSlug(), $member->requiredCoreVersion() );
			$missing = $this->helpers->missing( $theme, $member->requiredHelpers() );
			if ( array() !== $missing ) {
				throw new RuntimeException( 'Missing required bundle PHP helpers: ' . implode( ', ', $missing ) );
			}
			$prepared = $this->blocks->stageBundleMember( $theme, $member );
			$this->validateMember( $theme, $member, $prepared, $names );
			foreach ( $prepared as $relative => $source ) {
				BlockBundlePaths::assertTarget( $theme, $relative );
				if ( isset( $files[ $relative ] ) ) {
					throw new RuntimeException( 'Bundle members write the same target: ' . $relative );
				}
				$files[ $relative ] = $source;
			}
		}
		$settingsOwner = new BlockBundleThemeSettings();
		$settingsSource = $settingsOwner->stage( $theme, $bundle );
		if ( null !== $settingsSource ) {
			if ( isset( $files['theme.json'] ) ) {
				throw new RuntimeException( 'Bundle members write the same target: theme.json' );
			}
			$files['theme.json'] = $settingsSource;
		}
		$this->assertBackupTarget( $theme );
		ksort( $files );
		$changes = $conflicts = $previous = array();
		foreach ( $files as $relative => $source ) {
			$target = $theme->resolve( $relative );
			BlockBundlePaths::assertTarget( $theme, $relative );
			$previous[ $relative ] = BlockBundlePaths::fileHash( $target );
			if ( 'theme.json' === $relative && $previous[ $relative ] !== $settingsOwner->previousHash( $source ) ) {
				throw new RuntimeException( 'Target theme.json changed while staging; run again.' );
			}
			$checksum = BlockBundlePaths::fileHash( $source );
			if ( null === $checksum ) {
				throw new RuntimeException( 'Missing staged bundle file: ' . $relative );
			}
			$action = null === $previous[ $relative ] ? 'add'
				: ( $checksum === $previous[ $relative ] ? 'unchanged' : 'replace' );
			$changes[] = array( 'path' => $relative, 'action' => $action, 'checksum' => $checksum );
			if ( 'replace' === $action ) {
				$conflicts[] = $relative;
			}
		}
		if ( $rootIdentity !== BlockBundlePaths::rootIdentity( $theme ) || $inputHashes !== $this->inputHashes( $theme ) ) {
			throw new RuntimeException( 'Theme configuration changed while staging; run again.' );
		}

		return new BlockBundlePlan(
			$theme,
			array(
				'type' => 'bundle',
				'source_block' => $bundle->sourceSlug(),
				'members' => $bundle->memberSlugs(),
				'destination_path' => $theme->path,
				'target_namespace' => $namespace,
				'target_category' => $theme->pattern( 'block_category', $theme->pattern( 'text_domain', 'st-wp-starter' ) ),
				'required_core_version' => $bundle->requiredCoreVersion(),
				'core_version_satisfied' => true,
				'changes' => $changes,
				'conflicts' => $conflicts,
				'shared_assets' => array(),
				'missing_dependencies' => array(),
				'build_command' => $this->npm->buildCommand(),
				'build_required' => true,
			),
			$files,
			$previous,
			$this->blocks,
			$settingsOwner,
			$inputHashes,
			$rootIdentity
		);
	}

	/**
	 * Apply the confirmed staged plan, with one backup and one final build.
	 * Build failures deliberately retain installed files for diagnosis/rebuilding.
	 *
	 * @return array<string,mixed>
	 */
	public function install( BlockBundlePlan $plan ): array {
		$theme = $plan->theme;
		$this->assertInputs( $plan );
		$this->npm->assertBuildAvailable( $theme );
		$this->assertBackupTarget( $theme );
		$this->assertPlanFiles( $plan );
		$lock = $this->acquireLock( $plan );
		try {
			$this->assertInputs( $plan );
			$this->assertPlanFiles( $plan );
			return $this->applyPlan( $plan );
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	/** @return array<string,mixed> */
	private function applyPlan( BlockBundlePlan $plan ): array {
		$theme = $plan->theme;
		$result = $plan->toArray();
		$backup = $this->backups->create( $theme->path, 'block-bundle-' . $result['source_block'] );
		$result['backup_path'] = $backup;
		$writes = array_filter( $result['changes'], static fn ( array $change ): bool => 'unchanged' !== $change['action'] );
		$attempted = array();
		try {
			// Verify the complete backup before attempting any member write. A
			// changed preimage must not become an unconfirmed replacement/rollback.
			foreach ( $writes as $change ) {
				$relative = $change['path'];
				$this->assertTargetState( $plan, $relative, $plan->previousHashes[ $relative ] );
				$this->backups->backupPath( $theme->path, $backup, $relative );
			}
			$this->assertInputs( $plan );
			$this->assertPlanFiles( $plan );
			foreach ( $writes as $change ) {
				$relative = $change['path'];
				$backupFile = $this->backupFile( $plan, $backup, $relative );
				if ( BlockBundlePaths::fileHash( $backupFile ) !== $plan->previousHashes[ $relative ] ) {
					throw new RuntimeException( 'Bundle backup changed after confirmation; run again: ' . $relative );
				}
			}
			foreach ( $writes as $change ) {
				$relative = $change['path'];
				$this->replaceFile(
					$plan, $relative, $plan->files[ $relative ], $plan->previousHashes[ $relative ], $change['checksum'],
					static function () use ( &$attempted, $relative, $change ): void {
						$attempted[ $relative ] = $change;
					}
				);
			}
			$this->assertInputs( $plan );
			foreach ( $result['changes'] as $change ) {
				$this->assertTargetState( $plan, $change['path'], $change['checksum'] );
			}
		} catch ( \Throwable $exception ) {
			$errors = $this->recover( $plan, $backup, $attempted );
			if ( array() !== $errors ) {
				throw new RuntimeException( 'Bundle write and recovery failed. Backup: ' . $backup . '. ' . implode( '; ', $errors ), 0, $exception );
			}
			throw new RuntimeException( 'Bundle write failed; owned files restored. Backup: ' . $backup . '. ' . $exception->getMessage(), 0, $exception );
		}

		try {
			$result['build'] = $this->npm->build( $theme );
		} catch ( \Throwable $exception ) {
			$result['build'] = array(
				'status' => 'failed',
				'command' => $this->npm->buildCommand(),
				'message' => $exception->getMessage(),
			);
			$result['recovery'] = 'Fix the build issue, then run: ' . $this->npm->buildCommand();
		}
		return $result;
	}

	/**
	 * Copy into a sibling temporary file, then rename. Never truncate the target
	 * inode: it may be hard-linked outside the theme, or a copy may fail midway.
	 */
	private function replaceFile( BlockBundlePlan $plan, string $relative, string $source, ?string $previous, string $checksum, callable $beforeRename, bool $recovering = false ): void {
		$this->assertRoot( $plan );
		$this->assertTargetState( $plan, $relative, $previous );
		$target = $plan->theme->resolve( $relative );
		$this->filesystem->mkdir( dirname( $target ) );
		$this->assertTargetState( $plan, $relative, $previous );
		$temp = $this->filesystem->tempnam( dirname( $target ), '.st-toolkit-bundle-' );
		try {
			if ( BlockBundlePaths::fileHash( $source ) !== $checksum ) {
				throw new RuntimeException( 'Bundle source changed after confirmation: ' . $relative );
			}
			$this->filesystem->copy( $source, $temp, true );
			if ( BlockBundlePaths::fileHash( $temp ) !== $checksum ) {
				throw new RuntimeException( 'Incomplete staged bundle write: ' . $relative );
			}
			$permissions = @fileperms( null === $previous ? $source : $target );
			if ( false === $permissions ) {
				throw new RuntimeException( 'Could not read bundle file permissions: ' . $relative );
			}
			$this->filesystem->chmod( $temp, $permissions & 0777 );
			if ( $recovering ) {
				$this->assertRoot( $plan );
			} else {
				$this->assertInputs( $plan );
			}
			$this->assertTargetState( $plan, $relative, $previous );
			$beforeRename();
			$this->filesystem->rename( $temp, $target, true );
		} finally {
			// unlink cannot recursively delete a directory substituted by a racer.
			if ( is_file( $temp ) || is_link( $temp ) ) {
				@unlink( $temp );
			}
		}
	}

	/**
	 * @param array<string,array<string,mixed>> $attempted Actually attempted writes.
	 * @return string[] Recovery conflicts/errors; other paths still get restored.
	 */
	private function recover( BlockBundlePlan $plan, string $backup, array $attempted ): array {
		$errors = array();
		foreach ( array_reverse( $attempted, true ) as $relative => $change ) {
			try {
				$this->assertRoot( $plan );
				BlockBundlePaths::assertTarget( $plan->theme, $relative );
				$current = BlockBundlePaths::fileHash( $plan->theme->resolve( $relative ) );
				$previous = $plan->previousHashes[ $relative ];
				if ( $current === $previous ) {
					continue;
				}
				if ( $current !== $change['checksum'] ) {
					throw new RuntimeException( 'Concurrent edit preserved during recovery: ' . $relative );
				}
				if ( null === $previous ) {
					if ( ! @unlink( $plan->theme->resolve( $relative ) ) ) {
						throw new RuntimeException( 'Could not remove new bundle file: ' . $relative );
					}
				} else {
					$this->replaceFile( $plan, $relative, $this->backupFile( $plan, $backup, $relative ), $current, $previous, static function (): void {}, true );
				}
			} catch ( \Throwable $exception ) {
				$errors[] = $exception->getMessage();
			}
		}
		return $errors;
	}

	private function backupFile( BlockBundlePlan $plan, string $backup, string $relative ): string {
		$path = str_replace( '\\', '/', substr( $backup, strlen( $plan->theme->path ) + 1 ) ) . '/' . $relative;
		BlockBundlePaths::assertTarget( $plan->theme, $path );
		return $plan->theme->resolve( $path );
	}

	/** @return array<string,?string> Configuration used by staging and build. */
	private function inputHashes( ThemeContext $theme ): array {
		return array(
			$theme->bootstrapPath => BlockBundlePaths::fileHash( $theme->bootstrapPath ),
			$theme->resolve( 'package.json' ) => BlockBundlePaths::fileHash( $theme->resolve( 'package.json' ) ),
		);
	}

	private function assertRoot( BlockBundlePlan $plan ): void {
		if ( null !== $plan->rootIdentity && $plan->rootIdentity !== BlockBundlePaths::rootIdentity( $plan->theme ) ) {
			throw new RuntimeException( 'Theme root changed after confirmation; run again.' );
		}
	}

	private function assertInputs( BlockBundlePlan $plan ): void {
		$this->assertRoot( $plan );
		foreach ( $plan->inputHashes as $path => $checksum ) {
			if ( BlockBundlePaths::fileHash( $path ) !== $checksum ) {
				throw new RuntimeException( 'Theme configuration changed after confirmation; run again: ' . $path );
			}
		}
	}

	private function assertTargetState( BlockBundlePlan $plan, string $relative, ?string $checksum ): void {
		$this->assertRoot( $plan );
		BlockBundlePaths::assertTarget( $plan->theme, $relative );
		if ( BlockBundlePaths::fileHash( $plan->theme->resolve( $relative ) ) !== $checksum ) {
			throw new RuntimeException( 'Bundle target changed after confirmation; run again: ' . $relative );
		}
	}

	private function assertPlanFiles( BlockBundlePlan $plan ): void {
		foreach ( $plan->toArray()['changes'] as $change ) {
			$relative = $change['path'];
			$this->assertTargetState( $plan, $relative, $plan->previousHashes[ $relative ] );
			if ( BlockBundlePaths::fileHash( $plan->files[ $relative ] ) !== $change['checksum'] ) {
				throw new RuntimeException( 'Bundle source changed after confirmation; run again: ' . $relative );
			}
		}
	}

	/** @return resource Held through the build; the lock file is never unlinked. */
	private function acquireLock( BlockBundlePlan $plan ) {
		$theme = $plan->theme;
		$relative = '.st-toolkit/block-bundle.lock';
		$this->filesystem->mkdir( $theme->resolve( '.st-toolkit' ) );
		$this->assertInputs( $plan );
		BlockBundlePaths::assertTarget( $theme, $relative );
		$lock = @fopen( $theme->resolve( $relative ), 'c+' );
		if ( false === $lock ) {
			throw new RuntimeException( 'Could not open bundle installation lock.' );
		}
		try {
			if ( ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
				throw new RuntimeException( 'Another bundle installation is running for this theme.' );
			}
			BlockBundlePaths::assertTarget( $theme, $relative );
			$opened = fstat( $lock );
			$current = @stat( $theme->resolve( $relative ) );
			if ( false === $opened || false === $current || $opened['ino'] !== $current['ino'] || $opened['dev'] !== $current['dev'] || 1 !== $opened['nlink'] ) {
				throw new RuntimeException( 'Unsafe bundle installation lock.' );
			}
			return $lock;
		} catch ( \Throwable $exception ) {
			fclose( $lock );
			throw $exception;
		}
	}

	private function assertCore( ThemeContext $theme, string $slug, string $constraint ): void {
		if ( ! $this->versions->satisfies( $theme->coreVersion, $constraint ) ) {
			throw new RuntimeException( sprintf( 'Bundle/member "%s" requires core %s. Installed core is %s. Update core explicitly before installing.', $slug, $constraint, $theme->coreVersion ) );
		}
	}

	private function assertBackupTarget( ThemeContext $theme ): void {
		BlockBundlePaths::assertTarget( $theme, '.st-toolkit/backups/.gitignore' );
		BlockBundlePaths::assertTarget( $theme, '.st-toolkit/block-bundle.lock' );
	}

	/**
	 * Validate metadata, file references, and fixed-member references in templates.
	 *
	 * @param array<string,string> $files Prepared files.
	 * @param string[] $names Fully qualified installed member names.
	 */
	private function validateMember( ThemeContext $theme, BlockManifest $member, array $files, array $names ): void {
		$namespace = $theme->pattern( 'block_namespace', 'stwp' );
		$root = 'blocks/' . $namespace . '-' . $member->sourceSlug() . '/';
		$metadata = $this->json->read( $files[ $root . 'block.json' ] ?? '' );
		if ( $namespace . '/' . $member->sourceSlug() !== ( $metadata['name'] ?? null ) ) {
			throw new RuntimeException( 'Bundle block metadata name does not match member: ' . $member->sourceSlug() );
		}
		$this->validateMetadataFiles( $metadata, $root, $files );
		foreach ( $files as $relative => $source ) {
			if ( 'php' === strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) ) ) {
				$this->phpValidator->validateFile( $source );
			}
			if ( str_ends_with( $relative, '.json' ) ) {
				$this->json->read( $source );
			}
			if ( ! $this->textFiles->isTextFile( $source ) ) {
				continue;
			}
			$contents = str_replace( '\\/', '/', (string) file_get_contents( $source ) );
			preg_match_all( '#(?<![A-Za-z0-9_-])([a-z0-9_-]+/el-[a-z0-9-]+)#', $contents, $references );
			foreach ( $references[1] as $reference ) {
				if ( ! in_array( $reference, $names, true ) ) {
					throw new RuntimeException( 'Invalid internal bundle block reference: ' . $reference . ' in ' . $relative );
				}
			}
			// PHP templates also form names with "{$namespace}/el-column".
			// Their fixed slug still has to resolve to a member of this same pack.
			preg_match_all( '#/(el-[a-z0-9-]+)#', $contents, $slugs );
			foreach ( $slugs[1] as $slug ) {
				if ( ! in_array( $namespace . '/' . $slug, $names, true ) ) {
					throw new RuntimeException( 'Invalid internal bundle template reference: ' . $slug . ' in ' . $relative );
				}
			}
		}
	}

	/** @param array<string,string> $files Prepared files. */
	private function validateMetadataFiles( mixed $value, string $root, array $files, string $key = '' ): void {
		if ( is_array( $value ) ) {
			foreach ( $value as $childKey => $child ) {
				$this->validateMetadataFiles( $child, $root, $files, is_string( $childKey ) ? $childKey : $key );
			}
			return;
		}
		if ( ! is_string( $value ) || ( ! str_starts_with( $value, 'file:' ) && 'renderTemplate' !== $key ) ) {
			return;
		}
		$relative = str_starts_with( $value, 'file:' ) ? substr( $value, 5 ) : $value;
		if ( str_starts_with( $relative, './' ) ) {
			$relative = substr( $relative, 2 );
		}
		BlockBundlePaths::assertRelative( $relative );
		if ( ! isset( $files[ $root . $relative ] ) ) {
			throw new RuntimeException( 'Missing bundle metadata file reference: ' . $root . $relative );
		}
		if ( 'renderTemplate' === $key ) {
			$this->phpValidator->validateFile( $files[ $root . $relative ] );
		}
	}
}

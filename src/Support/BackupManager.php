<?php
/**
 * Backup manager.
 *
 * The toolkit writes backups inside the target theme so rollback does not rely
 * on a machine-specific temp directory. The .st-toolkit directory is meant to
 * stay out of version control.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Support;

use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Creates and restores toolkit backups.
 */
final class BackupManager {
	public function __construct(
		private readonly Filesystem $filesystem = new Filesystem()
	) {}

	/**
	 * Create a named backup directory.
	 */
	public function create( string $themePath, string $type ): string {
		$root = $this->backupRoot( $themePath );
		$this->filesystem->mkdir( $root );
		$this->filesystem->dumpFile( $root . DIRECTORY_SEPARATOR . '.gitignore', '*' . PHP_EOL );

		$backup = $root . DIRECTORY_SEPARATOR . gmdate( 'Ymd-His' ) . '-' . $type . '-' . bin2hex( random_bytes( 3 ) );
		$this->filesystem->mkdir( $backup );

		return $backup;
	}

	/**
	 * Backup a relative file or directory if it currently exists.
	 */
	public function backupPath( string $themePath, string $backupPath, string $relativePath ): void {
		$source = $themePath . DIRECTORY_SEPARATOR . ltrim( $relativePath, '/\\' );

		if ( ! file_exists( $source ) ) {
			return;
		}

		$target = $backupPath . DIRECTORY_SEPARATOR . ltrim( $relativePath, '/\\' );
		$this->filesystem->mkdir( dirname( $target ) );

		if ( is_dir( $source ) ) {
			$this->filesystem->mirror( $source, $target, null, array( 'override' => true ) );
			return;
		}

		$this->filesystem->copy( $source, $target, true );
	}

	/**
	 * Restore the latest backup, or a named backup directory.
	 */
	public function restoreLatest( string $themePath, ?string $backup = null, ?string $type = null ): string {
		$backupPath = $backup ?: $this->latest( $themePath, $type );

		if ( null === $backupPath || ! is_dir( $backupPath ) ) {
			throw new RuntimeException( 'No toolkit backup found for rollback.' );
		}

		$finder = Finder::create()
			->files()
			->ignoreDotFiles( false )
			->notName( '.gitignore' )
			->in( $backupPath );

		foreach ( $finder as $file ) {
			$relative = $file->getRelativePathname();
			$target   = $themePath . DIRECTORY_SEPARATOR . $relative;

			$this->filesystem->mkdir( dirname( $target ) );
			$this->filesystem->copy( $file->getPathname(), $target, true );
		}

		return $backupPath;
	}

	/**
	 * Return the latest backup directory path.
	 */
	public function latest( string $themePath, ?string $type = null ): ?string {
		$root = $this->backupRoot( $themePath );

		if ( ! is_dir( $root ) ) {
			return null;
		}

		$backups = array_filter(
			glob( $root . DIRECTORY_SEPARATOR . '*' ) ?: array(),
			'is_dir'
		);

		if ( null !== $type ) {
			$backups = array_filter(
				$backups,
				static fn ( string $backup ): bool => str_contains( basename( $backup ), '-' . $type . '-' )
			);
		}

		rsort( $backups );

		return $backups[0] ?? null;
	}

	/**
	 * Resolve the backup root path.
	 */
	public function backupRoot( string $themePath ): string {
		return $themePath . DIRECTORY_SEPARATOR . '.st-toolkit' . DIRECTORY_SEPARATOR . 'backups';
	}
}

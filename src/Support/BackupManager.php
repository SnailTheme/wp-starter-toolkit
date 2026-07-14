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
	private const METADATA_FILE = '.st-toolkit-backup.json';

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
		$this->writeMetadata( $backup, array( 'absent_paths' => array() ) );

		return $backup;
	}

	/**
	 * Backup a relative file or directory if it currently exists.
	 */
	public function backupPath( string $themePath, string $backupPath, string $relativePath ): void {
		$relativePath = $this->normalizeRelativePath( $relativePath );
		$source       = $themePath . DIRECTORY_SEPARATOR . $relativePath;

		if ( ! file_exists( $source ) ) {
			$this->recordAbsentPath( $backupPath, $relativePath );
			return;
		}

		$target = $backupPath . DIRECTORY_SEPARATOR . $relativePath;
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

		$metadata = $this->readMetadata( $backupPath );

		foreach ( $metadata['absent_paths'] as $relativePath ) {
			$this->filesystem->remove(
				$themePath . DIRECTORY_SEPARATOR . $this->normalizeRelativePath( $relativePath )
			);
		}

		$finder = Finder::create()
			->files()
			->ignoreDotFiles( false )
			->notName( '.gitignore' )
			->notName( self::METADATA_FILE )
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

	/**
	 * Record a path that did not exist when the backup was created.
	 *
	 * Rollback removes these paths before restoring files. This makes rollback
	 * exact when an update adds a new package-owned file.
	 */
	private function recordAbsentPath( string $backupPath, string $relativePath ): void {
		$metadata = $this->readMetadata( $backupPath );

		if ( ! in_array( $relativePath, $metadata['absent_paths'], true ) ) {
			$metadata['absent_paths'][] = $relativePath;
			sort( $metadata['absent_paths'] );
			$this->writeMetadata( $backupPath, $metadata );
		}
	}

	/**
	 * Read backup metadata, including compatibility with pre-metadata backups.
	 *
	 * @return array{absent_paths:string[]}
	 */
	private function readMetadata( string $backupPath ): array {
		$metadataPath = $backupPath . DIRECTORY_SEPARATOR . self::METADATA_FILE;

		if ( ! is_file( $metadataPath ) ) {
			return array( 'absent_paths' => array() );
		}

		$data = json_decode( (string) file_get_contents( $metadataPath ), true );

		if ( ! is_array( $data ) || ! isset( $data['absent_paths'] ) || ! is_array( $data['absent_paths'] ) ) {
			throw new RuntimeException( sprintf( 'Invalid toolkit backup metadata: %s', $metadataPath ) );
		}

		return array(
			'absent_paths' => array_values(
				array_filter( $data['absent_paths'], 'is_string' )
			),
		);
	}

	/**
	 * Persist backup metadata without relying on platform-specific shell tools.
	 *
	 * @param array{absent_paths:string[]} $metadata Backup state metadata.
	 */
	private function writeMetadata( string $backupPath, array $metadata ): void {
		$encoded = json_encode( $metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		if ( false === $encoded ) {
			throw new RuntimeException( 'Could not encode toolkit backup metadata.' );
		}

		$this->filesystem->dumpFile(
			$backupPath . DIRECTORY_SEPARATOR . self::METADATA_FILE,
			$encoded . PHP_EOL
		);
	}

	/**
	 * Normalize and validate a theme-relative backup path.
	 */
	private function normalizeRelativePath( string $relativePath ): string {
		$relativePath = str_replace( '\\', '/', ltrim( $relativePath, '/\\' ) );
		$parts        = explode( '/', $relativePath );

		if ( '' === $relativePath || in_array( '..', $parts, true ) ) {
			throw new RuntimeException( sprintf( 'Unsafe toolkit backup path: %s', $relativePath ) );
		}

		return str_replace( '/', DIRECTORY_SEPARATOR, $relativePath );
	}
}

<?php
/**
 * Explicit, source-only workbench exports for package maintainers.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\UI;

use RuntimeException;
use SnailTheme\WPStarterToolkit\Support\BootstrapParser;
use SnailTheme\WPStarterToolkit\Support\PackagePaths;
use SnailTheme\WPStarterToolkit\Support\ReplacementEngine;
use SnailTheme\WPStarterToolkit\Theme\ThemeContext;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Never discovers ownership from client directories or an installation receipt.
 * The packaged checksum map is the explicit export allowlist.
 */
final class UIProfileMaintainer {
	private readonly string $profilesRoot;

	public function __construct(
		?string $profilesRoot = null,
		private readonly Filesystem $filesystem = new Filesystem()
	) {
		$this->profilesRoot = $profilesRoot ?? ( new PackagePaths() )->resources( 'ui-profiles' );
	}

	/**
	 * Validate all inputs and return a read-only drift report.
	 *
	 * @return array<string,mixed>
	 */
	public function plan( ThemeContext $theme, string $slug, bool $export = false ): array {
		if ( ! preg_match( '/^[a-z][a-z0-9-]*$/D', $slug ) ) {
			throw new RuntimeException( 'Invalid UI profile slug.' );
		}

		$root = $this->profilesRoot . '/' . $slug;
		$this->assertDirectory( $root );
		$this->assertDirectory( $theme->path );
		$this->assertDirectory( $root . '/files' );
		$parser = new BootstrapParser();
		$bootstrap = $this->file( $theme->path, 'core/bootstrap.php' );
		if ( $parser->parseThemePatterns( $bootstrap ) !== $theme->patterns ) {
			throw new RuntimeException( 'Workbench bootstrap changed; detect the theme again before exporting.' );
		}
		$package = realpath( $root );
		$source  = realpath( $theme->path );
		if ( $source === $package || str_starts_with( $source . '/', $package . '/' ) || str_starts_with( $package . '/', $source . '/' ) ) {
			throw new RuntimeException( 'Workbench and packaged profile directories must not overlap.' );
		}
		$backupRoot = dirname( $this->profilesRoot, 2 ) . '/.st-toolkit/backups';
		if ( str_starts_with( $backupRoot . '/', $source . '/' ) ) {
			throw new RuntimeException( 'Package backups must not be created inside the source workbench.' );
		}

		$manifestPath = $this->file( $root, 'st-ui.json' );
		$manifest     = $this->readJson( $manifestPath );
		if ( ( $manifest['slug'] ?? null ) !== $slug || ! is_string( $manifest['version'] ?? null ) ) {
			throw new RuntimeException( 'Profile manifest identity is invalid.' );
		}
		$declared = $manifest['checksums'] ?? null;
		if ( ! $declared instanceof \stdClass || array() === get_object_vars( $declared ) ) {
			throw new RuntimeException( 'Profile needs an explicit, non-empty checksums allowlist.' );
		}
		$checksums = get_object_vars( $declared );

		$receiptPath = $this->file( $theme->path, UIProfileInstaller::STATE_FILE );
		$receipt     = $this->readJson( $receiptPath );
		if ( ( $receipt['ui_profile'] ?? null ) !== $slug ) {
			throw new RuntimeException( 'Workbench UI profile does not match the requested profile.' );
		}
		foreach ( array( 'css_entries', 'vite_plugins' ) as $key ) {
			if ( $this->listSetting( $receipt, $key ) !== $this->listSetting( $manifest, $key ) ) {
				throw new RuntimeException( 'Workbench build configuration differs from the profile manifest: ' . $key );
			}
		}
		$marker = $this->read( $this->file( $theme->path, 'assets/scss/_st-toolkit-profile.scss' ) );
		if ( ! ( new UIProfileMarker() )->matches( $marker, $slug ) ) {
			throw new RuntimeException( 'Workbench Sass profile marker disagrees with its receipt.' );
		}
		if ( $export ) {
			$canonical = ( new BootstrapParser() )->parseThemePatterns( ( new PackagePaths() )->resources( 'core/files/core/bootstrap.php' ) );
			foreach ( $canonical as $key => $value ) {
				if ( $theme->pattern( $key, $value ) !== $value ) {
					throw new RuntimeException( 'Export requires a starter-identity workbench; customized pattern: ' . $key );
				}
			}
		}

		$changes = array();
		$updated = array();
		ksort( $checksums );
		foreach ( $checksums as $path => $checksum ) {
			$this->assertOwnedPath( $path );
			if ( ! is_string( $checksum ) || ! preg_match( '/^[a-f0-9]{64}$/D', $checksum ) ) {
				throw new RuntimeException( 'Invalid profile checksum: ' . $path );
			}
			$target   = $this->file( $root . '/files', $path, false );
			$existing = is_file( $target ) ? $this->read( $target ) : null;
			$local    = $this->file( $theme->path, $path, false );
			$contents = is_file( $local ) ? $this->read( $local ) : null;
			if ( $export && null === $contents ) {
				throw new RuntimeException( 'Missing workbench profile file: ' . $path );
			}
			$expected = $export || null === $existing ? $existing : ( new ReplacementEngine() )->applyThemePatterns( $existing, $theme->patterns );
			$changes[] = array(
				'path' => $path,
				'action' => null === $contents ? 'missing' : ( null === $existing ? 'add' : ( $contents === $expected ? 'matching' : 'modified' ) ),
				'checksum_stale' => null === $existing || hash( 'sha256', $existing ) !== $checksum,
			);
			if ( null !== $contents ) {
				$updated[ $path ] = hash( 'sha256', $contents );
			}
		}
		$this->assertInventory( $root . '/files', array_keys( $checksums ) );

		return array(
			'profile' => $slug,
			'version' => $manifest['version'],
			'changes' => $changes,
			'clean' => array() === array_filter( $changes, static fn ( array $change ): bool => 'matching' !== $change['action'] || $change['checksum_stale'] ),
			'checksums' => $updated,
			'snapshot' => $this->snapshot( $theme, $root, array_keys( $checksums ) ),
		);
	}

	/**
	 * Export only after confirmation, rechecking the approved plan before writes.
	 * Existing package bytes are backed up; partial write failures are restored.
	 *
	 * @param array<string,mixed> $approved Read-only plan previously reviewed.
	 * @return array<string,mixed>
	 */
	public function export( ThemeContext $theme, string $slug, array $approved ): array {
		$plan = $this->plan( $theme, $slug, true );
		if ( $approved !== $plan ) {
			throw new RuntimeException( 'Workbench or profile changed after planning. Review a fresh export plan.' );
		}
		if ( $plan['clean'] ) {
			return $plan;
		}
		$root         = $this->profilesRoot . '/' . $slug;
		$manifestPath = $this->file( $root, 'st-ui.json' );
		$manifest     = json_decode( $this->read( $manifestPath ), false, 512, JSON_THROW_ON_ERROR );
		$writes       = array();
		foreach ( $plan['changes'] as $change ) {
			if ( 'matching' !== $change['action'] ) {
				$contents = $this->read( $this->file( $theme->path, $change['path'] ) );
				if ( hash( 'sha256', $contents ) !== $plan['checksums'][ $change['path'] ] ) {
					throw new RuntimeException( 'Workbench changed while preparing export: ' . $change['path'] );
				}
				$writes[ 'files/' . $change['path'] ] = $contents;
			}
		}
		$manifest->checksums   = (object) $plan['checksums'];
		$writes['st-ui.json']  = json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR ) . PHP_EOL;
		$backup = dirname( $this->profilesRoot, 2 ) . '/.st-toolkit/backups/' . gmdate( 'Ymd-His' ) . '-profile-' . $slug . '-' . bin2hex( random_bytes( 6 ) );
		$this->assertAncestors( $backup );
		$filesystem = $this->filesystem;
		$originals  = array();
		foreach ( $writes as $path => $contents ) {
			$target = $this->file( $root, $path, false );
			$originals[ $path ] = is_file( $target ) ? $this->read( $target ) : null;
			if ( null !== $originals[ $path ] ) {
				$filesystem->dumpFile( $backup . '/' . $path, $originals[ $path ] );
			}
		}
		$filesystem->dumpFile( $backup . '/absent.json', json_encode( array_keys( array_filter( $originals, static fn ( $value ): bool => null === $value ) ), JSON_THROW_ON_ERROR ) );
		$written = array();
		try {
			// All reads happen before writes, including a second stale-plan check.
			if ( $this->plan( $theme, $slug, true ) !== $approved ) {
				throw new RuntimeException( 'Export inputs changed while preparing the backup.' );
			}
			foreach ( $writes as $path => $contents ) {
				$target = $this->file( $root, $path, false );
				$filesystem->dumpFile( $target, $contents );
				$written[] = $path;
			}
		} catch ( \Throwable $exception ) {
			$restoreErrors = array();
			foreach ( array_reverse( $written ) as $path ) {
				try {
					$target = $this->file( $root, $path, false );
					if ( null === $originals[ $path ] ) {
						$filesystem->remove( $target );
					} else {
						$filesystem->dumpFile( $target, $originals[ $path ] );
					}
				} catch ( \Throwable ) {
					$restoreErrors[] = $path;
				}
			}
			$message = array() === $restoreErrors ? 'Profile export failed; package files restored.' : 'Profile export failed; manual recovery required for: ' . implode( ', ', $restoreErrors );
			throw new RuntimeException( $message . ' Backup: ' . $backup, 0, $exception );
		}
		$plan['backup_path'] = $backup;
		return $plan;
	}

	/**
	 * Package resources may contain only declared source files, never symlinks.
	 */
	private function assertInventory( string $root, array $owned ): void {
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $iterator as $file ) {
			if ( $file->isLink() ) {
				throw new RuntimeException( 'Symlink in profile resources: ' . $file->getPathname() );
			}
			if ( $file->isDir() ) {
				continue;
			}
			$path = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
			if ( ! $file->isFile() || ! in_array( $path, $owned, true ) ) {
				throw new RuntimeException( 'Undeclared profile resource; add its path to manifest checksums first: ' . $path );
			}
		}
	}

	private function assertOwnedPath( mixed $path ): void {
		if ( ! is_string( $path ) || ! preg_match( '#^assets/(?:scss/[A-Za-z0-9_./-]+\.scss|styles/[A-Za-z0-9_./-]+\.css)$#D', $path ) ) {
			throw new RuntimeException( 'Profile ownership must contain only Sass or native CSS source paths.' );
		}
		if ( preg_match( '#^assets/(?:scss|styles)/(?:core/|styles-(?:enqueue|register)/(?:plugins|toolkit)/|components/(?:toolkit/|mega-menu(?:/|\.)))#', $path ) ) {
			throw new RuntimeException( 'Shared core, plugin and toolkit component assets are not UI profile-owned: ' . $path );
		}
		$this->assertRelative( $path );
	}

	private function assertRelative( string $path ): void {
		if ( '' === $path || str_contains( $path, '\\' ) || str_contains( $path, ':' ) || str_contains( $path, "\0" ) || str_starts_with( $path, '/' ) || array() !== array_intersect( explode( '/', $path ), array( '', '.', '..' ) ) ) {
			throw new RuntimeException( 'Unsafe profile path: ' . $path );
		}
	}

	private function assertDirectory( string $path ): void {
		$this->assertAncestors( $path );
		if ( ! is_dir( $path ) ) {
			throw new RuntimeException( 'Profile directory not found: ' . $path );
		}
	}

	private function assertAncestors( string $path ): void {
		while ( true ) {
			if ( is_link( $path ) || ( file_exists( $path ) && ! is_dir( $path ) ) ) {
				throw new RuntimeException( 'Unsafe profile directory: ' . $path );
			}
			$parent = dirname( $path );
			if ( $parent === $path ) {
				return;
			}
			$path = $parent;
		}
	}

	private function file( string $root, string $path, bool $required = true ): string {
		$this->assertRelative( $path );
		$target = $root . '/' . $path;
		$this->assertAncestors( dirname( $target ) );
		if ( is_link( $target ) || ( file_exists( $target ) && ! is_file( $target ) ) || ( $required && ! is_file( $target ) ) ) {
			throw new RuntimeException( 'Missing or unsafe profile file: ' . $target );
		}
		return $target;
	}

	private function read( string $path ): string {
		$contents = @file_get_contents( $path );
		if ( false === $contents ) {
			throw new RuntimeException( 'Could not read profile file: ' . $path );
		}
		return $contents;
	}

	/**
	 * Keep filesystem warnings out of machine-readable command output.
	 */
	private function readJson( string $path ): array {
		try {
			$data = json_decode( $this->read( $path ), false, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $exception ) {
			throw new RuntimeException( 'Invalid JSON in profile file ' . $path . ': ' . $exception->getMessage(), 0, $exception );
		}
		if ( ! $data instanceof \stdClass ) {
			throw new RuntimeException( 'Expected a JSON object in profile file: ' . $path );
		}
		return get_object_vars( $data );
	}

	/** Build settings are lists of strings; an empty JSON object is not a list. */
	private function listSetting( array $data, string $key ): array {
		$values = $data[ $key ] ?? array();
		if ( ! is_array( $values ) || ! array_is_list( $values ) ) {
			throw new RuntimeException( 'Profile build setting must be a JSON list: ' . $key );
		}
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				throw new RuntimeException( 'Profile build setting must contain non-empty strings: ' . $key );
			}
		}
		return $values;
	}

	/**
	 * Hash all approval-relevant inputs, not just changed file contents.
	 */
	private function snapshot( ThemeContext $theme, string $root, array $paths ): array {
		$hashes = array();
		$hashes['package/st-ui.json'] = hash( 'sha256', $this->read( $this->file( $root, 'st-ui.json' ) ) );
		foreach ( array_merge( array( 'core/bootstrap.php', UIProfileInstaller::STATE_FILE ), $paths ) as $path ) {
			$target = $this->file( $theme->path, $path, false );
			$hashes[ 'theme/' . $path ] = is_file( $target ) ? hash( 'sha256', $this->read( $target ) ) : null;
		}
		foreach ( $paths as $path ) {
			$target = $this->file( $root . '/files', $path, false );
			$hashes[ 'package/files/' . $path ] = is_file( $target ) ? hash( 'sha256', $this->read( $target ) ) : null;
		}
		return $hashes;
	}
}

<?php
/**
 * Stage the bundle's narrow theme.json overlay without touching the theme.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Block;

use JsonException;
use RuntimeException;
use SnailTheme\WPStarterToolkit\Theme\ThemeContext;
use stdClass;
use Symfony\Component\Filesystem\Filesystem;

final class BlockBundleThemeSettings {
	/** @var string[] Temporary directories retained by the bundle plan. */
	private array $temporaryDirectories = array();
	/** @var array<string,?string> Exact preimages used to build each overlay. */
	private array $previousHashes = array();

	public function __construct( private readonly Filesystem $filesystem = new Filesystem() ) {}

	public function __destruct() {
		foreach ( $this->temporaryDirectories as $directory ) {
			try {
				$this->filesystem->remove( $directory );
			} catch ( \Throwable ) {
				// Cleanup must not replace an install or preflight error.
			}
		}
	}

	/**
	 * Return a staged theme.json path, or null when the manifest has no settings.
	 */
	public function stage( ThemeContext $theme, BlockBundleManifest $bundle ): ?string {
		$settings = $bundle->themeSettings();
		if ( array() === $settings ) {
			return null;
		}
		BlockBundlePaths::assertTarget( $theme, 'theme.json' );
		$target = $theme->resolve( 'theme.json' );
		$previousHash = BlockBundlePaths::fileHash( $target );
		$contents = null !== $previousHash ? @file_get_contents( $target ) : null;
		if ( false === $contents ) {
			throw new RuntimeException( 'Could not read target theme.json.' );
		}
		if ( null !== $contents && hash( 'sha256', $contents ) !== $previousHash ) {
			throw new RuntimeException( 'Target theme.json changed while staging; run again.' );
		}
		try {
			// Objects must remain objects, especially empty project-owned settings.
			$data = null === $contents ? (object) array( 'version' => 3 ) : json_decode( $contents, false, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException $exception ) {
			throw new RuntimeException( 'Invalid JSON in target theme.json: ' . $exception->getMessage(), 0, $exception );
		}
		if ( ! $data instanceof stdClass ) {
			throw new RuntimeException( 'Target theme.json must contain a JSON object.' );
		}

		if ( isset( $settings['blocks']['stwp/el-wrapper'] ) ) {
			$settings['blocks'] = array( $theme->pattern( 'block_namespace', 'stwp' ) . '/el-wrapper' => $settings['blocks']['stwp/el-wrapper'] );
		}
		$changed = $this->overlay( $data, array( 'settings' => $settings ) );
		if ( $changed || null === $contents ) {
			try {
				$contents = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR ) . PHP_EOL;
			} catch ( JsonException $exception ) {
				throw new RuntimeException( 'Could not encode target theme.json: ' . $exception->getMessage(), 0, $exception );
			}
		}
		$temp = sys_get_temp_dir() . '/st-toolkit-bundle-settings-' . bin2hex( random_bytes( 6 ) );
		$this->temporaryDirectories[] = $temp;
		$this->filesystem->dumpFile( $temp . '/theme.json', $contents );
		BlockBundlePaths::assertTarget( $theme, 'theme.json' );
		if ( BlockBundlePaths::fileHash( $target ) !== $previousHash ) {
			throw new RuntimeException( 'Target theme.json changed while staging; run again.' );
		}
		$this->previousHashes[ $temp . '/theme.json' ] = $previousHash;
		return $temp . '/theme.json';
	}

	public function previousHash( string $source ): ?string {
		if ( ! array_key_exists( $source, $this->previousHashes ) ) {
			throw new RuntimeException( 'Unknown bundle theme settings stage.' );
		}
		return $this->previousHashes[ $source ];
	}

	/**
	 * Recursively overlay only manifest-validated leaves. Never replace a project
	 * object with an array or discard siblings to make room for bundle settings.
	 *
	 * @param array<string,mixed> $settings Allowlisted settings tree.
	 */
	private function overlay( stdClass $target, array $settings, string $path = '' ): bool {
		$changed = false;
		foreach ( $settings as $key => $value ) {
			if ( is_array( $value ) ) {
				if ( array() === $value ) {
					continue;
				}
				$node = property_exists( $target, $key ) ? $target->$key : new stdClass();
				if ( ! $node instanceof stdClass ) {
					throw new RuntimeException( 'Cannot merge bundle settings into a non-object in theme.json: ' . $path . $key );
				}
				if ( $this->overlay( $node, $value, $path . $key . '.' ) ) {
					$target->$key = $node;
					$changed = true;
				}
			} elseif ( ! property_exists( $target, $key ) || $target->$key !== $value ) {
				$target->$key = $value;
				$changed = true;
			}
		}
		return $changed;
	}
}

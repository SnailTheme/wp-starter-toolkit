<?php
/**
 * Fixed-member block bundle manifest and canonical member discovery.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Block;

use RuntimeException;
use SnailTheme\WPStarterToolkit\Support\JsonFile;
use SnailTheme\WPStarterToolkit\Support\PackagePaths;

final class BlockBundleManifest {
	/** @var array<string,mixed> */
	private readonly array $manifest;
	/**
	 * A path constructor keeps fixtures independent of the final PackagePaths API.
	 * Member resources always come from sibling canonical block directories.
	 */
	public function __construct( private readonly string $path, private readonly JsonFile $json = new JsonFile() ) {
		$data = $this->json->read( $path );
		if ( 1 !== ( $data['schema'] ?? null ) || 'bundle' !== ( $data['type'] ?? null )
			|| ! is_string( $data['source_slug'] ?? null ) || ! is_string( $data['default_title'] ?? null )
			|| ! is_string( $data['required_core_version'] ?? null ) || ! is_array( $data['members'] ?? null )
			|| ! array_is_list( $data['members'] ) || array() === $data['members']
			|| ! empty( $data['npm_dependencies'] ) || ! empty( $data['shared_assets'] ) ) {
			throw new RuntimeException( 'Invalid block bundle manifest: ' . $path );
		}
		$this->assertSlug( $data['source_slug'] );
		$seen = array();
		foreach ( $data['members'] as $slug ) {
			if ( ! is_string( $slug ) ) {
				throw new RuntimeException( 'Bundle members must be fixed source slugs.' );
			}
			$this->assertSlug( $slug );
			if ( isset( $seen[ $slug ] ) ) {
				throw new RuntimeException( 'Duplicate bundle member: ' . $slug );
			}
			$seen[ $slug ] = true;
		}
		if ( array_key_exists( 'theme_settings', $data ) ) {
			$decoded = json_decode( (string) file_get_contents( $path ), false, 512, JSON_THROW_ON_ERROR );
			$this->assertThemeSettings( $decoded->theme_settings, array(
				'blocks' => array( 'stwp/el-wrapper' => array( 'spacing' => array( 'margin' => true, 'padding' => true ) ) ),
			) );
			if ( ! empty( $data['theme_settings']['blocks']['stwp/el-wrapper'] ) && ! isset( $seen['el-wrapper'] ) ) {
				throw new RuntimeException( 'Bundle wrapper theme settings require the el-wrapper member.' );
			}
		}
		$this->manifest = $data;
	}

	public static function load( string $slug, PackagePaths $paths = new PackagePaths() ): self {
		BlockBundlePaths::assertRelative( $slug );
		return new self( $paths->resources( 'blocks/' . $slug . '/st-bundle.json' ) );
	}

	/** @return array<string,mixed> */
	private function data(): array {
		return $this->manifest;
	}

	public function sourceSlug(): string {
		return $this->data()['source_slug'];
	}

	public function defaultTitle(): string {
		return $this->data()['default_title'];
	}

	public function requiredCoreVersion(): string {
		return $this->data()['required_core_version'];
	}

	/** @return string[] */
	public function memberSlugs(): array {
		return $this->data()['members'];
	}

	/** @return array<string,mixed> Allowlisted settings, with canonical source keys. */
	public function themeSettings(): array {
		return $this->data()['theme_settings'] ?? array();
	}

	/** @param array<string,mixed> $allowed Allowed object keys and boolean leaves. */
	private function assertThemeSettings( mixed $settings, array $allowed, string $path = 'theme_settings' ): void {
		if ( ! $settings instanceof \stdClass ) {
			throw new RuntimeException( 'Bundle theme settings must be an object: ' . $path );
		}
		foreach ( get_object_vars( $settings ) as $key => $value ) {
			if ( ! array_key_exists( $key, $allowed ) ) {
				throw new RuntimeException( 'Bundle theme setting is not allowlisted: ' . $path . '.' . $key );
			}
			if ( is_array( $allowed[ $key ] ) ) {
				$this->assertThemeSettings( $value, $allowed[ $key ], $path . '.' . $key );
			} elseif ( true !== $value ) {
				throw new RuntimeException( 'Bundle spacing controls may only be enabled: ' . $path . '.' . $key );
			}
		}
	}

	/** @return BlockManifest[] */
	public function members(): array {
		$members = array();
		$root = dirname( $this->path, 2 );
		foreach ( $this->memberSlugs() as $slug ) {
			$directory = $root . '/' . $slug;
			if ( is_link( $directory ) || is_link( $directory . '/st-block.json' ) ) {
				throw new RuntimeException( 'Unsafe bundle member directory: ' . $slug );
			}
			$data = $this->json->read( $directory . '/st-block.json' );
			if ( 1 !== ( $data['schema'] ?? null ) || $slug !== ( $data['source_slug'] ?? null )
				|| true !== ( $data['bundle_only'] ?? null ) || 'stwp' !== ( $data['source_namespace'] ?? 'stwp' )
				|| ! empty( $data['npm_dependencies'] ) || ! empty( $data['shared_assets'] ) ) {
				throw new RuntimeException( 'Invalid bundle-only member (dependencies/shared assets are not supported): ' . $slug );
			}
			BlockBundlePaths::sourceFiles( $directory . '/files' );
			if ( file_exists( $directory . '/theme-files' ) || is_link( $directory . '/theme-files' ) ) {
				foreach ( BlockBundlePaths::sourceFiles( $directory . '/theme-files' ) as $relative ) {
					if ( ! str_starts_with( $relative, 'acf-json/' ) || ! str_ends_with( $relative, '.json' ) ) {
						throw new RuntimeException( 'Bundle theme files must be ACF JSON: ' . $relative );
					}
				}
			}
			$members[] = new BlockManifest( $data, $directory . '/st-block.json', $directory . '/files' );
		}
		return $members;
	}

	private function assertSlug( string $slug ): void {
		if ( 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) ) {
			throw new RuntimeException( 'Invalid bundle slug: ' . $slug );
		}
	}
}

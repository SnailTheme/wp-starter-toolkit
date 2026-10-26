<?php
/**
 * Block repository.
 *
 * Lists packaged block manifests from the toolkit resources directory.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Block;

use SnailTheme\WPStarterToolkit\Support\PackagePaths;
use Symfony\Component\Finder\Finder;

/**
 * Discovers packaged blocks.
 */
final class BlockRepository {
	public function __construct(
		private readonly PackagePaths $paths = new PackagePaths()
	) {}

	/**
	 * Return all packaged block manifests.
	 *
	 * @return BlockManifest[]
	 */
	public function all(): array {
		$root = $this->paths->resources( 'blocks' );

		if ( ! is_dir( $root ) ) {
			return array();
		}

		$blocks = array();
		$finder = Finder::create()->files()->name( 'st-block.json' )->depth( 1 )->in( $root );

		foreach ( $finder as $file ) {
			$slug     = basename( dirname( $file->getPathname() ) );
			$manifest = BlockManifest::load( $slug, $this->paths );
			if ( true !== ( $manifest->data()['bundle_only'] ?? false ) ) {
				$blocks[] = $manifest;
			}
		}

		usort(
			$blocks,
			static fn ( BlockManifest $left, BlockManifest $right ): int => $left->sourceSlug() <=> $right->sourceSlug()
		);

		return $blocks;
	}

	/**
	 * Discover bundles separately so existing standalone consumers (doctor) retain
	 * their BlockManifest contract and do not have to traverse bundle members.
	 *
	 * @return BlockBundleManifest[]
	 */
	public function bundles(): array {
		$root = $this->paths->resources( 'blocks' );
		if ( ! is_dir( $root ) ) {
			return array();
		}
		$bundles = array();
		foreach ( Finder::create()->files()->name( 'st-bundle.json' )->depth( 1 )->in( $root ) as $file ) {
			$bundles[] = new BlockBundleManifest( $file->getPathname() );
		}
		usort( $bundles, static fn ( BlockBundleManifest $left, BlockBundleManifest $right ): int => $left->sourceSlug() <=> $right->sourceSlug() );
		return $bundles;
	}
}

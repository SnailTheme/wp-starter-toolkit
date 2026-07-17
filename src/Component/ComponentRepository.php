<?php
/**
 * Component repository.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Component;

use SnailTheme\WPStarterToolkit\Support\PackagePaths;
use Symfony\Component\Finder\Finder;

/**
 * Discovers reusable component packages.
 */
final class ComponentRepository {
	public function __construct(
		private readonly PackagePaths $paths = new PackagePaths()
	) {}

	/**
	 * Return component summaries sorted by slug.
	 *
	 * @return array<int,array<string,string>>
	 */
	public function all(): array {
		$root = $this->paths->resources( 'components' );

		if ( ! is_dir( $root ) ) {
			return array();
		}

		$components = array();
		$finder     = Finder::create()->files()->name( 'st-component.json' )->depth( '== 1' )->in( $root );

		foreach ( $finder as $file ) {
			$manifest     = ComponentManifest::load( $file->getRelativePath(), $this->paths );
			$components[] = array(
				'slug'        => $manifest->slug(),
				'title'       => $manifest->title(),
				'version'     => $manifest->version(),
				'required_core_version' => $manifest->requiredCoreVersion(),
				'description' => $manifest->description(),
			);
		}

		usort(
			$components,
			static fn ( array $left, array $right ): int => $left['slug'] <=> $right['slug']
		);

		return $components;
	}
}

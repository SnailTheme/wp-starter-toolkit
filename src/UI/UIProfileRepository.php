<?php
/**
 * UI profile repository.
 *
 * Discovers profile manifests bundled with the installed toolkit package.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\UI;

use SnailTheme\WPStarterToolkit\Support\PackagePaths;
use Symfony\Component\Finder\Finder;

/**
 * Lists available UI profile packages.
 */
final class UIProfileRepository {
	public function __construct(
		private readonly PackagePaths $paths = new PackagePaths()
	) {}

	/**
	 * Return profile summaries sorted by slug.
	 *
	 * @return array<int,array<string,string>>
	 */
	public function all(): array {
		$root = $this->paths->resources( 'ui-profiles' );

		if ( ! is_dir( $root ) ) {
			return array();
		}

		$profiles = array();
		$finder   = Finder::create()->files()->name( 'st-ui.json' )->depth( '== 1' )->in( $root );

		foreach ( $finder as $file ) {
			$profile    = UIProfileManifest::load( $file->getRelativePath(), $this->paths );
			$profiles[] = array(
				'slug'        => $profile->slug(),
				'title'       => $profile->title(),
				'version'     => $profile->version(),
				'description' => $profile->description(),
			);
		}

		usort(
			$profiles,
			static fn ( array $left, array $right ): int => $left['slug'] <=> $right['slug']
		);

		return $profiles;
	}
}

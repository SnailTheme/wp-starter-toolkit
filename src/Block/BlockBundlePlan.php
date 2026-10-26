<?php
/**
 * A fully staged bundle transaction. Temporary sources never enter CLI JSON.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Block;

use SnailTheme\WPStarterToolkit\Theme\ThemeContext;

final class BlockBundlePlan {
	/**
	 * @param array<string,mixed>  $summary Public plan.
	 * @param array<string,string> $files Theme-relative targets to staged sources.
	 * @param array<string,?string> $previousHashes Confirmation-time file state.
	 * @param array<string,?string> $inputHashes Bootstrap/build configuration state.
	 * @param array<string,int|string>|null $rootIdentity Original root identity.
	 */
	public function __construct(
		public readonly ThemeContext $theme,
		private readonly array $summary,
		public readonly array $files,
		public readonly array $previousHashes,
		private readonly BlockInstaller $stagingOwner,
		private readonly ?BlockBundleThemeSettings $settingsOwner = null,
		public readonly array $inputHashes = array(),
		public readonly ?array $rootIdentity = null
	) {}

	/** @return array<string,mixed> */
	public function toArray(): array {
		return $this->summary;
	}
}

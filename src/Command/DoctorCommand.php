<?php
/**
 * doctor command.
 *
 * Performs static checks that help developers understand whether the current
 * theme is ready for core updates and block installs.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Command;

use SnailTheme\WPStarterToolkit\Block\BlockRepository;
use SnailTheme\WPStarterToolkit\Block\PackageJsonInspector;
use SnailTheme\WPStarterToolkit\Core\CoreUpdater;
use SnailTheme\WPStarterToolkit\Support\HelperInspector;
use SnailTheme\WPStarterToolkit\UI\UIProfileInstaller;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Run toolkit readiness checks.
 */
final class DoctorCommand extends ToolkitCommand {
	protected static $defaultName = 'doctor';

	protected function configure(): void {
		$this
			->setName( 'doctor' )
			->setDescription( 'Inspect the target theme for toolkit readiness.' );

		$this->addSharedOptions();
	}

	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$theme      = $this->theme( $input );
		$core       = ( new CoreUpdater() )->check( $theme );
		$repository = new BlockRepository();
		$inspector  = new PackageJsonInspector();
		$helpers    = new HelperInspector();
		$blocks     = array();

		foreach ( $repository->all() as $manifest ) {
			$blocks[] = array(
				'slug'                 => $manifest->sourceSlug(),
				'required_core_version' => $manifest->requiredCoreVersion(),
				'missing_dependencies' => $inspector->missing( $theme, $manifest->npmDependencies() ),
				'missing_helpers'      => $helpers->missing( $theme, $manifest->requiredHelpers() ),
				'required_helpers'     => $manifest->requiredHelpers(),
			);
		}

		$result = array(
			'theme_path' => $theme->path,
			'core'       => $core,
			'ui'         => ( new UIProfileInstaller() )->status( $theme ),
			'patterns'   => $theme->patterns,
			'blocks'     => $blocks,
		);

		if ( $this->wantsJson( $input ) ) {
			return $this->writeJson( $output, $result );
		}

		$output->writeln( '<info>ST Toolkit Doctor</info>' );
		$output->writeln( sprintf( 'Theme: %s', $theme->path ) );
		$output->writeln( sprintf( 'Core: installed %s, packaged %s', $core['installed_core'], $core['packaged_core'] ) );
		$output->writeln( sprintf( 'Text domain: %s', $theme->pattern( 'text_domain' ) ) );
		$output->writeln( sprintf( 'Function prefix: %s', $theme->pattern( 'function_names' ) ) );
		$output->writeln( sprintf( 'Block namespace: %s', $theme->pattern( 'block_namespace' ) ) );
		$output->writeln( sprintf( 'Block category: %s', $theme->pattern( 'block_category' ) ) );
		$output->writeln( sprintf( 'UI profile: %s %s', $result['ui']['profile'], $result['ui']['version'] ) );
		$output->writeln( sprintf( 'UI selection: %s', $result['ui']['locked'] ? 'locked' : 'not yet locked' ) );
		$output->writeln( sprintf( 'Modified UI scaffold files: %d', count( $result['ui']['modified_files'] ) ) );

		if ( ! $result['ui']['locked'] ) {
			$output->writeln( '  next: choose once with composer toolkit:ui-install PROFILE' );
		}

		foreach ( $blocks as $block ) {
			$output->writeln( sprintf( 'Block %s, requires core %s:', $block['slug'], $block['required_core_version'] ?: 'any' ) );

			if ( empty( $block['missing_dependencies'] ) ) {
				$output->writeln( '  npm dependencies: ok' );
			} else {
				foreach ( $block['missing_dependencies'] as $name => $version ) {
					$output->writeln( sprintf( '  missing: npm install %s@%s', $name, $version ) );
				}
			}

			if ( empty( $block['missing_helpers'] ) ) {
				$output->writeln( '  PHP helpers: ok' );
			} else {
				foreach ( $block['missing_helpers'] as $helper ) {
					$output->writeln( sprintf( '  missing helper: %s', $helper ) );
				}
			}
		}

		return self::SUCCESS;
	}
}

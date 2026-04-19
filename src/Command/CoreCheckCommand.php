<?php
/**
 * core:check command.
 *
 * Inspects the target theme and reports whether the bundled core package is
 * newer than the installed ST_WP_CORE_VERSION.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Command;

use SnailTheme\WPStarterToolkit\Core\CoreUpdater;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Check installed and packaged core versions.
 */
final class CoreCheckCommand extends ToolkitCommand {
	protected static $defaultName = 'core:check';

	protected function configure(): void {
		$this
			->setName( 'core:check' )
			->setDescription( 'Check the installed theme core version against the packaged core version.' );

		$this->addSharedOptions();
	}

	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$theme  = $this->theme( $input );
		$result = ( new CoreUpdater() )->check( $theme );

		if ( $this->wantsJson( $input ) ) {
			return $this->writeJson( $output, $result );
		}

		$output->writeln( '<info>ST Core Check</info>' );
		$output->writeln( sprintf( 'Theme: %s', $result['theme_path'] ) );
		$output->writeln( sprintf( 'Installed core: %s', $result['installed_core'] ) );
		$output->writeln( sprintf( 'Packaged core: %s', $result['packaged_core'] ) );
		$output->writeln( sprintf( 'Update available: %s', $result['update_available'] ? 'yes' : 'no' ) );
		$output->writeln( sprintf( 'Manifest-owned files: %d', $result['owned_file_count'] ) );

		return self::SUCCESS;
	}
}

<?php
/**
 * ui:status command.
 *
 * Reports the installed profile and any scaffold files changed since install.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Command;

use SnailTheme\WPStarterToolkit\UI\UIProfileInstaller;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Show the active UI profile state.
 */
final class UiStatusCommand extends ToolkitCommand {
	protected static $defaultName = 'ui:status';

	protected function configure(): void {
		$this
			->setName( 'ui:status' )
			->setDescription( 'Show the active UI profile and local modifications.' );
		$this->addSharedOptions();
	}

	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$status = ( new UIProfileInstaller() )->status( $this->theme( $input ) );

		if ( $this->wantsJson( $input ) ) {
			return $this->writeJson( $output, $status );
		}

		$output->writeln( sprintf( '<info>Active UI profile:</info> %s %s', $status['profile'], $status['version'] ) );
		$output->writeln( sprintf( 'Selection: %s', $status['locked'] ? 'locked' : 'not yet locked' ) );
		$output->writeln( sprintf( 'Modified scaffold files: %d', count( $status['modified_files'] ) ) );
		$output->writeln( sprintf( 'Missing scaffold files: %d', count( $status['missing_files'] ) ) );

		foreach ( $status['modified_files'] as $file ) {
			$output->writeln( sprintf( '- modified %s', $file ) );
		}

		foreach ( $status['missing_files'] as $file ) {
			$output->writeln( sprintf( '- missing %s', $file ) );
		}

		if ( ! $status['locked'] ) {
			$output->writeln( '<comment>Choose the project UI once with composer toolkit:ui-install PROFILE.</comment>' );
		}

		return self::SUCCESS;
	}
}

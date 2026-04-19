<?php
/**
 * core:update command.
 *
 * Updates only files owned by the packaged core manifest. Project files in
 * /inc/, templates, generic assets, and /blocks/ are intentionally ignored.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Command;

use SnailTheme\WPStarterToolkit\Core\CoreUpdater;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Dry-run or apply a core update.
 */
final class CoreUpdateCommand extends ToolkitCommand {
	protected static $defaultName = 'core:update';

	protected function configure(): void {
		$this
			->setName( 'core:update' )
			->setDescription( 'Update manifest-owned ST core files in the target theme.' );

		$this->addSharedOptions( dryRun: true, yes: true );
	}

	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$theme   = $this->theme( $input );
		$updater = new CoreUpdater();

		if ( (bool) $input->getOption( 'dry-run' ) ) {
			$result = $updater->plan( $theme );
			$result['dry_run'] = true;

			if ( $this->wantsJson( $input ) ) {
				return $this->writeJson( $output, $result );
			}

			$output->writeln( '<info>Core update dry run</info>' );
			$this->printChanges( $output, $result['changes'] );

			return self::SUCCESS;
		}

		$plan = $updater->plan( $theme );

		if ( ! $this->confirm( $input, $output, 'Write packaged core files to the target theme?' ) ) {
			$output->writeln( '<comment>Core update cancelled.</comment>' );
			return self::SUCCESS;
		}

		$result = $updater->update( $theme );

		if ( $this->wantsJson( $input ) ) {
			return $this->writeJson( $output, $result );
		}

		$output->writeln( '<info>Core update complete</info>' );
		$output->writeln( sprintf( 'Backup: %s', $result['backup_path'] ) );
		$this->printChanges( $output, $plan['changes'] );

		return self::SUCCESS;
	}

	/**
	 * Print a compact file change summary.
	 *
	 * @param array<int,array<string,string>> $changes Planned changes.
	 */
	private function printChanges( OutputInterface $output, array $changes ): void {
		foreach ( $changes as $change ) {
			$output->writeln( sprintf( '- %s %s', $change['action'], $change['path'] ) );
		}
	}
}

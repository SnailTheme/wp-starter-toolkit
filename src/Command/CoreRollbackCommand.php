<?php
/**
 * core:rollback command.
 *
 * Restores the latest toolkit backup by copying backed-up files over the theme.
 * This is intentionally simple for v1 and is scoped to files present in the
 * backup directory.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Command;

use SnailTheme\WPStarterToolkit\Support\BackupManager;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Restore the latest toolkit backup.
 */
final class CoreRollbackCommand extends ToolkitCommand {
	protected static $defaultName = 'core:rollback';

	protected function configure(): void {
		$this
			->setName( 'core:rollback' )
			->setDescription( 'Restore the latest ST Toolkit backup.' )
			->addOption( 'backup', null, InputOption::VALUE_REQUIRED, 'Specific backup directory to restore.' );

		$this->addSharedOptions( yes: true );
	}

	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$theme  = $this->theme( $input );
		$backup = $input->getOption( 'backup' );

		if ( ! $this->confirm( $input, $output, 'Restore the selected toolkit backup?' ) ) {
			$output->writeln( '<comment>Rollback cancelled.</comment>' );
			return self::SUCCESS;
		}

		$restored = ( new BackupManager() )->restoreLatest( $theme->path, is_string( $backup ) ? $backup : null, 'core' );
		$result   = array(
			'theme_path'      => $theme->path,
			'restored_backup' => $restored,
		);

		if ( $this->wantsJson( $input ) ) {
			return $this->writeJson( $output, $result );
		}

		$output->writeln( '<info>Rollback complete</info>' );
		$output->writeln( sprintf( 'Restored backup: %s', $restored ) );

		return self::SUCCESS;
	}
}

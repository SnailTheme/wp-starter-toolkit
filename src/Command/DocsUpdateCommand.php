<?php
/**
 * docs:update command.
 *
 * Refreshes toolkit-managed local agent documentation without updating core
 * files or installing blocks.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Command;

use SnailTheme\WPStarterToolkit\AgentDocs\AgentDocsInstaller;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Update toolkit-managed local development notes.
 */
final class DocsUpdateCommand extends ToolkitCommand {
	protected static $defaultName = 'docs:update';

	protected function configure(): void {
		$this
			->setName( 'docs:update' )
			->setDescription( 'Update local-only agent docs in the target theme.' )
			->addOption( 'force', null, InputOption::VALUE_NONE, 'Refresh current-version managed docs.' );

		$this->addSharedOptions( dryRun: true, yes: true );
	}

	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$theme     = $this->theme( $input );
		$installer = AgentDocsInstaller::create();
		$force     = (bool) $input->getOption( 'force' );

		if ( (bool) $input->getOption( 'dry-run' ) ) {
			$result = $installer->plan( $theme, $force );
			$result['dry_run'] = true;

			if ( $this->wantsJson( $input ) ) {
				return $this->writeJson( $output, $result );
			}

			$output->writeln( '<info>Toolkit docs update dry run</info>' );
			$this->printResult( $output, $result );

			return self::SUCCESS;
		}

		$plan = $installer->plan( $theme, $force );

		if ( ! $this->confirm( $input, $output, 'Update local agent docs in the target theme?' ) ) {
			$output->writeln( '<comment>Toolkit docs update cancelled.</comment>' );
			return self::SUCCESS;
		}

		$result = $installer->install( $theme, $force );

		if ( $this->wantsJson( $input ) ) {
			return $this->writeJson( $output, $result );
		}

		$output->writeln( '<info>Toolkit docs update complete</info>' );
		$this->printResult( $output, $result ?: $plan );

		return self::SUCCESS;
	}

	/**
	 * Print docs and ignore-rule changes.
	 *
	 * @param array<string,mixed> $result Installer result.
	 */
	private function printResult( OutputInterface $output, array $result ): void {
		$output->writeln( sprintf( 'Theme: %s', $result['theme_path'] ) );
		$output->writeln( sprintf( 'Agent docs version: %s', $result['version'] ) );
		$output->writeln( sprintf( 'Block namespace: %s', $result['namespace'] ) );

		foreach ( $result['changes'] as $change ) {
			$output->writeln( sprintf( '- %s %s (%s)', $change['action'], $change['path'], $change['reason'] ) );
		}

		$gitignore = $result['gitignore'];
		$output->writeln( sprintf( '- %s %s', $gitignore['action'], $gitignore['path'] ) );

		foreach ( $gitignore['missing_lines'] as $line ) {
			$output->writeln( sprintf( '  add ignore: %s', $line ) );
		}
	}
}

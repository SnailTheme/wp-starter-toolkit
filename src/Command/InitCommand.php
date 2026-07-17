<?php
/**
 * init command.
 *
 * Installs local-only agent documentation into a theme and ensures those files
 * are ignored by Git.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Command;

use SnailTheme\WPStarterToolkit\AgentDocs\AgentDocsInstaller;
use SnailTheme\WPStarterToolkit\UI\UIProfileInstaller;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Install toolkit-managed local development notes.
 */
final class InitCommand extends ToolkitCommand {
	protected static $defaultName = 'init';

	protected function configure(): void {
		$this
			->setName( 'init' )
			->setDescription( 'Install local-only agent docs and ignore rules in the target theme.' );

		$this->addSharedOptions( dryRun: true, yes: true );
	}

	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$theme     = $this->theme( $input );
		$installer = AgentDocsInstaller::create();
		$uiStatus  = ( new UIProfileInstaller() )->status( $theme );

		if ( (bool) $input->getOption( 'dry-run' ) ) {
			$result = $installer->plan( $theme );
			$result['dry_run'] = true;

			if ( $this->wantsJson( $input ) ) {
				return $this->writeJson( $output, $result );
			}

			$output->writeln( '<info>Toolkit init dry run</info>' );
			$this->printResult( $output, $result );
			$this->printUiSetup( $output, $uiStatus );

			return self::SUCCESS;
		}

		$plan = $installer->plan( $theme );

		if ( ! $this->confirm( $input, $output, 'Install or update local agent docs in the target theme?' ) ) {
			$output->writeln( '<comment>Toolkit init cancelled.</comment>' );
			return self::SUCCESS;
		}

		$result = $installer->install( $theme );

		if ( $this->wantsJson( $input ) ) {
			return $this->writeJson( $output, $result );
		}

		$output->writeln( '<info>Toolkit init complete</info>' );
		$this->printResult( $output, $result ?: $plan );
		$this->printUiSetup( $output, $uiStatus );

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

	/**
	 * Suggest the one-time UI choice while the starter is still unlocked.
	 *
	 * @param array<string,mixed> $status Current UI profile status.
	 */
	private function printUiSetup( OutputInterface $output, array $status ): void {
		if ( $status['locked'] ) {
			return;
		}

		$output->writeln( '' );
		$output->writeln( '<comment>Next: choose the project UI once before adding project styles.</comment>' );
		$output->writeln( '  composer toolkit:ui-list' );
		$output->writeln( '  composer toolkit:ui-install PROFILE' );
		$output->writeln( 'The selected profile is then locked; replacing it later is an explicit destructive operation.' );
	}
}

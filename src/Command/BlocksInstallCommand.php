<?php
/**
 * blocks:install command.
 *
 * Installs a packaged block into a destination slug chosen by the developer,
 * applying theme patterns and block-specific replacements.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Command;

use SnailTheme\WPStarterToolkit\Block\BlockInstaller;
use SnailTheme\WPStarterToolkit\Block\BlockManifest;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Install a curated block.
 */
final class BlocksInstallCommand extends ToolkitCommand {
	protected static $defaultName = 'blocks:install';

	protected function configure(): void {
		$this
			->setName( 'blocks:install' )
			->setDescription( 'Install a packaged block into the target theme.' )
			->addArgument( 'source-block', InputArgument::REQUIRED, 'Packaged block slug, for example slider.' )
			->addArgument( 'destination-block', InputArgument::REQUIRED, 'Destination block slug, for example hero-slider.' )
			->addOption( 'replace-shared-assets', null, InputOption::VALUE_NONE, 'Replace declared shared assets when checksums differ.' );

		$this->addSharedOptions( dryRun: true, yes: true );
	}

	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$theme       = $this->theme( $input );
		$source      = (string) $input->getArgument( 'source-block' );
		$destination = (string) $input->getArgument( 'destination-block' );
		$manifest    = BlockManifest::load( $source );
		$installer   = new BlockInstaller();

		if ( (bool) $input->getOption( 'dry-run' ) ) {
			$result = $installer->plan( $theme, $manifest, $destination );
			$result['dry_run'] = true;

			if ( $this->wantsJson( $input ) ) {
				return $this->writeJson( $output, $result );
			}

			$output->writeln( '<info>Block install dry run</info>' );
			$this->printPlan( $output, $result );

			return self::SUCCESS;
		}

		$plan = $installer->plan( $theme, $manifest, $destination );
		$replaceSharedAssets = (bool) $input->getOption( 'replace-shared-assets' );

		if ( ! $replaceSharedAssets && $this->hasSharedAssetConflicts( $plan ) ) {
			if ( ! $input->isInteractive() ) {
				$output->writeln( '<error>Shared assets differ. Re-run with --replace-shared-assets to replace them non-interactively.</error>' );
				return self::FAILURE;
			}

			$helper = $this->getHelper( 'question' );

			if ( ! $helper->ask( $input, $output, new ConfirmationQuestion( 'One or more shared assets differ. Replace them? [y/N] ', false ) ) ) {
				$output->writeln( '<comment>Block install cancelled because shared assets differ.</comment>' );
				return self::FAILURE;
			}

			$replaceSharedAssets = true;
		}

		if ( is_dir( $plan['destination_path'] ) && ! $this->confirm( $input, $output, 'Destination block exists. Backup and replace it?' ) ) {
			$output->writeln( '<comment>Block install cancelled.</comment>' );
			return self::SUCCESS;
		}

		if ( ! is_dir( $plan['destination_path'] ) && ! $this->confirm( $input, $output, 'Install this block into the target theme?' ) ) {
			$output->writeln( '<comment>Block install cancelled.</comment>' );
			return self::SUCCESS;
		}

		$result = $installer->install(
			$theme,
			$manifest,
			$destination,
			$replaceSharedAssets
		);

		if ( $this->wantsJson( $input ) ) {
			return $this->writeJson( $output, $result );
		}

		$output->writeln( '<info>Block install complete</info>' );
		$output->writeln( sprintf( 'Backup: %s', $result['backup_path'] ) );
		$this->printPlan( $output, $result );

		return self::SUCCESS;
	}

	/**
	 * Print install plan details.
	 *
	 * @param array<string,mixed> $plan Install plan.
	 */
	private function printPlan( OutputInterface $output, array $plan ): void {
		$output->writeln( sprintf( 'Destination: %s', $plan['destination_path'] ) );
		$output->writeln( sprintf( 'Block namespace: %s', $plan['target_namespace'] ) );
		$output->writeln( sprintf( 'Block category: %s', $plan['target_category'] ) );
		$output->writeln( sprintf( 'Required core: %s (%s)', $plan['required_core_version'] ?: 'any', $plan['core_version_satisfied'] ? 'ok' : 'not satisfied' ) );

		foreach ( $plan['changes'] as $change ) {
			$output->writeln( sprintf( '- %s %s', $change['action'], $change['path'] ) );
		}

		foreach ( $plan['shared_assets'] as $asset ) {
			$output->writeln( sprintf( '- shared %s %s', $asset['action'], $asset['path'] ) );
		}

		foreach ( $plan['missing_dependencies'] as $name => $version ) {
			$output->writeln( sprintf( '<comment>Missing npm dependency:</comment> npm install %s@%s', $name, $version ) );
		}
	}

	/**
	 * Check whether the install plan contains changed shared assets.
	 *
	 * @param array<string,mixed> $plan Install plan.
	 */
	private function hasSharedAssetConflicts( array $plan ): bool {
		foreach ( $plan['shared_assets'] as $asset ) {
			if ( 'conflict' === $asset['action'] ) {
				return true;
			}
		}

		return false;
	}
}

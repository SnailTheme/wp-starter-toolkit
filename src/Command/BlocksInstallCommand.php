<?php
/**
 * blocks:install command.
 *
 * Installs a packaged block into a destination slug chosen by the developer,
 * applying theme patterns and block-specific replacements.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Command;

use RuntimeException;
use SnailTheme\WPStarterToolkit\Block\BlockInstaller;
use SnailTheme\WPStarterToolkit\Block\BlockManifest;
use SnailTheme\WPStarterToolkit\Block\NpmRunner;
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
		$npm         = new NpmRunner();

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
		$npmInstall = array(
			'status'  => 'skipped',
			'command' => 'dependencies already satisfied',
		);

		if ( ! $plan['core_version_satisfied'] ) {
			$output->writeln(
				sprintf(
					'<error>Block "%s" requires core %s. Installed core is %s.</error>',
					$manifest->sourceSlug(),
					$plan['required_core_version'],
					$theme->coreVersion
				)
			);
			return self::FAILURE;
		}

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

		if ( array() !== $plan['missing_dependencies'] ) {
			if ( ! $this->shouldInstallDependencies( $input, $output, $plan['npm_install_command'] ) ) {
				$output->writeln( '<comment>Block install cancelled because required npm dependencies are missing.</comment>' );
				return self::FAILURE;
			}

			try {
				if ( ! $this->wantsJson( $input ) ) {
					$output->writeln( sprintf( '<info>Running:</info> %s', $plan['npm_install_command'] ) );
				}
				$npmInstall = $npm->installDependencies( $theme, $plan['missing_dependencies'] );
			} catch ( RuntimeException $exception ) {
				if ( $this->wantsJson( $input ) ) {
					$this->writeJson(
						$output,
						array(
							'status'  => 'failed',
							'command' => $plan['npm_install_command'],
							'message' => $exception->getMessage(),
						)
					);
					return self::FAILURE;
				}
				$output->writeln( sprintf( '<error>%s</error>', $exception->getMessage() ) );
				return self::FAILURE;
			}

			$plan = $installer->plan( $theme, $manifest, $destination );
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
		$result['npm_install'] = $npmInstall;

		try {
			if ( ! $this->wantsJson( $input ) ) {
				$output->writeln( sprintf( '<info>Running:</info> %s', $result['build_command'] ) );
			}
			$result['build'] = $npm->build( $theme );
		} catch ( RuntimeException $exception ) {
			$result['build'] = array(
				'status'  => 'failed',
				'command' => $npm->buildCommand(),
				'message' => $exception->getMessage(),
			);

			if ( $this->wantsJson( $input ) ) {
				$this->writeJson( $output, $result );
				return self::FAILURE;
			}

			$output->writeln( '<error>Block install completed, but the asset build failed.</error>' );
			$output->writeln( sprintf( '<error>%s</error>', $exception->getMessage() ) );
			$output->writeln( sprintf( 'Backup: %s', $result['backup_path'] ) );
			$output->writeln( sprintf( '<comment>Fix the build issue, then run:</comment> %s', $npm->buildCommand() ) );

			return self::FAILURE;
		}

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

		if ( array() === $plan['missing_dependencies'] ) {
			$output->writeln( '- npm dependencies ok' );
		} else {
			foreach ( $plan['missing_dependencies'] as $name => $version ) {
				$output->writeln( sprintf( '<comment>Missing npm dependency:</comment> %s@%s', $name, $version ) );
			}

			$output->writeln( sprintf( '- planned npm install: %s', $plan['npm_install_command'] ) );
		}

		if ( isset( $plan['npm_install'] ) ) {
			$output->writeln( sprintf( '- npm install %s: %s', $plan['npm_install']['status'], $plan['npm_install']['command'] ) );
		}

		foreach ( $plan['changes'] as $change ) {
			$output->writeln( sprintf( '- %s %s', $change['action'], $change['path'] ) );
		}

		foreach ( $plan['shared_assets'] as $asset ) {
			$output->writeln( sprintf( '- shared %s %s', $asset['action'], $asset['path'] ) );
		}

		if ( isset( $plan['build'] ) ) {
			$output->writeln( sprintf( '- build %s: %s', $plan['build']['status'], $plan['build']['command'] ) );
		} elseif ( $plan['build_required'] ) {
			$output->writeln( sprintf( '- planned build: %s', $plan['build_command'] ) );
		}
	}

	/**
	 * Decide whether missing npm dependencies should be installed now.
	 */
	private function shouldInstallDependencies( InputInterface $input, OutputInterface $output, string $command ): bool {
		if ( (bool) $input->getOption( 'yes' ) ) {
			return true;
		}

		if ( ! $input->isInteractive() ) {
			$output->writeln( sprintf( '<error>Missing npm dependencies. Run "%s" or re-run this command with --yes.</error>', $command ) );
			return false;
		}

		$helper = $this->getHelper( 'question' );

		return (bool) $helper->ask(
			$input,
			$output,
			new ConfirmationQuestion( sprintf( 'Missing npm dependencies. Run "%s" now? [y/N] ', $command ), false )
		);
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

<?php
/**
 * blocks:install command.
 *
 * Installs a standalone block into a chosen slug or a fixed-member bundle,
 * applying theme patterns and block-specific replacements.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Command;

use RuntimeException;
use SnailTheme\WPStarterToolkit\Block\BlockInstaller;
use SnailTheme\WPStarterToolkit\Block\BlockBundleInstaller;
use SnailTheme\WPStarterToolkit\Block\BlockBundleManifest;
use SnailTheme\WPStarterToolkit\Block\BlockManifest;
use SnailTheme\WPStarterToolkit\Block\NpmRunner;
use SnailTheme\WPStarterToolkit\Support\PackagePaths;
use SnailTheme\WPStarterToolkit\Theme\ThemeContext;
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
			->setDescription( 'Install a packaged block or bundle into the target theme.' )
			->addArgument( 'source-block', InputArgument::REQUIRED, 'Packaged block or bundle slug, for example slider or elementals.' )
			->addArgument( 'destination-block', InputArgument::OPTIONAL, 'Required for standalone blocks; forbidden for fixed-member bundles.' )
			->addOption( 'replace-shared-assets', null, InputOption::VALUE_NONE, 'Replace declared shared assets when checksums differ.' );

		$this->addSharedOptions( dryRun: true, yes: true );
	}

	protected function execute( InputInterface $input, OutputInterface $output ): int {
		try {
			return $this->executeInstall( $input, $output );
		} catch ( \Throwable $exception ) {
			if ( ! $this->wantsJson( $input ) ) {
				throw $exception;
			}
			$this->writeJson( $output, array( 'status' => 'failed', 'message' => $exception->getMessage() ) );
			return self::FAILURE;
		}
	}

	private function executeInstall( InputInterface $input, OutputInterface $output ): int {
		$theme       = $this->theme( $input );
		$source      = (string) $input->getArgument( 'source-block' );
		$destination = (string) $input->getArgument( 'destination-block' );
		if ( 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $source ) ) {
			throw new RuntimeException( 'Invalid source block slug.' );
		}
		if ( is_file( ( new PackagePaths() )->resources( 'blocks/' . $source . '/st-bundle.json' ) ) ) {
			if ( null !== $input->getArgument( 'destination-block' ) || $input->getOption( 'replace-shared-assets' ) ) {
				throw new RuntimeException( 'Fixed-member bundles accept no destination override or shared-asset replacement.' );
			}
			return $this->installBundle( $theme, BlockBundleManifest::load( $source ), $input, $output );
		}
		$manifest    = BlockManifest::load( $source );
		if ( true === ( $manifest->data()['bundle_only'] ?? false ) ) {
			throw new RuntimeException( 'This block is bundle-only. Install elementals instead.' );
		}
		if ( '' === $destination ) {
			throw new RuntimeException( 'A destination-block argument is required for standalone blocks.' );
		}
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
					'<error>Block "%s" requires core %s. Installed core is %s. Run composer toolkit:core-update first.</error>',
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
	 * Bundle confirmation covers the complete staged plan, not individual members.
	 */
	private function installBundle( ThemeContext $theme, BlockBundleManifest $bundle, InputInterface $input, OutputInterface $output ): int {
		$installer = new BlockBundleInstaller();
		$prepared = $installer->plan( $theme, $bundle );
		$plan = $prepared->toArray();
		if ( (bool) $input->getOption( 'dry-run' ) ) {
			$plan['dry_run'] = true;
			if ( $this->wantsJson( $input ) ) {
				return $this->writeJson( $output, $plan );
			}
			$output->writeln( '<info>Block bundle install dry run</info>' );
			$this->printPlan( $output, $plan );
			return self::SUCCESS;
		}
		if ( ! $this->wantsJson( $input ) ) {
			$this->printPlan( $output, $plan );
		}
		// JSON automation must opt in with --yes and never start a prompt/npm run.
		$confirmed = $this->wantsJson( $input )
			? (bool) $input->getOption( 'yes' )
			: $this->confirm( $input, $output, 'Install all bundle members and replace the listed conflicts?' );
		if ( ! $confirmed ) {
			$plan['status'] = 'cancelled';
			$plan['message'] = 'Block bundle install cancelled. Re-run with --yes to confirm non-interactively.';
			if ( $this->wantsJson( $input ) ) {
				$this->writeJson( $output, $plan );
			} else {
				$output->writeln( '<comment>' . $plan['message'] . '</comment>' );
			}
			return $input->isInteractive() && ! $this->wantsJson( $input ) ? self::SUCCESS : self::FAILURE;
		}
		$result = $installer->install( $prepared );
		$failed = 'failed' === $result['build']['status'];
		if ( $this->wantsJson( $input ) ) {
			$this->writeJson( $output, $result );
		} else {
			$output->writeln( $failed ? '<error>Block bundle installed, but the asset build failed.</error>' : '<info>Block bundle install complete</info>' );
			$output->writeln( 'Backup: ' . $result['backup_path'] );
			if ( $failed ) {
				$output->writeln( $result['build']['message'] );
				$output->writeln( $result['recovery'] );
			}
		}
		return $failed ? self::FAILURE : self::SUCCESS;
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

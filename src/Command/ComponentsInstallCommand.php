<?php
/**
 * components:install command.
 *
 * Installs reusable component behavior into the active theme project.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Command;

use RuntimeException;
use SnailTheme\WPStarterToolkit\Block\NpmRunner;
use SnailTheme\WPStarterToolkit\Component\ComponentInstaller;
use SnailTheme\WPStarterToolkit\Component\ComponentManifest;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Install a toolkit component.
 */
final class ComponentsInstallCommand extends ToolkitCommand {
	protected static $defaultName = 'components:install';

	protected function configure(): void {
		$this
			->setName( 'components:install' )
			->setDescription( 'Install a reusable component into the theme.' )
			->addArgument( 'component', InputArgument::REQUIRED, 'Component slug, for example mega-menu.' )
			->addOption( 'force', null, InputOption::VALUE_NONE, 'Replace existing component files after backup.' );

		$this->addSharedOptions( true, true );
	}

	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$theme     = $this->theme( $input );
		$component = ComponentManifest::load( (string) $input->getArgument( 'component' ) );
		$installer = new ComponentInstaller();
		$plan      = $installer->plan( $theme, $component );

		if ( (bool) $input->getOption( 'dry-run' ) ) {
			return $this->render( $input, $output, $plan, true );
		}

		if ( ! $plan['ui_profile_ready'] ) {
			$output->writeln( '<error>Component installation requires an installed UI profile.</error>' );
			$output->writeln( 'Choose the project profile first with composer toolkit:ui-install PROFILE.' );

			return self::FAILURE;
		}

		if ( ! $plan['core_version_satisfied'] ) {
			$output->writeln(
				sprintf(
					'<error>Component %s requires core %s; the theme has %s. Run composer toolkit:core-update first.</error>',
					$component->slug(),
					$plan['required_core_version'],
					$theme->coreVersion
				)
			);

			return self::FAILURE;
		}

		if ( array() !== $plan['conflicts'] && ! (bool) $input->getOption( 'force' ) ) {
			$output->writeln( '<error>Existing component files differ. Review the dry run and use --force to replace them.</error>' );

			return self::FAILURE;
		}

		if ( ! $this->confirm( $input, $output, sprintf( 'Install component "%s"?', $component->slug() ) ) ) {
			$output->writeln( '<comment>Component installation cancelled.</comment>' );

			return self::FAILURE;
		}

		$result = $installer->install( $theme, $component, (bool) $input->getOption( 'force' ) );

		try {
			$result['build'] = ( new NpmRunner() )->build( $theme );
		} catch ( RuntimeException $exception ) {
			$result['build'] = array( 'status' => 'failed', 'message' => $exception->getMessage() );
			$this->render( $input, $output, $result, false );

			if ( ! $this->wantsJson( $input ) ) {
				$output->writeln( sprintf( '<error>%s</error>', $exception->getMessage() ) );
				$output->writeln( sprintf( '<error>Component files were installed, but the build failed. Backup: %s</error>', $result['backup_path'] ) );
			}

			return self::FAILURE;
		}

		return $this->render( $input, $output, $result, false );
	}

	/**
	 * Print an install plan or completed result.
	 *
	 * @param array<string,mixed> $data Component result.
	 */
	private function render( InputInterface $input, OutputInterface $output, array $data, bool $dryRun ): int {
		unset( $data['prepared_path'], $data['file_checksums'] );

		if ( $this->wantsJson( $input ) ) {
			$data['dry_run'] = $dryRun;

			return $this->writeJson( $output, $data );
		}

		$output->writeln( $dryRun ? '<info>Component dry run</info>' : '<info>Component installation complete</info>' );
		$output->writeln( sprintf( 'Component: %s %s', $data['component'], $data['version'] ) );
		$output->writeln(
			sprintf(
				'Core requirement: %s (%s)',
				$data['required_core_version'] ?: 'none',
				$data['core_version_satisfied'] ? 'satisfied' : 'update required'
			)
		);
		$output->writeln(
			sprintf(
				'UI profile: %s (%s)',
				$data['ui_profile'],
				$data['ui_profile_ready'] ? 'ready' : 'ui:install required'
			)
		);

		foreach ( $data['changes'] as $change ) {
			$output->writeln( sprintf( '- %s %s', $change['action'], $change['path'] ) );
		}

		if ( $dryRun ) {
			if ( ! $data['ui_profile_ready'] ) {
				$output->writeln( '- required before install: composer toolkit:ui-install PROFILE' );
			}

			$output->writeln( sprintf( '- planned build: %s', $data['build_command'] ) );
		} else {
			$output->writeln( sprintf( 'Backup: %s', $data['backup_path'] ) );
			$output->writeln( sprintf( 'Build: %s', $data['build']['status'] ) );
		}

		return self::SUCCESS;
	}
}

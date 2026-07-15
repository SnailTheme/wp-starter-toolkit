<?php
/**
 * ui:install command.
 *
 * Installs a project-owned style scaffold, its optional build integration,
 * and any required development dependencies.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Command;

use RuntimeException;
use SnailTheme\WPStarterToolkit\Block\NpmRunner;
use SnailTheme\WPStarterToolkit\Block\PackageJsonInspector;
use SnailTheme\WPStarterToolkit\UI\UIProfileInstaller;
use SnailTheme\WPStarterToolkit\UI\UIProfileManifest;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Choose the theme UI profile or explicitly replace a locked selection.
 */
final class UiInstallCommand extends ToolkitCommand {
	protected static $defaultName = 'ui:install';

	protected function configure(): void {
		$this
			->setName( 'ui:install' )
			->setDescription( 'Choose and lock a toolkit UI profile for the theme.' );
		$this->addArgument( 'profile', InputArgument::REQUIRED, 'Profile slug, for example bare, blueprint, or tailwind.' );
		$this->addOption( 'force', null, InputOption::VALUE_NONE, 'Replace locally modified profile files after backup.' );
		$this->addOption( 'replace', null, InputOption::VALUE_NONE, 'Replace a locked UI profile after destructive-change review.' );
		$this->addSharedOptions( true, true );
	}

	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$theme     = $this->theme( $input );
		$slug      = (string) $input->getArgument( 'profile' );
		$profile   = UIProfileManifest::load( $slug );
		$installer = new UIProfileInstaller();
		$npm       = new NpmRunner();
		$plan      = $installer->plan( $theme, $profile );
		$missing   = ( new PackageJsonInspector() )->missing( $theme, $profile->npmDevDependencies() );
		$plan['missing_dependencies'] = $missing;
		$plan['npm_install_command']  = array() === $missing ? '' : $npm->installDevCommand( $missing );
		$plan['build_command']        = $npm->buildCommand();
		$replace                      = (bool) $input->getOption( 'replace' );

		if ( (bool) $input->getOption( 'dry-run' ) ) {
			return $this->render( $input, $output, $plan, true );
		}

		if ( $plan['replacement_required'] && ! $replace ) {
			$output->writeln( '<error>The active UI profile is locked.</error>' );
			$output->writeln( 'Changing it removes or replaces files managed by the current UI scaffold.' );
			$output->writeln( 'Run the target profile with --dry-run, then explicitly re-run with --replace.' );

			return self::FAILURE;
		}

		if ( array() !== $plan['conflicts'] && ! (bool) $input->getOption( 'force' ) ) {
			$output->writeln( '<error>Modified profile files would be replaced or removed.</error>' );
			$output->writeln( 'Review ui:install with --dry-run, then re-run with --force.' );

			return self::FAILURE;
		}

		if ( $plan['replacement_required'] ) {
			$output->writeln( '<fg=yellow;options=bold>DESTRUCTIVE UI PROFILE REPLACEMENT</>' );
			$output->writeln(
				sprintf(
					'All files managed by "%s" that are not part of "%s" will be deleted after backup.',
					$plan['current_profile'],
					$plan['target_profile']
				)
			);
		}

		$confirmation = $plan['replacement_required']
			? sprintf( 'Replace locked UI profile "%s" with "%s"?', $plan['current_profile'], $slug )
			: sprintf( 'Choose and lock UI profile "%s"?', $slug );

		if ( ! $this->confirm( $input, $output, $confirmation ) ) {
			$output->writeln( '<comment>UI profile installation cancelled.</comment>' );

			return self::FAILURE;
		}

		if ( array() !== $missing ) {
			try {
				$plan['npm_install'] = $npm->installDevDependencies( $theme, $missing );
			} catch ( RuntimeException $exception ) {
				$output->writeln( sprintf( '<error>%s</error>', $exception->getMessage() ) );

				return self::FAILURE;
			}
		}

		$result = $installer->install( $theme, $profile, (bool) $input->getOption( 'force' ), $replace );
		$result['missing_dependencies'] = $missing;
		$result['npm_install']          = $plan['npm_install'] ?? array( 'status' => 'skipped', 'command' => 'dependencies already satisfied' );

		try {
			$result['build'] = $npm->build( $theme );
		} catch ( RuntimeException $exception ) {
			$result['build'] = array( 'status' => 'failed', 'command' => $npm->buildCommand(), 'message' => $exception->getMessage() );
			$this->render( $input, $output, $result, false );

			if ( ! $this->wantsJson( $input ) ) {
				$output->writeln( sprintf( '<error>Profile files were installed, but the build failed. Backup: %s</error>', $result['backup_path'] ) );
			}

			return self::FAILURE;
		}

		return $this->render( $input, $output, $result, false );
	}

	/**
	 * Print a profile plan or completed install.
	 *
	 * @param array<string,mixed> $data Profile result.
	 */
	private function render( InputInterface $input, OutputInterface $output, array $data, bool $dryRun ): int {
		unset( $data['prepared_path'], $data['target_file_checksums'] );

		if ( $this->wantsJson( $input ) ) {
			$data['dry_run'] = $dryRun;

			return $this->writeJson( $output, $data );
		}

		$output->writeln( $dryRun ? '<info>UI profile dry run</info>' : '<info>UI profile installation complete</info>' );
		$output->writeln( sprintf( 'Profile: %s -> %s', $data['current_profile'], $data['target_profile'] ) );
		$output->writeln( sprintf( 'Current selection: %s', $data['ui_locked'] ? 'locked' : 'not yet locked' ) );

		if ( $data['replacement_required'] ) {
			$output->writeln( '<fg=yellow;options=bold>WARNING: this is a destructive UI profile replacement.</>' );
			$output->writeln( '- obsolete files managed by the current UI profile will be deleted' );
			$output->writeln( '- target profile files will replace matching managed files' );
			$output->writeln( '- a backup is created before the profile files change' );
			$output->writeln( '- --replace is required; locally modified managed files also require --force' );
		} elseif ( $data['first_selection'] ) {
			$output->writeln( '- this first UI choice will be locked for the project' );
		}

		foreach ( $data['changes'] as $change ) {
			$output->writeln( sprintf( '- %s %s%s', $change['action'], $change['path'], $change['modified'] ? ' (locally modified)' : '' ) );
		}

		foreach ( $data['missing_dependencies'] ?? array() as $name => $version ) {
			$output->writeln( sprintf( '- missing development dependency %s@%s', $name, $version ) );
		}

		if ( $dryRun ) {
			if ( ! empty( $data['npm_install_command'] ) ) {
				$output->writeln( sprintf( '- planned npm install: %s', $data['npm_install_command'] ) );
			}

			$output->writeln( sprintf( '- planned build: %s', $data['build_command'] ) );
		} else {
			$output->writeln( sprintf( 'Backup: %s', $data['backup_path'] ) );
			$output->writeln( sprintf( 'Build: %s', $data['build']['status'] ) );

			if ( ! empty( $data['build']['message'] ) ) {
				$output->writeln( sprintf( '<error>Build error: %s</error>', $data['build']['message'] ) );
			}
		}

		return self::SUCCESS;
	}
}

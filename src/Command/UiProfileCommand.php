<?php
/**
 * Read-only profile verification and confirmed maintainer exports.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Command;

use RuntimeException;
use SnailTheme\WPStarterToolkit\Support\PackagePaths;
use SnailTheme\WPStarterToolkit\UI\UIProfileMaintainer;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The same preflight powers verification and export, with separate CLI names.
 */
final class UiProfileCommand extends ToolkitCommand {
	public function __construct( private readonly bool $export = false ) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName( $this->export ? 'ui:profile-export' : 'ui:profile-verify' )
			->setDescription( $this->export ? 'Export allowlisted workbench sources into this toolkit checkout (maintainers only).' : 'Compare workbench sources with packaged UI files and checksums, without writing.' )
			->addArgument( 'profile', InputArgument::REQUIRED, 'Packaged UI profile slug.' );
		$this->addSharedOptions( $this->export, $this->export );
	}

	protected function execute( InputInterface $input, OutputInterface $output ): int {
		try {
			$theme   = $this->theme( $input );
			$slug    = (string) $input->getArgument( 'profile' );
			$service = new UIProfileMaintainer();
			$plan    = $service->plan( $theme, $slug, $this->export );
			$dryRun  = $this->export && (bool) $input->getOption( 'dry-run' );
			$status  = ! $this->export && ! $plan['clean'] ? self::FAILURE : self::SUCCESS;
			$plan['exported'] = false;
			$plan['dry_run']  = $dryRun;
			if ( ! $this->wantsJson( $input ) ) {
				$output->writeln( sprintf( 'Profile %s %s: %s', $slug, $plan['version'], $plan['clean'] ? 'matches packaged resources' : 'drift detected' ) );
				foreach ( $plan['changes'] as $change ) {
					if ( 'matching' !== $change['action'] || $change['checksum_stale'] ) {
						$output->writeln( sprintf( '%s %s%s', $change['action'], $change['path'], $change['checksum_stale'] ? ' (packaged checksum stale)' : '' ) );
					}
				}
			}
			if ( $this->export && ! $dryRun && ! $plan['clean'] ) {
				$checkout = ( new PackagePaths() )->root() . '/.git';
				if ( is_link( $checkout ) || ( ! is_dir( $checkout ) && ! is_file( $checkout ) ) ) {
					throw new RuntimeException( 'Export requires a toolkit Git checkout; do not modify an installed vendor package.' );
				}
				if ( ( $this->wantsJson( $input ) && ! $input->getOption( 'yes' ) ) || ! $this->confirm( $input, $output, 'Replace the listed packaged profile files and refresh checksums? Versions will not change.' ) ) {
					$plan['cancelled'] = true;
					$status = self::FAILURE;
				} else {
					unset( $plan['exported'], $plan['dry_run'] );
					$plan = $service->export( $theme, $slug, $plan );
					$plan['exported'] = true;
					$plan['dry_run'] = false;
				}
			}
			unset( $plan['snapshot'], $plan['checksums'] );
			if ( $this->wantsJson( $input ) ) {
				$this->writeJson( $output, $plan );
				return $status;
			}
			if ( isset( $plan['backup_path'] ) ) {
				$output->writeln( 'Export complete. Backup: ' . $plan['backup_path'] );
			} elseif ( $dryRun ) {
				$output->writeln( 'Dry run: no files written.' );
			} elseif ( isset( $plan['cancelled'] ) ) {
				$output->writeln( 'Export cancelled: no files written.' );
			}
			return $status;
		} catch ( \Throwable $exception ) {
			if ( $this->wantsJson( $input ) ) {
				$this->writeJson( $output, array( 'error' => $exception->getMessage() ) );
			} else {
				$output->writeln( $exception->getMessage(), OutputInterface::OUTPUT_RAW );
			}
			return self::FAILURE;
		}
	}
}

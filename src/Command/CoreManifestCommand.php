<?php
/**
 * core:manifest maintainer command; never targets an installed theme.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Command;

use RuntimeException;
use SnailTheme\WPStarterToolkit\Core\CoreManifestGenerator;
use SnailTheme\WPStarterToolkit\Support\PackagePaths;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Regenerates only package resource checksums, with explicit confirmation.
 */
final class CoreManifestCommand extends ToolkitCommand {
	protected static $defaultName = 'core:manifest';

	public function __construct( private readonly CoreManifestGenerator $generator = new CoreManifestGenerator() ) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName( 'core:manifest' )
			->setDescription( 'Validate or regenerate packaged core manifest checksums.' )
			->addOption( 'check', null, InputOption::VALUE_NONE, 'Check checksums without writing; exit nonzero on drift.' )
			->addOption( 'dry-run', null, InputOption::VALUE_NONE, 'Show checksum changes without writing.' )
			->addOption( 'yes', 'y', InputOption::VALUE_NONE, 'Confirm writes without prompting.' )
			->addOption( 'json', null, InputOption::VALUE_NONE, 'Print machine-readable JSON output.' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ): int {
		try {
			$check = (bool) $input->getOption( 'check' );
			$dryRun = (bool) $input->getOption( 'dry-run' );
			if ( ! $check && ! $dryRun ) {
				$git = ( new PackagePaths() )->root() . '/.git';
				if ( is_link( $git ) || ( ! is_dir( $git ) && ! is_file( $git ) ) ) {
					throw new RuntimeException( 'Writing core checksums requires a package Git checkout (.git directory or worktree file); use --check or --dry-run in vendor installations.' );
				}
			}
			$result = $this->generator->plan();
			$approvedSnapshot = $result['snapshot'];
			unset( $result['snapshot'] );
			$result['check'] = $check;
			$result['dry_run'] = $dryRun;
			$result['written'] = false;
			$result['cancelled'] = false;
			if ( ! $this->wantsJson( $input ) ) {
				$output->writeln( '<info>ST Core Manifest</info>' );
				$output->writeln( sprintf( 'Core version: %s; packaged files: %d', $result['version'], $result['file_count'] ) );
				foreach ( $result['changes'] as $change ) {
					$output->writeln( sprintf( '- %s %s', $change['action'], $change['path'] ), OutputInterface::OUTPUT_RAW );
				}
			}
			if ( ! $check && ! $dryRun && $result['drift'] ) {
				// Machine-readable output must never be mixed with an interactive prompt.
				if ( ( $this->wantsJson( $input ) && ! $input->getOption( 'yes' ) ) || ! $this->confirm( $input, $output, 'Write packaged core manifest checksums?' ) ) {
					$result['cancelled'] = true;
				} else {
					$generated = $this->generator->generate( $approvedSnapshot );
					unset( $generated['snapshot'] );
					$result = array_replace( $result, $generated );
				}
			}
			if ( $this->wantsJson( $input ) ) {
				$this->writeJson( $output, $result );
			} else {
				if ( $result['cancelled'] ) {
					$output->writeln( '<comment>Core manifest generation cancelled.</comment>' );
				}
				$output->writeln( $result['written'] ? 'Checksums written.' : ( $result['drift'] ? 'Checksum drift detected.' : 'Checksums are current.' ) );
			}
			return $result['cancelled'] || ( $check && $result['drift'] ) ? self::FAILURE : self::SUCCESS;
		} catch ( RuntimeException $exception ) {
			if ( $this->wantsJson( $input ) ) {
				$this->writeJson( $output, array( 'error' => $exception->getMessage() ) );
			} else {
				$output->writeln( $exception->getMessage(), OutputInterface::OUTPUT_RAW );
			}
			return self::FAILURE;
		}
	}
}

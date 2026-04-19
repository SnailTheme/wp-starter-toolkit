<?php
/**
 * Base command helpers.
 *
 * Shared options keep the command surface consistent:
 * --theme for explicit theme detection, --dry-run for planning, --yes for
 * non-interactive confirmation, and --json for automation.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Command;

use SnailTheme\WPStarterToolkit\Theme\ThemeContext;
use SnailTheme\WPStarterToolkit\Theme\ThemeDetector;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Common helpers for toolkit commands.
 */
abstract class ToolkitCommand extends Command {
	private readonly ThemeDetector $themeDetector;

	public function __construct( ?ThemeDetector $themeDetector = null ) {
		parent::__construct();

		$this->themeDetector = $themeDetector ?? new ThemeDetector();
	}

	/**
	 * Add shared command options.
	 */
	protected function addSharedOptions( bool $dryRun = false, bool $yes = false ): void {
		$this->addOption( 'theme', null, InputOption::VALUE_REQUIRED, 'Path to the target theme.' );
		$this->addOption( 'json', null, InputOption::VALUE_NONE, 'Print machine-readable JSON output.' );

		if ( $dryRun ) {
			$this->addOption( 'dry-run', null, InputOption::VALUE_NONE, 'Show planned changes without writing files.' );
		}

		if ( $yes ) {
			$this->addOption( 'yes', 'y', InputOption::VALUE_NONE, 'Confirm writes without prompting.' );
		}
	}

	/**
	 * Resolve the target theme from shared options.
	 */
	protected function theme( InputInterface $input ): ThemeContext {
		$theme = $input->getOption( 'theme' );

		return $this->themeDetector->detect( is_string( $theme ) ? $theme : null );
	}

	/**
	 * Whether --json output was requested.
	 */
	protected function wantsJson( InputInterface $input ): bool {
		return (bool) $input->getOption( 'json' );
	}

	/**
	 * Write JSON or human output.
	 *
	 * @param array<string,mixed> $data Output data.
	 */
	protected function writeJson( OutputInterface $output, array $data ): int {
		$output->writeln(
			(string) json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
		);

		return self::SUCCESS;
	}

	/**
	 * Ask for confirmation unless --yes is provided.
	 */
	protected function confirm( InputInterface $input, OutputInterface $output, string $question ): bool {
		if ( (bool) $input->getOption( 'yes' ) ) {
			return true;
		}

		if ( ! $input->isInteractive() ) {
			return false;
		}

		$helper = $this->getHelper( 'question' );

		return (bool) $helper->ask( $input, $output, new ConfirmationQuestion( $question . ' [y/N] ', false ) );
	}
}

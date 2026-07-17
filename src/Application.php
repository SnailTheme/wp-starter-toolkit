<?php
/**
 * Console application bootstrap.
 *
 * The toolkit is a development-only CLI. It avoids bootstrapping WordPress so
 * commands can safely inspect and transform a theme from Composer scripts,
 * CI jobs, or a plain terminal.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit;

use SnailTheme\WPStarterToolkit\Command\BlocksInstallCommand;
use SnailTheme\WPStarterToolkit\Command\BlocksListCommand;
use SnailTheme\WPStarterToolkit\Command\CoreCheckCommand;
use SnailTheme\WPStarterToolkit\Command\CoreRollbackCommand;
use SnailTheme\WPStarterToolkit\Command\CoreUpdateCommand;
use SnailTheme\WPStarterToolkit\Command\ComponentsInstallCommand;
use SnailTheme\WPStarterToolkit\Command\ComponentsListCommand;
use SnailTheme\WPStarterToolkit\Command\DocsUpdateCommand;
use SnailTheme\WPStarterToolkit\Command\DoctorCommand;
use SnailTheme\WPStarterToolkit\Command\InitCommand;
use SnailTheme\WPStarterToolkit\Command\UiInstallCommand;
use SnailTheme\WPStarterToolkit\Command\UiListCommand;
use SnailTheme\WPStarterToolkit\Command\UiStatusCommand;
use Symfony\Component\Console\Application as ConsoleApplication;

/**
 * Registers all toolkit commands.
 */
final class Application extends ConsoleApplication {
	/**
	 * Build the CLI application with all v1 commands.
	 */
	public function __construct() {
		parent::__construct( 'ST WP Starter Toolkit', '1.1.0' );

		$this->add( new CoreCheckCommand() );
		$this->add( new CoreUpdateCommand() );
		$this->add( new CoreRollbackCommand() );
		$this->add( new DocsUpdateCommand() );
		$this->add( new DoctorCommand() );
		$this->add( new InitCommand() );
		$this->add( new BlocksListCommand() );
		$this->add( new BlocksInstallCommand() );
		$this->add( new UiListCommand() );
		$this->add( new UiStatusCommand() );
		$this->add( new UiInstallCommand() );
		$this->add( new ComponentsListCommand() );
		$this->add( new ComponentsInstallCommand() );
	}
}

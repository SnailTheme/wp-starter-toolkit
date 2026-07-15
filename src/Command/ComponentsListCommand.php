<?php
/**
 * components:list command.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Command;

use SnailTheme\WPStarterToolkit\Component\ComponentRepository;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * List reusable toolkit components.
 */
final class ComponentsListCommand extends ToolkitCommand {
	protected static $defaultName = 'components:list';

	protected function configure(): void {
		$this
			->setName( 'components:list' )
			->setDescription( 'List toolkit components.' );
		$this->addSharedOptions();
	}

	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$components = ( new ComponentRepository() )->all();

		if ( $this->wantsJson( $input ) ) {
			return $this->writeJson( $output, array( 'components' => $components ) );
		}

		$output->writeln( '<info>Available components</info>' );

		foreach ( $components as $component ) {
			$output->writeln(
				sprintf(
					'- %s (%s, core %s): %s',
					$component['slug'],
					$component['version'],
					$component['required_core_version'] ?: 'any',
					$component['description']
				)
			);
		}

		return self::SUCCESS;
	}
}

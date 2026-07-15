<?php
/**
 * ui:list command.
 *
 * Lists every styling profile bundled with the installed toolkit version.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Command;

use SnailTheme\WPStarterToolkit\UI\UIProfileRepository;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * List available UI profiles.
 */
final class UiListCommand extends ToolkitCommand {
	protected static $defaultName = 'ui:list';

	protected function configure(): void {
		$this
			->setName( 'ui:list' )
			->setDescription( 'List toolkit UI profiles.' );
		$this->addSharedOptions();
	}

	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$profiles = ( new UIProfileRepository() )->all();

		if ( $this->wantsJson( $input ) ) {
			return $this->writeJson( $output, array( 'profiles' => $profiles ) );
		}

		$output->writeln( '<info>Available UI profiles</info>' );

		foreach ( $profiles as $profile ) {
			$output->writeln(
				sprintf( '- %s (%s): %s', $profile['slug'], $profile['version'], $profile['description'] )
			);
		}

		return self::SUCCESS;
	}
}

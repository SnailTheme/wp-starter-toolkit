<?php
/**
 * blocks:list command.
 *
 * Lists curated block packages shipped with the toolkit.
 */

declare(strict_types=1);

namespace SnailTheme\WPStarterToolkit\Command;

use SnailTheme\WPStarterToolkit\Block\BlockRepository;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * List packaged blocks.
 */
final class BlocksListCommand extends ToolkitCommand {
	protected static $defaultName = 'blocks:list';

	protected function configure(): void {
		$this
			->setName( 'blocks:list' )
			->setDescription( 'List block packages available for installation.' );

		$this->addSharedOptions();
	}

	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$blocks = array_map(
			static fn ( $manifest ): array => array(
				'slug'                  => $manifest->sourceSlug(),
				'title'                 => $manifest->defaultTitle(),
				'required_core_version' => $manifest->requiredCoreVersion(),
			),
			( new BlockRepository() )->all()
		);

		if ( $this->wantsJson( $input ) ) {
			return $this->writeJson( $output, array( 'blocks' => $blocks ) );
		}

		$output->writeln( '<info>Available Blocks</info>' );

		foreach ( $blocks as $block ) {
			$output->writeln(
				sprintf(
					'- %s (%s), requires core %s',
					$block['slug'],
					$block['title'],
					$block['required_core_version'] ?: 'any'
				)
			);
		}

		return self::SUCCESS;
	}
}

<?php

namespace Team51\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;

/**
 * CLI command for launching a given Pressable site.
 */
final class Pressable_Site_Launch extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * {@inheritdoc}
	 */
	protected static $defaultName = 'pressable:launch-site'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

	/**
	 * The Pressable site to process.
	 *
	 * @var object|null
	 */
	protected ?object $pressable_site = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Performs various automated actions to ease the launch process of a website.' )
			->setHelp( 'This command allows you convert a given Pressable staging site into a live site and update its main URL. If any 1Password entries use the old URL, those are updated as well to use the new one.' );

		$this->addArgument( 'site', InputArgument::REQUIRED, 'ID or URL of the site which is being launched.' )
			->addArgument( 'url', InputArgument::REQUIRED, 'The launched URL of the site.' );
	}

	// endregion
}

<?php

namespace Team51\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Team51\Helper\WPCOM_API_Helper;
use function Team51\Helper\delete_pressable_site_collaborator_by_id;
use function Team51\Helper\delete_wpcom_site_user_by_id;
use function Team51\Helper\get_email_input;
use function Team51\Helper\get_pressable_collaborators;
use function Team51\Helper\get_wpcom_sites;
use function Team51\Helper\maybe_define_console_verbosity;

/**
 * CLI command to remove a Pressable collaborators and WPCOM user by email.
 */
final class Remove_User extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * {@inheritdoc}
	 */
	protected static $defaultName = 'remove-user'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

	/**
	 * The user identifier. Currently, only email is supported.
	 *
	 * @var string|null
	 */
	protected ?string $user = null;

	/**
	 * Whether to just list the sites where the user was found.
	 *
	 * @var bool|null
	 */
	protected ?bool $just_list = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Removes a Pressable collaborator and WordPress user by email.' )
			->setHelp( 'This command allows you to delete in bulk via CLI all Pressable collaborators and WPCOM users registered with the given email.' );

		$this->addArgument( 'user', InputArgument::REQUIRED, 'The email of the user you\'d like to remove access from sites.' )
			->addOption( 'list', null, InputOption::VALUE_NONE, 'Instead of removing the user, just list the sites where an account was found.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		maybe_define_console_verbosity( $output->getVerbosity() );

		$this->user      = get_email_input( $input, $output, null, 'user' );
		$this->just_list = (bool) $input->getOption( 'list' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$action = $this->just_list ? "Listing all sites where $this->user is found." : "Removing $this->user from all sites.";
		$output->writeln( "<fg=magenta;options=bold>$action</>" );

		// Get collaborators from Pressable
		$output->writeln( '<comment>Getting collaborator data from Pressable.</comment>' );

		$pressable_collaborators = $this->get_pressable_collaborators();
		if ( ! \is_array( $pressable_collaborators ) ) {
			$output->writeln( '<error>Something has gone wrong while looking up the Pressable collaborators.</error>' );
			return 1;
		}

		if ( empty( $pressable_collaborators ) ) {
			$output->writeln( "<info>No collaborators found in Pressable with the email '$this->user'.</info>" );
		} else {
			$this->output_pressable_collaborators( $output, $pressable_collaborators );
		}

		// Get users from wordpress.com
		$output->writeln( '<comment>Getting user data from WordPress.com.</comment>' );

		$wpcom_users = $this->get_wpcom_users( $output );
		if ( ! \is_array( $wpcom_users ) ) {
			$output->writeln( '<error>Something has gone wrong while looking up the WordPress.com collaborators.</error>' );
			return 1;
		}

		if ( empty( $wpcom_users ) ) {
			$output->writeln( "<info>No users found on WordPress.com sites with the email '$this->user'.</info>" );
		} else {
			$this->output_wpcom_collaborators( $output, $wpcom_users );
		}

		// Remove?
		if ( $this->just_list ) {
			return 0;
		}
		if ( $input->isInteractive() ) {
			$question = new ConfirmationQuestion( '<question>Are you sure you want to remove this user on <fg=red;options=bold>ALL</> sites listed above? [y/N]</question> ', false );
			if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
				$output->writeln( '<comment>Aborting.</comment>' );
				return 1;
			}
		}

		foreach ( $pressable_collaborators as $collaborator ) {
			if ( delete_pressable_site_collaborator_by_id( $collaborator->siteId, $collaborator->id ) ) {
				$output->writeln( "<info>✅ Removed $collaborator->email from Pressable site $collaborator->siteName.</info>" );
			} else {
				$output->writeln( "<comment>❌ Failed to remove from $collaborator->email from Pressable site $collaborator->siteName.</comment>" );
			}
		}
		foreach ( $wpcom_users as $user ) {
			if ( delete_wpcom_site_user_by_id( $user->siteId, $user->userId ) ) {
				$output->writeln( "<info>✅ Removed $user->email from WordPress.com site $user->siteName.</info>" );
			} else {
				$output->writeln( "<comment>❌ Failed to remove $user->email from WordPress.com site $user->siteName.</comment>" );
			}
		}

		return 0;
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the Pressable collaborator objects that match the given email.
	 *
	 * @return  object[]|null
	 */
	protected function get_pressable_collaborators(): ?array {
		$collaborators = get_pressable_collaborators();
		if ( ! \is_array( $collaborators ) ) {
			return null;
		}

		return \array_filter(
			$collaborators,
			fn ( $collaborator ) => $collaborator->email === $this->user,
		);
	}

	/**
	 * Outputs the Pressable collaborators to the console in tabular form.
	 *
	 * @param   OutputInterface $output         The output object.
	 * @param   object[]        $collaborators  The collaborators to output.
	 *
	 * @return  void
	 */
	protected function output_pressable_collaborators( OutputInterface $output, array $collaborators ): void {
		$table = new Table( $output );

		$table->setHeaderTitle( "$this->user is a collaborator on the following Pressable sites" );
		$table->setHeaders( array( 'Default Pressable URL', 'Site ID', 'Collaborator ID' ) );

		foreach ( $collaborators as $collaborator ) {
			$table->addRow( array( $collaborator->siteName . '.mystagingwebsite.com', $collaborator->siteId, $collaborator->id ) );
		}

		$table->setStyle( 'box-double' );
		$table->render();
	}

	/**
	 * Returns the WordPress.com collaborator objects that match the given email.
	 *
	 * @return  object[]|null
	 * @noinspection PhpDocMissingThrowsInspection
	 */
	protected function get_wpcom_users( OutputInterface $output ): ?array {
		// Get sites from WPCOM.
		$output->writeln( '<comment>Fetching the list of WordPress.com & Jetpack sites...</comment>', OutputInterface::VERBOSITY_VERBOSE );

		$sites = get_wpcom_sites( array( 'fields' => 'ID,URL' ) );
		if ( ! \is_array( $sites ) ) {
			return null;
		}

		$excluded = array( 'https://woocommerce.com' );
		$sites    = \array_filter(
			$sites,
			static fn ( $site ) => ! \in_array( $site->URL, $excluded, true ),
		);

		// Search for the user on each site.
		$output->writeln( "<comment>Searching for '$this->user' across " . \count( $sites ) . ' WordPress.com & Jetpack sites...</comment>', OutputInterface::VERBOSITY_VERBOSE );

		$collaborators = WPCOM_API_Helper::call_api_concurrent(
			\array_map(
				fn ( $site ) => "sites/$site->ID/users/?search=$this->user&search_columns=user_email&fields=ID,email,site_ID,URL",
				$sites
			)
		);
		$failed_sites  = \array_intersect_key( $sites, \array_filter( $collaborators, static fn ( $collaborator ) => \is_null( $collaborator ) ) );
		$collaborators = \array_filter( $collaborators, static fn ( $collaborator ) => \is_object( $collaborator ) && 0 < $collaborator->found );

		! empty( $failed_sites ) && $this->output_wpcom_failed_sites( $output, $failed_sites );

		return \array_map(
			static fn( string $site_id, object $collaborator ) => (object) array(
				'siteId'   => $site_id,
				'siteName' => $sites[ $site_id ]->URL,
				'userId'   => $collaborator->users[0]->ID,
			),
			\array_keys( $collaborators ),
			$collaborators,
		);
	}

	/**
	 * Outputs the WordPress.com sites that failed to fetch collaborators from.
	 *
	 * @param   OutputInterface $output The output object.
	 * @param   array           $sites  The sites to output.
	 *
	 * @return  void
	 */
	protected function output_wpcom_failed_sites( OutputInterface $output, array $sites ): void {
		$table = new Table( $output );

		$table->setHeaderTitle( 'Failed to fetch collaborators from the following WordPress.com sites' );
		$table->setHeaders( array( 'WP URL', 'Site ID' ) );

		foreach ( $sites as $site ) {
			$table->addRow( array( $site->URL, $site->ID ) );
		}

		$table->setStyle( 'box-double' );
		$table->render();
	}

	/**
	 * Outputs the WordPress.com collaborators to the console in tabular form.
	 *
	 * @param   OutputInterface $output         The output object.
	 * @param   object[]        $collaborators  The collaborators to output.
	 *
	 * @return  void
	 */
	protected function output_wpcom_collaborators( OutputInterface $output, array $collaborators ): void {
		$table = new Table( $output );

		$table->setHeaderTitle( "$this->user is a collaborator on the following WordPress.com sites" );
		$table->setHeaders( array( 'WP URL', 'Site ID', 'WP User ID' ) );

		foreach ( $collaborators as $collaborator ) {
			$table->addRow( array( $collaborator->siteName, $collaborator->siteId, $collaborator->userId ) );
		}

		$table->setStyle( 'box-double' );
		$table->render();
	}

	// endregion
}

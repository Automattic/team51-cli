<?php

namespace Team51\Command;

use stdClass;
use Team51\Helper\API_Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use function Team51\Helper\create_pressable_site_collaborator;
use function Team51\Helper\get_pressable_site_by_id;
use function Team51\Helper\get_pressable_site_collaborator_default_roles;
use function Team51\Helper\get_pressable_sites;

class Pressable_Grant_Access extends Command {
	protected static $defaultName = 'pressable-grant-access';

	/**
	 * Access to the QuestionHelper
	 *
	 * @return \Symfony\Component\Console\Helper\QuestionHelper
	 */
	private function get_question_helper(): QuestionHelper {
		return $this->getHelper( 'question' );
	}

	/** @inheritDoc */
	protected function configure() {
		$this
		->setDescription( 'Grants user access to a Pressable site' )
		->setHelp( 'Requires --email and --site. Grants access to a Pressable site, using site ID or site domain.' )
		->addOption( 'email', null, InputOption::VALUE_REQUIRED, 'The user email.' )
		->addOption( 'site', null, InputOption::VALUE_OPTIONAL, 'The Pressable site. Can be a numeric site ID or by domain.' )
		->addOption( 'search', null, InputOption::VALUE_OPTIONAL, 'Search for any site by domain.' );
	}

	/**
	 * Main callback for the command.
	 *
	 * @param \Symfony\Component\Console\Input\InputInterface $input
	 * @param \Symfony\Component\Console\Output\OutputInterface $output
	 * @return void
	 */
	protected function execute( InputInterface $input, OutputInterface $output ) {
		// Handle if searching by domain or using the passed site name.
		if ( $input->getOption( 'search' ) ) {
			$this->handle_search_for_site( $input, $output );
		} else {
			$this->handle_site_by_name( $input, $output );
		}

		$output->writeln( "<info>\nAll done!<info>" );
	}

	/***********************************
	 ***********************************
	 *          INPUT GETTERS          *
	 ***********************************
	 ***********************************/

	/**
	 * Gets the email address either form Input or prompted for.
	 *
	 * @param \Symfony\Component\Console\Input\InputInterface $input
	 * @param \Symfony\Component\Console\Output\OutputInterface $output
	 * @return string
	 */
	private function get_email( InputInterface $input, OutputInterface $output ): string {
		// Attempt to get the email from the Input.
		$email = $input->getOption( 'email' );

		// If we don't have an email, prompt the user.
		if ( empty( $email ) ) {
			$email = $this->get_question_helper()->ask( $input, $output, $this->ask_for_email_address() );
		}

		// If we still don't have en email fail.
		if ( empty( $email ) ) {
			$output->writeln( '<error>You must supply a valid email address.</error>' );
			exit;
		}

		return $email;
	}

	/**
	 * Gets the search term either form Input or prompted for.
	 *
	 * @param \Symfony\Component\Console\Input\InputInterface $input
	 * @param \Symfony\Component\Console\Output\OutputInterface $output
	 * @return string
	 */
	private function get_search_term( InputInterface $input, OutputInterface $output ): string {
		// Attempt to get search term from Input.
		$search_term = $input->getOption( 'search' );

		// If we still don't have a search term fail.
		if ( empty( $search_term ) ) {
			$output->writeln( '<error>You must supply a valid search term to look for matching domains.</error>' );
			exit;
		}

		return $search_term;
	}

	/**
	 * Gets the site url either form Input or prompted for.
	 *
	 * @param \Symfony\Component\Console\Input\InputInterface $input
	 * @param \Symfony\Component\Console\Output\OutputInterface $output
	 * @return string
	 */
	private function get_site( InputInterface $input, OutputInterface $output ): string {
		// Attempt to get site name from Input.
		$site_name = $input->getOption( 'site' );

		// If we don't have a site name, prompt the user.
		if ( empty( $site_name ) ) {
			$site_name = $this->get_question_helper()->ask( $input, $output, $this->ask_site_name() );
		}

		// If we still don't have a site name fail.
		if ( empty( $site_name ) ) {
			$output->writeln( '<error>You must supply a valid site URL.</error>' );
			exit;
		}

		return $site_name;
	}

	/*************************************
	 *************************************
	 *             QUESTIONS             *
	 *************************************
	 *************************************/


	/**
	 * Returns the question for looking for a site.
	 *
	 * @return \Symfony\Component\Console\Question\Question
	 */
	private function ask_site_name(): Question {
		return new Question( 'Please enter the sites url or ID: ', false );
	}

	/**
	 * Returns the question to ask for the email.
	 *
	 * @return \Symfony\Component\Console\Question\Question
	 */
	private function ask_for_email_address(): Question {
		return new Question( 'Please enter the email: ', false );
	}

	/**
	 * Returns the confirmation question for if a site should be granted access to.
	 *
	 * @return \Symfony\Component\Console\Question\ConfirmationQuestion
	 */
	private function ask_to_grant_access_for_site( string $site_name ): ConfirmationQuestion {
		return new ConfirmationQuestion( 'Grant access to ' . $site_name . '? [y|yes|n|no] ', false );
	}

	/*************************************
	 *************************************
	 *             HANDLERS              *
	 *************************************
	 *************************************/

	/**
	 * Handles if we are searching for a site by domain/name.
	 *
	 * @param \Symfony\Component\Console\Input\InputInterface $input
	 * @param \Symfony\Component\Console\Output\OutputInterface $output
	 * @return void
	 */
	private function handle_search_for_site( InputInterface $input, OutputInterface $output ): void {
		// Get the email address.
		$email = $this->get_email( $input, $output );

		// Find all matching sites.
		$search_term = $this->get_search_term( $input, $output );
		$sites       = $this->search_for_matching_sites( $search_term );

		// If we have no sites, fail.
		if ( empty( $sites ) ) {
			$output->writeln( '<error>No sites found matching the search term.</error>' );
			exit;
		}

		// Callback for granting permission
		$grant_access = $this->grant_access_to_site( $input, $output );

		$output->writeln( sprintf( '<info>Searching for all sites containing "%s"</info>', $search_term ) );

		// Iterate through all sites and prompt.
		foreach ( $sites as $site ) {
			// Check if we should grant access to this site.
			if ( $this->get_question_helper()->ask( $input, $output, $this->ask_to_grant_access_for_site( $site->url ) ) ) {
				$output->writeln( sprintf( '<info>Attempting to grant access to: %s for: %s</info>', $site->url, $email ) );
				$grant_access( $email, $site->id );
			} else {
				$output->writeln( sprintf( '<error>User chose to not give access to: %s for: %s</error>', $site->url, $email ) );
			}
		}
	}

	/**
	 * Handles if we are granting access to a site by siteID or domain.
	 *
	 * @param \Symfony\Component\Console\Input\InputInterface $input
	 * @param \Symfony\Component\Console\Output\OutputInterface $output
	 * @return void
	 */
	private function handle_site_by_name( InputInterface $input, OutputInterface $output ): void {
		// Get the email address.
		$email = $this->get_email( $input, $output );

		$site_name = $this->get_site( $input, $output );

		if ( is_numeric( $site_name ) ) {
			$site = get_pressable_site_by_id( (int) $site_name );
		} else {
			// Find the site by name. Fail if we can't find it.
			$site = $this->search_for_site_url( $site_name );
		}
		if ( ! $site ) {
			$output->writeln( '<error>Site not found.</error>' );
			exit;
		}

		// Callback for granting permission
		$output->writeln( sprintf( '<info>Attempting to grant access to: %s for: %s</info>', $site->url, $email ) );

		$grant_access = $this->grant_access_to_site( $input, $output );
		$grant_access( $email, $site->id );
	}

	/************************************
	 ************************************
	 *         PRESSABLE ACCESS         *
	 ************************************
	 ************************************/

	/**
	 * Attempts to find a site by domain.
	 *
	 * @param string $site_url
	 * @return null|stdClass
	 */
	private function search_for_site_url( string $site_url ): ?\stdClass {
		// Get all sites from Pressable.
		$sites = get_pressable_sites();

		// If we have no results.
		if ( is_null( $sites ) ) {
			return null;
		}

		foreach ( $sites as $site ) {
			// if $site_url is the end of site url
			if ( substr( $site->url, - strlen( $site_url ) ) === $site_url ) {
				return $site;
			}
		}

		// Return null if not found.
		return null;
	}

	/**
	 * Searches all sites for any which have a name/domain matching the search term.
	 *
	 * @param string $search_term
	 * @return \stdClass[]
	 */
	private function search_for_matching_sites( string $search_term ): array {
		// Get all sites from Pressable.
		$sites = get_pressable_sites();

		// If we have no results.
		if ( is_null( $sites ) ) {
			return array();
		}

		return array_filter(
			$sites,
			function( \stdClass $site ) use ( $search_term ): bool {
				return ( strpos( $site->name, $search_term ) !== false
				|| strpos( $site->url, $search_term ) !== false );
			}
		);
	}

	/**
	 * Creates a closure that can be used to grant access to a site by url or siteID.
	 *
	 * @param \Symfony\Component\Console\Input\InputInterface $input
	 * @param \Symfony\Component\Console\Output\OutputInterface $output
	 * @return \Closure(string $email, string $site): void
	 */
	private function grant_access_to_site( InputInterface $input, OutputInterface $output ): \Closure {
		/**
		 * @param string $email The users email
		 * @param int $site_id The site to grant access to
		 * @return void
		 */
		return function( string $email, $site_id ) use ( $input, $output ): void {
			$output->writeln( '<comment>Granting ' . $email . ' access to site ' . $site_id . '.</comment>' );

			$async_result = create_pressable_site_collaborator( $email, $site_id, null, true );
			if ( ! \is_null( $async_result ) ) {
				$output->writeln( "<info>\nCollaborator added to the site.<info>" );
			} else {
				$output->writeln( "<error>\nSomething went wrong while running collaborators/batch_create!<error>" );
			}
		};
	}
}

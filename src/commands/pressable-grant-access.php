<?php

namespace Team51\Command;

use Team51\Helper\API_Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Pressable_Grant_Access extends Command {
	protected static $defaultName = 'pressable-grant-access';
	private $api_helper;
	private $output;

	protected function configure() {
		$this
		->setDescription( 'Grants user access to a Pressable site' )
		->setHelp( 'Requires --email and --site. Grants access to Pressable a site, using site ID or site domain.' )
		->addOption( 'email', null, InputOption::VALUE_REQUIRED, "The user email." )
		->addOption( 'site', null, InputOption::VALUE_REQUIRED, "The Pressable site. Can be a numeric site ID or by domain." );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$this->api_helper = new API_Helper();
		$this->output     = $output;

		$email = $input->getOption( 'email' );
		$site = $input->getOption( 'site' );

		if ( empty( $email ) ) {
			$email = trim( readline( 'Please enter the email: ' ) );
			if ( empty( $email ) ) {
				$output->writeln( '<error>Missing email (--email=someone@a8c.com).</error>' );
				exit;
			}
		}

		if ( empty( $site ) ) {
			$site = trim( readline( 'Please enter the site: ' ) );
			if ( empty( $site ) ) {
				$output->writeln( '<error>Missing site (--site=my-webiste.com).</error>' );
				exit;
			}
		}

		$output->writeln( '<comment>Granting ' . $email . ' access to site ' . $site . '.</comment>' );

		$site_id = null;
		if ( is_numeric( $site ) ) {
			$site_id = $site;
		} else {
			$search_site = $this->api_helper->call_pressable_api( sprintf('sites', $site), 'GET', array() );
			$output->writeln( '<comment>Looping through ' . count($search_site->data) . ' domains searching for ' . $site . '.</comment>' );

			// Loop to find site_id by domain
			foreach ( $search_site->data as $key => $val ) {
				if ( $val->url === $site) {
					$site_id = $val->id;
					$output->writeln( "<comment>Found it! It's site ID {$site_id}</comment>" );
					break;
				}
			}
		}

		if ( ! $site_id ) {
			$output->writeln( "<error>We couldn't find any site ID or domain similar to '{$site}'</error>" );
			exit;
		}

		// Note: batch_create is needed because it's the only way to assign sftp_access roles to the new user
		// POST /sites/{site_id}/collaborators would be a better fit if it allowed the `roles` parame
		$async_result = $this->api_helper->call_pressable_api(
			'collaborators/batch_create',
			'POST',
			array(
				'email' => $email,
				'siteIds' => [$site_id],
				'roles' => [ 'clone_site', 'sftp_access', 'download_backups', 'reset_collaborator_password', 'wp_access' ]
			)
		);

		if ( $async_result->errors === NULL ) {
			$output->writeln( "<info>\nCollaborator added to the site. Pressable says: {$async_result->message}<info>" );
		} else {
			$output->writeln( "<error>\nSomething went wrong while running collaborators/batch_create! Message: {$async_result->message}<error>" );
		}


		$output->writeln( "<info>\nAll done!<info>" );
	}
}

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
		->setHelp( 'Requires --client_id and --client_secret. This command allows you to generate a Pressable OAuth token for a given API Application Client ID and Client Secret. This allows external collaborators to have access to Pressable functionality using Team51 CLI.' )
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

		$site_id;
		if ( is_numeric( $site ) ) {
			$site_id = $site;
		} else {
			$search_site = $this->api_helper->call_pressable_api( sprintf('sites?per_page=5&tag_name=%s', $site), 'GET', array() );
			var_dump($search_site);
			// TODO: loop and find site_id by domain
		}

		// Note: batch_create is needed because it's the only way to assign sftp_access roles to the new user
		// POST /sites/{site_id}/collaborators would be a better fit if it allowed the `roles` parame
		$async_result = $this->api_helper->call_pressable_api(
			'collaborators/batch_create',
			'POST',
			array(
				'email' => $email,
				'siteIds' => [$site],
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

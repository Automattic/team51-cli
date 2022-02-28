<?php

namespace Team51\Command;

use Team51\Helper\API_Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Rotate_GitHub_Secrets extends Command {
    protected static $defaultName = 'rotate-github-secrets';

    protected function configure() {
        $this
        ->setDescription( 'Updates GitHub secrets across all site repositories within the GITHUB_API_OWNER organization.' )
		->setHelp( 'This command allows you to bulk-update GitHub secrets.' );
    }

    protected function execute( InputInterface $input, OutputInterface $output ) {
		$api_helper = new API_Helper();

		$mappings = array();

		$output->writeln( '<comment>Getting data from DeployHQ.</comment>' );

		$projects = $api_helper->call_deploy_hq_api( 'projects', 'GET', array() );

		if ( empty( $projects ) ) {
			$output->writeln( '<error>Failed to get data from DeployHQ.</error>' );
			exit;
		}

		foreach ( $projects as $project ) {
			$repository    = false;
			$sftp_username = false;

			// Get git repo name.
			if ( empty( $project->repository->url ) ) {
				continue;
			}

			if ( ! preg_match( '/git@github.com:' . GITHUB_API_OWNER . '\/(.*).git/', $project->repository->url, $matches ) ) {
				continue;
			}

			$repository = $matches[1];

			// Get SFTP username.
			$servers = $api_helper->call_deploy_hq_api( "projects/{$project->name}/servers", 'GET', array() );

			if ( empty( $servers ) ) {
				continue;
			}

			foreach ( $servers as $server ) {
				if ( ! empty( $server->branch ) && 'trunk' === $server->branch ) {
					$sftp_username = $server->username;
					break;
				}
			}

			if ( ! empty( $repository ) && ! empty( $sftp_username ) ) {
				$mappings[ $sftp_username ] = array(
					'repository' => $repository
				);
			}
		}

		$output->writeln( '<comment>Getting site data from Pressable.</comment>' );

		$sites = $api_helper->call_pressable_api( 'sites', 'GET', array() );

		if ( 'Success' !== $sites->message || empty( $sites->data ) ) {
			$output->writeln( '<error>Failed to get data from Pressable.</error>' );
			exit;
		}

		foreach ( $sites->data as $site ) {

			// Get SFTP accounts for this site.
			$users = $api_helper->call_pressable_api( "/sites/{$site->id}/ftp", 'GET', array() );

			if ( 'Success' !== $users->message || empty( $users->data ) ) {
				continue;
			}

			$sftp_username = false;

			foreach ( $users->data as $user ) {
				if ( PRESSABLE_ACCOUNT_EMAIL === $user->email ) {
					$sftp_username = $user->username;
					break;
				}
			}

			if ( ! empty( $sftp_username ) && array_key_exists( $sftp_username, $mappings ) ) {
				$mappings[ $sftp_username ]['site_url'] = $site->url;
			}
		}

		$output->writeln( '<comment>Adding secrets to GitHub.</comment>' );

		foreach ( $mappings as $sftp => $data ) {
			$secrets = array(
				'GH_BOT_TOKEN'   => GITHUB_API_TOKEN,
				'DEPLOYHQ_TOKEN' => DEPLOY_HQ_API_KEY,
				'SITE_URL_TRUNK' => $data['site_url'],
			);

			$gh_key = $api_helper->call_github_api(
				sprintf( 'repos/%s/%s/actions/secrets/public-key', GITHUB_API_OWNER, $data['repository'] ),
				array(),
				'GET'
			);

			if ( empty( $gh_key ) ) {
				$output->writeln( "<error>Failed to get public key for repository '{$data['repository']}, skipping'.</error>" );
				continue;
			}

			foreach ( $secrets as $secret_name => $secret_value ) {
				$public_key = sodium_base642bin( $gh_key->key, SODIUM_BASE64_VARIANT_ORIGINAL );

				$secret = $api_helper->call_github_api(
					sprintf( 'repos/%s/%s/actions/secrets/%s', GITHUB_API_OWNER, $data['repository'], $secret_name ),
					array(
						'encrypted_value' => base64_encode( sodium_crypto_box_seal( $secret_value, $public_key ) ),
						'key_id'          => $gh_key->key_id,
					),
					'PUT'
				);
			}
		}

		$output->writeln( "<info>All done.</info>" );
    }
}

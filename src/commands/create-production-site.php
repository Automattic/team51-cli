<?php

namespace Team51\Command;

use Team51\Helper\API_Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;

class Create_Production_Site extends Command {
    protected static $defaultName = 'create-production-site';

    protected function configure() {
        $this
        ->setDescription( "Creates a new production site (on Pressable)." )
        ->setHelp( "This command allows you to create a new production site." )
        ->addOption( 'site-name', null, InputOption::VALUE_REQUIRED, "This is root name that will be given to the site. Think of it as really the project name. No need to specifiy \"prod\" or \"development\" in the naming here. The script will take care of that for you -- no spaces, hyphens, non-alphanumeric characters, or capitalized letters." )
        ->addOption( 'connect-to-repo', null, InputOption::VALUE_REQUIRED, "The repository you'd like to have automatically configured in DeployHQ to work with the new site. This accepts the repository slug.\nOnly GitHub repositories are supported and they must be in the a8cteam51 organization, otherwise the script won't have access." );
    }

    protected function execute( InputInterface $input, OutputInterface $output ) {
        $api_helper = new API_Helper;

        $manual_task_notices = array();

        if ( empty( $input->getOption( 'site-name' ) ) ) {
            $output->writeln('<error>Site name is required for production site creation.</error>');
            exit;
        }

        if ( empty( $input->getOption( 'connect-to-repo' ) ) ) {
            $output->writeln('<error>GitHub repository name is required for production site creation.</error>');
            exit;
        }

        // We call the command line parameter 'site-name' for readability, but it's really our project name.
        // Let's make sure it's valid for all the places we'll use it, error if not.
        $project_name = $this->_slugify( $input->getOption( 'site-name' ) );
        if ( $project_name !== $input->getOption( 'site-name' ) ) {
            $output->writeln( "<error>The site-name parameter you entered is not valid. Try $project_name instead.</error>" );
            exit;
        }

        $github_repo = $input->getOption( 'connect-to-repo' );
        $site_name   = "{$project_name}-production";

        $output->writeln( "<comment>Creating new Pressable site</comment>" );
        $pressable_site = $api_helper->call_pressable_api(
            'sites',
            'POST',
            array(
                'name' => $site_name,
            )
        );

        // TODO: This code is duplicated below for the site clone. Should be a function.
        if ( empty( $pressable_site->data ) || empty( $pressable_site->data->id ) ) {
            $output->writeln( "<error>Failed to create new Pressable site. Aborting!</error>" );
            exit;
        } else {
            $output->writeln( "<info>Created new Pressable site.</info>\n" );
        }

        $output->writeln( "<comment>Waiting for Pressable site to deploy.</comment>" );
        $progress_bar = new ProgressBar( $output );
        $progress_bar->start();
        do {
            $pressable_site_check = $api_helper->call_pressable_api( "sites/{$pressable_site->data->id}", 'GET', array() );

            if ( empty( $pressable_site_check->data ) || empty( $pressable_site_check->data->id ) ) {
                $output->writeln( "<error>Something has gone wrong while checking on the Pressable site. Aborting!</error>" );
                exit;
            }

            if ( ! empty( $pressable_site_check->data->state ) ) {
                $pressable_site_state = $pressable_site_check->data->state;
            } else {
                $pressable_site_state = 'deployed';
            }

            $progress_bar->advance();
            sleep( 1 );
        } while ( 'deploying' === $pressable_site_state );

        $progress_bar->finish();
        $output->writeln( "" );
        $output->writeln( "<info>The Pressable site has been deployed!</info>\n" );

        $jetpack_activation_link  = sprintf( 'https://my.pressable.com/sites/%d/jetpack_partnership/activate', (int) $pressable_site->data->id );
        $jetpack_connection_link  = sprintf( 'https://my.pressable.com/sites/%d/jetpack_partnership/next_url', (int) $pressable_site->data->id );
        $networkadmin_search_link = sprintf( 'https://wordpress.com/wp-admin/network/sites.php?s=%s&submit=Search+Sites', $pressable_site->data->url );

        $manual_task_notices[] = 'Make sure your browser is logged in to the Team Pressable and WordPress.com accounts.';
        $manual_task_notices[] = 'Install/activate Jetpack: ' . $jetpack_activation_link;
        $manual_task_notices[] = 'Connect Jetpack: ' . $jetpack_connection_link;
        $manual_task_notices[] = 'Flag the Jetpack shadow site as "blue" from its RC: ' . $networkadmin_search_link;
        $manual_task_notices[] = 'Sticker this site as team-owned/managed: https://mc.a8c.com/tdiv/team-51/';

        $server_config = array(
            'name'        => 'Production',
            'environment' => 'production',
            'branch'      => 'master',
        );

        // Set server config elements common to production and development environments.
        $server_config['server_path'] = 'wp-content';

        // Grab SFTP connection info from Pressable.
        $ftp_data = $api_helper->call_pressable_api( "sites/{$pressable_site->data->id}/ftp", 'GET', array() );

        if( ! empty( $ftp_data->data ) ) {
            foreach( $ftp_data->data as $ftp_user ) {
                if( true === $ftp_user->owner ) { // If concierge@wordpress.com is the owner, grab the info.
                    $server_config['pressable_sftp_username'] = $ftp_user->username;
                    $server_config['pressable_sftp_hostname'] = $ftp_user->sftpDomain;

                    $password_reset = $api_helper->call_pressable_api( "sites/{$pressable_site->data->id}/ftp/password/{$ftp_user->username}", 'POST', array() );
                    if( ! empty( $password_reset->data ) ) {
                        $server_config['pressable_sftp_password'] = $password_reset->data;
                    }
                }
            }
        }

        $output->writeln( "<comment>Creating new project in DeployHQ</comment>" );
        $project_info = $api_helper->call_deploy_hq_api( 'projects', 'POST', array( 'name' => $project_name ) );

        if ( empty( $project_info ) || empty( $project_info->permalink ) ) {
            $output->writeln( "<error>Failed to create new project in DeployHQ. Aborting!</error>" );
            exit;
        } else {
            $output->writeln( "<info>Created new project in DeployHQ.</info>\n" );
        }


        // Make sure we received a public_key when we created the project.
        $output->writeln( "<comment>Verifying we received a public key when we created the new DeployHQ project.</comment>" );
        if ( empty( $project_info ) || empty( $project_info->public_key ) ) {
            $output->writeln( "<error>Failed to retrieve public key from new DeployHQ project. Aborting!</error>" );
            exit;
        } else {
            $output->writeln( "<info>Successfully retrieved public key from new DeployHQ project.</info>\n" );
        }

        $github_api_query = 'repos/' . GITHUB_API_OWNER . '/' . $github_repo . '/keys';

        $output->writeln( "<comment>Adding DeployHQ public key to GitHub repository's deploy keys.</comment>" );
        $github_deploy_key_request = $api_helper->call_github_api(
            $github_api_query,
            array(
                'title'     => 'DeployHQ',
                'key'       => $project_info->public_key,
                'read_only' => false,
            )
        );

        if ( empty( $github_deploy_key_request ) || empty( $github_deploy_key_request->id ) ) {
            $output->writeln( "<error>Failed to add DeployHQ public key to GitHub repository. Aborting!</error>" );
            exit;
        } else {
            $output->writeln( "<info>Successfully added DeployHQ public key to GitHub repository.</info>\n" );
        }

        $repository_url = "git@github.com:a8cteam51/$github_repo.git";

        $output->writeln( "<comment>Connecting DeployHQ project to GitHub repository.</comment>" );
        $deploy_hq_add_repository_request = $api_helper->call_deploy_hq_api(
            "projects/{$project_info->permalink}/repository",
            'POST',
            array(
                'repository' => array(
                    'scm_type' => 'git',
                    'url'      => $repository_url,
                    'branch'   => 'master',
                    'username' => null,
                    'port'     => null,
                ),
            )
        );

        if ( empty( $deploy_hq_add_repository_request ) || empty( $deploy_hq_add_repository_request->url ) ) {
            $output->writeln( "<error>Failed to add GitHub repository to DeployHQ. Aborting!</error>" );
            exit;
        } else {
            $output->writeln( "<info>Successfully added and configured GitHub repository in DeployHQ</info>\n" );
        }

        $output->writeln( "<comment>Creating new DeployHQ {$server_config['environment']} server for project $project_name.</comment>" );

        $progress_bar->start();
        while ( empty( $server_info ) || empty( $server_info->host_key ) ) {
            $server_info = $api_helper->call_deploy_hq_api(
                "projects/{$project_info->permalink}/servers",
                'POST',
                array(
                    'server' => array(
                        'name'               => $server_config['name'],
                        'protocol_type'      => 'ssh',
                        'server_path'        => $server_config['server_path'],
                        'email_notify_on'    => 'never',
                        'root_path'          => '',
                        'auto_deploy'        => true,
                        'notification_email' => '',
                        'branch'             => $server_config['branch'],
                        'environment'        => $server_config['environment'],
                        'hostname'           => $server_config['pressable_sftp_hostname'],
                        'username'           => $server_config['pressable_sftp_username'],
                        'password'           => $server_config['pressable_sftp_password'],
                        'port'               => 22,
                    ),
                )
            );
            
            $progress_bar->advance();
            sleep( 1 );
        }

        $progress_bar->finish();
        $output->writeln( "" );

        if ( empty( $server_info ) || empty( $server_info->host_key ) ) {
            $output->writeln( "<error>Failed to create new server in DeployHQ. Aborting!</error>" );
            exit;
        } else {
            $output->writeln( "<info>Created new server in DeployHQ.</info>\n" );
        }

        $output->writeln( "<comment>Verifying we received a webhook URL for automatic deploys when we created the new DeployHQ project.</comment>" );
        if ( empty( $project_info ) || empty( $project_info->auto_deploy_url ) ) {
            $output->writeln( "<error>Failed to retrieve webhook URL from new DeployHQ project. Aborting!</error>" );
            exit;
        } else {
            $output->writeln( "<info>Successfully retrieved webhook URL from new DeployHQ project.</info>\n" );
        }

        $github_api_query = 'repos/' . GITHUB_API_OWNER . '/' . $github_repo . '/hooks';

        $output->writeln( "<comment>Adding DeployHQ webhook URL to GitHub repository's list of hooks.</comment>" );
        $github_webhook_url_request = $api_helper->call_github_api(
            $github_api_query,
            array(
                'name'   => 'web',
                'events' => array( 'push' ),
                'active' => true,
                'config' => array(
                    'url'          => $project_info->auto_deploy_url,
                    'content_type' => 'form',
                    'insecure_ssl' => 0,
                ),
            )
        );

        if ( empty( $github_webhook_url_request ) || empty( $github_webhook_url_request->id ) ) {
            $output->writeln( "<error>Failed to add DeployHQ webhook URL to GitHub repository. Aborting!</error>" );
            exit;
        } else {
            $output->writeln( "<info>Successfully added DeployHQ webhook URL to GitHub repository.</info>\n" );
        }

        $manual_task_notices[] = 'On DeployHQ for this project, visit Integrations -> New Integration -> HTTP Post -> set Endpoint to https://webhooks.wpspecialprojects.com/hooks/deployhq-event and then Create.';

        $output->writeln( "\n<info>Deploy HQ is now set up and ready to start receiving and deploying commits!</info>\n" );

        $output->writeln( "<comment>IMPORTANT: there are now some tasks you must perform manually:</comment>" );
        $manual_task_count = 1;
        foreach ( $manual_task_notices as $notice ) {
            $output->writeln( "<comment>$manual_task_count) $notice</comment>" );
            $manual_task_count++;
        }
        
        $output->writeln( "" );

        $site_name_for_slack = $input->getOption( 'site-name' );

        $api_helper->log_to_slack(
            sprintf(
                'INFO: Pressable / DeployHQ: %s run for %s',
                'create-production-site',
                $site_name_for_slack
            )
        );

        exit;
    }

    // Convert a text string to something ready to be used as a unique, machine-friendly identifier
    protected function _slugify( $_text ) {

        $_slug = strtolower( $_text ); // convert to lowercase
        $_slug = preg_replace( '/\s+/', '-', $_slug ); // convert all contiguous whitespace to a single hyphen
        $_slug = preg_replace( '/[^a-z0-9\-]/', '', $_slug ); // Lowercase alphanumeric characters and dashes are allowed.
        $_slug = preg_replace( '/-+/', '-', $_slug ); // convert multiple contiguous hyphens to a single hyphen

        return $_slug;
    }
}

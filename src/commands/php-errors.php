<?php

namespace Team51\Command;

use Team51\Helper\API_Helper;
use phpseclib3\Net\SFTP;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableSeparator;

class Get_PHP_Errors extends Command {
    protected static $defaultName = 'php-errors';

    protected function configure() {
        $this
        ->setDescription( "Pulls the 3 most recent fatal errors from the site's PHP error log." )
        ->setHelp( "Ex: team51 php-errors asia.si.edu" )
        ->addArgument( 'site-domain', InputArgument::REQUIRED, "Site domain/URL (e.g. asia.si.edu)." )
        ->addOption( 'raw', null, InputOption::VALUE_NONE, "You can get an unprocessed dump to stdout of the entire php-errors log by passing --raw." )
        ->addOption( 'table', null, InputOption::VALUE_NONE, "Print the results in a table by using --table." )
        ->addOption( 'limit', null, InputOption::VALUE_REQUIRED, "You can choose the number of distinct PHP fatals to display with (ex: --limit=10). Default is 3." );
    }

    protected function execute( InputInterface $input, OutputInterface $output ) {
        $api_helper = new API_Helper;

        if ( ! empty( $input->getOption( 'raw' ) ) && ! empty( $input->getOption( 'table' ) ) ) {
            $output->writeln( "<error>You can't have --raw and --table. Pick one!</error>" );
            exit;
        }

        $site_domain = $input->getArgument( 'site-domain' );

        // Strip out everything except the hostname if we detect a URL is passed.
        if ( false !== strpos( $site_domain, 'http' ) ) {
            $site_domain = parse_url( $site_domain, PHP_URL_HOST );
        }

        $output->writeln( "<comment>Finding $site_domain.</comment>" );

        $pressable_sites = $api_helper->call_pressable_api(
            "sites/",
            'GET',
            array()
        );

        if ( empty( $pressable_sites->data ) ) {
            $output->writeln( "<error>Failed to retrieve Pressable sites. Aborting!</error>" );
            exit;
        }

        $pressable_site = null;

        foreach( $pressable_sites->data as $_pressable_site ) {
            if ( ! empty( $site_domain ) && $site_domain !== $_pressable_site->url ) {
                continue;
            }

			$pressable_site = $_pressable_site;
            break;
        }

        if ( empty( $pressable_site->id ) ) {
            $output->writeln( "<error>Failed to find $site_domain. Aborting!</error>" );
            exit;
        }

        $output->writeln( "<comment>Adding temporary bot collaborator to $site_domain.</comment>" );

        $add_collaborator_request = $api_helper->call_pressable_api(
                "collaborators/batch_create",
                'POST',
                array(
                    'siteIds' => array(
                        $pressable_site->id,
                    ),
                    'email'   => PRESSABLE_BOT_COLLABORATOR_EMAIL,
                    'roles'   => 'sftp_access',
                )
        );

        if ( ! is_null( $add_collaborator_request->errors ) ) {
            $output->writeln( "<error>Error creating temporary bot collaborator. Aborting!</error>" );
            exit;
        }
        // Get new collaborator id.
        $tries           = 0;
        $delay           = 1;
        $collaborator_id = null;
        while ( empty( $collaborator_id ) || $tries <= 3 ) {

            $get_collaborator_list = $api_helper->call_pressable_api(
                    "/sites/{$pressable_site->id}/collaborators",
                    'GET',
                    array()
            );

			if ( ! empty( $get_collaborator_list->data ) ) {
				foreach ( $get_collaborator_list->data as $collaborator ) {
					if ( PRESSABLE_BOT_COLLABORATOR_EMAIL === $collaborator->email ) {
						$collaborator_id = $collaborator->id;
						break( 2 );
					}
				}
			}

            sleep( $delay );
            $tries++;
            $delay = $delay * 2;
        }

        if ( empty( $collaborator_id ) ) {
            $output->writeln( "<error>Trouble finding temporary bot collaborator, may need to be removed manually.</error>" );
        }

        $output->writeln( "<comment>Getting bot collaborator SFTP credentials.</comment>" );

        // Grab SFTP connection info from Pressable bot collaborator.
		$ftp_config = $this->grab_sftp_connection_data( $api_helper, $pressable_site->id );

		if ( ! empty( $ftp_config['error'] )) {
			$output->writeln( sprintf('<error>%s</error>', $ftp_config['error'] ) );
			exit;
		}

        $output->writeln( "<comment>Opening SFTP connection to $site_domain.</comment>" );

        // Time to connect to the server.
        $sftp_connection = new SFTP( $ftp_config['sftp_hostname'] );

        if ( ! $sftp_connection->login( $ftp_config['sftp_username'], $ftp_config['sftp_password'] ) ) {
            $output->writeln( "<error>Failed to connect to the server via SFTP. Aborting!</error>" );
            exit;
        }

        $output->writeln( "<comment>Pulling down PHP error log.</comment>" );

        $php_errors = $sftp_connection->get( '/tmp/php-errors' );

        if ( empty( $php_errors ) ) {
            $output->writeln( "<info>The PHP errors log appears to be empty. Go make some errors and try again.</info>" );
            exit;
        }

        $output->writeln( "<comment>Getting bot collaborator SFTP credentials.</comment>" );

        $output->writeln( "<comment>Removing bot collaborator.</comment>" );

        $delete_contributor_request = $api_helper->call_pressable_api(
            "sites/{$pressable_site->id}/collaborators/{$collaborator_id}",
            'DELETE',
            array()
        );

        if ( is_null( $collaborator_id ) ) {
            $output->writeln( "<error>Unable to remove temporary bot collaborator. Please remove manually.</error>" );
        }

        // If they asked for the raw log, give it to them and bail.
        if ( ! empty( $input->getOption( 'raw' ) ) ) {
            passthru( 'clear' );
            echo $php_errors;
            exit;
        }

        $output->writeln( "<comment>Parsing error log into something usable.</comment>" );

        $site_info = new Table( $output );
        $site_info->setStyle( 'box-double' );
        $site_info->setHeaders( [''] );
        $site_info->setHeaderTitle( 'The 3 most recent PHP Fatals' );

        $php_errors = explode( PHP_EOL, $php_errors );

        $parsed_php_errors = array();

        foreach( $php_errors as $php_error ) {
            // Remove non-fatals.
            if ( false === stripos( $php_error, 'php fatal' ) ) {
                continue;
            }
            preg_match( '/\[(.*)\].*(PHP .*?):(.*)/', $php_error, $matches );
            $matches = array_map( 'trim', $matches );

            if ( empty( $matches[1] ) || empty( $matches[2] ) || empty( $matches[3] ) ) {
                continue;
            }

            $parsed_php_errors[] = array(
                'timestamp' => $matches[1],
                'error_level' => $matches[2],
                'error_message' => $matches[3]
            );
        }

        $_php_error_stats_table = array();

        foreach( $parsed_php_errors as $parsed_php_error ) {
            if ( isset( $_php_error_stats_table[ $parsed_php_error['error_message'] ] ) ){
                $_php_error_stats_table[ $parsed_php_error['error_message'] ]['error_count'] += 1;

                if ( strtotime( $parsed_php_error['timestamp'] ) > strtotime( $_php_error_stats_table[ $parsed_php_error['error_message'] ]['timestamp'] ) ) {
                    $_php_error_stats_table[ $parsed_php_error['error_message'] ]['timestamp'] = $parsed_php_error['timestamp'];
                }
            } else {
                $_php_error_stats_table[ $parsed_php_error['error_message'] ] = array(
                    'timestamp' => $parsed_php_error['timestamp'],
                    'error_level' => $parsed_php_error['error_level'],
                    'error_count' => 1,
                    'error_message' => $parsed_php_error['error_message'],
                );
            }
        }

        usort( $_php_error_stats_table, function ( $a, $b ) {
            return strtotime( $b['timestamp'] ) - strtotime( $a['timestamp'] );
        });

        // Only show the 3 most recent PHP errors or the user specified limit.
        $error_limit = ! empty( intval( $input->getOption( 'limit' ) ) ) ? intval( $input->getOption( 'limit' ) ) : 3;
        $_php_error_stats_table = array_slice( $_php_error_stats_table, 0, $error_limit );

        /*
         * If --table isn't set, show the standard output and bail.
         * Otherwise, go on to build and output a table view of the data.
         */
        if ( empty( $input->getOption( 'table' ) ) ) {
            // Add some padding to previous output before dumping data.
            $output->writeln( "" );
            $output->writeln( "-- The 3 most recent PHP Fatals --" );
            $output->writeln( "" );
            foreach( $_php_error_stats_table as $table_row ) {
                $output->writeln( "<info>Timestamp: {$table_row['timestamp']}</info>" );
                $output->writeln( "<info>Error Level: {$table_row['error_level']}</info>" );
                $output->writeln( "<info>Number of repeated errors: {$table_row['error_count']}</info>" );
                $output->writeln( "<fg=magenta>{$table_row['error_message']}</>" );
                $output->writeln( "" );
            }
            exit;
        }

        $php_error_stats_table = array();

        foreach( $_php_error_stats_table as $table_row ) {
            $php_error_stats_table[] = [ new TableCell( "Timestamp: {$table_row['timestamp']}", ['colspan' => 3] ) ];
            $php_error_stats_table[] = [ new TableCell( "Error Level: {$table_row['error_level']}", ['colspan' => 3] ) ];
            $php_error_stats_table[] = [ new TableCell( "Number of repeated errors: {$table_row['error_count']}", ['colspan' => 3] ) ];
            $php_error_stats_table[] = [ new TableCell( "<fg=magenta>{$table_row['error_message']}</>", ['colspan' => 3] ) ];
            $php_error_stats_table[] = new TableSeparator();
        }

        $site_info->setRows( $php_error_stats_table );
        $site_info->render();

    }

	private function grab_sftp_connection_data( $api_helper, $pressable_site_id ) {
		$ftp_data = $api_helper->call_pressable_api( "sites/{$pressable_site_id}/ftp", 'GET', array() );

		$ftp_config = array();

		if ( empty( $ftp_data->data ) ) {
			$ftp_config['error'] = 'Failed to retrieve FTP users. Aborting!';
			return $ftp_config;
		}

		foreach ( $ftp_data->data as $ftp_user ) {
			if ( PRESSABLE_BOT_COLLABORATOR_EMAIL === $ftp_user->email ) { // We found the bot collaborator we created, grab the info.
				$ftp_config['sftp_username'] = $ftp_user->username;
				$ftp_config['sftp_hostname'] = $ftp_user->sftpDomain;

				$password_reset = $api_helper->call_pressable_api( "sites/{$pressable_site_id}/ftp/password/{$ftp_user->username}", 'POST', array() );
				if ( ! empty( $password_reset->data ) ) {
					$ftp_config['sftp_password'] = $password_reset->data;
					break;
				} else {
					$ftp_config['error'] = 'Failed to retrieve password for temporary bot collaborator. Aborting!';
					return $ftp_config;
				}
			}
		}

		return $ftp_config;
	}
}

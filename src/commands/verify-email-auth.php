<?php

namespace Team51\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Team51\Helper\WPCOM_API_Helper;
use function Team51\Helper\get_wpcom_jetpack_sites;
use function Team51\Helper\get_wpcom_site;

class Verify_Email_Auth extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * {@inheritdoc}
	 */
	protected static $defaultName = 'verify-email-auth';

	/**
	 * The site ID or domain name to check. If not provided, all Team 51 sites will be checked.
	 *
	 * @var string|null
	 */
	protected ?string $site = null;

	// List of known SMTP plugin slugs for identification
	private array $smtpPlugins = [
		'wp-mail-smtp',
		'easy-wp-smtp',
		'post-smtp',
		'mailin',
		'fluent-smtp',
		'gmail-smtp',
		'smtp-mailer',
		'connect-sendgrid-for-emails',
		'mailster-sendgrid',
		'smtp-sendgrid',
		'mailpoet',
		'wp-sendgrid-mailer',
	];

	private array $checked_sites = [];

	//endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Checks and reports the email authentication setup for each managed site or a specific site if provided.' )
			 ->setHelp( "This command will check if each site is using Automattic's nameservers and if any SMTP plugin is configured.\nExample usage:\nverify-email-auth\nverify-email-auth --site=example.com\n" );

		$this->addOption( 'site', null, InputOption::VALUE_OPTIONAL, "Optional. The ID or domain of the site to check. If not provided, all sites will be checked." );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->site = $input->getOption( 'site' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Checking email authentication setup for " . ( $this->site ? "site: `$this->site`." : "all Team 51 sites." ) . "</>" );

		if ( $this->site ) {
			$site = get_wpcom_site( $this->site );

			if ( empty( $site ) ) {
				$output->writeln( "<error>Failed to fetch site info for $this->site</error>" );

				return 1;
			}

			$this->checkSite(
				array(
					'domain' => parse_url( $site->URL, PHP_URL_HOST ),
					'ID'     => $site->ID,
				),
				$output
			);
		} else {
			// Get the list of sites.
			$sites = get_wpcom_jetpack_sites();

			if ( empty( $sites ) ) {
				$output->writeln( '<error>Failed to fetch sites.<error>' );

				return 1;
			}

			foreach ( $sites as $site ) {
				if ( $this->is_ignored_site( $site->domain ) ) {
					continue;
				}

				$this->checkSite(
					array(
						'domain' => $site->domain,
						'ID'     => $site->userblog_id,
					),
					$output
				);
				$output->writeln('');

				// Wait a few seconds to avoid rate limiting
				sleep( 10 );
			}
		}

		return 0;
	}

	//endregion

	// region CUSTOM METHODS

	protected function is_ignored_site( string $site_domain ): bool {
		$ignore = array(
			'staging',
			'testing',
			'jurassic',
			'wpengine',
			'wordpress',
			'develop',
			'mdrovdahl',
			'/dev.',
			'woocommerce.com',
		);

		foreach ( $ignore as $ignored ) {
			if ( str_contains( $site_domain, $ignored ) ) {
				return true;
			}
		}

		// If the site has already been checked, ignore it
		if ( in_array( $site_domain, $this->checked_sites, true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Check the DNS records and SMTP plugin status for a single site.
	 *
	 * @param array          $site   The site domain to check.
	 * @param OutputInterface $output The output interface to write messages to.
	 */
	protected function checkSite( array $site, OutputInterface $output ): void {
		$dns_info = $this->fetchDnsInfo( $site['domain'] );

		if ( ! $dns_info ) {
			$output->writeln( "<error>Failed to fetch DNS info for " . $site['domain'] . "</error>" );

			return;
		}

		$output->writeln( "<info>" . $site['domain'] . ":</info>" );

		// Check if using Automattic's nameservers
		$nameserver = $this->isUsingAutomatticNameservers( $dns_info );
		$output->writeln( " - Using Automattic Nameservers: " . ( $nameserver === true ? "<fg=green>Yes ✓</>" : "<fg=red>No ($nameserver) ✗</>" ) );

		// Check for SMTP plugins in the DNS records
		$plugin_data = WPCOM_API_Helper::call_api( 'rest/v1.1/jetpack-blogs/' . $site['ID'] . '/rest-api/?path=/jetpack/v4/plugins' );

		if ( ! empty( $plugin_data->error ) || empty( $plugin_data->data ) ) {
			$output->writeln( " - SMTP Plugin Found: " . "<error>Failed to fetch plugins!</error>" );
		} else {
			$plugin_found = $this->checkForSmtpPlugins( $plugin_data );
			$output->writeln( " - SMTP Plugin Found: " . ( $plugin_found ? "<fg=red>Yes ($plugin_found) ✗</>" : "<fg=green>No ✓</>" ) );
		}

		$this->checked_sites[] = $site['domain'];
	}

	/**
	 * Fetch the DNS information for a given site.
	 *
	 * @param string $domain The site domain to fetch DNS info for.
	 *
	 * @return array|null The DNS information or null if the fetch fails.
	 */
	protected function fetchDnsInfo( string $domain ): ?array {
		$url = "https://public-api.wordpress.com/wpcom/v2/site-profiler/$domain?_envelope=1";

		// Example of making a GET request to fetch DNS info. You might need to adjust this based on your environment.
		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		$response = curl_exec( $ch );
		curl_close( $ch );

		if ( ! $response ) {
			return null;
		}

		$data = json_decode( $response, true );

		return $data['body']['dns'] ?? null;
	}

	/**
	 * Check if the site is using Team 51's nameservers based on DNS records.
	 *
	 * @param array $dnsInfo The DNS records for the site.
	 *
	 * @return string|bool Whether the site is using Team 51's nameservers, or the nameserver found if not.
	 */
	protected function isUsingAutomatticNameservers( array $dnsInfo ) {
		$nameservers = array( 'wordpress.com', 'pressable.com', 'openhostingservice.com' );
		$ns          = '';

		foreach ( $dnsInfo as $record ) {
			if ( $record['type'] === 'NS' ) {
				$ns = $record['target'];
				foreach ( $nameservers as $nameserver ) {
					if ( str_contains( strtolower( $record['target'] ), $nameserver ) ) {
						return true;
					}
				}
			}
		}

		return $ns === '' ? 'Nameserver Not Found' : $ns;
	}

	/**
	 * Check the DNS records for entries related to known SMTP plugins.
	 *
	 * @param object $plugins Array of plugins for this site
	 *
	 * @return string|bool The slug of the plugin found, or false if no SMTP plugin found.
	 */
	protected function checkForSmtpPlugins( object $plugins ) {
		foreach ( $plugins->data as $key => $value ) {
			$plugin_slug = explode( '/', $key )[0];

			if ( str_contains( $plugin_slug, 'smtp' ) || in_array( $plugin_slug, $this->smtpPlugins, true ) ) {
				return $plugin_slug;
			}
		}

		return false;
	}

	//endregion
}

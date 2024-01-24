<?php

namespace Team51\Command;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Team51\Helper\Pressable_Connection_Helper;
use function Team51\Helper\get_email_input;
use function Team51\Helper\get_pressable_site_from_input;
use function Team51\Helper\get_pressable_site_sftp_user_by_email;
use function Team51\Helper\get_pressable_sites;
use function Team51\Helper\list_1password_accounts;
use function Team51\Helper\maybe_define_console_verbosity;

/**
 * CLI command for connecting to a Pressable site via SSH/SFTP and continuing on the interactive shell.
 */
class Pressable_Get_Db_Backup extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * {@inheritdoc}
	 */
	protected static $defaultName = 'pressable:get-db-backup'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

	/**
	 * Tables to scrub
	 *
	 * @var string[]
	 */
	protected ?array $ignore_tables = [
		'woocommerce_order_itemmeta',
		'woocommerce_order_items',
		'woocommerce_api_keys',
		'woocommerce_payment_tokens',
		'woocommerce_payment_tokenmeta',
		'wp_woocommerce_log',
		'woocommerce_sessions',
		'wc_orders',
		'wc_order_addresses',
		'wc_order_operational_data',
		'wc_orders_meta',
		'wpml_mails',
	];

	protected ?array $ignore_options = [];

	/**
	 * The Pressable site to connect to.
	 *
	 * @var object|null
	 */
	protected ?object $pressable_site = null;

	/**
	 * The email of the Pressable collaborator to connect as.
	 *
	 * @var string|null
	 */
	protected ?string $user_email = null;

	/**
	 * The interactive shell type to open.
	 *
	 * @var string|null
	 */
	protected ?string $shell_type = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Downloads a Pressable database backup.' )
			 ->setHelp( "This command accepts a Pressable site as an input, then exports and downloads the database for that site.\nThe downloaded file will be in the current directory with the name pressable-<site id>-<timestamp>.sql" );

		$this->addArgument( 'site', InputArgument::REQUIRED, 'ID or URL of the site to connect to.' )
			 ->addOption( 'user', 'u', InputOption::VALUE_REQUIRED, 'Email of the user to connect as. Defaults to your Team51 1Password email.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		maybe_define_console_verbosity( $output->getVerbosity() );

		$this->ignore_options = $this->get_safety_net_list();

		// Retrieve and validate the modifier options.
		$this->shell_type = 'ssh';

		// Retrieve and validate the site.
		$this->pressable_site = get_pressable_site_from_input( $input, $output, fn() => $this->prompt_site_input( $input, $output ) );
		if ( \is_null( $this->pressable_site ) ) {
			exit( 1 ); // Exit if the site does not exist.
		}

		// Store the ID of the site in the argument field.
		$input->setArgument( 'site', $this->pressable_site->id );

		// Figure out the SFTP user to connect as.
		$this->user_email = get_email_input(
			$input,
			$output,
			static function() {
				$team51_op_account = \array_filter(
					list_1password_accounts(),
					static fn( object $account ) => 'ZVYA3AB22BC37JPJZJNSGOPYEQ' === $account->account_uuid
				);
				return empty( $team51_op_account ) ? null : \reset( $team51_op_account )->email;
			},
			'user'
		);
		$input->setOption( 'user', $this->user_email ); // Store the user email in the input.

		// Get the database prefix with wp cli command
		$ssh = Pressable_Connection_Helper::get_ssh_connection( $this->pressable_site->id );
		if ( \is_null( $ssh ) ) {
			$output->writeln( '<error>Could not connect to the SSH server.</error>' );
			exit( 1 );
		}

		$dbPrefix = trim( $ssh->exec( 'wp db prefix --quiet --skip-plugins --skip-themes 2> /dev/null' ) );

		$this->ignore_tables = array_map( fn( string $table ) => $dbPrefix . $table, $this->ignore_tables );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Exporting {$this->pressable_site->displayName} (ID {$this->pressable_site->id}, URL {$this->pressable_site->url}) as $this->user_email.</>" );

		// Retrieve the SFTP user for the given email.
		$sftp_user = get_pressable_site_sftp_user_by_email( $this->pressable_site->id, $this->user_email );
		if ( \is_null( $sftp_user ) ) {
			$output->writeln( "<comment>Could not find a Pressable SFTP user with the email $this->user_email on {$this->pressable_site->displayName}. Creating...</comment>", OutputInterface::VERBOSITY_VERBOSE );
		}

		$ssh = Pressable_Connection_Helper::get_ssh_connection( $this->pressable_site->id );
		if ( \is_null( $ssh ) ) {
			$output->writeln( '<error>Could not connect to the SSH server.</error>' );
			return 1;
		}

		$database      = trim( $ssh->exec( 'basename "$(pwd)"' ) );
		$date          = new \DateTime();
		$formattedDate = $date->format( 'Y-m-d' );
		$filename      = "{$this->pressable_site->displayName}-{$formattedDate}.sql";

		$ssh->setTimeout( 0 ); // Disable timeout in case the command takes a long time.

		$baseCommand     = "mysqldump --single-transaction --skip-lock-tables $database";
		$excludedOptions = "'" . implode( "', '", $this->ignore_options ) . "'";

		// Array of all commands
		$commands = [
			"$baseCommand --no-data --ignore-table={$database}.wp_posts --ignore-table={$database}.wp_postmeta --ignore-table={$database}.wp_users --ignore-table={$database}.wp_usermeta > $filename",
			"$baseCommand --tables wp_options --where=\"option_name NOT IN ($excludedOptions) AND option_name NOT LIKE '%key%'\" >> $filename",
			"$baseCommand --tables wp_postmeta --where=\"post_id not in (select ID from wp_posts where post_type in ('shop_order', 'shop_order_refund', 'shop_subscription', 'subscription'))\" >> $filename",
			"$baseCommand --tables wp_posts --where=\"post_type NOT IN ('shop_order', 'shop_order_refund', 'shop_subscription', 'subscription')\" >> $filename",
			"$baseCommand --tables wp_users --where=\"ID not in (select user_id from wp_usermeta where meta_key = 'wp_user_level' and meta_value = 0)\" >> $filename",
			"$baseCommand --tables wp_usermeta --where=\"user_id in (select user_id from wp_usermeta where meta_key = 'wp_user_level' and meta_value != 0)\" >> $filename",
			"$baseCommand --tables wp_comments --where=\"comment_type != 'order_note'\" >> $filename",
		];

		// Get list of all tables in the database
		$all_tables = $ssh->exec("mysql -N -e 'SHOW TABLES' $database");

		// Exclude ignored tables and tables that we're getting data for in other commands
		$tables_to_dump = implode(
			' ',
			array_diff(
				explode( "\n", trim( $all_tables ) ),
				$this->ignore_tables,
				[
					'wp_options',
					'wp_posts',
					'wp_postmeta',
					'wp_users',
					'wp_usermeta',
					'wp_comments',
				]
			)
		);

		$commands[] = "$baseCommand --tables $tables_to_dump >> $filename";

		// Execute each command
		foreach ($commands as $cmd) {
			$output->writeln("<info>Executing: $cmd</info>");
			$ssh->exec( $cmd );
		}

		// Download the file.
		$sftp = Pressable_Connection_Helper::get_sftp_connection( $this->pressable_site->id );
		if ( \is_null( $sftp ) ) {
			$output->writeln( '<error>Could not connect to the SFTP server.</error>' );
			return 1;
		}

		$output->writeln( "<info>Downloading $filename</info>" );

		$result = $sftp->get( "/home/$database/$filename", $filename);

		if ( ! $result ) {
			$output->writeln( '<error>Could not download the file.</error>' );
			$output->writeln( "<error>{$sftp->getLastSFTPError()}</error>" );
			return 1;
		}

		$current_directory = getcwd();

		$output->writeln( "<info>File downloaded to $current_directory/$filename</info>" );

		// Delete the file from the server
		$ssh->exec( "rm /home/$database/$filename" );

		return 0;
	}

	// endregion

	// region HELPERS

	/**
	 * Prompts the user for a site if in interactive mode.
	 *
	 * @param   InputInterface      $input      The input object.
	 * @param   OutputInterface     $output     The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_site_input( InputInterface $input,OutputInterface $output ): ?string {
		if ( $input->isInteractive() ) {
			$question = new Question( '<question>Enter the site ID or URL to connect to:</question> ' );
			$question->setAutocompleterValues( \array_map( static fn( object $site ) => $site->url, get_pressable_sites() ?? array() ) );

			$site = $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return $site ?? null;
	}

	/**
	 * Retrieves a list from Safety Net of options to ignore
	 *
	 * @return array
	 */
	private function get_safety_net_list() : array {
		$list = file_get_contents( 'https://github.com/a8cteam51/safety-net/raw/trunk/assets/data/option_scrublist.txt' );

		// If the list can't be retrieved, use this as a fallback.
		if ( ! $list ) {
			return array(
				'jetpack_active_modules',
				'jetpack_private_options',
				'jetpack_secrets',
				'klaviyo_api_key',
				'klaviyo_edd_license_key',
				'klaviyo_settings',
				'leadin_access_token',
				'mailchimp-woocommerce',
				'mailchimp-woocommerce-cached-api-account-name',
				'mailster_options',
				'mc4wp',
				'novos_klaviyo_option_name',
				'shareasale_wc_tracker_options',
				'woocommerce-ppcp-settings',
				'woocommerce_afterpay_settings',
				'woocommerce_braintree_credit_card_settings',
				'woocommerce_braintree_paypal_settings',
				'woocommerce_paypal_settings',
				'woocommerce_ppcp-gateway_settings',
				'woocommerce_referralcandy_settings',
				'woocommerce_shipstation_auth_key',
				'woocommerce_stripe_account_settings',
				'woocommerce_stripe_api_settings',
				'woocommerce_stripe_settings',
				'woocommerce_woocommerce_payments_settings',
				'wpmandrill',
				'wprus',
				'yotpo_settings',
				'zmail_access_token',
				'zmail_auth_code',
				'zmail_integ_client_secret',
				'zmail_refresh_token',
			);
		}

		return explode( "\n", $list );
	}

	// endregion
}

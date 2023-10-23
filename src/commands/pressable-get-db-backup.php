<?php

namespace Team51\Command;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Team51\Helper\Pressable_Connection_Helper;
use function Team51\Helper\create_pressable_site_collaborator;
use function Team51\Helper\get_email_input;
use function Team51\Helper\get_enum_input;
use function Team51\Helper\get_pressable_site_from_input;
use function Team51\Helper\get_pressable_site_sftp_user_by_email;
use function Team51\Helper\get_pressable_sites;
use function Team51\Helper\list_1password_accounts;
use function Team51\Helper\maybe_define_console_verbosity;
use function Team51\Helper\reset_pressable_site_sftp_user_password;

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
	 * Safety Net data file URLs.
	 *
	 * @var string[]
	 */
	protected const SAFETY_NET_DATA_URLS = [
		'scrublist' => 'https://github.com/a8cteam51/safety-net/blob/trunk/assets/data/option_scrublist.txt',
		'denylist' => 'https://github.com/a8cteam51/safety-net/blob/trunk/assets/data/plugin_denylist.txt',
	];

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
	 * The interactive hell type to open.
	 *
	 * @var string|null
	 */
	protected ?string $shell_type = null;

	/**
	 * The exported SQL filename.
	 *
	 * @var string|null
	 */
	protected ?string $sql_filename = null;

	/**
	 * File handle for the exported SQL file.
	 *
	 * @var resource|null
	 */
	protected ?resource $sql_file = null;

	/**
	 * The current table we are processing.
	 *
	 * @var string|null
	 */
	protected ?string $current_table = null;

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
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Downloading the database for {$this->pressable_site->url}).</>" );

		$ssh = Pressable_Connection_Helper::get_ssh_connection( $this->pressable_site->id );
		if ( \is_null( $ssh ) ) {
			$output->writeln( '<error>Could not connect to the SSH server.</error>' );
			return 1;
		}

		$date = new \DateTime();
		$formatted_date = $date->format('Y-m-d');
		$filename = "{$this->pressable_site->id}-{$formatted_date}.sql";

		$ssh->setTimeout( 0 ); // Disable timeout in case the command takes a long time.
		$ssh->exec(
			"wp db export $filename --exclude_tables=wp_users,woocommerce_order_itemmeta,woocommerce_order_items,wc_orders,wc_order_addresses,wc_order_operational_data,wc_orders_meta,wpml_mails",
			static function( string $str ): void {
				echo $str;
			}
		);

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
	 * Processes the current line of the SQL file. Updates the data as necessary
	 *
	 * @param string $line	The current line of the SQL file.
	 *
	 * @return string
	 */
	function process_line( string $line ) {
		return $line;
	}

	/**
	 * Opens the SQL file for processing.
	 *
	 * @return resource | false
	 */
	private function open_sql_file( ) : resource|false {
		$this->sql_file = fopen( $this->sql_filename, 'r' );
	}

	/**
	 * Checks if we are currently in the specified table.
	 *
	 * @param string $table_name
	 *
	 * @return bool
	 */
	private function is_in_table( string $table_name ) : bool {
		return $this->current_table === $table_name;
	}

	/**
	 * Retrieves list from Safety Net repo
	 *
	 * @param $listname string
	 *
	 * @return array
	 */
	function get_safety_net_list( string $listname ) : array {
		$list = file_get_contents( self::SAFETY_NET_DATA_URLS[$listname] );
		return explode( "\n", $list );
	}

	// endregion
}

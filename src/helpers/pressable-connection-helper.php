<?php

namespace Team51\Helper;

use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Handles the connection and authentication to Pressable sites via SSH or SFTP.
 */
final class Pressable_Connection_Helper {
	// region FIELDS AND CONSTANTS

	/**
	 * The SSH URL.
	 */
	private const SSH_HOST = 'ssh.atomicsites.net';

	/**
	 * The SFTP URL.
	 */
	private const SFTP_HOST = 'sftp.pressable.com';

	// endregion

	// region METHODS

	/**
	 * Opens a new SFTP connection to a Pressable site.
	 *
	 * @param   string  $site_id    The ID of the Pressable site to open a connection to.
	 *
	 * @return  SFTP|null
	 */
	public static function get_sftp_connection( string $site_id ): ?SFTP {
		$login_data = self::get_bot_login_data( $site_id );
		if ( \is_null( $login_data ) || \is_null( $login_data['password'] ) ) {
			return null;
		}

		$connection = new SFTP( self::SFTP_HOST );
		if ( ! $connection->login( $login_data['username'], $login_data['password'] ) ) {
			return null;
		}

		return $connection;
	}

	/**
	 * Opens a new SSH connection to a Pressable site.
	 *
	 * @param   string  $site_id    The ID of the Pressable site to open a connection to.
	 *
	 * @return  SFTP|null
	 */
	public static function get_ssh_connection( string $site_id ): ?SSH2 {
		$login_data = self::get_bot_login_data( $site_id );
		if ( \is_null( $login_data ) || \is_null( $login_data['password'] ) ) {
			return null;
		}

		$connection = new SSH2( self::SSH_HOST );
		if ( ! $connection->login( $login_data['username'], $login_data['password'] ) ) {
			return null;
		}

		return $connection;
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the SFTP/SSH login data for the bot user on a given Pressable site.
	 *
	 * @param   string  $site_id    The ID of the Pressable site to get the login data for.
	 *
	 * @return  array|null
	 */
	private static function get_bot_login_data( string $site_id ): ?array {
		$bot_collaborator = self::get_bot_collaborator( $site_id );
		if ( \is_null( $bot_collaborator ) ) {
			console_writeln( "Could not find the bot collaborator for site $site_id. Looking for fallback...", OutputInterface::VERBOSITY_VERBOSE );

			// If the bot collaborator is not found and could not be created, try using the concierge user as a fallback.
			$bot_collaborator = get_pressable_site_sftp_user_by_email( $site_id, 'concierge@wordpress.com' );
			if ( \is_null( $bot_collaborator ) ) {
				console_writeln( "Could not find the concierge user for site $site_id.", OutputInterface::VERBOSITY_VERBOSE );
				return null;
			}
		}

		return array(
			'username' => $bot_collaborator->username,
			'password' => reset_pressable_site_sftp_user_password( $site_id, $bot_collaborator->username ),
		);
	}

	/**
	 * Returns the bot collaborator object on a given Pressable site.
	 *
	 * @param   string  $site_id    The ID of the Pressable site to get the bot collaborator for.
	 *
	 * @return  object|null
	 */
	private static function get_bot_collaborator( string $site_id ): ?object {
		$bot_collaborator = get_pressable_site_sftp_user_by_email( $site_id, PRESSABLE_BOT_COLLABORATOR_EMAIL );
		if ( \is_null( $bot_collaborator ) ) {
			console_writeln( "Could not find the bot collaborator for site $site_id. Creating...", OutputInterface::VERBOSITY_VERBOSE );

			$bot_collaborator = create_pressable_site_collaborator( PRESSABLE_BOT_COLLABORATOR_EMAIL, $site_id, (array) 'sftp_access' );
			if ( ! \is_null( $bot_collaborator ) ) {
				// Collaborator objects don't contain the SFTP/SSH username, so we need to query for the SFTP user object again.
				$bot_collaborator = get_pressable_site_sftp_user_by_email( $site_id, PRESSABLE_BOT_COLLABORATOR_EMAIL );
			}
		}

		return $bot_collaborator;
	}

	// endregion
}

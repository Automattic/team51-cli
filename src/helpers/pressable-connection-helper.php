<?php

namespace Team51\Helper;

use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;

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
		$login_data = self::get_login_data( $site_id );
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
		$login_data = self::get_login_data( $site_id );
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
	 * Returns the SFTP/SSH login data for the concierge user on a given Pressable site.
	 *
	 * @param   string  $site_id    The ID of the Pressable site to get the login data for.
	 *
	 * @return  array|null
	 */
	private static function get_login_data( string $site_id ): ?array {
		static $cache = array();

		if ( ! isset( $cache[ $site_id ] ) ) {
			$collaborator = get_pressable_site_sftp_user_by_email( $site_id, 'concierge@wordpress.com' );
			if ( \is_null( $collaborator ) ) {
				console_writeln( '❌ Could not find the Pressable site collaborator.' );
				return null;
			}

			$cache[ $site_id ] = array(
				'username' => $collaborator->username,
				'password' => reset_pressable_site_sftp_user_password( $site_id, $collaborator->username ),
			);
		}

		return $cache[ $site_id ];
	}

	// endregion
}

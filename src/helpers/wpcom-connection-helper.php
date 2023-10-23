<?php

namespace Team51\Helper;

use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;

/**
 * Handles the connection and authentication to WPCOM sites via SSH or SFTP.
 */
final class WPCOM_Connection_Helper {
	// region FIELDS AND CONSTANTS

	/**
	 * The SSH URL.
	 */
	public const SSH_HOST = 'sftp.wp.com';

	/**
	 * The SFTP URL.
	 */
	public const SFTP_HOST = 'sftp.wp.com';

	// endregion

	// region METHODS

	/**
	 * Opens a new SFTP connection to a WPCOM site.
	 *
	 * @param   string  $site_id    The ID of the WPCOM site to open a connection to.
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
			$connection->isConnected() && $connection->disconnect();
			return null;
		}

		return $connection;
	}

	/**
	 * Opens a new SSH connection to a WPCOM site.
	 *
	 * @param   string  $site_id    The ID of the WPCOM site to open a connection to.
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
			$connection->isConnected() && $connection->disconnect();
			return null;
		}

		// Shortly after a new site is created, the server does not support SSH commands yet, but it will still accept
		// and authenticate the connection. We need to wait a bit before we can actually run commands. So the following
		// lines are a short hack to check if the server is indeed ready.
		$response = $connection->exec( 'ls -la' );
		if ( "This service allows sftp connections only.\n" === $response || 0 !== $connection->getExitStatus() ) {
			$connection->isConnected() && $connection->disconnect();
			return null;
		}

		return $connection;
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the SFTP/SSH login data for the concierge user on a given WPCOM site.
	 *
	 * @param   string  $site_id    The ID of the WPCOM site to get the login data for.
	 *
	 * @return  array|null
	 */
	private static function get_login_data( string $site_id ): ?array {
		static $cache = array();

		if ( ! isset( $cache[ $site_id ] ) ) {
			$sftp_username = get_wpcom_site_sftp_user( $site_id );
			if ( \is_null( $sftp_username ) ) {
				console_writeln( '❌ There is no SFTP user added to the WPCOM site.' );
				return null;
			}

			$cache[ $site_id ] = array(
				'username' => $sftp_username,
				'password' => reset_wpcom_site_sftp_user_password( $site_id, $sftp_username ),
			);
		}

		return $cache[ $site_id ];
	}

	// endregion
}

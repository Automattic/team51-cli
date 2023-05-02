<?php

namespace Team51\Helper;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Team51\Command\Pressable_Site_Run_WP_CLI_Command;

// region API

/**
 * Get a list of sites belonging to your account. Sites can be filtered by tag name.
 * Site listing is a full list of sites attached to your account, unless pagination is requested.
 *
 * @param   array   $params    The query parameters to send with the request.
 *
 * @link    https://my.pressable.com/documentation/api/v1#sites
 *
 * @return  object[]|null
 */
function get_pressable_sites( array $params = array() ): ?array {
	$sites = Pressable_API_Helper::call_api( 'sites?' . \http_build_query( $params ) );
	if ( \is_null( $sites ) || empty( $sites->data ) ) {
		return null;
	}

	return $sites->data;
}

/**
 * Get site information and settings for all sites that contain a given search term in their name or URL.
 *
 * @param   string  $search_term    The search term.
 * @param   array   $params         The query parameters for filtering the results.
 *
 * @return  object[]|null
 */
function get_pressable_sites_by_search_term( string $search_term, array $params = array() ): ?array {
	$sites = get_pressable_sites( $params );
	if ( \is_null( $sites ) ) {
		return null;
	}

	$checker = static fn( object $site ): bool => (
		false !== \strpos( $site->name, $search_term ) ||
		false !== \strpos( $site->url, $search_term )
	);
	return \array_filter( $sites, $checker );
}

/**
 * Returns a tree-like structure of cloned Pressable sites with the production site as its root for a given site.
 *
 * @param   object          $site               The site to get the related ones for.
 * @param   callable|null   $node_generator     The function to use to generate the node.
 *
 * @return  array|null
 */
function get_related_pressable_sites( object $site, ?callable $node_generator = null ): ?array {
	// Ensure we always start with the root/production site.
	$production_site = $site;
	while ( ! empty( $production_site->clonedFromId ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$production_site = get_pressable_site_by_id( $production_site->clonedFromId ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( false !== \is_null( $production_site ) ) {
			break; // This is as high as we can go. Original site must've been deleted...
		}
	}

	// Initialize the tree with the production site.
	$node_generator = \is_callable( $node_generator ) ? $node_generator : static fn( object $site ) => $site;
	$related_sites  = array( 0 => array( $production_site->id => $node_generator( $production_site ) ) );

	// Identify the related sites by level.
	$all_sites = get_pressable_sites();

	do {
		$has_next_level = false;
		$current_level  = \count( $related_sites );

		foreach ( \array_keys( $related_sites[ $current_level - 1 ] ) as $parent_site_id ) {
			foreach ( $all_sites as $maybe_clone_site ) {
				if ( $maybe_clone_site->clonedFromId !== $parent_site_id ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					continue; // Skip if this is not a clone.
				}

				$related_sites[ $current_level ][ $maybe_clone_site->id ] = $node_generator( $maybe_clone_site );
				$has_next_level = true;
			}
		}
	} while ( true === $has_next_level );

	return $related_sites;
}

/**
 * Get site information and settings by Pressable site ID.
 *
 * @param   string  $site_id    The site ID.
 *
 * @link    https://my.pressable.com/documentation/api/v1#get-site
 *
 * @return  object|null
 */
function get_pressable_site_by_id( string $site_id ): ?object {
	$site = Pressable_API_Helper::call_api( "sites/$site_id" );
	if ( \is_null( $site ) || empty( $site->data ) ) {
		return null;
	}

	return $site->data;
}

/**
 * Get site information and settings by main site URL.
 *
 * @param   string  $site_url   The site URL.
 * @param   bool    $exact      Whether to match the exact URL.
 *
 * @return  object|null
 */
function get_pressable_site_by_url( string $site_url, bool $exact = true ): ?object {
	$sites = get_pressable_sites();
	if ( \is_null( $sites ) ) {
		return null;
	}

	foreach ( $sites as $site ) {
		if ( true === $exact ) {
			if ( true === is_case_insensitive_match( $site->url, $site_url ) ) {
				return $site;
			}
		} elseif ( \substr( $site->url, -1 * \strlen( $site_url ) ) === $site_url ) {
			// If $site_url is the ending of the site URL, return the site.
			// This will thus return the site with URL www.domain.com for a URL input of domain.com.
			return $site;
		}
	}

	return null;
}

/**
 * Get a list of FTP users for the specified site.
 *
 * @param   string  $site_id    The site ID.
 *
 * @link    https://my.pressable.com/documentation/api/v1#sites-ftp
 *
 * @return  object[]|null
 */
function get_pressable_site_sftp_users( string $site_id ): ?array {
	$sftp_users = Pressable_API_Helper::call_api( "sites/$site_id/ftp" );
	if ( \is_null( $sftp_users ) || empty( $sftp_users->data ) ) {
		return null;
	}

	return $sftp_users->data;
}

/**
 * Get SFTP user by username for the specified site.
 *
 * @param   string  $site_id    The site ID.
 * @param   string  $username   The username of the site SFTP user.
 *
 * @link    https://my.pressable.com/documentation/api/v1#get-sftp-user
 *
 * @return  object|null
 */
function get_pressable_site_sftp_user_by_username( string $site_id, string $username ): ?object {
	$sftp_user = Pressable_API_Helper::call_api( "sites/$site_id/ftp/$username" );
	if ( \is_null( $sftp_user ) || empty( $sftp_user->data ) ) {
		return null;
	}

	return $sftp_user->data;
}

/**
 * Get SFTP user by ID for the specified site.
 *
 * @param   string  $site_id    The site ID.
 * @param   string  $user_id    The ID of the site SFTP user.
 *
 * @return  object|null
 */
function get_pressable_site_sftp_user_by_id( string $site_id, string $user_id ): ?object {
	$sftp_users = get_pressable_site_sftp_users( $site_id );

	foreach ( $sftp_users as $sftp_user ) {
		if ( $user_id === (string) $sftp_user->id ) {
			return $sftp_user;
		}
	}

	return null;
}

/**
 * Get SFTP user by email for the specified site.
 *
 * @param   string  $site_id        The site ID.
 * @param   string  $user_email     The email of the site SFTP user.
 *
 * @return  object|null
 */
function get_pressable_site_sftp_user_by_email( string $site_id, string $user_email ): ?object {
	$sftp_users = get_pressable_site_sftp_users( $site_id );

	foreach ( $sftp_users as $sftp_user ) {
		if ( ! empty( $sftp_user->email ) && true === is_case_insensitive_match( $sftp_user->email, $user_email ) ) {
			return $sftp_user;
		}
	}

	return null;
}

/**
 * Resets an SFTP user password for the specified user and site.
 *
 * @param   string  $site_id    The site ID.
 * @param   string  $username   The username of the site SFTP user.
 *
 * @link    https://my.pressable.com/documentation/api/v1#reset-sftp-user-password
 *
 * @return  string|null     The new password or null if the password could not be reset.
 */
function reset_pressable_site_sftp_user_password( string $site_id, string $username ): ?string {
	$new_password = Pressable_API_Helper::call_api( "sites/$site_id/ftp/password/$username", 'POST' );
	if ( \is_null( $new_password ) || empty( $new_password->data ) ) {
		return null;
	}

	return $new_password->data;
}

/**
 * Get a list of collaborators for the specified site.
 *
 * @param   string  $site_id    The site ID.
 *
 * @return  object[]|null
 */
function get_pressable_site_collaborators( string $site_id ): ?array {
	$collaborators = Pressable_API_Helper::call_api( "sites/$site_id/collaborators" );
	if ( \is_null( $collaborators ) || empty( $collaborators->data ) ) {
		return null;
	}

	return $collaborators->data;
}

/**
 * Get site collaborator information by collaborator ID.
 *
 * @param   string  $site_id            The site ID.
 * @param   string  $collaborator_id    The collaborator ID.
 *
 * @link    https://my.pressable.com/documentation/api/v1#get-site-collaborator
 *
 * @return  object|null
 */
function get_pressable_site_collaborator_by_id( string $site_id, string $collaborator_id ): ?object {
	$collaborator = Pressable_API_Helper::call_api( "sites/$site_id/collaborators/$collaborator_id" );
	if ( \is_null( $collaborator ) || empty( $collaborator->data ) ) {
		return null;
	}

	return $collaborator->data;
}

/**
 * Get site collaborator information by collaborator email.
 *
 * @param   string  $site_id            The site ID.
 * @param   string  $collaborator_email The collaborator email.
 *
 * @return  object|null
 */
function get_pressable_site_collaborator_by_email( string $site_id, string $collaborator_email ): ?object {
	$collaborators = get_pressable_site_collaborators( $site_id );
	if ( \is_null( $collaborators ) ) {
		return null;
	}

	foreach ( $collaborators as $collaborator ) {
		if ( ! empty( $collaborator->email ) && true === is_case_insensitive_match( $collaborator->email, $collaborator_email ) ) {
			return $collaborator;
		}
	}

	return null;
}

/**
 * Adds a collaborator with the given email address to a given site. We reuse the bulk create endpoint because the single
 * create endpoint does not support the `roles` parameter.
 *
 * @param   string      $site_id                The site ID.
 * @param   string      $collaborator_email     The collaborator email.
 * @param   array|null  $collaborator_roles     The collaborator roles.
 * @param   bool        $skip_object_query      Whether to skip querying the collaborator after creation.
 *
 * @link    https://my.pressable.com/documentation/api/v1#collaborator-bulk-create
 *
 * @return  object|null
 */
function create_pressable_site_collaborator( string $collaborator_email, string $site_id, ?array $collaborator_roles = null, bool $skip_object_query = false ): ?object {
	// First send the request to create the collaborator.
	$collaborator_roles = $collaborator_roles ?? get_pressable_site_collaborator_default_roles( $site_id );
	$result             = bulk_create_pressable_site_collaborators( $collaborator_email, array( $site_id ), $collaborator_roles );
	if ( true !== $result ) {
		return null;
	}

	// If we're skipping the object query, return early.
	if ( true === $skip_object_query ) {
		return (object) array(
			'email'  => $collaborator_email,
			'siteId' => $site_id,
		);
	}

	// Now query the collaborator object. Adding the collaborator might take some time, so we need to retry it.
	for ( $try = 0, $delay = 1; $try <= 5; $try++, $delay *= 2 ) {
		$collaborator = get_pressable_site_collaborator_by_email( $site_id, $collaborator_email );
		if ( ! \is_null( $collaborator ) ) {
			break;
		}

		sleep( $delay );
	}

	return $collaborator;
}

/**
 * Adds a collaborator with the given a email address to a list of sites.
 *
 * @param   string  $collaborator_email     The collaborator email.
 * @param   array   $site_ids               The site IDs.
 * @param   array   $collaborator_roles     The collaborator roles.
 *
 * @link    https://my.pressable.com/documentation/api/v1#collaborator-bulk-create
 *
 * @return  bool
 */
function bulk_create_pressable_site_collaborators( string $collaborator_email, array $site_ids, array $collaborator_roles = array() ): bool {
	$result = Pressable_API_Helper::call_api(
		'collaborators/batch_create',
		'POST',
		array(
			'email'   => $collaborator_email,
			'siteIds' => $site_ids,
			'roles'   => $collaborator_roles,
		)
	);

	if ( ! \is_null( $result ) && ! empty( $result->message ) ) {
		console_writeln( $result->message, OutputInterface::VERBOSITY_VERBOSE );
	}

	return ! \is_null( $result );
}

/**
 * Returns the list of default roles for a site collaborator on a given site.
 *
 * @param   string  $site_id    The site ID.
 *
 * @return  string[]
 */
function get_pressable_site_collaborator_default_roles( string $site_id ): array {
	$collaborator_roles = array( 'clone_site', 'sftp_access', 'download_backups', 'reset_collaborator_password', 'manage_performance', 'php_my_admin_access' );
	if ( true === is_pressable_staging_site( $site_id ) ) {
		$collaborator_roles[] = 'wp_access';
	}

	return $collaborator_roles;
}

/**
 * If one of your collaborators is unable to log into the site’s WordPress dashboard because of a forgotten, or unknown, password,
 * this can be used to set their WP Admin password to a randomly generated value.
 *
 * @param   string  $site_id            The site ID.
 * @param   string  $collaborator_id    The collaborator ID.
 *
 * @link    https://my.pressable.com/documentation/api/v1#set-collaborator-wp-admin-password
 *
 * @return  string|null
 */
function reset_pressable_site_collaborator_wp_password( string $site_id, string $collaborator_id ): ?string {
	$new_password = Pressable_API_Helper::call_api( "sites/$site_id/collaborators/$collaborator_id/wp-password-reset", 'PUT' );
	if ( \is_null( $new_password ) || empty( $new_password->data ) ) {
		return null;
	}

	return $new_password->data;
}

/**
 * Reset the site owner's WP-Admin password. If you (account owner) are unable to log into your site’s WordPress dashboard because of a forgotten, or unknown, password,
 * this endpoint can be used to set your WP Admin password to a randomly generated value.
 *
 * Since the site owner does NOT show up in the list of collaborators on a site, this endpoint must be used to instead
 * of the @reset_pressable_site_collaborator_wp_password endpoint.
 *
 * @param   string  $site_id    The site ID.
 *
 * @link    https://my.pressable.com/documentation/api/v1#reset-wpadmin-password
 *
 * @return  string|null
 */
function reset_pressable_site_owner_wp_password( string $site_id ): ?string {
	$new_password = Pressable_API_Helper::call_api( "sites/$site_id/wordpress/password-reset", 'PUT' );
	if ( \is_null( $new_password ) || empty( $new_password->data ) ) {
		return null;
	}

	return $new_password->data;
}

/**
 * Returns whether a given Pressable site should be considered a staging site.
 *
 * Previously, the 'create-development-site' command did not set the staging flag. The check for the '-development' substring
 * is not 100% accurate, but it's the best we can do. For example, it will fail if the 'create-development-site' was called
 * with the flags 'temporary-clone' and 'label' because the '-development' substring will be then replaced with the given label.
 *
 * @param   string  $site_id    The site ID.
 *
 * @return  bool|null
 */
function is_pressable_staging_site( string $site_id ): ?bool {
	$site = get_pressable_site_by_id( $site_id );
	if ( \is_null( $site ) ) {
		return null;
	}

	return $site->staging // The staging flag is set.
		|| ( false !== \strpos( $site->url, '-development' ) ); // Legacy check.
}

/**
 * Returns whether a given Pressable site should be considered a production site.
 *
 * @param   string  $site_id    The site ID.
 *
 * @return  bool|null
 */
function is_pressable_production_site( string $site_id ): ?bool {
	$is_development = is_pressable_staging_site( $site_id );
	return \is_null( $is_development ) ? null : ! $is_development;
}

/**
 * Converts a staging site to a live site, and a live site to a staging site.
 *
 * @param   string  $site_id    The site ID.
 *
 * @link    https://my.pressable.com/documentation/api/v1#convert-site
 *
 * @return  object|null
 */
function convert_pressable_site( string $site_id ): ?object {
	$result = Pressable_API_Helper::call_api( "sites/$site_id/convert", 'PUT' );
	if ( \is_null( $result ) || empty( $result->data ) ) {
		return null;
	}

	return $result->data;
}

/**
 * Get a list of domains for the specified site. If there are no domains, an empty array is returned.
 *
 * @param   string  $site_id    The site ID.
 *
 * @link    https://my.pressable.com/documentation/api/v1#sites-domains
 *
 * @return  object[]|null
 */
function get_pressable_site_domains( string $site_id ): ?array {
	$domains = Pressable_API_Helper::call_api( "sites/$site_id/domains" );

	// The endpoint will return NULL for the data field if no custom domains are added, so we must handle that case explicitly.
	if ( \is_null( $domains ) || empty( $domains->message ) || ( empty( $domains->data ) && 'Success' !== $domains->message ) ) {
		return null;
	}

	return $domains->data ?? array();
}

/**
 * Adds a new given domain to a given Pressable site.
 *
 * @param   string  $site_id    The site ID.
 * @param   string  $domain     The domain to add.
 *
 * @link    https://my.pressable.com/documentation/api/v1#add-domain-to-site
 *
 * @return  object[]|null
 */
function add_pressable_site_domain( string $site_id, string $domain ): ?array {
	$result = Pressable_API_Helper::call_api( "sites/$site_id/domains", 'POST', array( 'name' => $domain ) );
	if ( \is_null( $result ) || empty( $result->data ) ) {
		return null;
	}

	return $result->data;
}

/**
 * Sets a given domain as the primary domain of a given Pressable site.
 *
 * @param   string  $site_id    The site ID.
 * @param   string  $domain_id  The domain ID.
 *
 * @link    https://my.pressable.com/documentation/api/v1#set-primary-domain
 *
 * @return  object|null
 */
function set_pressable_site_primary_domain( string $site_id, string $domain_id ): ?object {
	$result = Pressable_API_Helper::call_api( "sites/$site_id/domains/$domain_id/primary", 'PUT' );
	if ( \is_null( $result ) || empty( $result->data ) ) {
		return null;
	}

	return $result->data;
}

// endregion

// region WRAPPERS

/**
 * Runs a given WP-CLI command on a given Pressable site and returns the exit code.
 *
 * @param   Application         $application        The application instance.
 * @param   string              $site_id_or_url     The Pressable site ID or URL to run the command on.
 * @param   string              $wp_cli_command     The WP-CLI command to execute.
 * @param   OutputInterface     $output             The output to use for the command.
 * @param   bool                $interactive        Whether to run the command in interactive mode.
 *
 * @return int  The command exit code.
 * @noinspection PhpDocMissingThrowsInspection
 */
function run_pressable_site_wp_cli_command( Application $application, string $site_id_or_url, string $wp_cli_command, OutputInterface $output, bool $interactive = false ): int {
	/* @noinspection PhpUnhandledExceptionInspection */
	return run_app_command(
		$application,
		Pressable_Site_Run_WP_CLI_Command::getDefaultName(),
		array(
			'site'           => $site_id_or_url,
			'wp-cli-command' => $wp_cli_command,
		),
		$output,
		$interactive
	);
}

// endregion

// region CONSOLE

/**
 * Outputs the related sites in a table format.
 *
 * @param   OutputInterface $output         The output instance.
 * @param   array           $sites          The related sites in tree form. Must be an output of @get_related_pressable_sites.
 * @param   array|null      $headers        The headers of the table.
 * @param   callable|null   $row_generator  The function to generate the row data from the tree node.
 *
 * @return  void
 */
function output_related_pressable_sites( OutputInterface $output, array $sites, ?array $headers = null, ?callable $row_generator = null ): void {
	$row_generator = \is_callable( $row_generator ) ? $row_generator
		: static fn( $node, $level ) => array( $node->id, $node->name, $node->url, $level, $node->clonedFromId ?: '--' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	$table = new Table( $output );

	$table->setHeaderTitle( 'Related Pressable sites' );
	$table->setHeaders( $headers ?? array( 'ID', 'Name', 'URL', 'Level', 'Parent ID' ) );
	foreach ( $sites as $level => $nodes ) {
		foreach ( $nodes as $node ) {
			$table->addRow( $row_generator( $node, $level ) );
		}

		if ( $level < ( \count( $sites ) - 1 ) ) {
			$table->addRow( new TableSeparator() );
		}
	}

	$table->setStyle( 'box-double' );
	$table->render();
}

/**
 * Grabs a value from the console input and tries to retrieve a Pressable site based on it.
 *
 * @param   InputInterface  $input          The console input.
 * @param   OutputInterface $output         The console output.
 * @param   callable|null   $no_input_func  The function to call if no input is given.
 * @param   string          $name           The name of the value to grab.
 *
 * @return  object|null
 */
function get_pressable_site_from_input( InputInterface $input, OutputInterface $output, ?callable $no_input_func = null, string $name = 'site' ): ?object {
	$site_id_or_url = get_site_input( $input, $output, $no_input_func, $name );
	$pressable_site = \is_numeric( $site_id_or_url ) ? get_pressable_site_by_id( $site_id_or_url ) : get_pressable_site_by_url( $site_id_or_url );

	if ( \is_null( $pressable_site ) ) {
		$output->writeln( "<error>Pressable site $site_id_or_url not found.</error>" );
	} else {
		$output->writeln( "<comment>Pressable site found: $pressable_site->displayName (ID $pressable_site->id, URL $pressable_site->url).</comment>", OutputInterface::VERBOSITY_VERY_VERBOSE );
	}

	return $pressable_site;
}

/**
 * Grabs a value from the console input and tries to retrieve a Pressable SFTP user based on it.
 *
 * @param   InputInterface  $input              The console input.
 * @param   OutputInterface $output             The console output.
 * @param   string          $pressable_site_id  The Pressable site ID.
 * @param   callable|null   $no_input_func      The function to call if no input is given.
 * @param   string          $name               The name of the value to grab.
 *
 * @return  object|null
 */
function get_pressable_site_sftp_user_from_input( InputInterface $input, OutputInterface $output, string $pressable_site_id, ?callable $no_input_func = null, string $name = 'user' ): ?object {
	$user_uname_or_id_or_email = get_user_input( $input, $output, $no_input_func, $name, false ); // Pressable SFTP users can also be retrieved by username so no validation is needed.
	$pressable_sftp_user       = \is_numeric( $user_uname_or_id_or_email ) ? get_pressable_site_sftp_user_by_id( $pressable_site_id, $user_uname_or_id_or_email )
		: ( get_pressable_site_sftp_user_by_username( $pressable_site_id, $user_uname_or_id_or_email ) ?? get_pressable_site_sftp_user_by_email( $pressable_site_id, $user_uname_or_id_or_email ) );

	if ( \is_null( $pressable_sftp_user ) ) {
		$output->writeln( "<error>Pressable SFTP user $user_uname_or_id_or_email not found on $pressable_site_id.</error>" );
	} else {
		$output->writeln( "<comment>Pressable SFTP user found on $pressable_site_id: $pressable_sftp_user->username (ID $pressable_sftp_user->id, email $pressable_sftp_user->email).</comment>", OutputInterface::VERBOSITY_VERY_VERBOSE );
	}

	return $pressable_sftp_user;
}

// endregion

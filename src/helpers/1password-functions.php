<?php

namespace Team51\Helper;

/**
 * List 1Password accounts.
 *
 * @param   array   $global_flags   The global flags to pass to the command.
 *
 * @link    https://developer.1password.com/docs/cli/reference/management-commands/account#account-list
 *
 * @return  array|null
 */
function list_1password_accounts( array $global_flags = array() ): ?array {
	$command = _build_1password_command_string( 'op account list', array(), array(), $global_flags );
	return decode_json_content( \shell_exec( "$command --format json" ) );
}

/**
 * Lists 1Password items.
 *
 * @param   array   $flags          The flags to filter the results by.
 * @param   array   $global_flags   The global flags to pass to the command.
 *
 * @link    https://developer.1password.com/docs/cli/reference/management-commands/item#item-list
 *
 * @return  object[]|null
 */
function list_1password_items( array $flags = array(), array $global_flags = array() ): ?array {
	$flags   = \array_intersect_key( $flags, \array_flip( array( 'categories', 'tags', 'vault', 'favorite', 'include-archive' ) ) );
	$command = _build_1password_command_string( 'op item list', $flags, array( 'categories', 'tags', 'vault' ), $global_flags );

	return decode_json_content( \shell_exec( "$command --format json" ) );
}

/**
 * Filters 1Password items based on a given search function.
 *
 * @param   callable    $search_func    The function to use to search for the item. Must resolve to true for a valid item and false otherwise.
 * @param   array       $flags          The flags to filter the results by.
 * @param   array       $global_flags   The global flags to pass to the command.
 *
 * @return  array|null
 */
function search_1password_items( callable $search_func, array $flags = array(), array $global_flags = array() ): ?array {
	static $op_items = array();

	// Cache the list of items to search through for performance/rate-limiting reasons.
	$flags_hash = \hash( 'sha256', encode_json_content( \array_merge( $flags, $global_flags ) ) );
	if ( ! isset( $op_items[ $flags_hash ] ) || true !== ( $global_flags['cache'] ?? true ) ) {
		$op_items[ $flags_hash ] = list_1password_items( $flags, $global_flags );
		if ( \is_null( $op_items[ $flags_hash ] ) ) {
			unset( $op_items[ $flags_hash ] );
			return null;
		}
	}

	// Search through the cached list for matching items.
	return \array_filter( $op_items[ $flags_hash ], $search_func );
}

/**
 * Creates a new 1Password item.
 *
 * @param   array   $fields         The fields to set on the new item.
 * @param   array   $flags          The flags to pass on to the command.
 * @param   array   $global_flags   The global flags to pass to the command.
 * @param   bool    $dry_run        Perform a dry run of the command and return a preview of the resulting item.
 *
 * @return  object|null
 */
function create_1password_item( array $fields, array $flags, array $global_flags = array(), bool $dry_run = false ): ?object {
	$flags   = \array_intersect_key(
		\array_merge( $flags, array( 'dry-run' => $dry_run ) ),
		\array_flip( array( 'title', 'url', 'template', 'category', 'tags', 'generate-password', 'vault', 'dry-run' ) )
	);
	$command = _build_1password_command_string( 'op item create', $flags, array( 'title', 'url', 'template', 'category', 'tags', 'vault' ), $global_flags );

	foreach ( $fields as $field => $value ) {
		$command .= " '$field=$value'";
	}

	return decode_json_content( \shell_exec( "$command --format json" ) );
}

/**
 * Returns details about a given 1Password item.
 *
 * @param   string  $item_id        The ID of the item to get.
 * @param   array   $flags          The flags to pass on to the command.
 * @param   array   $global_flags   The global flags to pass to the command.
 *
 * @link    https://developer.1password.com/docs/cli/reference/management-commands/item#item-get
 *
 * @return  object|null
 */
function get_1password_item( string $item_id, array $flags = array(), array $global_flags = array() ): ?object {
	$flags   = \array_intersect_key( $flags, \array_flip( array( 'fields', 'include-archive', 'otp', 'share-link', 'vault' ) ) );
	$command = _build_1password_command_string( "op item get $item_id", $flags, array( 'fields', 'vault' ), $global_flags );

	return decode_json_content( \shell_exec( "$command --format json" ) );
}

/**
 * Edits a given 1Password item.
 *
 * @param   string  $item_id        The ID of the item to update.
 * @param   array   $fields         The fields to set on the item.
 * @param   array   $flags          The flags to pass on to the command.
 * @param   array   $global_flags   The global flags to pass to the command.
 * @param   bool    $dry_run        Perform a dry run of the command and return a preview of the resulting item.
 *
 * @link    https://developer.1password.com/docs/cli/reference/management-commands/item#item-edit
 *
 * @return  object|null
 */
function update_1password_item( string $item_id, array $fields, array $flags = array(), array $global_flags = array(), bool $dry_run = false ): ?object {
	$flags   = \array_intersect_key(
		\array_merge( $flags, array( 'dry-run' => $dry_run ) ),
		\array_flip( array( 'title', 'url', 'tags', 'generate-password', 'vault', 'dry-run' ) )
	);
	$command = _build_1password_command_string( "op item edit $item_id", $flags, array( 'title', 'url', 'tags', 'vault' ), $global_flags );

	foreach ( $fields as $field => $new_value ) {
		$command .= " '$field=$new_value'";
	}

	return decode_json_content( \shell_exec( "$command --format json" ) );
}

/**
 * Deletes a given 1Password item either permanently or by archiving it.
 *
 * @param   string  $item_id        The ID of the item to delete.
 * @param   array   $flags          The flags to pass on to the command.
 * @param   array   $global_flags   The global flags to pass to the command.
 *
 * @link    https://developer.1password.com/docs/cli/reference/management-commands/item#item-delete
 *
 * @return  void
 */
function delete_1password_item( string $item_id, array $flags = array(), array $global_flags = array() ): void {
	$flags   = \array_intersect_key( $flags, \array_flip( array( 'archive', 'vault' ) ) );
	$command = _build_1password_command_string( "op item delete $item_id", $flags, array( 'vault' ), $global_flags );

	\shell_exec( $command );
}

/**
 * Prepares a 1Password command string.
 *
 * @param   string  $command        The command to run.
 * @param   array   $flags          The flags to filter the results by.
 * @param   array   $value_flags    The flags that can have a value.
 * @param   array   $global_flags   The global flags to pass to the command.
 *
 * @internal
 * @return  string
 */
function _build_1password_command_string( string $command, array $flags, array $value_flags, array $global_flags ): string {
	$global_flags = \array_intersect_key( $global_flags, \array_flip( array( 'account', 'cache', 'config', 'debug', 'encoding', 'iso-timestamps', 'session' ) ) );

	foreach ( $flags as $flag => $value ) {
		if ( \in_array( $flag, $value_flags, true ) ) {
			$command .= " --$flag " . \implode( ',', (array) $value );
		} elseif ( $value ) {
			$command .= " --$flag";
		}
	}
	foreach ( $global_flags as $flag => $value ) {
		if ( \in_array( $flag, array( 'account', 'config', 'encoding', 'session' ), true ) ) {
			$command .= " --$flag " . \implode( ',', (array) $value );
		} elseif ( $value ) {
			$command .= " --$flag";
		}
	}

	return $command;
}

/**
 * Returns true if the given 1Password item is a match for the given URL. False otherwise.
 *
 * @param   object  $op_item    The 1Password item object.
 * @param   string  $match_url  The URL to match the item against.
 *
 * @return  bool
 */
function is_1password_item_url_match( object $op_item, string $match_url ): bool {
	$result = false;

	$match_host = \trim( $match_url );
	if ( false !== \strpos( $match_host, 'http' ) ) { // Strip away everything but the domain itself.
		$match_host = \parse_url( $match_host, PHP_URL_HOST );
	} else { // Strip away endings like /wp-admin or /wp-login.php.
		$match_host = \explode( '/', $match_host, 2 )[0];
	}

	$op_item_urls = \property_exists( $op_item, 'urls' ) ? (array) $op_item->urls : array();
	foreach ( $op_item_urls as $op_item_url ) {
		$op_item_host = \trim( $op_item_url->href );
		if ( false !== \strpos( $op_item_host, 'http' ) ) { // Strip away everything but the domain itself.
			$op_item_host = \parse_url( $op_item_host, PHP_URL_HOST );
		} else { // Strip away endings like /wp-admin or /wp-login.php.
			$op_item_host = \explode( '/', $op_item_host, 2 )[0];
		}

		$result = is_case_insensitive_match( $match_host, $op_item_host );
		if ( $result ) {
			break;
		}
	}

	return $result;
}

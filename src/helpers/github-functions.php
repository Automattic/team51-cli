<?php

namespace Team51\Helper;

/**
 * Lists repositories for the specified organization.
 *
 * @param   string $organization The organization name. The name is not case-sensitive.
 * @param   array  $query_params The query parameters to add to the request.
 *
 * @link    https://docs.github.com/en/rest/repos/repos#list-organization-repositories
 *
 * @return  array|null
 */
function get_github_repositories( string $organization, array $query_params = array() ): ?array {
	$request_url = sprintf( 'orgs/%s/repos', $organization );
	if ( ! empty( $query_params ) ) {
		$request_url .= '?' . \http_build_query( $query_params );
	}

	$result = GitHub_API_Helper::call_api( $request_url );
	if ( ! \is_array( $result ) ) {
		return null;
	}

	return $result;
}

/**
 * Returns the description of the repository with the given owner and name.
 *
 * @param   string  $owner          The account owner of the repository. The name is not case-sensitive.
 * @param   string  $repository     The name of the repository. The name is not case-sensitive.
 *
 * @link    https://docs.github.com/en/rest/repos/repos#get-a-repository
 *
 * @return  object|null
 */
function get_github_repository( string $owner, string $repository ): ?object {
	$result = GitHub_API_Helper::call_api( sprintf( 'repos/%s/%s', $owner, $repository ) );
	if ( \is_null( $result ) || ! \property_exists( $result, 'id' ) ) {
		return null;
	}

	return $result;
}

/**
 * Creates a new repository using a repository template.
 *
 * @param   string          $owner                  The organization or person who will own the new repository.
 * @param   string          $repository             The name of the new repository.
 * @param   string          $template_owner         The owner of the template repository.
 * @param   string          $template_repository    The name of the template repository.
 * @param   string|null     $description            A short description of the new repository.
 *
 * @link    https://docs.github.com/en/rest/repos/repos#create-a-repository-using-a-template
 *
 * @return  object|null
 */
function create_github_repository_from_template( string $owner, string $repository, string $template_owner, string $template_repository, ?string $description = null ): ?object {
	$result = GitHub_API_Helper::call_api(
		sprintf( 'repos/%s/%s/generate', $template_owner, $template_repository ),
		'POST',
		array_filter(
			array(
				'owner'       => $owner,
				'name'        => $repository,
				'description' => $description,
				'private'     => true,
			)
		)
	);
	if ( \is_null( $result ) || ! \property_exists( $result, 'id' ) ) {
		return null;
	}

	return $result;
}

/**
 * Updates a repository.
 *
 * @param   string  $owner          The account owner of the repository. The name is not case-sensitive.
 * @param   string  $repository     The name of the repository. The name is not case-sensitive.
 * @param   array   $body           The body of the request.
 *
 * @link    https://docs.github.com/en/rest/repos/repos#update-a-repository
 *
 * @return  object|null
 */
function update_github_repository( string $owner, string $repository, array $body ): ?object {
	$result = GitHub_API_Helper::call_api( sprintf( 'repos/%s/%s', $owner, $repository ), 'PATCH', $body );
	if ( \is_null( $result ) || ! \property_exists( $result, 'id' ) ) {
		return null;
	}

	return $result;
}

/**
 * Deletes a label from a repository.
 *
 * @param   string  $owner          The account owner of the repository. The name is not case-sensitive.
 * @param   string  $repository     The name of the repository. The name is not case-sensitive.
 * @param   string  $name           The name of the label.
 *
 * @link    https://docs.github.com/en/rest/issues/labels#delete-a-label
 *
 * @return  bool
 */
function delete_github_repository_label( string $owner, string $repository, string $name ): bool {
	$result = GitHub_API_Helper::call_api( sprintf( 'repos/%s/%s/labels/%s', $owner, $repository, rawurlencode( $name ) ), 'DELETE' );
	return ! \is_null( $result );
}

/**
 * Creates a new label for a repository.
 *
 * @param   string          $owner           The account owner of the repository. The name is not case-sensitive.
 * @param   string          $repository      The name of the repository. The name is not case-sensitive.
 * @param   string          $name            The name of the label.
 * @param   string|null     $color           The color of the label.
 * @param   string|null     $description     A short description of the label.
 *
 * @link    https://docs.github.com/en/rest/issues/labels#create-a-label
 *
 * @return  object|null
 */
function create_github_repository_label( string $owner, string $repository, string $name, ?string $color = null, ?string $description = null ): ?object {
	$result = GitHub_API_Helper::call_api(
		sprintf( 'repos/%s/%s/labels', $owner, $repository ),
		'POST',
		array_filter(
			array(
				'name'        => $name,
				'color'       => $color,
				'description' => $description,
			)
		)
	);
	if ( \is_null( $result ) || ! \property_exists( $result, 'id' ) ) {
		return null;
	}

	return $result;
}

/**
 * Replaces all topics for a repository with the given ones.
 *
 * @param   string  $owner          The account owner of the repository. The name is not case-sensitive.
 * @param   string  $repository     The name of the repository. The name is not case-sensitive.
 * @param   array   $topics         The topics to replace the existing topics with. Uppercase letters are not allowed.
 *
 * @link    https://docs.github.com/en/rest/repos/repos#replace-all-repository-topics
 *
 * @return  object|null
 */
function replace_github_repository_topics( string $owner, string $repository, array $topics ): ?object {
	$result = GitHub_API_Helper::call_api(
		sprintf( 'repos/%s/%s/topics', $owner, $repository ),
		'PUT',
		array(
			'names' => $topics,
		)
	);
	if ( \is_null( $result ) || ! \property_exists( $result, 'names' ) ) {
		return null;
	}

	return $result;
}

/**
 * Lists all secrets available in a repository without revealing their encrypted values.
 *
 * @param   string  $owner          The account owner of the repository. The name is not case-sensitive.
 * @param   string  $repository     The name of the repository. The name is not case-sensitive.
 *
 * @return  object[]|null
 */
function get_github_repository_secrets( string $owner, string $repository ): ?array {
	$result = GitHub_API_Helper::call_api( \sprintf( 'repos/%s/%s/actions/secrets', $owner, $repository ) );
	if ( \is_null( $result ) || ! \property_exists( $result, 'secrets' ) ) {
		return null;
	}

	return $result->secrets;
}

/**
 * Gets your public key, which you need to encrypt secrets. You need to encrypt a secret before you can create or update secrets.
 *
 * @param   string  $owner          The account owner of the repository. The name is not case-sensitive.
 * @param   string  $repository     The name of the repository. The name is not case-sensitive.
 *
 * @return  object|null
 */
function get_github_repository_public_key( string $owner, string $repository ): ?object {
	$result = GitHub_API_Helper::call_api( \sprintf( 'repos/%s/%s/actions/secrets/public-key', $owner, $repository ) );
	if ( \is_null( $result ) || ! \property_exists( $result, 'key' ) ) {
		return null;
	}

	return $result;
}

/**
 * Creates or updates a repository secret with an encrypted value.
 *
 * @param   string  $owner              The account owner of the repository. The name is not case-sensitive.
 * @param   string  $repository         The name of the repository. The name is not case-sensitive.
 * @param   string  $secret_name        The name of the secret.
 * @param   string  $encrypted_value    Value for your secret, encrypted with LibSodium using the public key retrieved from the Get a repository public key endpoint.
 * @param   string  $key_id             ID of the key you used to encrypt the secret.
 *
 * @link    https://docs.github.com/en/rest/actions/secrets#create-or-update-a-repository-secret
 *
 * @return  bool
 */
function update_github_repository_secret( string $owner, string $repository, string $secret_name, string $encrypted_value, string $key_id ): bool {
	$result = GitHub_API_Helper::call_api(
		\sprintf( 'repos/%s/%s/actions/secrets/%s', $owner, $repository, $secret_name ),
		'PUT',
		array(
			'encrypted_value' => $encrypted_value,
			'key_id'          => $key_id,
		)
	);
	if ( \is_null( $result ) ) {
		return false;
	}

	return \is_object( $result ); // On success, we just have an empty object.
}

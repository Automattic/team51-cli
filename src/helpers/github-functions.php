<?php

namespace Team51\Helper;

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

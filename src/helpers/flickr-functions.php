<?php

namespace Team51\Helper;

/**
 * Returns a Flickr user object, given their username.
 *
 * @param   string  $username   The username of the user to lookup.
 *
 * @link    https://www.flickr.com/services/api/flickr.people.findByUsername.html
 *
 * @return  object|null
 */
function get_flickr_user_by_username( string $username ): ?object {
	$user = Flickr_API_Helper::call_api( 'flickr.people.findByUsername', array( 'username' => $username ) );
	return $user->user ?? null;
}

/**
 * Returns the photosets belonging to the specified user.
 *
 * @param   string  $user_id    The NSID of the user to get a photoset list for.
 *
 * @link    https://www.flickr.com/services/api/flickr.photosets.getList.html
 *
 * @return  object|null
 */
function get_flickr_photosets_for_user( string $user_id ): ?object {
	$photosets = Flickr_API_Helper::call_api( 'flickr.photosets.getList', array( 'user_id' => $user_id ) );
	return $photosets->photosets ?? null;
}

/**
 * Returns the list of photos in a set.
 *
 * @param   string  $photoset_id    The ID of the photoset to return the photos for.
 * @param   array   $arguments      Additional arguments to pass to the API call.
 *
 * @link    https://www.flickr.com/services/api/flickr.photosets.getPhotos.html
 *
 * @return   object|null
 */
function get_flickr_photos_for_photoset( string $photoset_id, array $arguments = array() ): ?object {
	$photos = Flickr_API_Helper::call_api( 'flickr.photosets.getPhotos', array( 'photoset_id' => $photoset_id ) + $arguments );
	return $photos->photoset ?? null;
}

/**
 * Returns photos from the given user's photostream. Only photos visible to the calling user will be returned.
 *
 * @param   string  $user_id    The NSID of the user whose photos to return. A value of "me" will return the calling user's photos.
 * @param   array   $arguments  Additional arguments to pass to the API call.
 *
 * @link    https://www.flickr.com/services/api/flickr.people.getPhotos.html
 *
 * @return  object|null
 */
function get_flickr_photos_for_user( string $user_id, array $arguments = array() ): ?object {
	$photos = Flickr_API_Helper::call_api( 'flickr.people.getPhotos', array( 'user_id' => $user_id ) + $arguments );
	return $photos->photos ?? null;
}

/**
 * Returns the available sizes for a photo. The calling user must have permission to view the photo.
 *
 * @param   string  $photo_id   The ID of the photo to return the sizes for.
 *
 * @link    https://www.flickr.com/services/api/flickr.photos.getSizes.html
 *
 * @return  object|null
 */
function get_flickr_photo_sizes( string $photo_id ): ?object {
	$sizes = Flickr_API_Helper::call_api( 'flickr.photos.getSizes', array( 'photo_id' => $photo_id ) );
	return $sizes->sizes ?? null;
}

/**
 * Returns the comments for a photo.
 *
 * @param   string  $photo_id   The ID of the photo to return the comments for.
 * @param   array   $arguments  Additional arguments to pass to the API call.
 *
 * @link    https://www.flickr.com/services/api/flickr.photos.comments.getList.html
 *
 * @return  object|null
 */
function get_flickr_comments_for_photo( string $photo_id, array $arguments = array() ): ?object {
	$comments = Flickr_API_Helper::call_api( 'flickr.photos.comments.getList', array( 'photo_id' => $photo_id ) + $arguments );
	return $comments->comments ?? null;
}

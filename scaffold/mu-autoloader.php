<?php
/**
 * This file is for loading all mu-plugins within subfolders
 * where the PHP file name is exactly like the directory name + .php.
 *
 * Example: /mu-tools/mu-tools.php
 */

$dirs = glob( dirname( __FILE__ ) . '/*', GLOB_ONLYDIR );

foreach ( $dirs as $dir ) {
	if ( file_exists( $dir . DIRECTORY_SEPARATOR . basename( $dir ) . '.php' ) ) {
		require $dir . DIRECTORY_SEPARATOR . basename( $dir ) . '.php';
	}
}

<?php
/**
 * This file is for loading Safety Net in the mu-plugins folder
 * 
 */

if ( file_exists( dirname( __FILE__ ) . '/safety-net/safety-net.php' ) ) {
	require dirname( __FILE__ ) . '/safety-net/safety-net.php';
}
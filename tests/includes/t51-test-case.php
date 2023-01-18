<?php
declare( strict_types=1 );

namespace Team51\Test;

use PHPUnit\Framework\TestCase;

/**
 * Includes utility functions useful for testing Team51 CLI code.
 */

abstract class T51TestCase extends TestCase {

	/**
	 * Invokes a private or protected method on an object.
	 *
	 * @param   object  $object       The object on which to invoke the method.
	 * @param   string  $method_name  The name of the method to invoke.
	 * @param   array   $parameters   The parameters to pass to the method.
	 *
	 * @return  mixed   The return value of the method.
	 */

	public function invoke_method( &$object, $method_name, array $parameters = array() ) {
		$reflection = new \ReflectionClass( get_class( $object ) );
		$method     = $reflection->getMethod( $method_name );
		$method->setAccessible( true );

		return $method->invokeArgs( $object, $parameters );
	}
}

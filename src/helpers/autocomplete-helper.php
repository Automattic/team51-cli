<?php

namespace Team51\Helper;

use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;

trait Autocomplete {

	public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void {
		$args = $input->getArguments();
		$arg_keys = array_keys($args);
		foreach( $arg_keys as $arg ) {
			if ( ! in_array($arg, [ 'command' ]) ) {
				$arg = $arg;
				$suggestions->suggestValue( $arg );
			}
		}

		$options = $input->getOptions();
		$opt_keys = array_keys($options);
		foreach( $opt_keys as $opt ) {
			if ( ! in_array($opt, ['ansi', 'contractor', 'help', 'no-interaction', 'version', 'verbose', 'quiet', 'dev']) ) {
				$opt = '--' . $opt;
				$suggestions->suggestValue( $opt );
			}
		}

		return;
    }
}

#!/usr/bin/env php
<?php

// Respect -q and --quiet.
if ( ! in_array( '-q', $argv ) && ! in_array( '--quiet', $argv ) ) {
    echo "Checking for updates.." . PHP_EOL;
}

exec( sprintf( "git -C %s %s",  __DIR__, 'pull' ) );
// TODO: Only run this when there are updates.
exec( sprintf( "composer install -o --working-dir %s", __DIR__ ) );
exec( sprintf( "composer dump-autoload -o --working-dir %s", __DIR__ ) );
echo PHP_EOL;

require __DIR__ . '/load-application.php';

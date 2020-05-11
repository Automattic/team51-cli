#!/usr/bin/env php
<?php

echo "Checking for updates.." . PHP_EOL;
exec( sprintf( "git -C %s %s",  __DIR__, 'pull' ) );
// TODO: Only run this when there are updates.
exec( "composer dump-autoload -o" );
echo PHP_EOL;

require __DIR__ . '/load-application.php';

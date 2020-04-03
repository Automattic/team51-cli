#!/usr/bin/env php
<?php

echo "Checking for updates.." . PHP_EOL;
exec( sprintf( "git -C %s %s",  __DIR__, 'pull' ) );
echo PHP_EOL;

require __DIR__ . '/load-application.php';

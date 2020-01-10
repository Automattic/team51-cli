#!/usr/bin/env php
<?php

echo "Checking for updates.." . PHP_EOL .  exec( 'git pull' ) . PHP_EOL;

require __DIR__ . '/load-application.php';

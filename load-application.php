<?php

require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Console\Application;

$application = new Application();

$application->add( new Team51\Command\Create_Production_Site() );
$application->add( new Team51\Command\Create_Development_Site() );
$application->add( new Team51\Command\Create_Repository() );
$application->add( new Team51\Command\Create_Repository() );

$application->run();
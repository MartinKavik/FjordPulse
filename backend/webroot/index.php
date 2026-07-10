<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Cake\Http\Server;
use FjordPulse\Application;

$server = new Server(new Application(dirname(__DIR__) . '/config'));
$server->emit($server->run());

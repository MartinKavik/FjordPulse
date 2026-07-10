<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Cake\Console\CommandRunner;
use FjordPulse\Application;

$runner = new CommandRunner(new Application(dirname(__DIR__) . '/config'), 'cake');
exit($runner->run($argv));

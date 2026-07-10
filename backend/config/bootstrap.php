<?php

declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'paths.php';
require CORE_PATH . 'config' . DS . 'bootstrap.php';
require CAKE . 'functions.php';

use Cake\Core\Configure;
use Cake\Core\Configure\Engine\PhpConfig;
use Cake\Error\ErrorTrap;
use Cake\Error\ExceptionTrap;
use Cake\Log\Engine\ConsoleLog;
use Cake\Log\Log;

Configure::config('default', new PhpConfig());
Configure::load('app', 'default', false);

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');
ini_set('intl.default_locale', 'en_US');

Log::setConfig('default', [
    'className' => ConsoleLog::class,
    'stream' => 'php://stderr',
    'levels' => ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'],
    'scopes' => false,
]);

(new ErrorTrap(Configure::read('Error')))->register();
(new ExceptionTrap(Configure::read('Error')))->register();

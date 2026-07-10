<?php

declare(strict_types=1);

use function Cake\Core\env;

return [
    'debug' => filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN),
    'App' => [
        'namespace' => 'FjordPulse',
        'encoding' => 'UTF-8',
        'defaultLocale' => 'en_US',
        'defaultTimezone' => 'UTC',
        'base' => false,
        'dir' => 'src',
        'webroot' => 'webroot',
        'wwwRoot' => WWW_ROOT,
        'fullBaseUrl' => env('APP_ORIGIN', 'http://127.0.0.1:8080'),
    ],
    'Error' => [
        'errorLevel' => E_ALL,
        'skipLog' => [],
        'log' => true,
        'trace' => filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN),
        'extraFatalErrorMemory' => 4,
    ],
    'FjordPulse' => [
        'environment' => (string)env('APP_ENV', 'development'),
        'dataMode' => (string)env('DATA_MODE', 'fake'),
        'version' => (string)env('APP_VERSION', 'dev'),
    ],
];

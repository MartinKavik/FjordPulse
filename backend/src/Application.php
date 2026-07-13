<?php

declare(strict_types=1);

namespace FjordPulse;

use Cake\Core\Configure;
use Cake\Error\Middleware\ErrorHandlerMiddleware;
use Cake\Http\BaseApplication;
use Cake\Http\Middleware\BodyParserMiddleware;
use Cake\Http\MiddlewareQueue;
use Cake\Routing\Middleware\RoutingMiddleware;
use FjordPulse\Config\RuntimeConfig;
use FjordPulse\Middleware\AdminAuthMiddleware;
use FjordPulse\Middleware\CorsMiddleware;
use FjordPulse\Middleware\HttpRateLimitMiddleware;
use FjordPulse\Middleware\JsonExceptionMiddleware;
use FjordPulse\Middleware\RequestIdMiddleware;
use FjordPulse\Middleware\SecurityHeadersMiddleware;
use FjordPulse\Middleware\StructuredAccessLogMiddleware;
use FjordPulse\Security\SignedToken;
use LogicException;

final class Application extends BaseApplication
{
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        $errorConfig = Configure::read('Error');
        if (!is_array($errorConfig)) {
            throw new LogicException('CakePHP Error configuration must be an array.');
        }
        $config = RuntimeConfig::fromEnvironment();
        $tokens = new SignedToken($config->adminSessionSecret);

        return $middlewareQueue
            ->add(new ErrorHandlerMiddleware($errorConfig, $this))
            ->add(new RequestIdMiddleware())
            ->add(new StructuredAccessLogMiddleware())
            ->add(new JsonExceptionMiddleware($config->debug))
            ->add(new SecurityHeadersMiddleware())
            ->add(new CorsMiddleware($config->allowedOrigins))
            ->add(new RoutingMiddleware($this))
            ->add(new AdminAuthMiddleware($tokens, $config->adminDemoAccess))
            ->add(new HttpRateLimitMiddleware($config->adminSessionSecret))
            ->add(new BodyParserMiddleware());
    }
}

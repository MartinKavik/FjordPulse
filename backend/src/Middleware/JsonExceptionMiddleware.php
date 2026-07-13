<?php

declare(strict_types=1);

namespace FjordPulse\Middleware;

use Cake\Core\Exception\HttpErrorCodeInterface;
use Cake\Http\Exception\HttpException;
use Cake\Log\Log;
use FjordPulse\Http\JsonErrorResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

final readonly class JsonExceptionMiddleware implements MiddlewareInterface
{
    public function __construct(private bool $debug)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (Throwable $error) {
            $requestId = $request->getAttribute('requestId');
            $requestId = is_string($requestId) ? $requestId : null;
            $status = $error instanceof HttpErrorCodeInterface ? $error->getCode() : 500;
            $status = $status >= 400 && $status <= 599 ? $status : 500;
            $code = match ($status) {
                400 => 'bad_request',
                401 => 'unauthorized',
                403 => 'forbidden',
                404 => 'not_found',
                405 => 'method_not_allowed',
                409 => 'conflict',
                422 => 'unprocessable_content',
                429 => 'rate_limited',
                503 => 'service_unavailable',
                default => 'internal_error',
            };
            $publicMessage = $status < 500 || $this->debug
                ? $error->getMessage()
                : 'The service could not complete the request.';

            Log::error(json_encode([
                'event' => 'http_exception',
                'requestId' => $requestId,
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
                'status' => $status,
                'exception' => $error::class,
                'message' => $error->getMessage(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            $response = JsonErrorResponse::create($status, $code, $publicMessage, [], $requestId);
            if ($error instanceof HttpException) {
                foreach ($error->getHeaders() as $name => $value) {
                    $response = $response->withHeader($name, $value);
                }
            }

            return $response;
        }
    }
}

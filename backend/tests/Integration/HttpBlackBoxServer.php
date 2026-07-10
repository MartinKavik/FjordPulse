<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Integration;

use FjordPulse\Surreal\AppUserBootstrapper;
use FjordPulse\Surreal\MigrationRunner;
use FjordPulse\Surreal\SdkSurrealConnectionFactory;
use FjordPulse\Surreal\SurrealConnectionConfig;
use RuntimeException;

/**
 * Owns the complete HTTP black-box test boundary: SurrealDB, database setup,
 * FrankenPHP/Caddy, and an isolated frontend fallback directory.
 */
final class HttpBlackBoxServer
{
    public const string ALLOWED_ORIGIN = 'https://allowed.fjordpulse.test';
    public const string ADMIN_USERNAME = 'blackbox-admin';
    public const string ADMIN_PASSWORD = 'blackbox-password';

    /** @var resource|null */
    private mixed $surrealProcess = null;

    /** @var resource|null */
    private mixed $surrealInput = null;

    /** @var resource|null */
    private mixed $httpProcess = null;

    /** @var resource|null */
    private mixed $httpInput = null;

    private bool $stopped = false;

    private function __construct(
        private readonly string $root,
        private readonly string $temporaryDirectory,
        private readonly int $surrealPort,
        private readonly int $httpPort,
        private readonly string $database,
    ) {
    }

    public static function start(): self
    {
        $root = dirname(__DIR__, 3);
        $temporaryDirectory = sys_get_temp_dir() . '/fjordpulse-http-blackbox-' . bin2hex(random_bytes(8));
        if (!mkdir($temporaryDirectory . '/frontend', 0o700, true) && !is_dir($temporaryDirectory . '/frontend')) {
            throw new RuntimeException('Unable to create HTTP black-box temporary directory.');
        }
        file_put_contents(
            $temporaryDirectory . '/frontend/index.html',
            '<!doctype html><html><body data-test="fjordpulse-spa">FjordPulse test shell</body></html>',
        );

        $surrealPort = self::availablePort();
        do {
            $httpPort = self::availablePort();
        } while ($httpPort === $surrealPort);

        $server = new self(
            $root,
            $temporaryDirectory,
            $surrealPort,
            $httpPort,
            'http_blackbox_' . bin2hex(random_bytes(5)),
        );

        try {
            $server->startSurreal();
            $server->prepareDatabase();
            $server->startHttp();

            return $server;
        } catch (\Throwable $error) {
            $server->stop();
            throw $error;
        }
    }

    public function baseUrl(): string
    {
        return "http://127.0.0.1:{$this->httpPort}";
    }

    public function stopSurreal(): void
    {
        self::stopProcess($this->surrealProcess, $this->surrealInput);
        $this->surrealProcess = null;
        $this->surrealInput = null;
    }

    public function restartSurreal(): void
    {
        $this->startSurreal();
    }

    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }
        $this->stopped = true;
        self::stopProcess($this->httpProcess, $this->httpInput);
        $this->httpProcess = null;
        $this->httpInput = null;
        self::stopProcess($this->surrealProcess, $this->surrealInput);
        $this->surrealProcess = null;
        $this->surrealInput = null;
        self::removeDirectory($this->temporaryDirectory);
    }

    private function startSurreal(): void
    {
        if (is_resource($this->surrealProcess)) {
            return;
        }

        $data = $this->temporaryDirectory . '/surreal';
        if (!is_dir($data) && !mkdir($data, 0o700, true) && !is_dir($data)) {
            throw new RuntimeException('Unable to create SurrealDB black-box data directory.');
        }
        $log = $this->temporaryDirectory . '/surreal.log';
        $process = proc_open([
            $this->root . '/tools/surreal',
            'start',
            '--no-banner',
            '--log',
            'error',
            '--bind',
            "127.0.0.1:{$this->surrealPort}",
            '--user',
            'root',
            '--pass',
            'root',
            'surrealkv:' . $data . '/database',
        ], [
            0 => ['pipe', 'r'],
            1 => ['file', $log, 'a'],
            2 => ['file', $log, 'a'],
        ], $pipes, $this->root);

        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start owned SurrealDB process.');
        }
        $this->surrealProcess = $process;
        $input = $pipes[0] ?? null;
        if (!is_resource($input)) {
            proc_terminate($process, SIGKILL);
            proc_close($process);
            throw new RuntimeException('Owned SurrealDB process did not expose an input pipe.');
        }
        $this->surrealInput = $input;
        $this->waitForPort($this->surrealPort, 'SurrealDB', $log);
    }

    private function prepareDatabase(): void
    {
        $factory = new SdkSurrealConnectionFactory(new SurrealConnectionConfig(
            "http://127.0.0.1:{$this->surrealPort}",
            "ws://127.0.0.1:{$this->surrealPort}/rpc",
            'fjordpulse_http_test',
            $this->database,
            'fjordpulse_http_app',
            'blackbox-database-password',
        ));
        $root = $factory->syncRoot('root', 'root');
        try {
            (new MigrationRunner($root, $this->root . '/backend/migrations'))->migrate();
            (new AppUserBootstrapper($root))->bootstrap('fjordpulse_http_app', 'blackbox-database-password');
        } finally {
            $root->close();
        }
    }

    private function startHttp(): void
    {
        $log = $this->temporaryDirectory . '/http.log';
        $environment = getenv();
        $environment = [...$environment, ...$this->httpEnvironment()];
        $process = proc_open([
            $this->root . '/tools/frankenphp',
            'run',
            '--config',
            $this->root . '/infra/Caddyfile',
            '--adapter',
            'caddyfile',
        ], [
            0 => ['pipe', 'r'],
            1 => ['file', $log, 'a'],
            2 => ['file', $log, 'a'],
        ], $pipes, $this->root, $environment);

        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start owned FrankenPHP process.');
        }
        $this->httpProcess = $process;
        $input = $pipes[0] ?? null;
        if (!is_resource($input)) {
            proc_terminate($process, SIGKILL);
            proc_close($process);
            throw new RuntimeException('Owned FrankenPHP process did not expose an input pipe.');
        }
        $this->httpInput = $input;
        $this->waitForPort($this->httpPort, 'FrankenPHP', $log);
        $this->waitForHttp($log);
    }

    /** @return array<string, string> */
    private function httpEnvironment(): array
    {
        return [
            'APP_ENV' => 'test',
            'APP_DEBUG' => 'true',
            'APP_VERSION' => 'http-blackbox-test',
            'APP_ORIGIN' => $this->baseUrl(),
            'ALLOWED_ORIGINS' => self::ALLOWED_ORIGIN,
            'HTTP_HOST' => '127.0.0.1',
            'HTTP_PORT' => (string)$this->httpPort,
            'FRONTEND_DIST' => $this->temporaryDirectory . '/frontend',
            'BACKEND_WEBROOT' => $this->root . '/backend/webroot',
            'REALTIME_UPSTREAM' => '127.0.0.1:1',
            'REALTIME_PUBLIC_URL' => 'ws://127.0.0.1:1/live',
            'DATA_MODE' => 'fake',
            'SCENARIO' => 'normal',
            'SURREAL_HTTP_URL' => "http://127.0.0.1:{$this->surrealPort}",
            'SURREAL_URL' => "ws://127.0.0.1:{$this->surrealPort}/rpc",
            'SURREAL_NAMESPACE' => 'fjordpulse_http_test',
            'SURREAL_DATABASE' => $this->database,
            'SURREAL_USERNAME' => 'fjordpulse_http_app',
            'SURREAL_PASSWORD' => 'blackbox-database-password',
            'ADMIN_USERNAME' => self::ADMIN_USERNAME,
            'ADMIN_PASSWORD' => self::ADMIN_PASSWORD,
            'ADMIN_SESSION_SECRET' => str_repeat('blackbox-session-secret-', 3),
            'ENTUR_CLIENT_NAME' => 'martinkavik-fjordpulse-blackbox',
        ];
    }

    private function waitForPort(int $port, string $service, string $log): void
    {
        $deadline = microtime(true) + 15.0;
        do {
            $socket = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.2);
            if (is_resource($socket)) {
                fclose($socket);

                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        $details = is_file($log) ? file_get_contents($log) : false;
        throw new RuntimeException(sprintf(
            '%s did not become ready: %d %s%s',
            $service,
            $errorCode,
            $errorMessage,
            is_string($details) ? "\n{$details}" : '',
        ));
    }

    private function waitForHttp(string $log): void
    {
        $deadline = microtime(true) + 15.0;
        $context = stream_context_create(['http' => [
            'ignore_errors' => true,
            'timeout' => 1.0,
        ]]);
        do {
            $body = @file_get_contents($this->baseUrl() . '/api/health', false, $context);
            if (is_string($body) && str_contains($body, '"ok":true')) {
                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        $details = is_file($log) ? file_get_contents($log) : false;
        throw new RuntimeException('FrankenPHP did not serve the CakePHP health endpoint.'
            . (is_string($details) ? "\n{$details}" : ''));
    }

    private static function availablePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if (!is_resource($socket)) {
            throw new RuntimeException("Unable to reserve a test port: {$errorCode} {$errorMessage}");
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        if (!is_string($name) || preg_match('/:(\d+)$/D', $name, $matches) !== 1) {
            throw new RuntimeException('Unable to determine reserved test port.');
        }

        return (int)$matches[1];
    }

    /**
     * @param resource|null $process
     * @param resource|null $input
     */
    private static function stopProcess(mixed $process, mixed $input): void
    {
        if (is_resource($input)) {
            fclose($input);
        }
        if (!is_resource($process)) {
            return;
        }

        $status = proc_get_status($process);
        if ($status['running']) {
            proc_terminate($process, SIGTERM);
            $deadline = microtime(true) + 5.0;
            do {
                usleep(25_000);
                $status = proc_get_status($process);
            } while ($status['running'] && microtime(true) < $deadline);
            if ($status['running']) {
                proc_terminate($process, SIGKILL);
            }
        }
        proc_close($process);
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}

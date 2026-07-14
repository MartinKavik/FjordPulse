<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Integration;

use FjordPulse\Surreal\AppUserBootstrapper;
use FjordPulse\Surreal\MigrationReport;
use FjordPulse\Surreal\MigrationRunner;
use FjordPulse\Surreal\SdkSurrealConnectionFactory;
use FjordPulse\Surreal\SurrealConnectionConfig;
use PHPUnit\Framework\TestCase;

abstract class SurrealIntegrationTestCase extends TestCase
{
    protected const string ROOT_USERNAME = 'root';
    protected const string ROOT_PASSWORD = 'root';
    protected const string APP_USERNAME = 'fjordpulse_app';
    protected const string APP_PASSWORD = 'integration-test-password';

    /** @var resource|null */
    private static mixed $serverProcess = null;
    /** @var resource|null */
    private static mixed $serverInput = null;
    private static bool $ownsServer = false;
    private static string $httpUrl;
    private static string $webSocketUrl;
    private static string $dataDirectory;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $external = getenv('FJORDPULSE_SURREAL_TEST_URL');
        if (is_string($external) && $external !== '') {
            self::$webSocketUrl = rtrim($external, '/');
            self::$httpUrl = preg_replace('/^wss:/', 'https:', preg_replace('/^ws:/', 'http:', self::$webSocketUrl) ?? '') ?? '';
            self::$httpUrl = preg_replace('#/rpc$#', '', self::$httpUrl) ?? self::$httpUrl;
            self::$ownsServer = false;

            return;
        }

        $port = 18_100 + (getmypid() % 500);
        self::$httpUrl = "http://127.0.0.1:{$port}";
        self::$webSocketUrl = "ws://127.0.0.1:{$port}/rpc";
        self::$dataDirectory = sys_get_temp_dir() . '/fjordpulse-surreal-' . getmypid();
        self::$ownsServer = true;
        self::startOwnedServer();
    }

    public static function tearDownAfterClass(): void
    {
        self::stopOwnedServer();
        parent::tearDownAfterClass();
    }

    /** @return array{SdkSurrealConnectionFactory, MigrationReport, string} */
    protected function database(string $label): array
    {
        $database = strtolower(preg_replace('/[^a-z0-9_]/i', '_', $label) ?? $label)
            . '_' . bin2hex(random_bytes(4));
        $factory = new SdkSurrealConnectionFactory(new SurrealConnectionConfig(
            self::$httpUrl,
            self::$webSocketUrl,
            'fjordpulse_test',
            $database,
            self::APP_USERNAME,
            self::APP_PASSWORD,
        ));
        $root = $factory->syncRoot(self::ROOT_USERNAME, self::ROOT_PASSWORD);

        try {
            $report = (new MigrationRunner($root, dirname(__DIR__, 2) . '/migrations'))->migrate();
            (new AppUserBootstrapper($root))->bootstrap(self::APP_USERNAME, self::APP_PASSWORD);
        } finally {
            $root->close();
        }

        return [$factory, $report, $database];
    }

    protected function databaseUserFactory(
        string $database,
        string $username,
        string $password,
    ): SdkSurrealConnectionFactory {
        return new SdkSurrealConnectionFactory(new SurrealConnectionConfig(
            self::$httpUrl,
            self::$webSocketUrl,
            'fjordpulse_test',
            $database,
            $username,
            $password,
        ));
    }

    protected static function restartOwnedServer(): void
    {
        if (!self::$ownsServer) {
            self::markTestSkipped('Reconnect restart test requires the repository-managed SurrealDB process.');
        }

        self::stopOwnedServer();
        self::startOwnedServer();
    }

    protected static function stopServerForReconnect(): void
    {
        if (!self::$ownsServer) {
            self::markTestSkipped('Reconnect restart test requires the repository-managed SurrealDB process.');
        }

        self::stopOwnedServer();
    }

    protected static function startServerAfterReconnect(): void
    {
        if (!self::$ownsServer) {
            self::markTestSkipped('Reconnect restart test requires the repository-managed SurrealDB process.');
        }

        self::startOwnedServer();
    }

    private static function startOwnedServer(): void
    {
        if (!self::$ownsServer || is_resource(self::$serverProcess)) {
            return;
        }

        $root = dirname(__DIR__, 3);
        if (!is_dir(self::$dataDirectory) && !mkdir(self::$dataDirectory, 0o700, true) && !is_dir(self::$dataDirectory)) {
            throw new \RuntimeException('Unable to create SurrealDB integration-test data directory.');
        }

        $port = parse_url(self::$httpUrl, PHP_URL_PORT);
        if (!is_int($port)) {
            throw new \RuntimeException('Invalid SurrealDB integration-test port.');
        }

        $log = self::$dataDirectory . '/server.log';
        $process = proc_open([
            $root . '/tools/surreal',
            'start',
            '--no-banner',
            '--log',
            'error',
            '--bind',
            "127.0.0.1:{$port}",
            '--user',
            self::ROOT_USERNAME,
            '--pass',
            self::ROOT_PASSWORD,
            'surrealkv:' . self::$dataDirectory . '/database',
        ], [
            0 => ['pipe', 'r'],
            1 => ['file', $log, 'a'],
            2 => ['file', $log, 'a'],
        ], $pipes, $root);

        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start SurrealDB integration-test process.');
        }

        self::$serverProcess = $process;
        self::$serverInput = $pipes[0] ?? null;

        $deadline = microtime(true) + 15.0;
        do {
            $socket = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.2);
            if (is_resource($socket)) {
                fclose($socket);

                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        self::stopOwnedServer();
        throw new \RuntimeException("SurrealDB integration-test server did not become ready: {$errorCode} {$errorMessage}");
    }

    private static function stopOwnedServer(): void
    {
        if (!self::$ownsServer || !is_resource(self::$serverProcess)) {
            return;
        }

        if (is_resource(self::$serverInput)) {
            fclose(self::$serverInput);
        }
        self::$serverInput = null;

        $status = proc_get_status(self::$serverProcess);
        if ($status['running']) {
            proc_terminate(self::$serverProcess, SIGTERM);
            $deadline = microtime(true) + 5.0;
            do {
                usleep(25_000);
                $status = proc_get_status(self::$serverProcess);
            } while ($status['running'] && microtime(true) < $deadline);

            if ($status['running']) {
                proc_terminate(self::$serverProcess, SIGKILL);
            }
        }

        proc_close(self::$serverProcess);
        self::$serverProcess = null;
    }
}

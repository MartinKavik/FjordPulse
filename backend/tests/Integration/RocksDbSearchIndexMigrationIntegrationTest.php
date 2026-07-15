<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Integration;

use FjordPulse\Surreal\DatabaseRecord;
use FjordPulse\Surreal\MigrationRunner;
use FjordPulse\Surreal\SdkSurrealConnectionFactory;
use FjordPulse\Surreal\SurrealConnectionConfig;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class RocksDbSearchIndexMigrationIntegrationTest extends TestCase
{
    private const string ROOT_USERNAME = 'root';
    private const string ROOT_PASSWORD = 'root';

    /** @var resource|null */
    private mixed $serverProcess = null;
    /** @var resource|null */
    private mixed $serverInput = null;
    private string $root;
    private string $temporaryDirectory;
    private string $httpUrl;
    private string $webSocketUrl;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = dirname(__DIR__, 3);
        $this->temporaryDirectory = sys_get_temp_dir()
            . '/fjordpulse-rocks-search-' . getmypid() . '-' . bin2hex(random_bytes(4));
        if (!mkdir($this->temporaryDirectory, 0o700, true) && !is_dir($this->temporaryDirectory)) {
            throw new \RuntimeException('Unable to create RocksDB search-index test directory.');
        }

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if ($socket === false) {
            throw new \RuntimeException("Unable to reserve a RocksDB test port: {$errorCode} {$errorMessage}");
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $separator = is_string($address) ? strrpos($address, ':') : false;
        $port = is_string($address) && is_int($separator) ? (int)substr($address, $separator + 1) : 0;
        if ($port <= 0) {
            throw new \RuntimeException('Unable to determine the RocksDB test port.');
        }

        $this->httpUrl = "http://127.0.0.1:{$port}";
        $this->webSocketUrl = "ws://127.0.0.1:{$port}/rpc";
        $log = $this->temporaryDirectory . '/server.log';
        $process = proc_open([
            $this->root . '/tools/surreal',
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
            'rocksdb:' . $this->temporaryDirectory . '/database',
        ], [
            0 => ['pipe', 'r'],
            1 => ['file', $log, 'a'],
            2 => ['file', $log, 'a'],
        ], $pipes, $this->root);
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start the RocksDB search-index test server.');
        }
        $this->serverProcess = $process;
        $this->serverInput = $pipes[0] ?? null;

        $deadline = microtime(true) + 15.0;
        $socketErrorCode = 0;
        $socketErrorMessage = '';
        do {
            $connection = @fsockopen('127.0.0.1', $port, $socketErrorCode, $socketErrorMessage, 0.2);
            if (is_resource($connection)) {
                fclose($connection);

                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        throw new \RuntimeException(
            "RocksDB search-index test server did not become ready: {$socketErrorCode} {$socketErrorMessage}",
        );
    }

    protected function tearDown(): void
    {
        if (is_resource($this->serverInput)) {
            fclose($this->serverInput);
        }
        $this->serverInput = null;

        if (is_resource($this->serverProcess)) {
            $status = proc_get_status($this->serverProcess);
            if ($status['running']) {
                proc_terminate($this->serverProcess, SIGTERM);
                $deadline = microtime(true) + 5.0;
                do {
                    usleep(25_000);
                    $status = proc_get_status($this->serverProcess);
                } while ($status['running'] && microtime(true) < $deadline);

                if ($status['running']) {
                    proc_terminate($this->serverProcess, SIGKILL);
                }
            }
            proc_close($this->serverProcess);
        }
        $this->serverProcess = null;

        if (isset($this->temporaryDirectory) && is_dir($this->temporaryDirectory)) {
            $this->removeDirectory($this->temporaryDirectory);
        }

        parent::tearDown();
    }

    public function testBlockingRebuildRepairsTransactionalArrayIndexesOnRocksDb(): void
    {
        $migrations = $this->temporaryDirectory . '/migrations';
        if (!mkdir($migrations, 0o700) && !is_dir($migrations)) {
            throw new \RuntimeException('Unable to create the RocksDB migration fixture directory.');
        }

        $this->writeMigration($migrations . '/001_legacy_station.surql', <<<'SURQL'
DEFINE TABLE station SCHEMALESS PERMISSIONS NONE;

CREATE station:`NSR:StopPlace:34503` CONTENT {
    station_id: "NSR:StopPlace:34503",
    name: "Reed",
    kind: "stop_place",
    search_name: "reed",
    search_text: "reed",
    search_tokens: ["reed"]
};
SURQL);
        $this->copyMigration('014_station_search_indexes.surql', $migrations);
        $this->copyMigration('017_materialize_search_indexes.surql', $migrations);

        $factory = new SdkSurrealConnectionFactory(new SurrealConnectionConfig(
            $this->httpUrl,
            $this->webSocketUrl,
            'fjordpulse_rocks_test',
            'transactional_array_indexes',
            'unused_app_user',
            'unused_app_password',
        ));
        $root = $factory->syncRoot(self::ROOT_USERNAME, self::ROOT_PASSWORD);

        try {
            $report = (new MigrationRunner($root, $migrations))->migrate();
            self::assertSame(
                [
                    '001_legacy_station.surql',
                    '014_station_search_indexes.surql',
                    '017_materialize_search_indexes.surql',
                ],
                array_map(static fn($migration): string => $migration->name, $report->applied),
            );

            $results = $root->run(<<<'SURQL'
SELECT VALUE station_id
FROM station
WHERE search_one_edit_keys CONTAINSANY ["f:4:r"];
SELECT VALUE station_id
FROM station
WHERE search_token_prefixes CONTAINS "p:re";
EXPLAIN ANALYZE FORMAT TEXT
SELECT station_id
FROM station
WHERE search_one_edit_keys CONTAINSANY ["f:4:r"];
EXPLAIN ANALYZE FORMAT TEXT
SELECT station_id
FROM station
WHERE search_token_prefixes CONTAINS "p:re";
SURQL);

            self::assertSame(['NSR:StopPlace:34503'], $results[0] ?? []);
            self::assertSame(['NSR:StopPlace:34503'], $results[1] ?? []);

            $typoPlan = DatabaseRecord::string($results[2] ?? null, 'RocksDB typo index query plan');
            self::assertStringContainsString('station_search_one_edit', $typoPlan);
            self::assertStringNotContainsString('TableScan', $typoPlan);

            $prefixPlan = DatabaseRecord::string($results[3] ?? null, 'RocksDB prefix index query plan');
            self::assertStringContainsString('station_search_token_prefixes', $prefixPlan);
            self::assertStringNotContainsString('TableScan', $prefixPlan);
        } finally {
            $root->close();
        }
    }

    private function copyMigration(string $name, string $destination): void
    {
        $source = $this->root . '/backend/migrations/' . $name;
        $contents = file_get_contents($source);
        if ($contents === false) {
            throw new \RuntimeException("Unable to read migration fixture {$name}.");
        }
        $this->writeMigration($destination . '/' . $name, $contents);
    }

    private function writeMigration(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new \RuntimeException("Unable to write migration fixture {$path}.");
        }
    }

    private function removeDirectory(string $directory): void
    {
        $entries = scandir($directory);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}

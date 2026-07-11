<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Integration;

use RuntimeException;

final class ControllableEnturServer
{
    /** @var resource|null */
    private mixed $process = null;

    /** @var resource|null */
    private mixed $input = null;

    private function __construct(
        private readonly string $root,
        private readonly string $temporaryDirectory,
        private readonly int $port,
    ) {
    }

    public static function start(): self
    {
        $root = dirname(__DIR__, 3);
        $temporaryDirectory = sys_get_temp_dir() . '/fjordpulse-entur-recovery-' . bin2hex(random_bytes(8));
        if (!mkdir($temporaryDirectory, 0o700, true) && !is_dir($temporaryDirectory)) {
            throw new RuntimeException('Unable to create the controlled Entur server directory.');
        }
        $server = new self($root, $temporaryDirectory, self::availablePort());
        $server->setAvailable(false);

        try {
            $server->startProcess();

            return $server;
        } catch (\Throwable $error) {
            $server->stop();
            throw $error;
        }
    }

    public function endpoint(): string
    {
        return 'http://127.0.0.1:' . $this->port . '/graphql';
    }

    public function setAvailable(bool $available): void
    {
        $written = file_put_contents(
            $this->statePath(),
            json_encode(['available' => $available], JSON_THROW_ON_ERROR),
            LOCK_EX,
        );
        if ($written === false) {
            throw new RuntimeException('Unable to change the controlled Entur server state.');
        }
    }

    public function stopServing(): void
    {
        $this->stopProcess();
        $deadline = microtime(true) + 3.0;
        do {
            $socket = @fsockopen('127.0.0.1', $this->port, $errorCode, $errorMessage, 0.05);
            if (!is_resource($socket)) {
                return;
            }
            fclose($socket);
            usleep(20_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('Controlled Entur server did not release its port.');
    }

    public function restart(): void
    {
        if (is_resource($this->process)) {
            throw new \LogicException('Controlled Entur server is already running.');
        }
        $this->startProcess();
    }

    /** @return list<array{method: mixed, path: mixed, clientName: mixed, query: mixed}> */
    public function requests(): array
    {
        if (!is_file($this->logPath())) {
            return [];
        }
        $lines = file($this->logPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            throw new RuntimeException('Unable to read controlled Entur request log.');
        }

        return array_map(static function (string $line): array {
            $entry = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($entry)) {
                throw new RuntimeException('Controlled Entur request log contains an invalid entry.');
            }

            return [
                'method' => $entry['method'] ?? null,
                'path' => $entry['path'] ?? null,
                'clientName' => $entry['clientName'] ?? null,
                'query' => $entry['query'] ?? null,
            ];
        }, $lines);
    }

    public function stop(): void
    {
        $this->stopProcess();
        foreach (glob($this->temporaryDirectory . '/*') ?: [] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        @rmdir($this->temporaryDirectory);
    }

    private function startProcess(): void
    {
        @unlink($this->shutdownPath());
        $environment = getenv();
        $environment['FJORDPULSE_ENTUR_FAKE_STATE'] = $this->statePath();
        $environment['FJORDPULSE_ENTUR_FAKE_LOG'] = $this->logPath();
        $environment['FJORDPULSE_ENTUR_FAKE_PORT'] = (string)$this->port;
        $environment['FJORDPULSE_ENTUR_FAKE_SHUTDOWN'] = $this->shutdownPath();
        $process = proc_open([
            $this->root . '/tools/php',
            $this->root . '/backend/tests/Fixture/controllable-entur-server.php',
        ], [
            0 => ['pipe', 'r'],
            1 => ['file', $this->temporaryDirectory . '/server.log', 'a'],
            2 => ['file', $this->temporaryDirectory . '/server.log', 'a'],
        ], $pipes, $this->root, $environment);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start the controlled Entur server.');
        }
        $this->process = $process;
        $this->input = $pipes[0] ?? null;

        try {
            $this->waitUntilReady();
        } catch (\Throwable $error) {
            $this->stopProcess();
            throw $error;
        }
    }

    private function stopProcess(): void
    {
        if (is_resource($this->input)) {
            fclose($this->input);
        }
        $this->input = null;
        if (!is_resource($this->process)) {
            return;
        }
        file_put_contents($this->shutdownPath(), 'stop', LOCK_EX);
        $status = proc_get_status($this->process);
        if ($status['running']) {
            $deadline = microtime(true) + 3.0;
            do {
                usleep(20_000);
                $status = proc_get_status($this->process);
            } while ($status['running'] && microtime(true) < $deadline);
            if ($status['running']) {
                proc_terminate($this->process, SIGTERM);
            }
        }
        proc_close($this->process);
        $this->process = null;
    }

    private function waitUntilReady(): void
    {
        $deadline = microtime(true) + 10.0;
        do {
            $socket = @fsockopen('127.0.0.1', $this->port, $errorCode, $errorMessage, 0.2);
            if (is_resource($socket)) {
                fclose($socket);

                return;
            }
            usleep(25_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException("Controlled Entur server did not become ready: {$errorCode} {$errorMessage}");
    }

    private function statePath(): string
    {
        return $this->temporaryDirectory . '/state.json';
    }

    private function logPath(): string
    {
        return $this->temporaryDirectory . '/requests.jsonl';
    }

    private function shutdownPath(): string
    {
        return $this->temporaryDirectory . '/shutdown';
    }

    private static function availablePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if (!is_resource($socket)) {
            throw new RuntimeException("Unable to reserve an Entur test port: {$errorCode} {$errorMessage}");
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        if (!is_string($address) || preg_match('/:(\d+)$/D', $address, $matches) !== 1) {
            throw new RuntimeException('Unable to parse the reserved Entur test port.');
        }

        return (int)$matches[1];
    }
}

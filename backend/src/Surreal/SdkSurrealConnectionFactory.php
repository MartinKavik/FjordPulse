<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use SurrealDB\SDK\Auth\DatabaseAuth;
use SurrealDB\SDK\Auth\RootAuth;
use SurrealDB\SDK\Connection\ConnectOptions;
use SurrealDB\SDK\Connection\DriverOptions;
use SurrealDB\SDK\Reconnect\ExponentialBackoffReconnect;
use SurrealDB\SDK\Runtime\Runtime;
use SurrealDB\SDK\Surreal;
use SurrealDB\SDK\Types\Table;

final readonly class SdkSurrealConnectionFactory implements SurrealConnectionFactory
{
    public function __construct(private SurrealConnectionConfig $config)
    {
    }

    public function sync(): SurrealConnection
    {
        return $this->connectApp(Runtime::sync(), $this->config->httpUrl, reconnect: false);
    }

    public function ampCommand(): SurrealConnection
    {
        return $this->connectApp($this->ampRuntime(), $this->config->httpUrl, reconnect: false);
    }

    public function ampLive(): SurrealConnection
    {
        return $this->connectApp(
            $this->ampRuntime(),
            $this->config->webSocketUrl,
            reconnect: new ExponentialBackoffReconnect(
                maxAttempts: -1,
                retryDelay: 0.25,
                retryDelayMax: 10.0,
                retryDelayMultiplier: 2.0,
                retryDelayJitter: 0.1,
            ),
        );
    }

    public function syncRoot(string $username, string $password): SurrealConnection
    {
        $events = new SdkEventDispatcher();
        $options = Runtime::sync();
        $options->events = $events;
        $client = new Surreal($options);
        $client->connect($this->config->httpUrl, new ConnectOptions(
            authentication: new RootAuth($username, $password),
            reconnect: false,
        ));
        $namespace = Table::escapeIdent($this->config->namespace);
        $database = Table::escapeIdent($this->config->database);
        $client->run("DEFINE NAMESPACE IF NOT EXISTS {$namespace};");
        $client->use($this->config->namespace);
        $client->run("DEFINE DATABASE IF NOT EXISTS {$database};");
        $client->use($this->config->namespace, $this->config->database);

        return new SdkSurrealConnection($client, $events);
    }

    private function connectApp(
        DriverOptions $runtime,
        string $url,
        bool|ExponentialBackoffReconnect $reconnect,
    ): SurrealConnection {
        $events = new SdkEventDispatcher();
        $runtime->events = $events;
        $client = new Surreal($runtime);
        $client->connect($url, new ConnectOptions(
            namespace: $this->config->namespace,
            database: $this->config->database,
            authentication: new DatabaseAuth(
                $this->config->namespace,
                $this->config->database,
                $this->config->username,
                $this->config->password,
            ),
            reconnect: $reconnect,
        ));

        return new SdkSurrealConnection($client, $events);
    }

    private function ampRuntime(): DriverOptions
    {
        $options = Runtime::amp();
        $options->scheduler = new BootstrapRevoltScheduler();

        return $options;
    }
}

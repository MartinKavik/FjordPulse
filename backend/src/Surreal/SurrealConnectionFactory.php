<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

interface SurrealConnectionFactory
{
    /** Short CakePHP request-path connection using Runtime::sync(). */
    public function sync(): SurrealConnection;

    /** Long-running command/query connection using Runtime::amp(). */
    public function ampCommand(): SurrealConnection;

    /** Dedicated WebSocket live-query connection using Runtime::amp(). */
    public function ampLive(): SurrealConnection;

    /** Migration/bootstrap connection authenticated as a root system user. */
    public function syncRoot(string $username, string $password): SurrealConnection;
}

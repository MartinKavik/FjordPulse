<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

final readonly class RealtimeDatabaseConnections
{
    public function __construct(
        public SurrealConnection $command,
        public SurrealConnection $live,
    ) {
        if ($command === $live) {
            throw new \InvalidArgumentException('Command and live-query connections must be separate instances.');
        }
    }

    public static function connect(SurrealConnectionFactory $factory): self
    {
        return new self($factory->ampCommand(), $factory->ampLive());
    }

    public function close(): void
    {
        $this->live->close();
        $this->command->close();
    }
}

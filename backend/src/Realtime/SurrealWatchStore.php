<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

use FjordPulse\Dto\Watch;
use FjordPulse\Surreal\WatchRepository;

final readonly class SurrealWatchStore implements WatchStore
{
    public function __construct(private WatchRepository $repository)
    {
    }

    public function save(Watch $watch): void
    {
        $this->repository->save($watch);
    }

    public function delete(string $watchId): void
    {
        $this->repository->delete($watchId);
    }
}

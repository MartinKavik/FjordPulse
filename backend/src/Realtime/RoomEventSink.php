<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

use DateTimeImmutable;
use FjordPulse\Dto\RealtimeEvent;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class RoomEventSink implements RealtimeEventSink
{
    /** @var array<string, array{version: DateTimeImmutable, eventId: string}> */
    private array $ledger = [];

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly RoomRegistry $rooms,
        ?LoggerInterface $logger = null,
        private readonly RealtimeEventValidator $validator = new RealtimeEventValidator(),
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function publish(RealtimeEvent $event): void
    {
        $this->validator->validate($event);
        try {
            $version = new DateTimeImmutable($event->version);
        } catch (\Exception $error) {
            throw new \InvalidArgumentException('Realtime event version must be RFC3339.', previous: $error);
        }
        $previous = $this->ledger[$event->scope] ?? null;
        if ($previous !== null && ($previous['eventId'] === $event->eventId || $version <= $previous['version'])) {
            $this->logger->debug('Ignored duplicate or older realtime event.', [
                'eventId' => $event->eventId,
                'scope' => $event->scope,
                'version' => $event->version,
            ]);

            return;
        }
        $this->ledger[$event->scope] = ['version' => $version, 'eventId' => $event->eventId];
        $recipients = $this->rooms->broadcast($event->scope, EnvelopeFactory::persistentEvent($event));
        $this->logger->info('Published database-originated realtime event.', [
            'eventId' => $event->eventId,
            'eventType' => $event->type->value,
            'scope' => $event->scope,
            'version' => $event->version,
            'recipients' => $recipients,
        ]);
    }
}

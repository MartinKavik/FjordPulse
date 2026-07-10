<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use Amp\Cancellation;
use Amp\CancelledException;
use DateTimeImmutable;
use DateTimeZone;
use FjordPulse\Dto\RealtimeEvent;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SurrealDB\SDK\Live\LiveAction;
use SurrealDB\SDK\Live\LiveMessage;
use SurrealDB\SDK\Protocol\Features;
use SurrealDB\SDK\Types\Uuid;

use function Amp\delay;

final class SupervisedLiveQueryBridge implements LiveQueryBridge
{
    private LiveQueryBridgeState $state = LiveQueryBridgeState::Stopped;
    private ?SurrealConnection $connection = null;
    private ?string $queryId = null;
    private ?DateTimeImmutable $startedAt = null;
    private ?DateTimeImmutable $connectedAt = null;
    private ?DateTimeImmutable $lastEventAt = null;
    private ?DateTimeImmutable $lastErrorAt = null;
    private ?string $lastError = null;
    private int $failureCount = 0;
    private int $subscriptionCount = 0;
    private bool $running = false;
    private bool $stopping = false;

    /** @var \Closure(float, ?Cancellation): void */
    private readonly \Closure $delay;
    private readonly LoggerInterface $logger;

    /**
     * @param (\Closure(float, ?Cancellation): void)|null $delay
     */
    public function __construct(
        private readonly SurrealConnectionFactory $connections,
        ?LoggerInterface $logger = null,
        ?\Closure $delay = null,
        private readonly float $minimumRetryDelay = 0.25,
        private readonly float $maximumRetryDelay = 10.0,
    ) {
        if ($minimumRetryDelay < 0.0 || $maximumRetryDelay < $minimumRetryDelay) {
            throw new InvalidArgumentException('Invalid live-query bridge retry delays.');
        }

        $this->logger = $logger ?? new NullLogger();
        $this->delay = $delay ?? static function (float $seconds, ?Cancellation $cancellation): void {
            delay($seconds, cancellation: $cancellation);
        };
    }

    public function run(\Closure $onEvent, ?\Closure $onRecovery = null, ?Cancellation $cancellation = null): void
    {
        if ($this->running) {
            throw new \LogicException('The live-query bridge is already running.');
        }

        $this->running = true;
        $this->stopping = false;
        $this->startedAt = self::now();
        $this->state = LiveQueryBridgeState::Connecting;
        $cancellationSubscription = $cancellation?->subscribe(function (): void {
            $this->stop();
        });

        try {
            while (!$this->stopping) {
                $cancellation?->throwIfRequested();
                $wasRecovery = $this->subscriptionCount > 0;

                try {
                    $this->consumeSubscription($onEvent, $onRecovery, $wasRecovery, $cancellation);

                    if (!$this->stopping) {
                        throw new \RuntimeException('SurrealDB live-query stream terminated unexpectedly.');
                    }
                } catch (CancelledException) {
                    $this->stopping = true;
                } catch (\Throwable $error) {
                    $this->markFailure($error);
                    $this->closeCurrentConnection();
                    ($this->delay)($this->retryDelay(), $cancellation);
                    $this->state = LiveQueryBridgeState::Connecting;
                }
            }
        } finally {
            if ($cancellation !== null && $cancellationSubscription !== null) {
                $cancellation->unsubscribe($cancellationSubscription);
            }

            $this->state = LiveQueryBridgeState::Stopping;
            $this->closeCurrentConnection();
            $this->running = false;
            $this->stopping = false;
            $this->state = LiveQueryBridgeState::Stopped;
        }
    }

    public function stop(): void
    {
        if (!$this->running || $this->stopping) {
            return;
        }

        $this->stopping = true;
        $this->state = LiveQueryBridgeState::Stopping;
        // alpha.1 exposes no live-query kill RPC, and issuing SurrealQL KILL
        // through its query RPC cannot resolve the active per-connection id.
        // Closing this dedicated WebSocket is the tested graceful fallback and
        // releases all live queries owned by the connection.
        $this->closeCurrentConnection();
    }

    public function status(): LiveQueryBridgeStatus
    {
        return new LiveQueryBridgeStatus(
            $this->state,
            $this->queryId,
            $this->startedAt,
            $this->connectedAt,
            $this->lastEventAt,
            $this->lastErrorAt,
            $this->lastError,
            $this->failureCount,
            $this->subscriptionCount,
        );
    }

    /**
     * @param \Closure(RealtimeEvent): void $onEvent
     * @param (\Closure(LiveQueryBridgeStatus): void)|null $onRecovery
     */
    private function consumeSubscription(
        \Closure $onEvent,
        ?\Closure $onRecovery,
        bool $wasRecovery,
        ?Cancellation $cancellation,
    ): void {
        $this->state = $wasRecovery ? LiveQueryBridgeState::Reconnecting : LiveQueryBridgeState::Connecting;
        $connection = $this->connections->ampLive();
        $this->connection = $connection;
        $streamActive = true;
        $streamError = null;
        $unsubscribers = [
            $connection->subscribe('reconnecting', function () use (&$streamActive): void {
                if (!$this->stopping) {
                    $this->state = LiveQueryBridgeState::Reconnecting;
                }
                $streamActive = false;
            }),
            $connection->subscribe('disconnected', function () use (&$streamActive): void {
                $streamActive = false;
            }),
            $connection->subscribe('error', function (\Throwable $error) use (&$streamActive, &$streamError): void {
                if (!$this->stopping) {
                    $this->lastError = $error->getMessage();
                    $this->lastErrorAt = self::now();
                }
                $streamError = $error;
                $streamActive = false;
            }),
        ];

        try {
            if (!$connection->supports(Features::liveQueries())) {
                throw new \RuntimeException('The connected SurrealDB transport does not support live queries.');
            }

            $results = $connection->run('LIVE SELECT * FROM realtime_event;');
            $queryId = self::queryId($results[0] ?? null);
            $this->queryId = $queryId;
            $unsubscribers[] = $connection->subscribeLiveMessages(
                $queryId,
                function (LiveMessage $message) use (&$streamActive, &$streamError, $onEvent): void {
                    if ($message->action === LiveAction::Killed) {
                        $streamActive = false;

                        return;
                    }

                    if ($message->action !== LiveAction::Create) {
                        return;
                    }

                    try {
                        // SurrealDB 3.2 sends the changed record id in
                        // `record` and selected data in `result`/value.
                        $record = DatabaseRecord::one($message->value ?? $message->record);
                        if ($record === null) {
                            throw new \RuntimeException('SurrealDB CREATE notification did not contain a record.');
                        }

                        $event = SurrealDtoMapper::realtimeEvent($record);
                        $this->lastEventAt = self::now();
                        $onEvent($event);
                    } catch (\Throwable $error) {
                        $streamError = $error;
                        $streamActive = false;
                    }
                },
            );
            $this->connectedAt = self::now();
            $this->subscriptionCount++;
            $this->state = LiveQueryBridgeState::Healthy;

            if ($wasRecovery && $onRecovery !== null) {
                $onRecovery($this->status());
            }

            while ($streamActive && !$this->stopping) {
                $cancellation?->throwIfRequested();
                delay(0.02, cancellation: $cancellation);
            }

            if ($streamError !== null) {
                throw $streamError;
            }
        } finally {
            foreach ($unsubscribers as $unsubscribe) {
                $unsubscribe();
            }
        }
    }

    private function markFailure(\Throwable $error): void
    {
        $this->failureCount++;
        $this->lastErrorAt = self::now();
        $this->lastError = $error->getMessage();
        $this->state = LiveQueryBridgeState::Degraded;
        $this->logger->error('SurrealDB live-query bridge degraded.', [
            'error' => $error->getMessage(),
            'failureCount' => $this->failureCount,
            'queryId' => $this->queryId,
        ]);
    }

    private function closeCurrentConnection(): void
    {
        try {
            $this->connection?->close();
        } catch (\Throwable $error) {
            $this->logger->warning('Unable to close SurrealDB live-query connection.', [
                'error' => $error->getMessage(),
            ]);
        } finally {
            $this->connection = null;
            $this->queryId = null;
        }
    }

    private function retryDelay(): float
    {
        $exponent = min(max($this->failureCount - 1, 0), 16);
        $delay = min($this->minimumRetryDelay * (2 ** $exponent), $this->maximumRetryDelay);
        $jitter = $delay * 0.1 * ((mt_rand() / mt_getrandmax()) * 2.0 - 1.0);

        return max(0.0, $delay + $jitter);
    }

    private static function queryId(mixed $value): string
    {
        if ($value instanceof Uuid) {
            return (string) $value;
        }

        if (is_array($value) && array_is_list($value) && count($value) === 1) {
            $value = $value[0];
        }

        if (!is_string($value) || $value === '') {
            throw new \RuntimeException('LIVE SELECT did not return a query UUID.');
        }

        return $value;
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

}

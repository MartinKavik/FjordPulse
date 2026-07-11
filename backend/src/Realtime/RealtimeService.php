<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

use Amp\DeferredCancellation;
use Amp\Future;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket\InternetAddress;
use Amp\Websocket\ConstantRateLimit;
use Amp\Websocket\Parser\Rfc6455ParserFactory;
use Amp\Websocket\Server\Rfc6455ClientFactory;
use Amp\Websocket\Server\Websocket;
use FjordPulse\Surreal\LiveQueryBridge;
use FjordPulse\Surreal\LiveQueryBridgeState;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;

use function Amp\async;
use function Amp\delay;
use function Amp\trapSignal;

final class RealtimeService
{
    private ?SocketHttpServer $httpServer = null;
    private ?DeferredCancellation $cancellation = null;

    /** @var Future<mixed>|null */
    private ?Future $bridgeFuture = null;

    /** @var list<string> */
    private array $watchers = [];

    private bool $running = false;
    private ?LiveQueryBridgeState $lastBridgeState = null;

    /** @var (\Closure(array<string, mixed>): void)|null */
    private readonly ?\Closure $statusSink;

    /**
     * @param (\Closure(array<string, mixed>): void)|null $statusSink
     */
    public function __construct(
        private readonly RealtimeServiceConfig $config,
        private readonly RoomRegistry $rooms,
        private readonly ProtocolRouter $router,
        private readonly RealtimeTelemetry $telemetry,
        private readonly WatchScheduler $scheduler,
        private readonly LiveQueryBridge $bridge,
        private readonly RealtimeEventSink $eventSink,
        private readonly TokenVerifier $tokens,
        private readonly LoggerInterface $logger,
        ?\Closure $statusSink = null,
    ) {
        $this->statusSink = $statusSink;
    }

    public function start(): void
    {
        if ($this->running) {
            throw new \LogicException('Realtime service is already running.');
        }
        $this->running = true;
        $this->cancellation = new DeferredCancellation();

        try {
            $server = SocketHttpServer::createForDirectAccess(
                $this->logger,
                enableCompression: false,
                connectionLimit: 1_000,
                connectionLimitPerIp: 20,
                concurrencyLimit: 1_000,
                allowedMethods: ['GET'],
            );
            $host = str_contains($this->config->host, ':')
                ? '[' . trim($this->config->host, '[]') . ']'
                : $this->config->host;
            $server->expose(InternetAddress::fromString($host . ':' . $this->config->port));
            $acceptor = new SecuredWebsocketAcceptor($this->config->allowedOrigins, $this->tokens);
            $clientFactory = new Rfc6455ClientFactory(
                rateLimit: new ConstantRateLimit(
                    bytesPerSecondLimit: max($this->config->maximumMessageBytes * 4, 262_144),
                    framesPerSecondLimit: max($this->config->messagesPerWindow * 2, 60),
                ),
                parserFactory: new Rfc6455ParserFactory(
                    textOnly: true,
                    validateUtf8: true,
                    messageSizeLimit: $this->config->maximumMessageBytes,
                    frameSizeLimit: $this->config->maximumMessageBytes,
                ),
            );
            $handler = new RealtimeClientHandler(
                $this->rooms,
                $this->router,
                $this->config->maximumMessageBytes,
                $this->config->messagesPerWindow,
                $this->config->rateWindowSeconds,
                $this->logger,
            );
            $websocket = new Websocket(
                httpServer: $server,
                logger: $this->logger,
                acceptor: $acceptor,
                clientHandler: $handler,
                clientFactory: $clientFactory,
            );
            $httpHandler = new RealtimeHttpHandler($websocket, $this->health(...));
            $server->start($httpHandler, new DefaultErrorHandler());
            $this->httpServer = $server;

            $cancellation = $this->cancellation->getCancellation();
            $this->bridgeFuture = async(function () use ($cancellation): void {
                $this->bridge->run(
                    $this->eventSink->publish(...),
                    function (): void {
                        $this->router->bridgeRecovered();
                    },
                    $cancellation,
                );
            });
            $this->bridgeFuture->catch(function (\Throwable $error): void {
                if ($this->running) {
                    $this->logger->error('Live-query bridge fiber terminated.', ['error' => $error->getMessage()]);
                    $this->router->bridgeDegraded('The database live-query bridge stopped unexpectedly.', 'offline');
                }
            })->ignore();

            $this->watchers[] = EventLoop::repeat($this->config->schedulerIntervalSeconds, function (): void {
                async($this->scheduler->tick(...))->catch(function (\Throwable $error): void {
                    $this->logger->error('Realtime watch scheduler tick failed.', ['error' => $error->getMessage()]);
                })->ignore();
            });
            $this->watchers[] = EventLoop::repeat(0.25, $this->monitorBridge(...));
            $this->watchers[] = EventLoop::repeat($this->config->telemetryIntervalSeconds, $this->publishTelemetry(...));
            $this->logger->info('FjordPulse realtime service started.', [
                'host' => $this->config->host,
                'port' => $this->config->port,
                'endpoint' => '/live',
                'replicas' => 1,
            ]);
        } catch (\Throwable $error) {
            $this->stop();
            throw $error;
        }
    }

    public function runUntilSignal(?string $shutdownFile = null): int
    {
        $this->start();
        if ($shutdownFile !== null) {
            while (!is_file($shutdownFile)) {
                delay(0.1);
            }
            @unlink($shutdownFile);
            $this->logger->info('Realtime shutdown file received.', ['shutdownFile' => $shutdownFile]);
            $this->stop();

            return 0;
        }
        $signal = trapSignal([SIGINT, SIGTERM]);
        $this->logger->info('Realtime shutdown signal received.', ['signal' => $signal]);
        $this->stop();

        return $signal;
    }

    public function stop(): void
    {
        if (!$this->running && $this->httpServer === null) {
            return;
        }
        $this->running = false;
        foreach ($this->watchers as $watcher) {
            EventLoop::cancel($watcher);
        }
        $this->watchers = [];
        $this->bridge->stop();
        $this->cancellation?->cancel();

        try {
            $this->httpServer?->stop();
        } catch (\Throwable $error) {
            $this->logger->warning('Realtime HTTP server shutdown failed.', ['error' => $error->getMessage()]);
        }
        try {
            $this->bridgeFuture?->await();
        } catch (\Throwable $error) {
            $this->logger->debug('Live-query bridge ended during shutdown.', ['error' => $error->getMessage()]);
        }
        $this->httpServer = null;
        $this->bridgeFuture = null;
        $this->cancellation = null;
        $this->logger->info('FjordPulse realtime service stopped.', ['replicas' => 0]);
    }

    public function running(): bool
    {
        return $this->running;
    }

    /** @return array<string, mixed> */
    public function health(): array
    {
        $bridge = $this->bridge->status();

        return [
            'status' => $this->running && $bridge->healthy() ? 'healthy' : ($this->running ? 'degraded' : 'stopped'),
            'checkedAt' => EnvelopeFactory::now(),
            'endpoint' => '/live',
            'replicas' => $this->running ? 1 : 0,
            'clients' => $this->telemetry->activeClients(),
            'rooms' => $this->rooms->roomCount(),
            'roomDetails' => $this->rooms->roomDetails(),
            'bridge' => $bridge->toArray(),
            'telemetry' => $this->telemetry->toArray(),
        ];
    }

    private function monitorBridge(): void
    {
        $status = $this->bridge->status();
        if ($status->state === $this->lastBridgeState) {
            return;
        }
        $this->lastBridgeState = $status->state;
        if (in_array($status->state, [
            LiveQueryBridgeState::Connecting,
            LiveQueryBridgeState::Reconnecting,
            LiveQueryBridgeState::Degraded,
            LiveQueryBridgeState::Stopped,
        ], true)) {
            $bridgeStatus = match ($status->state) {
                LiveQueryBridgeState::Connecting, LiveQueryBridgeState::Reconnecting => 'reconnecting',
                LiveQueryBridgeState::Degraded => 'degraded',
                default => 'offline',
            };
            $this->router->bridgeDegraded(
                $status->lastError ?? 'The database live-query bridge is not healthy.',
                $bridgeStatus,
            );
        }
        // Persist and publish every bridge transition immediately. Waiting for
        // the periodic telemetry interval made a healthy fresh process appear
        // degraded to HTTP clients during its first seconds.
        $this->publishTelemetry();
    }

    private function publishTelemetry(): void
    {
        $status = $this->bridge->status();
        $healthy = $status->healthy();
        $payload = [
            'backend' => $this->running ? ($healthy ? 'ok' : 'degraded') : 'offline',
            'realtime' => $this->running ? ($healthy ? 'connected' : 'reconnecting') : 'offline',
            'entur' => $this->telemetry->enturState(),
            'liveQueryBridge' => $healthy ? 'connected' : match ($status->state) {
                LiveQueryBridgeState::Connecting, LiveQueryBridgeState::Reconnecting => 'reconnecting',
                LiveQueryBridgeState::Degraded => 'degraded',
                default => 'offline',
            },
            'refreshMode' => $healthy ? 'realtime' : 'polling',
            'lastUpdateAt' => $status->lastEventAt?->format(\DateTimeInterface::RFC3339_EXTENDED),
        ];
        $this->router->telemetry($payload);
        if ($this->statusSink !== null) {
            try {
                ($this->statusSink)($this->health());
            } catch (\Throwable $error) {
                $this->logger->warning('Unable to persist realtime status.', ['error' => $error->getMessage()]);
            }
        }
    }
}

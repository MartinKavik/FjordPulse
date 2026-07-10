<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Spike;

use Cake\Core\Configure;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Auth\DatabaseAuth;
use SurrealDB\SDK\Connection\ConnectOptions;
use SurrealDB\SDK\Live\LiveAction;
use SurrealDB\SDK\Protocol\Features;
use SurrealDB\SDK\Reconnect\ExponentialBackoffReconnect;
use SurrealDB\SDK\Runtime\Runtime;
use SurrealDB\SDK\Scheduler\Amp\RevoltScheduler;
use SurrealDB\SDK\Scheduler\SyncScheduler;
use SurrealDB\SDK\Surreal;

#[CoversNothing]
final class DependencySurfaceTest extends TestCase
{
    public function testPinnedCakePhpSixRunsOnPhpEightFive(): void
    {
        self::assertSame('8.5.8', PHP_VERSION);
        self::assertSame('6.0.0-dev', Configure::version());
    }

    public function testDocumentedSurrealDbImportsExistInPinnedAlpha(): void
    {
        self::assertTrue(class_exists(Surreal::class));
        self::assertTrue(class_exists(ConnectOptions::class));
        self::assertTrue(class_exists(DatabaseAuth::class));
        self::assertTrue(class_exists(ExponentialBackoffReconnect::class));
        self::assertTrue(class_exists(Features::class));
        self::assertTrue(enum_exists(LiveAction::class));
    }

    public function testSyncRuntimeConstructsTheSdkWithSyncScheduler(): void
    {
        $options = Runtime::sync();

        self::assertInstanceOf(SyncScheduler::class, $options->scheduler);
        self::assertInstanceOf(Surreal::class, new Surreal($options));
    }

    public function testAmpRuntimeConstructsTheSdkWithNonBlockingTransports(): void
    {
        $options = Runtime::amp();

        self::assertInstanceOf(RevoltScheduler::class, $options->scheduler);
        self::assertNotNull($options->httpTransportFactory);
        self::assertNotNull($options->webSocketTransportFactory);
        self::assertInstanceOf(Surreal::class, new Surreal($options));
    }

    public function testDatabaseAuthReconnectFeatureAndLiveActionSurface(): void
    {
        $auth = new DatabaseAuth('fjordpulse', 'fjordpulse', 'fjordpulse', 'secret');
        $reconnect = new ExponentialBackoffReconnect(maxAttempts: -1);
        $options = new ConnectOptions(
            namespace: 'fjordpulse',
            database: 'fjordpulse',
            authentication: $auth,
            reconnect: $reconnect,
        );

        self::assertSame($auth, $options->authentication);
        self::assertSame($reconnect, $options->reconnect);
        self::assertSame('live-queries', Features::liveQueries()->name);
        self::assertSame('CREATE', LiveAction::Create->value);
    }
}

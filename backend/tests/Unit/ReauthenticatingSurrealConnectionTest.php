<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use FjordPulse\Surreal\ReauthenticatingSurrealConnection;
use FjordPulse\Surreal\SurrealConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Exceptions\HttpConnectionException;

final class ReauthenticatingSurrealConnectionTest extends TestCase
{
    public function testExpiredTokenReconnectsAndRetriesInterruptedQueryOnce(): void
    {
        $expired = $this->connection();
        $healthy = $this->connection();
        $failure = self::expiredTokenFailure();
        $expired->expects(self::once())
            ->method('run')
            ->with('RETURN $value;', ['value' => 42])
            ->willThrowException($failure);
        $expired->expects(self::once())->method('close');
        $healthy->expects(self::once())
            ->method('run')
            ->with('RETURN $value;', ['value' => 42])
            ->willReturn([[42]]);

        $connectCount = 0;
        $connection = new ReauthenticatingSurrealConnection(
            self::connector([$expired, $healthy], $connectCount),
        );

        self::assertSame([[42]], $connection->run('RETURN $value;', ['value' => 42]));
        self::assertSame(2, $connectCount);
    }

    public function testReplacementFailureIsNotRetriedAgain(): void
    {
        $first = $this->connection();
        $second = $this->connection();
        $first->method('run')->willThrowException(self::expiredTokenFailure());
        $first->expects(self::once())->method('close');
        $second->expects(self::once())->method('run')->willThrowException(self::expiredTokenFailure());

        $connectCount = 0;
        $connection = new ReauthenticatingSurrealConnection(
            self::connector([$first, $second], $connectCount),
        );

        try {
            $connection->run('RETURN true;');
            self::fail('The replacement failure must remain visible.');
        } catch (HttpConnectionException $error) {
            self::assertSame(401, $error->status);
        }
        self::assertSame(2, $connectCount, 'An operation may trigger only one reconnect and one retry.');
    }

    public function testUnrelatedUnauthorizedFailureDoesNotReconnect(): void
    {
        $failed = $this->connection();
        $failure = new HttpConnectionException('Not authorized', 401, 'Unauthorized', 'Not authorized');
        $failed->expects(self::once())->method('run')->willThrowException($failure);
        $failed->expects(self::never())->method('close');

        $connectCount = 0;
        $connection = new ReauthenticatingSurrealConnection(
            self::connector([$failed], $connectCount),
        );

        try {
            $connection->run('RETURN true;');
            self::fail('The unrelated authorization failure must remain visible.');
        } catch (HttpConnectionException $error) {
            self::assertSame($failure, $error);
        }
        self::assertSame(1, $connectCount);
    }

    /** @return SurrealConnection&MockObject */
    private function connection(): SurrealConnection
    {
        return $this->createMock(SurrealConnection::class);
    }

    /**
     * @param non-empty-list<SurrealConnection> $connections
     * @param int $connectCount
     * @return \Closure(): SurrealConnection
     */
    private static function connector(array $connections, int &$connectCount): \Closure
    {
        return static function () use ($connections, &$connectCount): SurrealConnection {
            $connection = $connections[$connectCount] ?? null;
            if (!$connection instanceof SurrealConnection) {
                throw new \RuntimeException('Unexpected extra connection attempt.');
            }
            $connectCount++;

            return $connection;
        };
    }

    private static function expiredTokenFailure(): HttpConnectionException
    {
        return new HttpConnectionException(
            'The token has expired',
            401,
            'Unauthorized',
            'The token has expired',
        );
    }
}

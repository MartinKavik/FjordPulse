<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use Amp\Future;
use SurrealDB\SDK\Exceptions\HttpConnectionException;
use SurrealDB\SDK\Protocol\Feature;

use function Amp\async;

/**
 * Keeps the long-running HTTP command connection usable after SurrealDB's
 * database-user JWT expires. surrealdb.php 2.0.0-alpha.1 throws HTTP 401
 * before its RPC auth middleware can renew the token, so recovery must replace
 * the authenticated delegate and retry the interrupted operation once.
 */
final class ReauthenticatingSurrealConnection implements SurrealConnection
{
    private SurrealConnection $delegate;

    /** @var Future<mixed>|null */
    private ?Future $recovery = null;

    private bool $closed = false;

    /**
     * @param \Closure(): SurrealConnection $connect
     */
    public function __construct(private readonly \Closure $connect)
    {
        $this->delegate = ($this->connect)();
    }

    public function run(string $surql, array $bindings = []): array
    {
        $failedDelegate = $this->delegate;

        try {
            return $failedDelegate->run($surql, $bindings);
        } catch (\Throwable $error) {
            if (!self::isExpiredTokenFailure($error)) {
                throw $error;
            }

            $this->recover($failedDelegate);

            // Exactly one retry. A bad replacement or revoked credentials must
            // remain visible to the caller instead of entering a retry loop.
            return $this->delegate->run($surql, $bindings);
        }
    }

    public function live(string $queryId): iterable
    {
        return $this->delegate->live($queryId);
    }

    public function subscribeLiveMessages(string $queryId, callable $listener): \Closure
    {
        return $this->delegate->subscribeLiveMessages($queryId, $listener);
    }

    public function supports(Feature $feature): bool
    {
        return $this->delegate->supports($feature);
    }

    public function subscribe(string $event, callable $listener): \Closure
    {
        return $this->delegate->subscribe($event, $listener);
    }

    public function isConnected(): bool
    {
        return !$this->closed && $this->delegate->isConnected();
    }

    public function version(): string
    {
        return $this->delegate->version();
    }

    public function health(): void
    {
        $this->delegate->health();
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->delegate->close();
    }

    private function recover(SurrealConnection $failedDelegate): void
    {
        if ($this->closed) {
            throw new \RuntimeException('Cannot recover a closed SurrealDB connection.');
        }

        if ($this->delegate !== $failedDelegate) {
            return;
        }

        $recovery = $this->recovery;
        if ($recovery === null) {
            $recovery = async(function () use ($failedDelegate): void {
                if ($this->delegate !== $failedDelegate) {
                    return;
                }

                $replacement = ($this->connect)();
                if ($this->closed) {
                    $replacement->close();
                    throw new \RuntimeException('SurrealDB connection closed during authentication recovery.');
                }

                $this->delegate = $replacement;
                try {
                    $failedDelegate->close();
                } catch (\Throwable) {
                    // The authenticated replacement is ready. Failure to close
                    // the already-invalid HTTP delegate must not block recovery.
                }
            });
            $this->recovery = $recovery;
        }

        try {
            $recovery->await();
        } finally {
            if ($this->recovery === $recovery) {
                $this->recovery = null;
            }
        }
    }

    private static function isExpiredTokenFailure(\Throwable $error): bool
    {
        do {
            if ($error instanceof HttpConnectionException && $error->status === 401) {
                $detail = strtolower($error->body . ' ' . $error->getMessage());
                if (str_contains($detail, 'token') && str_contains($detail, 'expired')) {
                    return true;
                }
            }
            $error = $error->getPrevious();
        } while ($error !== null);

        return false;
    }
}

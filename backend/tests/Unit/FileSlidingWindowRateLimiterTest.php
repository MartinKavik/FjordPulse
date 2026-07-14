<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use FjordPulse\Http\FileSlidingWindowRateLimiter;
use FjordPulse\Http\RateLimitDecision;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileSlidingWindowRateLimiter::class)]
#[CoversClass(RateLimitDecision::class)]
final class FileSlidingWindowRateLimiterTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/fjordpulse-rate-limit-store-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->directory);
        parent::tearDown();
    }

    public function testStateSurvivesLimiterAndApplicationRecreation(): void
    {
        $key = hash('sha256', 'same-client-and-route');
        $firstProcess = new FileSlidingWindowRateLimiter($this->directory);
        $secondProcess = new FileSlidingWindowRateLimiter($this->directory);

        self::assertTrue($firstProcess->consume($key, 1, 100.0)->allowed);
        $blocked = $secondProcess->consume($key, 1, 101.0);

        self::assertFalse($blocked->allowed);
        self::assertSame(59, $blocked->retryAfterSeconds);
    }

    public function testExpiredEntriesAndOtherBucketsDoNotConsumeTheBudget(): void
    {
        $first = hash('sha256', 'first-client');
        $second = hash('sha256', 'second-client');
        $limiter = new FileSlidingWindowRateLimiter($this->directory);

        self::assertTrue($limiter->consume($first, 1, 100.0)->allowed);
        self::assertTrue($limiter->consume($second, 1, 101.0)->allowed);
        self::assertTrue($limiter->consume($first, 1, 161.0)->allowed);
    }

    public function testInvalidKeysAreRejectedBeforeTouchingTheFilesystem(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SHA-256 HMAC');

        (new FileSlidingWindowRateLimiter($this->directory))->consume('../escape', 1, 100.0);
    }
}

<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use DateTimeImmutable;
use FjordPulse\Service\HostResourceDiagnostics;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HostResourceDiagnostics::class)]
final class HostResourceDiagnosticsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/fjordpulse-host-resources-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory . '/cgroup/app.slice', 0o700, true));
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function testCpuUsageUsesDifferencesBetweenProcStatSamples(): void
    {
        $first = "cpu 100 0 50 850 0 0 0 0 20 0\ncpu0 50 0 25 425 0 0 0 0 10 0\n";
        $second = "cpu 150 0 70 880 0 0 0 0 30 0\ncpu0 75 0 35 440 0 0 0 0 15 0\n";

        self::assertSame(70.0, HostResourceDiagnostics::usagePercentFromSamples($first, $second));
        self::assertNull(HostResourceDiagnostics::usagePercentFromSamples($first, $first));
        self::assertNull(HostResourceDiagnostics::usagePercentFromSamples('not proc stat', $second));
    }

    public function testSnapshotPrefersFiniteCgroupMemoryAndReportsLoadCpuAndDisk(): void
    {
        $this->write('proc-stat', "cpu 100 0 50 850 0 0 0 0\ncpu0 50 0 25 425 0 0 0 0\ncpu1 50 0 25 425 0 0 0 0\n");
        $this->write('proc-loadavg', "0.62 0.48 0.41 1/128 42\n");
        $this->write('proc-meminfo', "MemTotal:        2097152 kB\nMemAvailable:    1048576 kB\n");
        $this->write('proc-self-cgroup', "0::/app.slice\n");
        $this->write('cgroup/app.slice/memory.max', "1048576\n");
        $this->write('cgroup/app.slice/memory.current', "262144\n");

        $snapshot = $this->diagnostics()->snapshot();

        self::assertNotFalse(DateTimeImmutable::createFromFormat(DateTimeImmutable::RFC3339_EXTENDED, $snapshot['checkedAt']));
        self::assertSame([
            'usagePercent' => null,
            'load1' => 0.62,
            'load5' => 0.48,
            'load15' => 0.41,
            'logicalCores' => 2,
        ], $snapshot['cpu']);
        self::assertSame([
            'totalBytes' => 1_048_576,
            'availableBytes' => 786_432,
            'usedBytes' => 262_144,
            'usedPercent' => 25.0,
            'scope' => 'cgroup',
        ], $snapshot['memory']);
        self::assertSame(realpath($this->directory), $snapshot['disk']['path']);
        self::assertIsInt($snapshot['disk']['totalBytes']);
        self::assertIsInt($snapshot['disk']['freeBytes']);
        self::assertIsInt($snapshot['disk']['usedBytes']);
        self::assertIsFloat($snapshot['disk']['usedPercent']);
        self::assertSame(
            $snapshot['disk']['totalBytes'],
            $snapshot['disk']['freeBytes'] + $snapshot['disk']['usedBytes'],
        );
    }

    public function testSnapshotFallsBackToHostMeminfoWhenCgroupLimitIsUnlimited(): void
    {
        $this->write('proc-stat', "cpu 1 0 1 8 0 0 0 0\ncpu0 1 0 1 8 0 0 0 0\n");
        $this->write('proc-loadavg', "invalid\n");
        $this->write('proc-meminfo', "MemTotal:        2048 kB\nMemAvailable:     512 kB\n");
        $this->write('proc-self-cgroup', "0::/app.slice\n");
        $this->write('cgroup/app.slice/memory.max', "max\n");
        $this->write('cgroup/app.slice/memory.current', "262144\n");

        $snapshot = $this->diagnostics()->snapshot();

        self::assertSame([
            'totalBytes' => 2_097_152,
            'availableBytes' => 524_288,
            'usedBytes' => 1_572_864,
            'usedPercent' => 75.0,
            'scope' => 'host',
        ], $snapshot['memory']);
        self::assertNull($snapshot['cpu']['load1']);
        self::assertNull($snapshot['cpu']['load5']);
        self::assertNull($snapshot['cpu']['load15']);
    }

    public function testUnavailableMeasurementsRemainNullable(): void
    {
        $missing = $this->directory . '/missing';
        $snapshot = new HostResourceDiagnostics(
            $missing,
            $missing . '/stat',
            $missing . '/loadavg',
            $missing . '/meminfo',
            $missing . '/self-cgroup',
            $missing . '/cgroup',
            0,
        )->snapshot();

        self::assertSame([
            'usagePercent' => null,
            'load1' => null,
            'load5' => null,
            'load15' => null,
            'logicalCores' => null,
        ], $snapshot['cpu']);
        self::assertSame([
            'totalBytes' => null,
            'availableBytes' => null,
            'usedBytes' => null,
            'usedPercent' => null,
            'scope' => 'host',
        ], $snapshot['memory']);
        self::assertSame($missing, $snapshot['disk']['path']);
        self::assertNull($snapshot['disk']['totalBytes']);
        self::assertNull($snapshot['disk']['freeBytes']);
        self::assertNull($snapshot['disk']['usedBytes']);
        self::assertNull($snapshot['disk']['usedPercent']);
    }

    private function diagnostics(): HostResourceDiagnostics
    {
        return new HostResourceDiagnostics(
            $this->directory,
            $this->directory . '/proc-stat',
            $this->directory . '/proc-loadavg',
            $this->directory . '/proc-meminfo',
            $this->directory . '/proc-self-cgroup',
            $this->directory . '/cgroup',
            0,
        );
    }

    private function write(string $relativePath, string $contents): void
    {
        self::assertNotFalse(file_put_contents($this->directory . '/' . $relativePath, $contents));
    }
}

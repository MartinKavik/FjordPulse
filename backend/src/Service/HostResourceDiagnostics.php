<?php

declare(strict_types=1);

namespace FjordPulse\Service;

use DateTimeImmutable;
use DateTimeInterface;

final readonly class HostResourceDiagnostics
{
    private const int DEFAULT_CPU_SAMPLE_MICROSECONDS = 50_000;

    public function __construct(
        private string $applicationPath,
        private string $procStatPath = '/proc/stat',
        private string $procLoadavgPath = '/proc/loadavg',
        private string $procMeminfoPath = '/proc/meminfo',
        private string $procSelfCgroupPath = '/proc/self/cgroup',
        private string $cgroupRoot = '/sys/fs/cgroup',
        private int $cpuSampleMicroseconds = self::DEFAULT_CPU_SAMPLE_MICROSECONDS,
    ) {
    }

    /**
     * @return array{
     *   checkedAt: string,
     *   cpu: array{usagePercent: ?float, load1: ?float, load5: ?float, load15: ?float, logicalCores: ?int},
     *   memory: array{totalBytes: ?int, availableBytes: ?int, usedBytes: ?int, usedPercent: ?float, scope: 'host'|'cgroup'},
     *   disk: array{path: string, totalBytes: ?int, freeBytes: ?int, usedBytes: ?int, usedPercent: ?float}
     * }
     */
    public function snapshot(): array
    {
        $firstCpuSample = self::read($this->procStatPath);
        if ($firstCpuSample !== null && $this->cpuSampleMicroseconds > 0) {
            usleep($this->cpuSampleMicroseconds);
        }
        $secondCpuSample = self::read($this->procStatPath);
        [$load1, $load5, $load15] = $this->loadAverages();

        return [
            'checkedAt' => (new DateTimeImmutable())->format(DateTimeInterface::RFC3339_EXTENDED),
            'cpu' => [
                'usagePercent' => $firstCpuSample === null || $secondCpuSample === null
                    ? null
                    : self::usagePercentFromSamples($firstCpuSample, $secondCpuSample),
                'load1' => $load1,
                'load5' => $load5,
                'load15' => $load15,
                'logicalCores' => self::logicalCoreCount($secondCpuSample ?? $firstCpuSample),
            ],
            'memory' => $this->memory(),
            'disk' => $this->disk(),
        ];
    }

    public static function usagePercentFromSamples(string $first, string $second): ?float
    {
        $firstValues = self::aggregateCpuValues($first);
        $secondValues = self::aggregateCpuValues($second);
        if ($firstValues === null || $secondValues === null) {
            return null;
        }

        $totalDelta = $secondValues['total'] - $firstValues['total'];
        $idleDelta = $secondValues['idle'] - $firstValues['idle'];
        if ($totalDelta <= 0 || $idleDelta < 0) {
            return null;
        }

        $busyDelta = max(0, $totalDelta - $idleDelta);

        return round(min(100.0, ($busyDelta / $totalDelta) * 100), 1);
    }

    /** @return array{0: ?float, 1: ?float, 2: ?float} */
    private function loadAverages(): array
    {
        $contents = self::read($this->procLoadavgPath);
        if ($contents === null) {
            return [null, null, null];
        }
        $parts = preg_split('/\s+/', trim($contents));
        if ($parts === false || count($parts) < 3) {
            return [null, null, null];
        }

        return [
            self::nonNegativeFloat($parts[0]),
            self::nonNegativeFloat($parts[1]),
            self::nonNegativeFloat($parts[2]),
        ];
    }

    /**
     * @return array{totalBytes: ?int, availableBytes: ?int, usedBytes: ?int, usedPercent: ?float, scope: 'host'|'cgroup'}
     */
    private function memory(): array
    {
        $cgroupDirectory = $this->cgroupDirectory();
        if ($cgroupDirectory !== null) {
            $limit = self::positiveIntegerFile($cgroupDirectory . '/memory.max');
            $current = self::nonNegativeIntegerFile($cgroupDirectory . '/memory.current');
            if ($limit !== null && $current !== null) {
                $used = min($current, $limit);
                $available = $limit - $used;

                return [
                    'totalBytes' => $limit,
                    'availableBytes' => $available,
                    'usedBytes' => $used,
                    'usedPercent' => self::percentage($used, $limit),
                    'scope' => 'cgroup',
                ];
            }
        }

        $meminfo = self::read($this->procMeminfoPath);
        $values = $meminfo === null ? [] : self::meminfoValues($meminfo);
        $total = $values['MemTotal'] ?? null;
        $available = $values['MemAvailable'] ?? null;
        if ($total === null || $available === null) {
            return [
                'totalBytes' => null,
                'availableBytes' => null,
                'usedBytes' => null,
                'usedPercent' => null,
                'scope' => 'host',
            ];
        }

        $available = min($available, $total);
        $used = $total - $available;

        return [
            'totalBytes' => $total,
            'availableBytes' => $available,
            'usedBytes' => $used,
            'usedPercent' => self::percentage($used, $total),
            'scope' => 'host',
        ];
    }

    /**
     * @return array{path: string, totalBytes: ?int, freeBytes: ?int, usedBytes: ?int, usedPercent: ?float}
     */
    private function disk(): array
    {
        $path = realpath($this->applicationPath) ?: $this->applicationPath;
        $totalValue = @disk_total_space($path);
        $freeValue = @disk_free_space($path);
        $total = self::diskBytes($totalValue);
        $free = self::diskBytes($freeValue);
        if ($total === null || $free === null) {
            return [
                'path' => $path,
                'totalBytes' => null,
                'freeBytes' => null,
                'usedBytes' => null,
                'usedPercent' => null,
            ];
        }

        $free = min($free, $total);
        $used = $total - $free;

        return [
            'path' => $path,
            'totalBytes' => $total,
            'freeBytes' => $free,
            'usedBytes' => $used,
            'usedPercent' => self::percentage($used, $total),
        ];
    }

    private function cgroupDirectory(): ?string
    {
        $contents = self::read($this->procSelfCgroupPath);
        if ($contents === null) {
            return null;
        }
        foreach (preg_split('/\R/', trim($contents)) ?: [] as $line) {
            if (!str_starts_with($line, '0::')) {
                continue;
            }
            $relative = ltrim(substr($line, 3), '/');
            if (in_array('..', explode('/', $relative), true)) {
                return null;
            }

            return rtrim($this->cgroupRoot, '/') . ($relative === '' ? '' : '/' . $relative);
        }

        return null;
    }

    /** @return array{total: int, idle: int}|null */
    private static function aggregateCpuValues(string $sample): ?array
    {
        $line = preg_split('/\R/', trim($sample))[0] ?? null;
        if (!is_string($line)) {
            return null;
        }
        $parts = preg_split('/\s+/', trim($line));
        if ($parts === false || ($parts[0] ?? null) !== 'cpu' || count($parts) < 5) {
            return null;
        }

        $values = [];
        foreach (array_slice($parts, 1, 8) as $part) {
            $value = self::integerString($part);
            if ($value === null) {
                return null;
            }
            $values[] = $value;
        }
        if (count($values) < 4) {
            return null;
        }

        $total = array_sum($values);
        $idle = $values[3] + ($values[4] ?? 0);

        return ['total' => $total, 'idle' => $idle];
    }

    private static function logicalCoreCount(?string $sample): ?int
    {
        if ($sample === null) {
            return null;
        }
        $matches = [];
        $count = preg_match_all('/^cpu\d+\s/m', $sample, $matches);

        return is_int($count) && $count > 0 ? $count : null;
    }

    /** @return array<string, int> */
    private static function meminfoValues(string $contents): array
    {
        $matches = [];
        preg_match_all('/^([A-Za-z_()]+):\s+(\d+)\s+kB$/m', $contents, $matches, PREG_SET_ORDER);
        $values = [];
        foreach ($matches as $match) {
            $kilobytes = self::integerString($match[2]);
            if ($kilobytes === null || $kilobytes > intdiv(PHP_INT_MAX, 1024)) {
                continue;
            }
            $values[(string)$match[1]] = $kilobytes * 1024;
        }

        return $values;
    }

    private static function percentage(int $used, int $total): ?float
    {
        return $total > 0 ? round(($used / $total) * 100, 1) : null;
    }

    private static function nonNegativeFloat(mixed $value): ?float
    {
        if (!is_string($value) || !is_numeric($value)) {
            return null;
        }
        $number = (float)$value;

        return is_finite($number) && $number >= 0 ? $number : null;
    }

    private static function positiveIntegerFile(string $path): ?int
    {
        $value = self::integerString(self::read($path));

        return $value !== null && $value > 0 ? $value : null;
    }

    private static function nonNegativeIntegerFile(string $path): ?int
    {
        return self::integerString(self::read($path));
    }

    private static function integerString(mixed $value): ?int
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }
        $normalized = ltrim($value, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        $maximum = (string)PHP_INT_MAX;
        if (strlen($normalized) > strlen($maximum) || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0)) {
            return null;
        }

        return (int)$normalized;
    }

    private static function diskBytes(float|false $value): ?int
    {
        if ($value === false || !is_finite($value) || $value < 0 || $value > PHP_INT_MAX) {
            return null;
        }

        return (int)round($value);
    }

    private static function read(string $path): ?string
    {
        $contents = @file_get_contents($path);

        return is_string($contents) ? $contents : null;
    }
}

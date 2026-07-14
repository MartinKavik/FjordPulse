<?php

declare(strict_types=1);

namespace FjordPulse\Http;

final readonly class IpAddress
{
    private function __construct(
        public string $value,
        private string $packed,
    ) {
    }

    public static function parse(string $value): ?self
    {
        $value = trim($value);
        if ($value === '' || str_contains($value, '%')) {
            return null;
        }

        $packed = @inet_pton($value);
        if (!is_string($packed)) {
            return null;
        }

        $mappedPrefix = str_repeat("\0", 10) . "\xff\xff";
        if (strlen($packed) === 16 && str_starts_with($packed, $mappedPrefix)) {
            $packed = substr($packed, 12);
        }

        $normalized = inet_ntop($packed);
        if (!is_string($normalized)) {
            return null;
        }

        return new self(strtolower($normalized), $packed);
    }

    public function bitLength(): int
    {
        return strlen($this->packed) * 8;
    }

    public function matchesPrefix(self $network, int $prefixLength): bool
    {
        if ($this->bitLength() !== $network->bitLength() || $prefixLength < 0 || $prefixLength > $this->bitLength()) {
            return false;
        }

        $wholeBytes = intdiv($prefixLength, 8);
        if ($wholeBytes > 0 && substr($this->packed, 0, $wholeBytes) !== substr($network->packed, 0, $wholeBytes)) {
            return false;
        }

        $remainingBits = $prefixLength % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remainingBits)) & 0xff;

        return (ord($this->packed[$wholeBytes]) & $mask) === (ord($network->packed[$wholeBytes]) & $mask);
    }
}

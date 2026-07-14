<?php

declare(strict_types=1);

namespace FjordPulse\Config;

use FjordPulse\Http\IpAddress;
use InvalidArgumentException;

final readonly class TrustedProxyConfig
{
    /**
     * @param list<array{network: IpAddress, prefixLength: int}> $networks
     */
    private function __construct(private array $networks)
    {
    }

    public static function fromCommaSeparated(string $value): self
    {
        $value = trim($value);
        if ($value === '') {
            return new self([]);
        }

        $networks = [];
        foreach (explode(',', $value) as $entry) {
            $entry = trim($entry);
            if ($entry === '' || substr_count($entry, '/') > 1) {
                throw self::invalid($entry);
            }

            $parts = explode('/', $entry, 2);
            $addressValue = $parts[0];
            $prefixValue = $parts[1] ?? null;
            $address = IpAddress::parse($addressValue);
            if ($address === null) {
                throw self::invalid($entry);
            }

            $prefixLength = $address->bitLength();
            if ($prefixValue !== null) {
                if ($prefixValue === '' || !ctype_digit($prefixValue)) {
                    throw self::invalid($entry);
                }
                $prefixLength = (int)$prefixValue;
            }
            if ($prefixLength > $address->bitLength()) {
                throw self::invalid($entry);
            }

            $networks[] = ['network' => $address, 'prefixLength' => $prefixLength];
        }

        return new self($networks);
    }

    public function isTrusted(IpAddress $address): bool
    {
        foreach ($this->networks as $network) {
            if ($address->matchesPrefix($network['network'], $network['prefixLength'])) {
                return true;
            }
        }

        return false;
    }

    public function isEmpty(): bool
    {
        return $this->networks === [];
    }

    private static function invalid(string $entry): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            'TRUSTED_PROXIES contains an invalid IP address or CIDR: %s',
            $entry === '' ? '<empty>' : $entry,
        ));
    }
}

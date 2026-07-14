<?php

declare(strict_types=1);

namespace FjordPulse\Http;

use FjordPulse\Config\TrustedProxyConfig;
use Psr\Http\Message\ServerRequestInterface;

final readonly class ClientAddressResolver
{
    private const MAX_HEADER_BYTES = 8_192;
    private const MAX_CHAIN_LENGTH = 64;

    public function __construct(private TrustedProxyConfig $trustedProxies)
    {
    }

    public function resolve(ServerRequestInterface $request): string
    {
        $server = $request->getServerParams();
        $remoteValue = $server['REMOTE_ADDR'] ?? null;
        $remote = is_string($remoteValue) ? IpAddress::parse($remoteValue) : null;
        if ($remote === null) {
            return 'unknown';
        }

        if (!$this->trustedProxies->isTrusted($remote)) {
            return $remote->value;
        }

        $forwarded = $this->forwardedChain($request, $remote);
        if ($forwarded === null || $forwarded === []) {
            return $remote->value;
        }

        return $this->selectClient($forwarded, $remote)->value;
    }

    /** @param list<IpAddress> $forwarded */
    private function selectClient(array $forwarded, IpAddress $remote): IpAddress
    {
        $chain = [...$forwarded, $remote];
        for ($index = count($chain) - 1; $index >= 0; --$index) {
            if (!$this->trustedProxies->isTrusted($chain[$index])) {
                return $chain[$index];
            }
        }

        return $forwarded[0];
    }

    /** @return list<IpAddress>|null */
    private function forwardedChain(ServerRequestInterface $request, IpAddress $remote): ?array
    {
        $hasForwarded = $request->hasHeader('Forwarded');
        $hasXForwardedFor = $request->hasHeader('X-Forwarded-For');
        if ($hasForwarded) {
            $forwarded = self::parseForwarded($request->getHeaderLine('Forwarded'));
            if ($forwarded === null) {
                return null;
            }
            if ($hasXForwardedFor) {
                $xForwardedFor = self::parseXForwardedFor($request->getHeaderLine('X-Forwarded-For'));
                if (
                    $xForwardedFor === null
                    || $this->selectClient($forwarded, $remote)->value !== $this->selectClient($xForwardedFor, $remote)->value
                ) {
                    return null;
                }
            }

            return $forwarded;
        }
        if ($hasXForwardedFor) {
            return self::parseXForwardedFor($request->getHeaderLine('X-Forwarded-For'));
        }

        return [];
    }

    /** @return list<IpAddress>|null */
    private static function parseForwarded(string $header): ?array
    {
        if (!self::validHeaderLength($header)) {
            return null;
        }
        $elements = self::splitOutsideQuotes($header, ',');
        if ($elements === null || $elements === [] || count($elements) > self::MAX_CHAIN_LENGTH) {
            return null;
        }

        $addresses = [];
        foreach ($elements as $element) {
            $parameters = self::splitOutsideQuotes($element, ';');
            if ($parameters === null || $parameters === []) {
                return null;
            }
            $address = null;
            foreach ($parameters as $parameter) {
                $parts = explode('=', $parameter, 2);
                if (count($parts) !== 2 || trim($parts[0]) === '' || trim($parts[1]) === '') {
                    return null;
                }
                if (strtolower(trim($parts[0])) !== 'for') {
                    continue;
                }
                if ($address !== null) {
                    return null;
                }
                $value = self::unquote(trim($parts[1]));
                if ($value === null) {
                    return null;
                }
                $address = self::parseAddressEndpoint($value);
                if ($address === null) {
                    return null;
                }
            }
            if ($address === null) {
                return null;
            }
            $addresses[] = $address;
        }

        return $addresses;
    }

    /** @return list<IpAddress>|null */
    private static function parseXForwardedFor(string $header): ?array
    {
        if (!self::validHeaderLength($header)) {
            return null;
        }
        $values = explode(',', $header);
        if (count($values) > self::MAX_CHAIN_LENGTH) {
            return null;
        }

        $addresses = [];
        foreach ($values as $value) {
            $address = self::parseAddressEndpoint(trim($value));
            if ($address === null) {
                return null;
            }
            $addresses[] = $address;
        }

        return $addresses;
    }

    private static function parseAddressEndpoint(string $value): ?IpAddress
    {
        if ($value === '' || str_starts_with(strtolower($value), 'unknown') || str_starts_with($value, '_')) {
            return null;
        }

        if (str_starts_with($value, '[')) {
            if (preg_match('/\A\[([^\]]+)](?::([0-9]{1,5}))?\z/D', $value, $matches) !== 1) {
                return null;
            }
            if (isset($matches[2]) && !self::validPort($matches[2])) {
                return null;
            }

            return IpAddress::parse($matches[1]);
        }

        $address = IpAddress::parse($value);
        if ($address !== null) {
            return $address;
        }

        if (preg_match('/\A(.+):([0-9]{1,5})\z/D', $value, $matches) !== 1 || !self::validPort($matches[2])) {
            return null;
        }
        $address = IpAddress::parse($matches[1]);

        return $address !== null && $address->bitLength() === 32 ? $address : null;
    }

    private static function validPort(string $value): bool
    {
        $port = (int)$value;

        return $port >= 1 && $port <= 65_535;
    }

    private static function validHeaderLength(string $header): bool
    {
        return $header !== '' && strlen($header) <= self::MAX_HEADER_BYTES;
    }

    /** @return list<string>|null */
    private static function splitOutsideQuotes(string $value, string $delimiter): ?array
    {
        $parts = [];
        $current = '';
        $quoted = false;
        $escaped = false;
        $length = strlen($value);
        for ($index = 0; $index < $length; ++$index) {
            $character = $value[$index];
            $ordinal = ord($character);
            if ($ordinal === 0 || $character === "\r" || $character === "\n") {
                return null;
            }
            if ($escaped) {
                $current .= $character;
                $escaped = false;
                continue;
            }
            if ($quoted && $character === '\\') {
                $current .= $character;
                $escaped = true;
                continue;
            }
            if ($character === '"') {
                $quoted = !$quoted;
                $current .= $character;
                continue;
            }
            if (!$quoted && $character === $delimiter) {
                $part = trim($current);
                if ($part === '') {
                    return null;
                }
                $parts[] = $part;
                $current = '';
                continue;
            }
            $current .= $character;
        }
        if ($quoted || $escaped) {
            return null;
        }
        $part = trim($current);
        if ($part === '') {
            return null;
        }
        $parts[] = $part;

        return $parts;
    }

    private static function unquote(string $value): ?string
    {
        if (!str_starts_with($value, '"')) {
            return str_contains($value, '"') ? null : $value;
        }
        if (strlen($value) < 2 || !str_ends_with($value, '"')) {
            return null;
        }

        $result = '';
        $escaped = false;
        $inner = substr($value, 1, -1);
        $length = strlen($inner);
        for ($index = 0; $index < $length; ++$index) {
            $character = $inner[$index];
            if ($escaped) {
                $result .= $character;
                $escaped = false;
                continue;
            }
            if ($character === '\\') {
                $escaped = true;
                continue;
            }
            if ($character === '"' || ord($character) < 0x20 || ord($character) === 0x7f) {
                return null;
            }
            $result .= $character;
        }

        return $escaped ? null : $result;
    }
}

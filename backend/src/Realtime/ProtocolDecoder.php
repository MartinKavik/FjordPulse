<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

use DateTimeImmutable;
use JsonException;

final readonly class ProtocolDecoder
{
    public const int PROTOCOL_VERSION = 1;

    public function __construct(public int $maximumBytes = 65_536)
    {
        if ($maximumBytes < 256) {
            throw new \InvalidArgumentException('Maximum message size must be at least 256 bytes.');
        }
    }

    public function decode(string $raw): ClientMessage
    {
        if (strlen($raw) > $this->maximumBytes) {
            throw new ProtocolException('message_too_large', 'Message exceeds the allowed size.', [
                'maximumBytes' => $this->maximumBytes,
            ]);
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ProtocolException('invalid_json', 'Message must contain valid JSON.');
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ProtocolException('invalid_message', 'Message root must be an object.');
        }

        $idValue = $decoded['id'] ?? null;
        $messageId = is_string($idValue) && self::validMessageId($idValue) ? $idValue : null;
        $this->assertOnlyKeys($decoded, ['protocolVersion', 'id', 'type', 'payload'], $messageId, 'message');

        if (($decoded['protocolVersion'] ?? null) !== self::PROTOCOL_VERSION) {
            throw new ProtocolException(
                'unsupported_protocol_version',
                'Only realtime protocol version 1 is supported.',
                ['supportedVersions' => [self::PROTOCOL_VERSION]],
                $messageId,
            );
        }
        if ($messageId === null) {
            throw new ProtocolException(
                'invalid_message',
                'Message id is invalid.',
                ['field' => 'id'],
                $messageId,
            );
        }
        if (!is_string($decoded['type'] ?? null)) {
            throw new ProtocolException('invalid_message', 'Message type is required.', ['field' => 'type'], $messageId);
        }

        $type = ClientMessageType::tryFrom($decoded['type']);
        if ($type === null) {
            throw new ProtocolException(
                'unknown_message_type',
                'Message type is not supported.',
                ['type' => $decoded['type']],
                $messageId,
            );
        }

        $payload = $decoded['payload'] ?? null;
        if (!is_array($payload) || array_is_list($payload)) {
            throw new ProtocolException('invalid_message', 'Message payload must be an object.', ['field' => 'payload'], $messageId);
        }

        return new ClientMessage($messageId, $type, $this->validatePayload($type, $payload, $messageId));
    }

    /**
     * @param array<mixed> $payload
     * @return array<string, string>
     */
    private function validatePayload(ClientMessageType $type, array $payload, string $messageId): array
    {
        $allowed = match ($type) {
            ClientMessageType::WatchStation, ClientMessageType::WatchVehicle, ClientMessageType::FocusVehicle => [
                $type === ClientMessageType::WatchStation ? 'stationId' : 'vehicleId',
                'knownVersion',
                'lastEventId',
            ],
            ClientMessageType::UnwatchStation => ['stationId'],
            ClientMessageType::UnwatchVehicle,
            ClientMessageType::UnfocusVehicle,
            ClientMessageType::PauseFocus,
            ClientMessageType::ResumeFocus => ['vehicleId'],
            ClientMessageType::Ping => ['sentAt'],
        };
        $this->assertOnlyKeys($payload, $allowed, $messageId, 'payload');

        $required = match ($type) {
            ClientMessageType::WatchStation, ClientMessageType::UnwatchStation => 'stationId',
            ClientMessageType::WatchVehicle,
            ClientMessageType::UnwatchVehicle,
            ClientMessageType::FocusVehicle,
            ClientMessageType::UnfocusVehicle,
            ClientMessageType::PauseFocus,
            ClientMessageType::ResumeFocus => 'vehicleId',
            ClientMessageType::Ping => null,
        };

        if ($required !== null && !is_string($payload[$required] ?? null)) {
            throw new ProtocolException(
                'invalid_message',
                'Message payload is invalid.',
                ['field' => 'payload.' . $required],
                $messageId,
            );
        }
        if ($required === 'stationId' && preg_match('/^NSR:StopPlace:[A-Za-z0-9_-]+$/D', $payload[$required]) !== 1) {
            throw new ProtocolException('invalid_message', 'Station identifier is invalid.', ['field' => 'payload.stationId'], $messageId);
        }
        if ($required === 'vehicleId' && preg_match('/^[A-Za-z0-9][A-Za-z0-9:._-]{0,199}$/D', $payload[$required]) !== 1) {
            throw new ProtocolException('invalid_message', 'Vehicle identifier is invalid.', ['field' => 'payload.vehicleId'], $messageId);
        }

        foreach (['knownVersion', 'sentAt'] as $dateField) {
            if (!array_key_exists($dateField, $payload)) {
                continue;
            }
            if (!is_string($payload[$dateField]) || !self::isRfc3339($payload[$dateField])) {
                throw new ProtocolException(
                    'invalid_message',
                    'Timestamp must be UTC/RFC3339.',
                    ['field' => 'payload.' . $dateField],
                    $messageId,
                );
            }
        }
        if (array_key_exists('lastEventId', $payload)
            && (!is_string($payload['lastEventId']) || strlen($payload['lastEventId']) < 1 || strlen($payload['lastEventId']) > 200)) {
            throw new ProtocolException('invalid_message', 'Last event id is invalid.', ['field' => 'payload.lastEventId'], $messageId);
        }

        $normalized = [];
        foreach ($payload as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new ProtocolException(
                    'invalid_message',
                    'Message payload contains an invalid value.',
                    ['field' => 'payload'],
                    $messageId,
                );
            }
            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * @param array<mixed> $value
     * @param list<string> $allowed
     */
    private function assertOnlyKeys(array $value, array $allowed, ?string $messageId, string $field): void
    {
        foreach (array_keys($value) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new ProtocolException(
                    'invalid_message',
                    'Message contains an unknown property.',
                    ['field' => $field . '.' . (is_string($key) ? $key : '?')],
                    $messageId,
                );
            }
        }
    }

    private static function validMessageId(mixed $value): bool
    {
        return is_string($value)
            && strlen($value) <= 128
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $value) === 1;
    }

    private static function isRfc3339(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
            return false;
        }
        try {
            new DateTimeImmutable($value);
        } catch (\Exception) {
            return false;
        }

        return true;
    }
}

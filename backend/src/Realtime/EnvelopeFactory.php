<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use FjordPulse\Dto\RealtimeEvent;
use JsonException;

final class EnvelopeFactory
{
    /** @return array<string, mixed> */
    public static function error(ProtocolException $error): array
    {
        $message = [
            'protocolVersion' => ProtocolDecoder::PROTOCOL_VERSION,
            'type' => 'error',
            'createdAt' => self::now(),
            'error' => [
                'code' => $error->errorCode,
                'message' => $error->getMessage(),
                'details' => $error->details,
            ],
        ];
        if ($error->messageId !== null) {
            $message['id'] = $error->messageId;
        }

        return $message;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function acknowledgement(
        string $id,
        string $type,
        string $scope,
        string $entityId,
        array $payload,
    ): array {
        return [
            'protocolVersion' => ProtocolDecoder::PROTOCOL_VERSION,
            'id' => $id,
            'type' => $type,
            'scope' => $scope,
            'entityId' => $entityId,
            'createdAt' => self::now(),
            'payload' => $payload,
        ];
    }

    /** @return array<string, mixed> */
    public static function pong(ClientMessage $message): array
    {
        return [
            'protocolVersion' => ProtocolDecoder::PROTOCOL_VERSION,
            'id' => $message->id,
            'type' => 'pong',
            'createdAt' => self::now(),
            'payload' => [
                'serverTime' => self::now(),
                'echoedSentAt' => $message->payload['sentAt'] ?? null,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function snapshot(string $type, string $scope, string $entityId, string $version, array $payload): array
    {
        return [
            'protocolVersion' => ProtocolDecoder::PROTOCOL_VERSION,
            'type' => $type,
            'scope' => $scope,
            'entityId' => $entityId,
            'version' => $version,
            'createdAt' => self::now(),
            'payload' => $payload,
        ];
    }

    /** @return array<string, mixed> */
    public static function persistentEvent(RealtimeEvent $event): array
    {
        return $event->toArray();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function notification(string $type, array $payload, ?string $scope = null): array
    {
        $message = [
            'protocolVersion' => ProtocolDecoder::PROTOCOL_VERSION,
            'type' => $type,
            'createdAt' => self::now(),
            'payload' => $payload,
        ];
        if ($scope !== null) {
            $message['scope'] = $scope;
        }

        return $message;
    }

    /** @param array<string, mixed> $message */
    public static function encode(array $message): string
    {
        try {
            return json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $error) {
            throw new \RuntimeException('Unable to encode realtime message.', previous: $error);
        }
    }

    public static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::RFC3339_EXTENDED);
    }
}

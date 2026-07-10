<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use FjordPulse\Realtime\ClientMessageType;
use FjordPulse\Realtime\EnvelopeFactory;
use FjordPulse\Realtime\ProtocolDecoder;
use FjordPulse\Realtime\ProtocolException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RealtimeProtocolTest extends TestCase
{
    public function testEveryCanonicalClientFixtureDecodesStrictly(): void
    {
        $fixtures = self::fixture('client-messages.json');
        self::assertIsList($fixtures);
        $decoder = new ProtocolDecoder();
        $types = [];
        foreach ($fixtures as $fixture) {
            self::assertIsArray($fixture);
            $message = $decoder->decode(json_encode($fixture, JSON_THROW_ON_ERROR));
            $types[] = $message->type;
            self::assertSame($fixture['id'], $message->id);
        }

        self::assertEqualsCanonicalizing(ClientMessageType::cases(), $types);
    }

    /** @param array<mixed> $message */
    #[DataProvider('invalidMessages')]
    public function testInvalidCanonicalFixturesAreRejected(array $message): void
    {
        $this->expectException(ProtocolException::class);
        (new ProtocolDecoder())->decode(json_encode($message, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function invalidMessages(): iterable
    {
        foreach (self::fixture('invalid-client-messages.json') as $index => $case) {
            self::assertIsArray($case);
            self::assertIsArray($case['message'] ?? null);
            $reason = $case['reason'] ?? null;
            yield is_string($reason) ? $reason : 'invalid-' . $index => [$case['message']];
        }
    }

    public function testMalformedAndOversizedMessagesAreCategorized(): void
    {
        $decoder = new ProtocolDecoder(256);
        try {
            $decoder->decode('{not json');
            self::fail('Malformed JSON should fail.');
        } catch (ProtocolException $error) {
            self::assertSame('invalid_json', $error->errorCode);
        }

        try {
            $decoder->decode(str_repeat('x', 257));
            self::fail('Oversized message should fail.');
        } catch (ProtocolException $error) {
            self::assertSame('message_too_large', $error->errorCode);
            self::assertSame(256, $error->details['maximumBytes']);
        }
    }

    public function testStructuredErrorPreservesValidCorrelationId(): void
    {
        try {
            (new ProtocolDecoder())->decode('{"protocolVersion":2,"id":"msg_version","type":"ping","payload":{}}');
            self::fail('Unsupported version should fail.');
        } catch (ProtocolException $error) {
            $envelope = EnvelopeFactory::error($error);
            self::assertSame('msg_version', $envelope['id']);
            $details = $envelope['error'] ?? null;
            self::assertIsArray($details);
            self::assertSame('unsupported_protocol_version', $details['code']);
            self::assertSame(1, $envelope['protocolVersion']);
        }
    }

    /** @return array<mixed> */
    private static function fixture(string $name): array
    {
        $path = dirname(__DIR__, 3) . '/contracts/fixtures/realtime/' . $name;
        $decoded = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}

<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use FjordPulse\Validation\InputValidator;
use FjordPulse\Validation\ValidationFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InputValidatorTest extends TestCase
{
    public function testValidIdentifiersAndViewportInputs(): void
    {
        self::assertSame('NSR:StopPlace:36025', InputValidator::stationId('NSR:StopPlace:36025'));
        self::assertSame('SKY:Vehicle:123.abc-1', InputValidator::vehicleId('SKY:Vehicle:123.abc-1'));
        self::assertSame('Førde', InputValidator::query(' Førde '));
        self::assertSame(20, InputValidator::limit(null));
        self::assertSame(7.5, InputValidator::zoom('7.5'));

        $bbox = InputValidator::boundingBox('4.5,58.0,8.5,63.0');
        self::assertSame(4.5, $bbox->minLongitude);
        self::assertSame(63.0, $bbox->maxLatitude);
    }

    /** @return iterable<string, array{callable(): mixed}> */
    public static function invalidInputs(): iterable
    {
        yield 'station id' => [static fn(): string => InputValidator::stationId('station:1')];
        yield 'vehicle id' => [static fn(): string => InputValidator::vehicleId('../vehicle')];
        yield 'empty search' => [static fn(): string => InputValidator::query('  ')];
        yield 'large limit' => [static fn(): int => InputValidator::limit('51')];
        yield 'zoom' => [static fn(): float => InputValidator::zoom(25)];
        yield 'bbox shape' => [static fn() => InputValidator::boundingBox('1,2,3')];
        yield 'bbox order' => [static fn() => InputValidator::boundingBox('8,60,4,61')];
    }

    /** @param callable(): mixed $call */
    #[DataProvider('invalidInputs')]
    public function testInvalidInputsReturnStructuredValidationFailure(callable $call): void
    {
        $this->expectException(ValidationFailure::class);
        $call();
    }
}

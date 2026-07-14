<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use FjordPulse\Domain\EnturService;
use FjordPulse\Entur\EnturApiClient;
use FjordPulse\Entur\Http\TransportInterface;
use FjordPulse\Entur\Http\TransportResponse;
use FjordPulse\Entur\Mapper\JourneyPlannerMapper;
use FjordPulse\Entur\NullEnturRequestObserver;
use FjordPulse\Entur\Real\RealJourneyPlanner;
use FjordPulse\Entur\RequestBudget;
use PHPUnit\Framework\TestCase;

final class RealJourneyPlannerTest extends TestCase
{
    public function testCompactBoardRunsThroughOsloDayEndAndUsesSentinelRow(): void
    {
        $transport = new JourneyPlannerTransport(21);
        $planner = self::planner($transport);
        $now = new DateTimeImmutable('2026-07-14T20:00:00+02:00');

        $board = $planner->stationBoard('NSR:StopPlace:1', $now, 20);

        self::assertCount(20, $board->departures);
        self::assertTrue($board->departureHasMore);
        self::assertSame(20, $board->departureLimit);
        self::assertSame('2026-07-14T20:00:00+02:00', $board->departureWindowStartedAt?->format(DATE_RFC3339));
        self::assertSame('2026-07-15T00:00:00+02:00', $board->departureWindowEndsAt?->format(DATE_RFC3339));
        self::assertSame(21, $transport->lastVariables['limit'] ?? null);
        self::assertSame(14_400, $transport->lastVariables['departureTimeRange'] ?? null);
        self::assertSame('2026-07-14T20:00:00+02:00', $transport->lastVariables['departureWindowStart'] ?? null);
        self::assertStringContainsString('includeCancelledTrips: true', $transport->lastQuery ?? '');
    }

    public function testDailyTimetableUsesTheFullOsloCalendarDayAcrossDst(): void
    {
        $transport = new JourneyPlannerTransport(2);
        $planner = self::planner($transport);
        $serviceDay = new DateTimeImmutable('2026-03-29', new DateTimeZone('Europe/Oslo'));

        $timetable = $planner->dailyTimetable('NSR:StopPlace:1', $serviceDay);

        self::assertTrue($timetable->complete);
        self::assertCount(2, $timetable->departures);
        self::assertSame('Europe/Oslo', $timetable->timeZone);
        self::assertSame('2026-03-29T00:00:00+01:00', $timetable->windowStart->format(DATE_RFC3339));
        self::assertSame('2026-03-30T00:00:00+02:00', $timetable->windowEnd->format(DATE_RFC3339));
        self::assertSame(82_800, $transport->lastVariables['timeRange'] ?? null);
        self::assertSame(1_000, $transport->lastVariables['limit'] ?? null);
        self::assertStringContainsString('includeCancelledTrips: true', $transport->lastQuery ?? '');
    }

    public function testDailyTimetableUsesTheFullTwentyFiveHourOsloFallBackDay(): void
    {
        $transport = new JourneyPlannerTransport(2);
        $planner = self::planner($transport);
        $serviceDay = new DateTimeImmutable('2026-10-25', new DateTimeZone('Europe/Oslo'));

        $timetable = $planner->dailyTimetable('NSR:StopPlace:1', $serviceDay);

        self::assertTrue($timetable->complete);
        self::assertSame('2026-10-25T00:00:00+02:00', $timetable->windowStart->format(DATE_RFC3339));
        self::assertSame('2026-10-26T00:00:00+01:00', $timetable->windowEnd->format(DATE_RFC3339));
        self::assertSame(90_000, $transport->lastVariables['timeRange'] ?? null);
    }

    public function testDailyTimetableSplitsWhenRawRowsHitCapBeforeWindowFiltering(): void
    {
        $transport = new JourneyPlannerTransport(1_000, saturatedFirstRequestOnly: true);
        $planner = self::planner($transport);

        $timetable = $planner->dailyTimetable(
            'NSR:StopPlace:1',
            new DateTimeImmutable('2026-07-14', new DateTimeZone('Europe/Oslo')),
        );

        self::assertSame(3, $transport->requests);
        self::assertTrue($timetable->complete);
        self::assertNotEmpty($timetable->departures);
    }

    private static function planner(JourneyPlannerTransport $transport): RealJourneyPlanner
    {
        $limits = array_fill_keys(
            array_map(static fn(EnturService $service): string => $service->value, EnturService::cases()),
            100,
        );

        return new RealJourneyPlanner(
            new EnturApiClient(
                $transport,
                new RequestBudget(100, $limits),
                new NullEnturRequestObserver(),
                'martinkavik-fjordpulse',
            ),
            new JourneyPlannerMapper(),
        );
    }
}

final class JourneyPlannerTransport implements TransportInterface
{
    /** @var array<string, mixed> */
    public array $lastVariables = [];
    public ?string $lastQuery = null;
    public int $requests = 0;

    public function __construct(
        private readonly int $departureCount,
        private readonly bool $saturatedFirstRequestOnly = false,
    )
    {
    }

    public function request(string $method, string $url, array $headers, ?array $json = null): TransportResponse
    {
        unset($method, $url, $headers);
        $this->requests++;
        $this->lastQuery = is_string($json['query'] ?? null) ? $json['query'] : null;
        $this->lastVariables = [];
        $variables = $json['variables'] ?? null;
        if (is_array($variables)) {
            foreach ($variables as $key => $value) {
                if (is_string($key)) {
                    $this->lastVariables[$key] = $value;
                }
            }
        }
        $rawStart = (
            $this->lastVariables['departureWindowStart']
            ?? $this->lastVariables['startTime']
            ?? '2026-07-14T20:00:00+02:00'
        );
        if (!is_string($rawStart)) {
            throw new \LogicException('Journey Planner test start variable must be a string.');
        }
        $start = new DateTimeImmutable($rawStart);
        $calls = [];
        $departureCount = $this->saturatedFirstRequestOnly && $this->requests > 1
            ? 2
            : $this->departureCount;
        for ($index = 0; $index < $departureCount; $index++) {
            $at = $this->saturatedFirstRequestOnly
                && $this->requests === 1
                && $index === $departureCount - 1
                && is_int($this->lastVariables['timeRange'] ?? null)
                    ? $start->modify('+' . $this->lastVariables['timeRange'] . ' seconds')->format(DATE_RFC3339)
                    : $start->modify('+' . ($index + 1) . ' minutes')->format(DATE_RFC3339);
            $calls[] = [
                'aimedDepartureTime' => $at,
                'expectedDepartureTime' => $at,
                'actualDepartureTime' => null,
                'cancellation' => false,
                'date' => $start->format('Y-m-d'),
                'quay' => ['id' => 'NSR:Quay:1', 'publicCode' => 'A'],
                'destinationDisplay' => ['frontText' => 'Destination'],
                'serviceJourney' => [
                    'id' => 'TEST:ServiceJourney:' . $index,
                    'journeyPattern' => ['line' => ['id' => 'TEST:Line:1', 'publicCode' => '1', 'name' => 'Line 1']],
                ],
            ];
        }
        $stopPlace = str_contains($this->lastQuery ?? '', 'query StationBoard')
            ? ['departureCalls' => $calls, 'recentVehicleCalls' => [], 'upcomingVehicleCalls' => []]
            : ['estimatedCalls' => $calls];

        return new TransportResponse(
            200,
            [],
            json_encode(['data' => ['stopPlace' => $stopPlace]], JSON_THROW_ON_ERROR),
        );
    }
}

<?php

declare(strict_types=1);

namespace FjordPulse\Domain;

enum StationKind: string
{
    case StopPlace = 'stop_place';
    case Station = 'station';
    case BusStation = 'bus_station';
    case FerryTerminal = 'ferry_terminal';
    case RailStation = 'rail_station';
    case TramStop = 'tram_stop';
    case MetroStation = 'metro_station';
    case Airport = 'airport';
    case Unknown = 'unknown';
}

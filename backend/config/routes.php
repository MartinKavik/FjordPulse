<?php

declare(strict_types=1);

use Cake\Routing\Route\DashedRoute;
use Cake\Routing\RouteBuilder;

return static function (RouteBuilder $routes): void {
    $routes->setRouteClass(DashedRoute::class);

    $routes->scope('/api', static function (RouteBuilder $api): void {
        $api->setExtensions(['json']);
        $api->get('/health', ['controller' => 'Health', 'action' => 'health']);
        $api->get('/readiness', ['controller' => 'Health', 'action' => 'readiness']);
        $api->get('/stations', ['controller' => 'Stations', 'action' => 'map']);
        $api->get('/search', ['controller' => 'Search', 'action' => 'index']);
        $api->get('/stations/{stationId}', ['controller' => 'Stations', 'action' => 'view'])
            ->setPatterns(['stationId' => '[^/]+'])
            ->setPass(['stationId']);
        $api->get('/stations/{stationId}/departures', ['controller' => 'Stations', 'action' => 'departures'])
            ->setPatterns(['stationId' => '[^/]+'])
            ->setPass(['stationId']);
        $api->get('/stations/{stationId}/nearby-vehicles', ['controller' => 'Stations', 'action' => 'nearbyVehicles'])
            ->setPatterns(['stationId' => '[^/]+'])
            ->setPass(['stationId']);
        $api->get('/vehicles/{vehicleId}', ['controller' => 'Vehicles', 'action' => 'view'])
            ->setPatterns(['vehicleId' => '[^/]+'])
            ->setPass(['vehicleId']);
        $api->post('/realtime-token', ['controller' => 'RealtimeToken', 'action' => 'create']);
        $api->get('/admin/session', ['controller' => 'AdminSession', 'action' => 'view']);
        $api->post('/admin/session', ['controller' => 'AdminSession', 'action' => 'create']);
        $api->delete('/admin/session', ['controller' => 'AdminSession', 'action' => 'delete']);
        $api->get('/admin/status', ['controller' => 'AdminDiagnostics', 'action' => 'status']);
        $api->get('/admin/watches', ['controller' => 'AdminDiagnostics', 'action' => 'watches']);
        $api->get('/admin/entur-log', ['controller' => 'AdminDiagnostics', 'action' => 'enturLog']);
        $api->get('/admin/realtime', ['controller' => 'AdminDiagnostics', 'action' => 'realtime']);
        $api->get('/admin/events', ['controller' => 'AdminDiagnostics', 'action' => 'events']);
        $api->get('/admin/migrations', ['controller' => 'AdminDiagnostics', 'action' => 'migrations']);
        $api->get('/dev/scenario', ['controller' => 'DevScenario', 'action' => 'view']);
        $api->post('/dev/scenario', ['controller' => 'DevScenario', 'action' => 'update']);
        $api->get('/dev/scenarios', ['controller' => 'DevScenario', 'action' => 'index']);
    });
};

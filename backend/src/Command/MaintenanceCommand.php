<?php

declare(strict_types=1);

namespace FjordPulse\Command;

use Cake\Command\Command;
use Cake\Console\ConsoleOptionParser;
use DateInterval;
use DateTimeImmutable;
use FjordPulse\Config\RuntimeConfig;
use FjordPulse\Surreal\SdkSurrealConnectionFactory;
use FjordPulse\Surreal\SurrealRepositories;
use FjordPulse\Surreal\SystemStatus;

final class MaintenanceCommand extends Command
{
    public static function getDescription(): string
    {
        return 'Prune expired watches and bounded diagnostic/history records outside the realtime hot path.';
    }

    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser->addArgument('action', [
            'required' => true,
            'choices' => ['run'],
            'help' => 'Maintenance action.',
        ]);
    }

    public function execute(): int
    {
        $config = RuntimeConfig::fromEnvironment();
        $connection = (new SdkSurrealConnectionFactory($config->surreal))->sync();
        $repositories = new SurrealRepositories($connection);
        try {
            $now = new DateTimeImmutable();
            $report = $repositories->cleanup->prune(
                $now,
                $now->sub(new DateInterval('PT' . $config->observationRetentionHours . 'H')),
                $now->sub(new DateInterval('PT' . $config->eventRetentionHours . 'H')),
                $now->sub(new DateInterval('P7D')),
            );
            $payload = [
                'vehicleObservations' => $report->vehicleObservations,
                'realtimeEvents' => $report->realtimeEvents,
                'expiredWatches' => $report->expiredWatches,
                'enturRequestLogs' => $report->enturRequestLogs,
                'total' => $report->total(),
            ];
            $repositories->systemStatus->save(new SystemStatus(
                'maintenance',
                'healthy',
                sprintf('Maintenance pruned %d records.', $report->total()),
                $now,
                null,
                $payload,
            ));
            $this->io->out(json_encode([
                'event' => 'maintenance_complete',
                ...$payload,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::CODE_SUCCESS;
        } finally {
            $connection->close();
        }
    }
}

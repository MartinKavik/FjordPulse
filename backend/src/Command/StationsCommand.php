<?php

declare(strict_types=1);

namespace FjordPulse\Command;

use Cake\Command\Command;
use Cake\Console\ConsoleOptionParser;
use FjordPulse\Config\RuntimeConfig;
use FjordPulse\Service\HttpApiServiceFactory;

final class StationsCommand extends Command
{
    public static function getDescription(): string
    {
        return 'Import canonical stations through the configured fake or Entur Stop Place adapter.';
    }

    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->addArgument('action', [
                'required' => true,
                'choices' => ['import'],
                'help' => 'Station command action.',
            ])
            ->addOption('limit', [
                'default' => '10000',
                'help' => 'Maximum number of stations to import (1..50000).',
            ])
            ->addOption('force', [
                'boolean' => true,
                'default' => false,
                'help' => 'Refresh records even when canonical station data already exists.',
            ]);
    }

    public function execute(): int
    {
        $limitValue = (string)$this->args->getOption('limit');
        if (!ctype_digit($limitValue) || (int)$limitValue < 1 || (int)$limitValue > 50_000) {
            $this->io->err('Station import limit must be between 1 and 50000.');

            return self::CODE_ERROR;
        }
        $service = (new HttpApiServiceFactory(RuntimeConfig::fromEnvironment()))->create();
        try {
            $result = $service->importStations((int)$limitValue, (bool)$this->args->getOption('force'));
            $this->io->out(json_encode([
                'event' => 'station_import_complete',
                ...$result,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::CODE_SUCCESS;
        } finally {
            $service->close();
        }
    }
}

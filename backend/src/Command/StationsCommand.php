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
                'default' => '0',
                'help' => 'Diagnostic source-item cap (1..250000); 0 imports the complete catalog.',
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
        if (!ctype_digit($limitValue) || (int)$limitValue > 250_000) {
            $this->io->err('Station import limit must be 0 or between 1 and 250000.');

            return self::CODE_ERROR;
        }
        $service = (new HttpApiServiceFactory(RuntimeConfig::fromEnvironment()))->create();
        try {
            $result = $service->importStations(
                (int)$limitValue === 0 ? null : (int)$limitValue,
                (bool)$this->args->getOption('force'),
                $this->reportImportProgress(...),
            );
            $this->io->out(json_encode([
                'event' => 'station_import_complete',
                ...$result,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            if (!$result['complete']) {
                $this->io->err('Station catalog remains partial; rerun without --limit to complete it.');

                return self::CODE_ERROR;
            }

            return self::CODE_SUCCESS;
        } finally {
            $service->close();
        }
    }

    /**
     * @param array{source: string, sourceVersion: string, sourceMode: string, imported: int, nextOffset: int, complete: bool} $progress
     */
    private function reportImportProgress(array $progress): void
    {
        $this->io->out(json_encode([
            'event' => 'station_import_progress',
            ...$progress,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}

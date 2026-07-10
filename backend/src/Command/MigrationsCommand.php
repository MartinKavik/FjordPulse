<?php

declare(strict_types=1);

namespace FjordPulse\Command;

use Cake\Command\Command;
use Cake\Console\ConsoleOptionParser;
use FjordPulse\Config\RuntimeConfig;
use FjordPulse\Surreal\AppUserBootstrapper;
use FjordPulse\Surreal\MigrationRunner;
use FjordPulse\Surreal\SdkSurrealConnectionFactory;

final class MigrationsCommand extends Command
{
    public static function getDescription(): string
    {
        return 'Apply checksum-verified SurrealDB migrations and bootstrap the database-scoped app user.';
    }

    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser->addArgument('action', [
            'required' => true,
            'choices' => ['migrate'],
            'help' => 'Migration action.',
        ]);
    }

    public function execute(): int
    {
        if ($this->args->getArgument('action') !== 'migrate') {
            return self::CODE_ERROR;
        }
        $config = RuntimeConfig::fromEnvironment();
        $factory = new SdkSurrealConnectionFactory($config->surreal);
        $connection = $factory->syncRoot(
            self::environment('SURREAL_ROOT_USERNAME', 'root'),
            self::environment('SURREAL_ROOT_PASSWORD', 'root'),
        );
        try {
            $report = (new MigrationRunner($connection, dirname(__DIR__, 2) . '/migrations'))->migrate();
            (new AppUserBootstrapper($connection))->bootstrap(
                $config->surreal->username,
                $config->surreal->password,
            );
            $this->io->out(json_encode([
                'event' => 'migrations_complete',
                'applied' => array_map(static fn($migration): string => $migration->name, $report->applied),
                'alreadyApplied' => array_map(static fn($migration): string => $migration->name, $report->alreadyApplied),
                'appUser' => $config->surreal->username,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::CODE_SUCCESS;
        } finally {
            $connection->close();
        }
    }

    private static function environment(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}

<?php

declare(strict_types=1);

namespace FjordPulse\Command;

use Cake\Command\Command;
use Cake\Console\ConsoleOptionParser;
use FjordPulse\Config\RuntimeConfig;
use FjordPulse\Config\SurrealOperatorBootstrapConfig;
use FjordPulse\Config\SurrealRootBootstrapConfig;
use FjordPulse\Surreal\DatabaseUserBootstrapper;
use FjordPulse\Surreal\DatabaseUserCredentials;
use FjordPulse\Surreal\DatabaseUserRole;
use FjordPulse\Surreal\MigrationRunner;
use FjordPulse\Surreal\SdkSurrealConnectionFactory;

final class MigrationsCommand extends Command
{
    public static function getDescription(): string
    {
        return 'Apply checksum-verified SurrealDB migrations and bootstrap application and read-only operator users.';
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
        $root = SurrealRootBootstrapConfig::fromEnvironment($config);
        $operator = SurrealOperatorBootstrapConfig::fromEnvironment(
            $config,
            $root->credentials->username,
            $root->credentials->password,
        );
        $factory = new SdkSurrealConnectionFactory($config->surreal);
        $connection = $factory->syncRoot($root->credentials->username, $root->credentials->password);
        try {
            $report = (new MigrationRunner($connection, dirname(__DIR__, 2) . '/migrations'))->migrate();
            $users = new DatabaseUserBootstrapper($connection);
            $appEvidence = $users->bootstrap(
                new DatabaseUserCredentials($config->surreal->username, $config->surreal->password),
                DatabaseUserRole::Editor,
            );
            $operatorEvidence = $operator === null
                ? null
                : $users->bootstrap($operator->credentials, DatabaseUserRole::Viewer);
            $this->io->out(json_encode([
                'event' => 'migrations_complete',
                'applied' => array_map(static fn($migration): string => $migration->name, $report->applied),
                'alreadyApplied' => array_map(static fn($migration): string => $migration->name, $report->alreadyApplied),
                'appUser' => $config->surreal->username,
                'databaseUsers' => [
                    'application' => $appEvidence->toArray(),
                    'operator' => $operatorEvidence?->toArray() ?? [
                        'username' => null,
                        'role' => DatabaseUserRole::Viewer->value,
                        'bootstrapped' => false,
                    ],
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::CODE_SUCCESS;
        } finally {
            $connection->close();
        }
    }
}

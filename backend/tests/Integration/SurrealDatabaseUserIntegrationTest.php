<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Integration;

use FjordPulse\Surreal\DatabaseRecord;
use FjordPulse\Surreal\DatabaseUserBootstrapper;
use FjordPulse\Surreal\DatabaseUserCredentials;
use FjordPulse\Surreal\DatabaseUserRole;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class SurrealDatabaseUserIntegrationTest extends SurrealIntegrationTestCase
{
    private const string OPERATOR_USERNAME = 'fjordpulse_operator';
    private const string OPERATOR_PASSWORD = 'integration-viewer-password';

    public function testViewerCanReadButCannotCreateUpdateDeleteDefineOrRemove(): void
    {
        [$appFactory, , $database] = $this->database('viewer_access');
        $root = $appFactory->syncRoot(self::ROOT_USERNAME, self::ROOT_PASSWORD);
        try {
            $evidence = (new DatabaseUserBootstrapper($root))->bootstrap(
                new DatabaseUserCredentials(self::OPERATOR_USERNAME, self::OPERATOR_PASSWORD),
                DatabaseUserRole::Viewer,
            );
            self::assertSame('VIEWER', $evidence->toArray()['role']);
            $root->run(<<<'SURQL'
UPSERT system_status:viewer_access CONTENT {
    service: "viewer_access",
    state: "ok",
    detail: "readable",
    checked_at: d"2026-07-14T20:00:00Z",
    latency_ms: NONE,
    metadata: {}
};
SURQL);

            $databaseInfo = DatabaseRecord::one($root->run('INFO FOR DB;')[0] ?? null);
            self::assertNotNull($databaseInfo);
            $tables = $databaseInfo['tables'] ?? null;
            self::assertIsArray($tables);
            foreach ($tables as $table => $tableDefinition) {
                self::assertIsString($table);
                self::assertIsString($tableDefinition);
                self::assertStringContainsString(
                    'PERMISSIONS NONE',
                    $tableDefinition,
                    "The VIEWER write boundary requires {$table} to deny record mutations.",
                );
            }
            $users = $databaseInfo['users'] ?? null;
            self::assertIsArray($users);
            $definition = $users[self::OPERATOR_USERNAME] ?? null;
            self::assertIsString($definition);
            self::assertStringContainsString('ROLES VIEWER', $definition);
        } finally {
            $root->close();
        }

        $viewer = $this->databaseUserFactory(
            $database,
            self::OPERATOR_USERNAME,
            self::OPERATOR_PASSWORD,
        )->sync();
        try {
            $read = DatabaseRecord::many(
                $viewer->run('SELECT service, state, detail FROM system_status WHERE service = "viewer_access";')[0] ?? null,
            );
            self::assertCount(1, $read);
            self::assertSame('readable', DatabaseRecord::string($read[0]['detail'] ?? null, 'detail'));

            self::assertSame([], DatabaseRecord::many($viewer->run(<<<'SURQL'
CREATE system_status:viewer_created CONTENT {
    service: "viewer_created",
    state: "changed",
    detail: "must not exist",
    checked_at: d"2026-07-14T20:01:00Z",
    latency_ms: NONE,
    metadata: {}
};
SURQL)[0] ?? null));
            self::assertSame([], DatabaseRecord::many(
                $viewer->run('UPDATE system_status:viewer_access SET detail = "changed";')[0] ?? null,
            ));
            self::assertSame([], DatabaseRecord::many(
                $viewer->run('DELETE system_status:viewer_access;')[0] ?? null,
            ));

            self::assertIamDenied(
                static fn() => $viewer->run('DEFINE TABLE viewer_must_not_define SCHEMALESS;'),
            );
            self::assertIamDenied(
                static fn() => $viewer->run('REMOVE TABLE system_status;'),
            );
        } finally {
            $viewer->close();
        }

        $root = $appFactory->syncRoot(self::ROOT_USERNAME, self::ROOT_PASSWORD);
        try {
            $retained = DatabaseRecord::many(
                $root->run('SELECT service, state, detail FROM system_status ORDER BY service ASC;')[0] ?? null,
            );
            self::assertCount(1, $retained);
            self::assertSame('viewer_access', DatabaseRecord::string($retained[0]['service'] ?? null, 'service'));
            self::assertSame('readable', DatabaseRecord::string($retained[0]['detail'] ?? null, 'detail'));

            $databaseInfo = DatabaseRecord::one($root->run('INFO FOR DB;')[0] ?? null);
            self::assertNotNull($databaseInfo);
            $tables = $databaseInfo['tables'] ?? null;
            self::assertIsArray($tables);
            self::assertArrayHasKey('system_status', $tables);
            self::assertArrayNotHasKey('viewer_must_not_define', $tables);
        } finally {
            $root->close();
        }
    }

    /** @param callable(): mixed $operation */
    private static function assertIamDenied(callable $operation): void
    {
        try {
            $result = $operation();
        } catch (\Throwable $error) {
            self::assertStringContainsString('Not enough permissions', $error->getMessage());

            return;
        }

        self::assertStringContainsString(
            'Not enough permissions',
            json_encode($result, JSON_THROW_ON_ERROR),
        );
    }
}

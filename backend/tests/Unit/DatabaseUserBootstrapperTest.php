<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use FjordPulse\Surreal\DatabaseUserBootstrapEvidence;
use FjordPulse\Surreal\DatabaseUserBootstrapper;
use FjordPulse\Surreal\DatabaseUserCredentials;
use FjordPulse\Surreal\DatabaseUserRole;
use FjordPulse\Surreal\SurrealConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatabaseUserBootstrapper::class)]
#[CoversClass(DatabaseUserBootstrapEvidence::class)]
#[CoversClass(DatabaseUserCredentials::class)]
#[CoversClass(DatabaseUserRole::class)]
final class DatabaseUserBootstrapperTest extends TestCase
{
    public function testViewerBootstrapUsesThePinnedDatabaseUserSyntaxAndReturnsNonSecretEvidence(): void
    {
        $connection = $this->createMock(SurrealConnection::class);
        $connection->expects(self::once())
            ->method('run')
            ->with(
                'DEFINE USER OVERWRITE fjordpulse_operator ON DATABASE '
                . 'PASSWORD s"operator-test-password" ROLES VIEWER;',
            )
            ->willReturn([null]);
        $credentials = new DatabaseUserCredentials('fjordpulse_operator', 'operator-test-password');

        $evidence = (new DatabaseUserBootstrapper($connection))->bootstrap(
            $credentials,
            DatabaseUserRole::Viewer,
        );

        self::assertSame([
            'username' => 'fjordpulse_operator',
            'role' => 'VIEWER',
            'bootstrapped' => true,
        ], $evidence->toArray());
        self::assertStringNotContainsString(
            $credentials->password,
            json_encode($evidence->toArray(), JSON_THROW_ON_ERROR),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function invalidUsernames(): iterable
    {
        yield 'empty' => [''];
        yield 'starts with a digit' => ['1operator'];
        yield 'contains a hyphen' => ['fjordpulse-operator'];
        yield 'contains SurrealQL punctuation' => ['operator`; REMOVE DATABASE fjordpulse;'];
        yield 'too long' => [str_repeat('a', 129)];
    }

    #[DataProvider('invalidUsernames')]
    public function testDatabaseUsernameMustBeABoundedIdentifier(string $username): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('username is not a valid identifier');

        new DatabaseUserCredentials($username, 'valid-test-password');
    }

    public function testDatabasePasswordMustNotBeEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('password is outside the accepted bounds');

        new DatabaseUserCredentials('fjordpulse_operator', '');
    }

    public function testDatabasePasswordMustNotBeWhitespaceOnly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('password is outside the accepted bounds');

        new DatabaseUserCredentials('fjordpulse_operator', " \t\n");
    }

    public function testDatabasePasswordMustBeBoundedUtf8(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('password is outside the accepted bounds');

        new DatabaseUserCredentials('fjordpulse_operator', "invalid-utf8-\xFF");
    }
}

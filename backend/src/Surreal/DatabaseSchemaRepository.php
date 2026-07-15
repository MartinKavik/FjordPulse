<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

final readonly class DatabaseSchemaRepository extends AbstractSurrealRepository
{
    private const string INSPECTION_QUERY = <<<'SURQL'
RETURN (INFO FOR DB STRUCTURE).tables.map(|$table| {
    LET $schema = INFO FOR TABLE $table.name STRUCTURE;
    RETURN {
        name: $table.name,
        kind: $table.kind.kind,
        schemafull: $table.schemafull,
        permissions: {
            select: $table.permissions.select,
            create: $table.permissions.create,
            update: $table.permissions.update,
            delete: $table.permissions.delete
        },
        fields: $schema.fields.map(|$field| {
            RETURN {
                name: $field.name,
                kind: $field.kind ?? "computed",
                readonly: $field.readonly,
                assertion: $field.assert ?? NULL,
                defaultValue: $field.default ?? NULL,
                computedValue: $field.computed ?? NULL,
                valueExpression: $field.value ?? NULL,
                referenceOnDelete: $field.reference.on_delete ?? NULL,
                permissions: {
                    select: $field.permissions.select,
                    create: $field.permissions.create,
                    update: $field.permissions.update
                }
            };
        }),
        indexes: $schema.indexes.map(|$index| {
            RETURN {
                name: $index.name,
                columns: $index.cols,
                mode: $index.index
            };
        }),
        events: $schema.events.map(|$event| {
            RETURN {
                name: $event.name,
                condition: $event.when ?? NULL,
                actions: $event.then
            };
        })
    };
});
SURQL;

    public function inspect(): DatabaseSchemaInspection
    {
        $results = $this->connection->run(self::INSPECTION_QUERY);

        return DatabaseSchemaInspection::fromResult($results[0] ?? null);
    }
}

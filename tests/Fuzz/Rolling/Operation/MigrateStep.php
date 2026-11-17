<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation;

/**
 * The migration job of a deploy migrating the database to one of the plan's schemas.
 */
final readonly class MigrateStep implements Step
{
    public function __construct(public int $schema) {}

    public function describe(): string
    {
        return "migrate to schema {$this->schema}";
    }

    public function toArray(): array
    {
        return ['type' => 'migrate', 'schema' => $this->schema];
    }
}

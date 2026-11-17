<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Unit\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\SqlServerConnection;
use InvalidArgumentException;
use Kontainer\ModelCache\Support\DependentTables;
use Kontainer\ModelCache\Tests\TestCase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use UnexpectedValueException;

final class DependentTablesTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'not an array' => ['cars'];
        yield 'a list of dependents without a table' => [[['drivers']]];
        yield 'a table with a single dependent' => [['cars' => 'drivers']];
        yield 'a dependent that is no name' => [['cars' => [1]]];
    }

    #[DataProvider('invalidConfigurations')]
    public function testInvalidConfiguredDependentTablesFailLoudly(mixed $configured): void
    {
        config(['model-cache.dependent_tables' => $configured]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid model-cache.dependent_tables, expected an array of table => list of tables');

        DependentTables::configured();
    }

    public function testSqlServerDependentsAreReadFromItsCatalog(): void
    {
        $dependents = DependentTables::load(self::sqlServer(
            foreignKeys: [['constraint_id' => 1, 'child_table' => 'pets', 'parent_table' => 'owners', 'child_column' => 'owner_id', 'parent_column' => 'id',
                'delete_rule' => 'CASCADE', 'update_rule' => 'NO_ACTION', 'child_type' => 'bigint', 'parent_type' => 'bigint']],
            views: [['base_table' => 'pets', 'view_name' => 'pet_counts']],
            primaryKeys: [
                ['table_name' => 'owners', 'column_name' => 'ID', 'data_type' => 'bigint'],
                ['table_name' => 'car_material', 'column_name' => 'car_id', 'data_type' => 'bigint'],
                ['table_name' => 'car_material', 'column_name' => 'material_id', 'data_type' => 'bigint'],
                ['table_name' => 'snapshots', 'column_name' => 'taken_at', 'data_type' => 'datetime2'],
            ],
            triggers: [
                ['id' => 7, 'name' => 'owners_audit', 'table_name' => 'owners', 'event' => 'DELETE', 'body' => "create trigger owners_audit on owners after delete, update as insert into owner_audits (event) values ('changed')"],
                ['id' => 7, 'name' => 'owners_audit', 'table_name' => 'owners', 'event' => 'UPDATE', 'body' => "create trigger owners_audit on owners after delete, update as insert into owner_audits (event) values ('changed')"],
            ],
        ));

        $this->assertSame(['pet_counts'], $dependents['a:pets']);
        $this->assertSame(['pet_counts', 'pets'], $dependents['d:owners']);
        $this->assertSame(['owner_audits'], $dependents['gd:owners']);
        $this->assertSame(['owner_audits'], $dependents['gu:owners']);
        $this->assertArrayNotHasKey('gi:owners', $dependents);
        $this->assertArrayNotHasKey('u:owners', $dependents);
        $this->assertSame(["owner_id\tn"], $dependents['k:pets']);
        $this->assertSame(["id\tn"], $dependents['p:owners']);
        $this->assertArrayNotHasKey('p:car_material', $dependents, 'Rows of a composite key are not tagged');
        $this->assertArrayNotHasKey('p:snapshots', $dependents, 'Rows of a key of a type values cannot be keyed for are not tagged');
    }

    public function testSqlServerObjectsWhoseNameIsHiddenFailLoudly(): void
    {
        $connection = self::sqlServer(
            foreignKeys: [['constraint_id' => 1, 'child_table' => null, 'parent_table' => 'owners', 'child_column' => 'owner_id', 'parent_column' => 'id',
                'delete_rule' => 'CASCADE', 'update_rule' => 'NO_ACTION', 'child_type' => 'bigint', 'parent_type' => 'bigint']],
            views: [],
            triggers: [],
        );

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Model Cache read child_table of the schema as null, expected text');

        DependentTables::load($connection);
    }

    public function testSchemasOfUnsupportedDriversCannotBeRead(): void
    {
        $connection = new Connection(static fn () => throw new LogicException('No query reaches the database'), 'app', '', ['driver' => 'oracle']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Model Cache cannot read view dependencies of driver oracle');

        DependentTables::load($connection);
    }

    /**
     * @param  list<array<string, mixed>>  $foreignKeys
     * @param  list<array<string, mixed>>  $views
     * @param  list<array<string, mixed>>  $triggers
     * @param  list<array<string, mixed>>  $primaryKeys
     */
    private static function sqlServer(array $foreignKeys, array $views, array $triggers, array $primaryKeys = []): SqlServerConnection
    {
        $catalog = ['sys.foreign_keys' => $foreignKeys, 'sys.sql_expression_dependencies' => $views, 'sys.triggers' => $triggers, 'sys.indexes' => $primaryKeys];

        return new class($catalog) extends SqlServerConnection
        {
            /**
             * @param  array<string, list<array<string, mixed>>>  $catalog
             */
            public function __construct(private readonly array $catalog)
            {
                parent::__construct(static fn () => throw new LogicException('The SQL Server catalog is read from memory'), 'app', '', ['driver' => 'sqlsrv']);
            }

            public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
            {
                foreach ($this->catalog as $view => $rows) {
                    if (str_contains($query, $view)) {
                        return array_map(static fn (array $row): object => (object) $row, $rows);
                    }
                }

                throw new LogicException("No SQL Server catalog rows for {$query}");
            }
        };
    }
}

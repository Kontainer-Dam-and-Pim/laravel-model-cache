<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling;

use Kontainer\ModelCache\Tests\Fuzz\Rolling\App\Release;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Api;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Condition;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Operator;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Read;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Write;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\WriteKind;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\Column;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\Identifier;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\SchemaGenerator;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\Table;

/**
 * Generates the reads and writes a release's code makes, against the schema and models it was
 * written for: conditions mostly on the columns that name rows, with the spellings of a value
 * the database compares as equal, through the builder, the models or raw SQL.
 */
final readonly class OperationGenerator
{
    private const array LABELS = ['a', 'b', 'c'];

    public function __construct(private Dice $dice) {}

    /**
     * @param  string|null  $table  the table to read, a random table or view when null
     */
    public function read(Release $release, ?string $table = null): Read
    {
        if ($table === null && $release->schema->views !== [] && $this->dice->chance(15)) {
            return new Read(Api::Builder, $this->dice->pick(array_keys($release->schema->views)), [], count: $this->dice->chance(30));
        }

        $table = $table === null ? $this->table($release, 5) : $release->schema->table($table);
        $api = Api::from($this->dice->weighted([Api::Model->value => 4, Api::Builder->value => 4, Api::Raw->value => 2]));
        $conditions = $this->conditions($release, $table, $api, $this->dice->weighted([0 => 2, 1 => 5, 2 => 3]));
        $model = $release->model($table->name);
        $joins = array_keys(array_filter($release->schema->foreignKeys, static fn ($foreignKey): bool => $foreignKey->child === $table->name));
        $join = $api !== Api::Model && $joins !== [] && $this->dice->chance(20) ? $this->dice->pick($joins) : null;
        $with = $api === Api::Model && $model->relations !== [] && $this->dice->chance(25) ? $this->dice->pick($model->relations)->name : null;

        return new Read($api, $table->name, $conditions, count($conditions) === 2 && $this->dice->chance(25), $join, $with, $with === null && $this->dice->chance(15));
    }

    /**
     * @param  bool  $inTransaction  whether the pod holds a transaction, which a truncate would end on MySQL
     * @param  string|null  $table  the table to write, a random table when null
     */
    public function write(Release $release, bool $inTransaction, ?string $table = null): Write
    {
        $table = $table === null ? $this->table($release, 3) : $release->schema->table($table);
        $kinds = [
            WriteKind::Insert->value => 15,
            WriteKind::InsertOrIgnore->value => 5,
            WriteKind::Upsert->value => 6,
            WriteKind::Update->value => 25,
            WriteKind::Delete->value => 12,
            ...($inTransaction ? [] : [WriteKind::Truncate->value => 1]),
            WriteKind::Save->value => 10,
            WriteKind::DeleteModel->value => 6,
            ...($table->autoIncrement ? [WriteKind::InsertGetId->value => 5] : []),
            ...($table->hasColumn('amount') ? [WriteKind::Increment->value => 5] : []),
        ];
        $kind = WriteKind::from($this->dice->weighted($kinds));
        $api = $this->dice->pick(match ($kind) {
            WriteKind::Insert, WriteKind::Update, WriteKind::Delete => [Api::Builder, Api::Raw, Api::Model],
            WriteKind::Upsert, WriteKind::Increment => [Api::Builder, Api::Model],
            WriteKind::Save, WriteKind::DeleteModel => [Api::Model],
            WriteKind::InsertGetId, WriteKind::InsertOrIgnore, WriteKind::Truncate => [Api::Builder],
        });

        return match ($kind) {
            WriteKind::Insert, WriteKind::InsertOrIgnore => new Write($api, $kind, $table->name, [], $this->rows($table, false)),
            WriteKind::InsertGetId => new Write($api, $kind, $table->name, [], [$this->row($table, false)]),
            WriteKind::Upsert => new Write($api, $kind, $table->name, [], $this->rows($table, true)),
            WriteKind::Truncate => new Write($api, $kind, $table->name, [], []),
            WriteKind::Increment => new Write($api, $kind, $table->name, $this->conditions($release, $table, $api, $this->dice->int(0, 2)), [['amount' => $this->dice->int(1, 2)]]),
            WriteKind::Delete, WriteKind::DeleteModel => $this->conditionalWrite($release, $table, $api, $kind, []),
            WriteKind::Update, WriteKind::Save => $this->conditionalWrite($release, $table, $api, $kind, $this->assignments($table)),
        };
    }

    /**
     * Inserts filling the table with rows that later reads and writes meet.
     *
     * @return list<Write>
     */
    public function seed(Release $release): array
    {
        $writes = [];
        foreach ($release->schema->tables as $table) {
            if ($table->name === SchemaGenerator::AUDIT) {
                continue;
            }

            foreach ($table->column($table->primaryKey[0])->kind->domain() as $key) {
                $writes[] = new Write(Api::Builder, WriteKind::InsertOrIgnore, $table->name, [], [$this->seedRow($table, $key)]);
            }
        }

        return $writes;
    }

    /**
     * A row with the key value, nullable foreign keys left empty so it inserts whatever rows its
     * parents have, and the identifier its key implies.
     *
     * @return array<string, int|string|null>
     */
    private function seedRow(Table $table, int|string $key): array
    {
        $row = [];
        foreach ($table->columns as $column) {
            $row[$column->name] = match (true) {
                $column->name === $table->primaryKey[0] => $key,
                $column->name === 'ref' && in_array('ref', $table->unique, true) => Identifier::of($key, $table->column($table->primaryKey[0])->kind),
                $column->nullable => null,
                default => $this->value($column, in_array($column->name, $table->primaryKey, true)),
            };
        }

        return $row;
    }

    /**
     * @param  array<string, int|string|null>  $values
     */
    private function conditionalWrite(Release $release, Table $table, Api $api, WriteKind $kind, array $values): Write
    {
        $count = $this->dice->chance(5) ? 0 : $this->dice->int(1, 2);
        $conditions = $this->conditions($release, $table, $api, $count);

        return new Write($api, $kind, $table->name, $conditions, $values === [] ? [] : [$values], count($conditions) === 2 && $this->dice->chance(20));
    }

    /**
     * A table of the release's data, or now and then its audit table.
     */
    public function table(Release $release, int $auditChance): Table
    {
        $tables = array_filter($release->schema->tables, static fn (Table $table): bool => $table->name !== SchemaGenerator::AUDIT);

        return $this->dice->chance($auditChance) ? $release->schema->table(SchemaGenerator::AUDIT) : $this->dice->pick($tables);
    }

    /**
     * @return list<Condition>
     */
    private function conditions(Release $release, Table $table, Api $api, int $count): array
    {
        $weights = [];
        $add = static function (string $column, int $weight) use (&$weights, $table): void {
            if ($table->hasColumn($column)) {
                $weights[$column] = ($weights[$column] ?? 0) + $weight;
            }
        };

        if ($api === Api::Model) {
            $add($release->model($table->name)->keyName, 4);
        }

        foreach ($table->primaryKey as $column) {
            $add($column, 3);
        }

        foreach ($table->unique as $column) {
            $add($column, 2);
        }

        foreach ($release->schema->foreignKeysOf($table->name) as $foreignKey) {
            foreach ($foreignKey->childColumns as $column) {
                $add($column, 2);
            }
        }

        $add('label', 1);
        $add('amount', 1);

        $conditions = [];
        for ($i = 0; $i < $count; $i++) {
            $conditions[] = $this->condition($table->column($this->dice->weighted($weights)));
        }

        return $conditions;
    }

    private function condition(Column $column): Condition
    {
        $operators = [Operator::Equals->value => 12, Operator::In->value => 5, Operator::GreaterThan->value => 1, ...($column->nullable ? [Operator::IsNull->value => 2] : [])];
        $operator = Operator::from($this->dice->weighted($operators));

        return match ($operator) {
            Operator::IsNull => new Condition($column->name, $operator, []),
            Operator::In => new Condition($column->name, $operator, array_map(fn (): int|string => $this->spelledValue($column), range(1, $this->dice->int(1, 3)))),
            default => new Condition($column->name, $operator, [$this->spelledValue($column)]),
        };
    }

    /**
     * A value of the column, in one of the spellings the database compares as equal to it.
     */
    private function spelledValue(Column $column): int|string
    {
        if ($column->name === 'label') {
            return $this->dice->pick(self::LABELS);
        }

        return $this->dice->pick($column->kind->spellings($this->dice->pick($column->kind->domain())));
    }

    /**
     * One or two rows giving the same columns, as a multi-row insert must.
     *
     * @return non-empty-list<array<string, int|string|null>>
     */
    private function rows(Table $table, bool $withKey): array
    {
        $first = $this->row($table, $withKey);
        $rows = [$first];

        if ($this->dice->chance(40)) {
            $second = [];
            foreach (array_keys($first) as $column) {
                $second[$column] = $this->value($table->column($column), in_array($column, $table->primaryKey, true));
            }
            $rows[] = $second;
        }

        return $rows;
    }

    /**
     * @return array<string, int|string|null>
     */
    private function row(Table $table, bool $withKey): array
    {
        $row = [];
        foreach ($table->columns as $column) {
            $isKey = in_array($column->name, $table->primaryKey, true);

            if ($isKey && $table->autoIncrement && ! $withKey && $this->dice->chance(50)) {
                continue;
            }

            if (! $isKey && ($column->nullable || $column->default !== null) && $this->dice->chance(25)) {
                continue;
            }

            $row[$column->name] = $this->value($column, $isKey);
        }

        return $this->withIdentifierOfKey($table, $row);
    }

    /**
     * Gives a row's unique text column the identifier its primary key value implies, as a row
     * keeps its public identifier, so reads by one and writes by the other meet the same rows.
     *
     * @param  array<string, int|string|null>  $row
     * @return array<string, int|string|null>
     */
    private function withIdentifierOfKey(Table $table, array $row): array
    {
        $key = $row[$table->primaryKey[0]] ?? null;

        if (! isset($row['ref']) || ! in_array('ref', $table->unique, true) || $key === null || $this->dice->chance(20)) {
            return $row;
        }

        $row['ref'] = $this->identifierOf($key, $table);

        return $row;
    }

    private function identifierOf(int|string $key, Table $table): string
    {
        return Identifier::of($key, $table->column($table->primaryKey[0])->kind, $this->dice->chance(30));
    }

    /**
     * A read of the rows a write pins by one of their keys, through their other key: by the
     * identifier of a primary key value, or by the primary key of an identifier, as code reading
     * a record by its public identifier and writing it by its key does. Null when the write pins
     * no rows that way.
     */
    public function readOfSameRows(Release $release, Write $write): ?Read
    {
        if ($write->or || ! isset($release->schema->tables[$write->table])) {
            return null;
        }

        $table = $release->schema->table($write->table);
        $primary = $table->primaryKey[0];

        if (count($table->primaryKey) !== 1 || ! in_array('ref', $table->unique, true)) {
            return null;
        }

        foreach ($write->conditions as $condition) {
            if (! in_array($condition->operator, [Operator::Equals, Operator::In], true)) {
                continue;
            }

            $column = match ($condition->column) {
                $primary => 'ref',
                'ref' => $primary,
                default => null,
            };

            if ($column === null) {
                continue;
            }

            $values = array_map(fn (int|string $value): int|string => $column === 'ref' ? $this->identifierOf($value, $table) : $this->keyOfIdentifier($table->column($primary), (string) $value), $condition->values);
            $api = $release->model($table->name)->keyName === $column || $this->dice->chance(50) ? Api::Model : Api::Builder;

            return new Read($api, $table->name, [new Condition($column, count($values) === 1 ? Operator::Equals : Operator::In, $values)]);
        }

        return null;
    }

    private function keyOfIdentifier(Column $key, string $identifier): int|string
    {
        return $this->dice->pick($key->kind->spellings(Identifier::keyOf($identifier, $key->kind)));
    }

    /**
     * @return array<string, int|string|null>
     */
    private function assignments(Table $table): array
    {
        $assignable = array_values(array_filter(
            $table->columns,
            fn (Column $column): bool => ! in_array($column->name, [...$table->primaryKey, ...$table->unique], true) || $this->dice->chance(15),
        ));

        if ($assignable === []) {
            return ['label' => $this->dice->pick(self::LABELS)];
        }

        $values = [];
        foreach ($this->dice->some($assignable, 2) ?: [$this->dice->pick($assignable)] as $column) {
            $values[$column->name] = $this->value($column, in_array($column->name, $table->primaryKey, true));
        }

        return $values;
    }

    private function value(Column $column, bool $isKey): int|string|null
    {
        if ($column->name === 'label' || $column->name === 'note') {
            return $this->dice->pick(self::LABELS);
        }

        if ($column->nullable && ! $isKey && $this->dice->chance(20)) {
            return null;
        }

        return $this->dice->pick($column->kind->domain());
    }
}

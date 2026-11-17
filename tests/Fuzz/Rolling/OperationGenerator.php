<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling;

use Kontainer\ModelCache\Tests\Fuzz\Rolling\App\RelationKind;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\App\Release;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Api;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Condition;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\KeywordCase;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Operator;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Quoting;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\RawStyle;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Read;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\ReadShape;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Write;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\WriteKind;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\Column;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\Driver;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\ForeignKey;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\Identifier;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\Schema;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\SchemaGenerator;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\Table;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\ValueKind;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\View;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\ViewKind;

/**
 * Generates the reads and writes a release's code makes, against the schema and models it was
 * written for: conditions mostly on the columns that name rows, with the spellings of a value
 * the database compares as equal, through the builder, the models or raw SQL.
 */
final readonly class OperationGenerator
{
    private const array LABELS = ['a', 'b', 'c'];

    private const int SEEDED_KEYS = 4;

    public function __construct(
        private Dice $dice,
        private Driver $driver,
    ) {}

    /**
     * @param  string|null  $table  the table to read, a random table or view when null
     */
    public function read(Release $release, ?string $table = null): Read
    {
        if ($table === null && $release->schema->views !== [] && $this->dice->chance(15)) {
            return new Read(Api::Builder, $this->dice->pick(array_keys($release->schema->views)), [], shape: $this->dice->chance(30) ? ReadShape::Count : ReadShape::Rows);
        }

        $table = $table === null ? $this->table($release, 5) : $release->schema->table($table);
        $api = Api::from($this->dice->weighted([Api::Model->value => 4, Api::Builder->value => 4, Api::Raw->value => 2]));
        $conditions = $this->conditions($release, $table, $api, $this->dice->weighted([0 => 2, 1 => 5, 2 => 3]));
        [$shape, $subject] = $this->shape($release, $table, $api);
        $label = in_array($shape, [ReadShape::WhereHas, ReadShape::ChildrenOfLabelledParents], true) ? $this->dice->pick(self::LABELS) : null;

        return new Read($api, $table->name, $conditions, count($conditions) === 2 && $this->dice->chance(25), $shape, $subject, $label);
    }

    /**
     * A shape the table supports through the API, with the foreign key, relation or other table
     * it needs.
     *
     * @return array{ReadShape, string|null}
     */
    private function shape(Release $release, Table $table, Api $api): array
    {
        $singleColumn = array_filter($release->schema->foreignKeys, static fn (ForeignKey $foreignKey): bool => $foreignKey->isSingleColumn());
        $subjects = [
            ReadShape::Rows->value => [null],
            ReadShape::Count->value => [null],
            ...($table->hasColumn('amount') && $api !== Api::Model ? [ReadShape::Sum->value => [null], ReadShape::Max->value => [null]] : []),
        ];
        $asChild = array_keys(array_filter($singleColumn, static fn (ForeignKey $foreignKey): bool => $foreignKey->child === $table->name));
        $asParent = array_keys(array_filter($singleColumn, static fn (ForeignKey $foreignKey): bool => $foreignKey->parent === $table->name));
        $relations = array_map(static fn ($relation): string => $relation->name, $release->model($table->name)->relations);
        $hasMany = array_map(static fn ($relation): string => $relation->name, array_filter($release->model($table->name)->relations, static fn ($relation): bool => $relation->kind === RelationKind::HasMany));
        $others = array_values(array_diff(array_keys($release->schema->tables), [$table->name]));

        $subjects += match ($api) {
            Api::Model => [
                ReadShape::EagerLoad->value => $relations,
                ReadShape::WithCount->value => $hasMany,
                ReadShape::WhereHas->value => $relations,
                ...($release->model($table->name)->softDeletes ? [ReadShape::WithTrashed->value => [null], ReadShape::OnlyTrashed->value => [null]] : []),
            ],
            Api::Raw => [ReadShape::RawParentsWithChildren->value => $asParent, ReadShape::RawChildCounts->value => $asParent],
            Api::Builder => [
                ReadShape::JoinParent->value => array_keys(array_filter($release->schema->foreignKeys, static fn (ForeignKey $foreignKey): bool => $foreignKey->child === $table->name)),
                ReadShape::ChildrenOfLabelledParents->value => $asChild,
                ReadShape::GroupedChildCounts->value => $asChild,
                ReadShape::ParentsWithChildren->value => $asParent,
                ReadShape::ChildCounts->value => $asParent,
                ReadShape::JoinedChildCounts->value => $asParent,
                ReadShape::MostChildren->value => $asParent,
                ReadShape::Union->value => $others,
            ],
        };

        $weights = [];
        foreach (array_filter($subjects, static fn (array $candidates): bool => $candidates !== []) as $shape => $_) {
            $weights[$shape] = $shape === ReadShape::Rows->value ? 12 : 2;
        }

        $shape = ReadShape::from($this->dice->weighted($weights));

        return [$shape, $this->dice->pick($subjects[$shape->value])];
    }

    /**
     * @param  bool  $inTransaction  whether the pod holds a transaction, which a truncate would end on MySQL
     * @param  string|null  $table  the table to write, a random table when null
     */
    public function write(Release $release, bool $inTransaction, ?string $table = null): Write
    {
        $table = $table === null ? $this->table($release, 3) : $release->schema->table($table);
        $views = $this->writableViews($release, $table);

        if ($views !== [] && $this->dice->chance(15)) {
            return $this->writeThroughView($release, $this->dice->pick($views));
        }
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
            ...($table->hasColumn('amount') ? [WriteKind::Increment->value => 5, WriteKind::IncrementEach->value => 2] : []),
            WriteKind::InsertUsing->value => 3,
            WriteKind::UpdateOrInsert->value => 3,
            WriteKind::Statements->value => 2,
            ...($this->parentKeys($release, $table) === [] ? [] : [WriteKind::UpdateJoined->value => 3, WriteKind::DeleteWhereParentExists->value => 2]),
            ...($this->driver->isMySqlFamily() || $this->driver === Driver::Sqlite ? [WriteKind::Replace->value => 2] : []),
            ...(count($table->primaryKey) === 1 && $this->driver !== Driver::MariaDb ? [WriteKind::CteDelete->value => 2] : []),
            ...(count($table->primaryKey) === 1 && $this->driver === Driver::PgSql ? [WriteKind::Merge->value => 2] : []),
            ...($release->model($table->name)->softDeletes ? [WriteKind::Restore->value => 10, WriteKind::ForceDelete->value => 5] : []),
        ];
        $kind = WriteKind::from($this->dice->weighted($kinds));
        $api = $this->dice->pick(match ($kind) {
            WriteKind::Insert, WriteKind::Update, WriteKind::Delete, WriteKind::Upsert => [Api::Builder, Api::Raw, Api::Model],
            WriteKind::Increment, WriteKind::IncrementEach => [Api::Builder, Api::Model],
            WriteKind::Save, WriteKind::DeleteModel, WriteKind::Restore, WriteKind::ForceDelete => [Api::Model],
            WriteKind::Statements, WriteKind::Replace, WriteKind::CteDelete, WriteKind::Merge => [Api::Raw],
            WriteKind::UpdateJoined, WriteKind::DeleteWhereParentExists => [Api::Builder, Api::Raw],
            WriteKind::InsertGetId, WriteKind::InsertOrIgnore, WriteKind::Truncate, WriteKind::InsertUsing, WriteKind::UpdateOrInsert => [Api::Builder],
        });
        $write = $this->unstyledWrite($release, $table, $kind, $api);

        return $api === Api::Raw && $kind !== WriteKind::Statements ? $write->styled($this->style($kind)) : $write;
    }

    private function unstyledWrite(Release $release, Table $table, WriteKind $kind, Api $api): Write
    {
        return match ($kind) {
            WriteKind::Insert, WriteKind::InsertOrIgnore => new Write($api, $kind, $table->name, [], $this->rows($release, $table, false)),
            WriteKind::InsertGetId => new Write($api, $kind, $table->name, [], [$this->row($release, $table, false)]),
            WriteKind::Upsert, WriteKind::Replace => new Write($api, $kind, $table->name, [], $this->rows($release, $table, true)),
            WriteKind::Merge => new Write($api, $kind, $table->name, [], [$this->row($release, $table, true)]),
            WriteKind::Truncate => new Write($api, $kind, $table->name, [], []),
            WriteKind::Increment, WriteKind::IncrementEach => new Write($api, $kind, $table->name, $this->conditions($release, $table, $api, $this->dice->int(0, 2)), [['amount' => $this->dice->int(1, 2)]]),
            WriteKind::Delete, WriteKind::DeleteModel, WriteKind::InsertUsing, WriteKind::CteDelete, WriteKind::Restore, WriteKind::ForceDelete => $this->conditionalWrite($release, $table, $api, $kind, []),
            WriteKind::Update, WriteKind::Save, WriteKind::Statements => $this->conditionalWrite($release, $table, $api, $kind, $this->assignments($table)),
            WriteKind::UpdateOrInsert => new Write($api, $kind, $table->name, $this->keyConditions($table), [$this->assignments($table)]),
            WriteKind::UpdateJoined => $this->conditionalWrite($release, $table, $api, $kind, $this->assignments($table))->joinedTo($this->dice->pick($this->parentKeys($release, $table)), $this->dice->pick(self::LABELS)),
            WriteKind::DeleteWhereParentExists => $this->conditionalWrite($release, $table, $api, $kind, [])->joinedTo($this->dice->pick($this->parentKeys($release, $table)), $this->dice->pick(self::LABELS)),
        };
    }

    /**
     * The projections of the table, which every database but SQLite lets writes through.
     *
     * @return list<View>
     */
    private function writableViews(Release $release, Table $table): array
    {
        return $this->driver === Driver::Sqlite ? [] : array_values(array_filter(
            $release->schema->views,
            static fn (View $view): bool => $view->kind === ViewKind::Projection && $view->source === $table->name,
        ));
    }

    /**
     * An update of labels or a delete through the view, of rows it pins by the table's key.
     */
    private function writeThroughView(Release $release, View $view): Write
    {
        $table = $release->schema->table($view->source);
        $conditions = $this->dice->chance(80) ? [$this->condition($table->column($table->primaryKey[0]))] : [];

        return $this->dice->chance(70)
            ? new Write(Api::Builder, WriteKind::UpdateView, $view->name, $conditions, [['label' => $this->dice->pick(self::LABELS)]])
            : new Write(Api::Builder, WriteKind::DeleteView, $view->name, $conditions, []);
    }

    /**
     * A spelling of a hand-written statement the database accepts: quoting, keyword case,
     * comments and whitespace, and `RETURNING` where the database has it for the statement.
     */
    private function style(WriteKind $kind): RawStyle
    {
        $comments = ['', '', "-- note\n", '/* job */ ', ...($this->driver->isMySqlFamily() ? ["# note\n"] : [])];
        $returns = in_array($kind, [WriteKind::Insert, WriteKind::Update, WriteKind::Delete], true)
            && ($this->driver === Driver::PgSql || $this->driver === Driver::Sqlite);

        return new RawStyle(
            $this->dice->pick(Quoting::supportedBy($this->driver)),
            $this->dice->pick(KeywordCase::cases()),
            $this->dice->pick($comments),
            $this->dice->pick(['', '', ';', ' -- done', "\n"]),
            $this->dice->chance(30),
            $returns && $this->dice->chance(30),
        );
    }

    /**
     * The foreign keys of the table to its parents.
     *
     * @return list<string>
     */
    private function parentKeys(Release $release, Table $table): array
    {
        return array_map(static fn (ForeignKey $foreignKey): string => $foreignKey->name, $release->schema->foreignKeysOf($table->name));
    }

    /**
     * Conditions pinning every column of the primary key to a value, as `updateOrInsert()` takes them.
     *
     * @return list<Condition>
     */
    private function keyConditions(Table $table): array
    {
        return array_map(
            fn (string $column): Condition => new Condition($column, Operator::Equals, [$this->value($table->column($column), true) ?? $table->column($column)->kind->domain()[0]]),
            $table->primaryKey,
        );
    }

    /**
     * Inserts filling each table with rows of the first keys of its domain, which later reads and
     * writes meet, leaving the others for later inserts. Generated keys are left to the database,
     * which assigns them in that order to the fresh tables.
     *
     * @return list<Write>
     */
    public function seed(Release $release): array
    {
        $writes = [];
        $seeded = [];
        foreach ($this->seedOrder($release->schema) as $table) {
            $keys = array_slice($table->column($table->primaryKey[0])->kind->domain(), 0, self::SEEDED_KEYS);

            foreach ($keys as $index => $key) {
                $writes[] = new Write(Api::Builder, WriteKind::InsertOrIgnore, $table->name, [], [$this->seedRow($release, $table, $key, $seeded, array_slice($keys, 0, $index))]);
            }

            $seeded[$table->name] = true;
        }

        return $writes;
    }

    /**
     * The data tables, each after the tables it references where foreign key cycles allow it,
     * and always after those its rows must reference.
     *
     * @return list<Table>
     */
    private function seedOrder(Schema $schema): array
    {
        $remaining = array_filter($schema->tables, static fn (Table $table): bool => $table->name !== SchemaGenerator::AUDIT);
        $order = [];

        while ($remaining !== []) {
            $ready = static fn (bool $onlyRequired): array => array_filter(
                $remaining,
                static fn (Table $table): bool => array_filter(
                    $schema->foreignKeysOf($table->name),
                    static fn (ForeignKey $foreignKey): bool => $foreignKey->parent !== $table->name && isset($remaining[$foreignKey->parent])
                        && (! $onlyRequired || SchemaGenerator::requires($schema, $table->name, $foreignKey->parent)),
                ) === [],
            );
            $next = $ready(false) ?: $ready(true) ?: throw new \LogicException('Every row of these tables must reference another of them: '.implode(', ', array_keys($remaining)));
            $table = reset($next);
            $order[] = $table;
            unset($remaining[$table->name]);
        }

        return $order;
    }

    /**
     * A row with the key value, the identifier it implies, and foreign keys referencing rows
     * seeded before it: always when the key must reference a row, mostly when it may.
     *
     * @param  array<string, true>  $seeded  the tables seeded before
     * @param  list<int|string>  $earlierKeys  the keys of the table's rows seeded before
     * @return array<string, int|string|null>
     */
    private function seedRow(Release $release, Table $table, int|string $key, array $seeded, array $earlierKeys): array
    {
        $row = [];
        foreach ($table->columns as $column) {
            $seededValue = self::seededValue($table, $column->name, $key);
            $row[$column->name] = match (true) {
                $seededValue !== null => $seededValue,
                $column->nullable => null,
                default => $this->value($column, false),
            };
        }

        if ($table->autoIncrement) {
            unset($row[$table->primaryKey[0]]);
        }

        foreach ($release->schema->foreignKeysOf($table->name) as $foreignKey) {
            $parent = $release->schema->table($foreignKey->parent);
            $parentKeys = $parent->name === $table->name ? $earlierKeys : (isset($seeded[$parent->name]) ? array_slice($parent->column($parent->primaryKey[0])->kind->domain(), 0, self::SEEDED_KEYS) : []);
            $required = ! $table->column($foreignKey->childColumns[0])->nullable;

            if ($parentKeys === [] || (! $required && ! $this->dice->chance(70))) {
                continue;
            }

            $parentKey = $this->dice->pick($parentKeys);
            foreach ($foreignKey->childColumns as $index => $column) {
                $row[$column] = self::seededValue($parent, $foreignKey->parentColumns[$index], $parentKey);
            }
        }

        return $row;
    }

    /**
     * The value the row seeded with the key has in a key column: the key itself, the identifier
     * it implies, or for the second column of a composite key the key spelled for that column;
     * null for other columns.
     */
    private static function seededValue(Table $table, string $column, int|string $key): int|string|null
    {
        $keyKind = $table->column($table->primaryKey[0])->kind;

        return match (true) {
            $column === $table->primaryKey[0] => $key,
            $column === 'ref' && in_array('ref', $table->unique, true) => Identifier::of($key, $keyKind),
            in_array($column, $table->primaryKey, true) => $table->column($column)->kind === ValueKind::Text ? Identifier::of($key, $keyKind) : $key,
            default => null,
        };
    }

    /**
     * Mostly gives the row's foreign keys the values of a parent the seeding inserted, as rows
     * referencing existing records do, and sometimes leaves them to chance.
     *
     * @param  array<string, int|string|null>  $row
     * @return array<string, int|string|null>
     */
    private function withExistingParents(Release $release, Table $table, array $row): array
    {
        foreach ($release->schema->foreignKeysOf($table->name) as $foreignKey) {
            if (! $this->dice->chance(70)) {
                continue;
            }

            $parent = $release->schema->table($foreignKey->parent);
            $key = $this->dice->pick($parent->column($parent->primaryKey[0])->kind->domain());

            foreach ($foreignKey->childColumns as $index => $column) {
                if (array_key_exists($column, $row)) {
                    $row[$column] = self::seededValue($parent, $foreignKey->parentColumns[$index], $key) ?? $row[$column];
                }
            }
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
    private function rows(Release $release, Table $table, bool $withKey): array
    {
        $first = $this->row($release, $table, $withKey);
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
    private function row(Release $release, Table $table, bool $withKey): array
    {
        $row = [];
        foreach ($table->columns as $column) {
            $isKey = in_array($column->name, $table->primaryKey, true);

            if ($isKey && $table->autoIncrement && ! $withKey && $this->dice->chance(90)) {
                continue;
            }

            if (! $isKey && ($column->nullable || $column->default !== null) && $this->dice->chance(25)) {
                continue;
            }

            $row[$column->name] = $this->value($column, $isKey);
        }

        return $this->withExistingParents($release, $table, $this->withIdentifierOfKey($table, $row));
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

        if ($column->nullable && ! $isKey && $this->dice->chance($column->kind === ValueKind::Timestamp ? 70 : 20)) {
            return null;
        }

        return $this->dice->pick($column->kind->domain());
    }
}

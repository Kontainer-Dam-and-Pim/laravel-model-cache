<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema;

use Kontainer\ModelCache\Tests\Fuzz\Rolling\Dice;

/**
 * Generates the schema an application starts with and the migrations adding to it for later releases:
 * tables keyed by generated integers, text codes or two columns, foreign keys between them with
 * every referential action the database supports, including keys referencing their own table,
 * cycles and keys referencing a unique column, views over tables, keys and views, and triggers
 * writing other tables.
 */
final readonly class SchemaGenerator
{
    public const string AUDIT = 'audit';

    public const string DELETED_AT = 'deleted_at';

    public function __construct(
        private Dice $dice,
        private Driver $driver,
    ) {}

    public function initial(): Schema
    {
        $schema = new Schema([self::AUDIT => new Table(
            self::AUDIT,
            [new Column('id', ValueKind::Integer, false), new Column('note', ValueKind::Text, false, 'a'), new Column('ref', ValueKind::Integer, true)],
            ['id'],
            true,
        )]);

        foreach (range(1, $this->dice->int(3, 5)) as $_) {
            $schema = $schema->withTable($this->table($this->nextName('t', array_keys($schema->tables))));
        }

        foreach ($this->dataTables($schema) as $table) {
            foreach (range(1, $this->dice->int(0, 2)) as $_) {
                $schema = $this->withForeignKey($schema, $table->name, false);
            }
        }

        foreach (range(1, $this->dice->int(0, 3)) as $_) {
            $schema = $this->withView($schema);
        }

        foreach (range(1, $this->dice->int(0, 3)) as $_) {
            $schema = $this->withTrigger($schema);
        }

        return $schema;
    }

    /**
     * The schema after a migration that only adds: what releases written against the previous
     * schema keep working with.
     */
    public function expand(Schema $schema): Schema
    {
        foreach (range(1, $this->dice->int(1, 3)) as $_) {
            $schema = match ($this->dice->weighted(['table' => 2, 'column' => 3, 'unique' => 2, 'key' => 3, 'rules' => 2, 'view' => 2, 'trigger' => 2, 'softDeletes' => 2, ...($this->driver === Driver::PgSql ? ['inheritance' => 2] : [])])) {
                'table' => $this->withForeignKey($schema->withTable($table = $this->table($this->nextName('t', array_keys($schema->tables)))), $table->name, false),
                'column' => $this->withColumn($schema),
                'unique' => $this->withUniqueColumn($schema),
                'key' => $this->withForeignKey($schema, $this->dice->pick($this->dataTables($schema))->name, true),
                'rules' => $this->withChangedRules($schema),
                'view' => $this->withView($schema),
                'trigger' => $this->withTrigger($schema),
                'softDeletes' => $this->withSoftDeletes($schema),
                'inheritance' => $this->withInheritingTable($schema),
            };
        }

        return $schema;
    }

    private function table(string $name): Table
    {
        $payload = [new Column('label', ValueKind::Text, false, 'a'), new Column('amount', ValueKind::Integer, true)];
        $ref = $this->dice->chance(30) ? [new Column('ref', ValueKind::Text, true)] : [];
        $unique = $ref === [] ? [] : ['ref'];

        return match ($this->dice->weighted(['generated' => 6, 'code' => 2, 'composite' => 2])) {
            'generated' => new Table($name, [new Column('id', ValueKind::Integer, false), ...$payload, ...$ref], ['id'], true, $unique),
            'code' => new Table($name, [new Column('code', ValueKind::Text, false), ...$payload, ...$ref], ['code'], false, $unique),
            'composite' => new Table($name, [new Column('a', ValueKind::Integer, false), new Column('b', ValueKind::Text, false), ...$payload, ...$ref], ['a', 'b'], false, $unique),
        };
    }

    /**
     * Adds a foreign key from new columns of the child to the primary key or a unique column of
     * a random table, which may be the child itself. Columns added to a table that may hold rows
     * are nullable without a default, as a migration must add them for its rows to satisfy the key,
     * and so are columns of a key that would close a cycle of keys every row must satisfy, which no
     * row could be inserted into.
     */
    private function withForeignKey(Schema $schema, string $child, bool $holdsRows): Schema
    {
        $parent = $this->dice->pick($this->dataTables($schema));
        $parentColumns = $parent->unique !== [] && $this->dice->chance(30) ? [$this->dice->pick($parent->unique)] : $parent->primaryKey;
        $parentNullable = array_filter($parentColumns, static fn (string $column): bool => $parent->column($column)->nullable) !== [];
        $name = $this->nextName('fk', [...array_keys($schema->foreignKeys), ...self::foreignKeyNamesOfColumns($schema)]);
        $nullable = $holdsRows || self::requires($schema, $parent->name, $child) || $this->dice->chance(60);
        $setsDefault = ! $holdsRows && $this->dice->chance(20);
        $allowed = $this->allowedRules($parentNullable, $nullable, $setsDefault);
        $onDelete = $this->dice->pick($allowed);
        $onUpdate = $this->dice->pick($allowed);
        $table = $schema->table($child);
        $childColumns = [];

        foreach ($parentColumns as $parentColumn) {
            $kind = $parent->column($parentColumn)->kind;
            $column = new Column("{$name}_{$parentColumn}", $kind, $nullable, $setsDefault ? $kind->domain()[0] : null);
            $table = $table->withColumn($column);
            $childColumns[] = $column->name;
        }

        $schema = $schema->withTable($table);

        return $schema->withForeignKey(new ForeignKey($name, $child, $childColumns, $parent->name, $parentColumns, $onDelete, $onUpdate));
    }

    /**
     * Whether every row of the table must reference a row of the other, through foreign keys
     * without nullable columns, directly or through tables in between. A table requires itself.
     */
    public static function requires(Schema $schema, string $table, string $other): bool
    {
        $reached = [$table => true];
        $pending = [$table];

        while ($pending !== []) {
            $current = array_pop($pending);

            if ($current === $other) {
                return true;
            }

            foreach ($schema->foreignKeysOf($current) as $foreignKey) {
                if (! isset($reached[$foreignKey->parent]) && ! $schema->table($current)->column($foreignKey->childColumns[0])->nullable) {
                    $reached[$foreignKey->parent] = true;
                    $pending[] = $foreignKey->parent;
                }
            }
        }

        return false;
    }

    /**
     * The names of foreign keys whose columns a table still has, from `<name>_<column>`, which a
     * new key must not reuse: the old values in them need not satisfy it.
     *
     * @return list<string>
     */
    private static function foreignKeyNamesOfColumns(Schema $schema): array
    {
        $names = [];
        foreach ($schema->tables as $table) {
            foreach ($table->columnNames() as $column) {
                if (preg_match('/^(fk\d+)_/', $column, $match) === 1) {
                    $names[] = $match[1];
                }
            }
        }

        return $names;
    }

    /**
     * The rules a foreign key from child columns of the given shape to the parent's may declare.
     *
     * @return non-empty-list<Rule>
     */
    private function allowedRules(bool $parentNullable, bool $childNullable, bool $childHasDefault): array
    {
        $cascadeNeedsNullable = $parentNullable && $this->driver->cascadesUpdatesOnlyIntoNullableColumnsFromNullableOnes();

        return array_values(array_filter(
            Rule::supportedBy($this->driver),
            static fn (Rule $rule): bool => ($childNullable || ! $rule->needsNullableColumns())
                && ($childHasDefault || $rule !== Rule::SetDefault)
                && ($childNullable || ! $cascadeNeedsNullable || $rule !== Rule::Cascade),
        ));
    }

    private function withColumn(Schema $schema): Schema
    {
        $table = $this->dice->pick($this->dataTables($schema));
        $name = $this->nextName('x', $table->columnNames());

        return $schema->withTable($table->withColumn(new Column($name, $this->dice->pick([ValueKind::Integer, ValueKind::Text]), true)));
    }

    /**
     * Adds a PostgreSQL table inheriting a data table's columns, whose rows reads of that table
     * include and writes to it reach.
     */
    private function withInheritingTable(Schema $schema): Schema
    {
        $parent = $this->dice->pick($this->dataTables($schema));

        return $schema->withTable(new Table($this->nextName('t', array_keys($schema->tables)), $parent->columns, $parent->primaryKey, $parent->autoIncrement, [], $parent->name));
    }

    /**
     * Adds the column soft deletes mark rows deleted in, which the next release's model uses.
     */
    private function withSoftDeletes(Schema $schema): Schema
    {
        $candidates = array_values(array_filter($this->dataTables($schema), static fn (Table $table): bool => ! $table->hasColumn(self::DELETED_AT)));

        return $candidates === [] ? $this->withColumn($schema) : $schema->withTable($this->dice->pick($candidates)->withColumn(new Column(self::DELETED_AT, ValueKind::Timestamp, true)));
    }

    /**
     * Adds a unique text column, as a release introducing public identifiers next to generated
     * keys does.
     */
    private function withUniqueColumn(Schema $schema): Schema
    {
        $candidates = array_values(array_filter($this->dataTables($schema), static fn (Table $table): bool => ! $table->hasColumn('ref')));

        return $candidates === [] ? $this->withColumn($schema) : $schema->withTable($this->dice->pick($candidates)->withColumn(new Column('ref', ValueKind::Text, true), true));
    }

    private function withChangedRules(Schema $schema): Schema
    {
        if ($schema->foreignKeys === []) {
            return $this->withForeignKey($schema, $this->dice->pick($this->dataTables($schema))->name, true);
        }

        $foreignKey = $this->dice->pick($schema->foreignKeys);
        $child = $schema->table($foreignKey->child)->column($foreignKey->childColumns[0]);
        $parent = $schema->table($foreignKey->parent);
        $parentNullable = array_filter($foreignKey->parentColumns, static fn (string $column): bool => $parent->column($column)->nullable) !== [];
        $allowed = $this->allowedRules($parentNullable, $child->nullable, $child->default !== null);

        return $schema->withoutForeignKey($foreignKey->name)->withForeignKey($foreignKey->withRules($this->dice->pick($allowed), $this->dice->pick($allowed)));
    }

    private function withView(Schema $schema): Schema
    {
        $name = $this->nextName('v', array_keys($schema->views));
        $kinds = [ViewKind::Projection, ...($schema->foreignKeys === [] ? [] : [ViewKind::ParentJoin, ViewKind::ChildCount]), ...($schema->views === [] ? [] : [ViewKind::OverView])];

        $view = match ($this->dice->pick($kinds)) {
            ViewKind::Projection => (static fn (Table $table): View => new View($name, ViewKind::Projection, $table->name, [...$table->primaryKey, 'label']))($this->dice->pick($this->dataTables($schema))),
            ViewKind::ParentJoin => (static fn (ForeignKey $key): View => new View($name, ViewKind::ParentJoin, $key->name, [...array_map(static fn (string $column): string => "c_{$column}", $schema->table($key->child)->primaryKey), 'parent_label']))($this->dice->pick($schema->foreignKeys)),
            ViewKind::ChildCount => (static fn (ForeignKey $key): View => new View($name, ViewKind::ChildCount, $key->name, [...$key->childColumns, 'n']))($this->dice->pick($schema->foreignKeys)),
            ViewKind::OverView => (static fn (View $source): View => new View($name, ViewKind::OverView, $source->name, $source->columns))($this->dice->pick($schema->views)),
        };

        return $schema->withView($view);
    }

    private function withTrigger(Schema $schema): Schema
    {
        $table = $this->dice->pick($this->dataTables($schema));
        $event = $this->dice->pick(TriggerEvent::cases());
        $perStatement = $this->driver->supportsStatementTriggers() && $this->dice->chance(25);
        $updateOf = $event === TriggerEvent::Update && $this->driver->supportsUpdateOfTriggers() && $this->dice->chance(50)
            ? $this->dice->some(array_values(array_diff($table->columnNames(), $table->primaryKey)), 2)
            : [];
        $action = $this->dice->pick($perStatement ? [TriggerAction::InsertAudit, TriggerAction::UpdateTarget] : TriggerAction::cases());
        $others = array_values(array_filter($this->dataTables($schema), fn (Table $other): bool => $other->name !== $table->name || ! $this->driver->isMySqlFamily()));

        if ($action !== TriggerAction::InsertAudit && $others === []) {
            $action = TriggerAction::InsertAudit;
        }

        $target = $action === TriggerAction::InsertAudit ? $schema->table(self::AUDIT) : $this->dice->pick($others);
        $match = $perStatement ? null : $this->match($table, $target, $action);

        if ($action === TriggerAction::DeleteTarget && $match === null) {
            [$action, $target, $match] = [TriggerAction::InsertAudit, $schema->table(self::AUDIT), null];
        }

        return $schema->withTrigger(new Trigger($this->nextName('g', array_keys($schema->triggers)), $table->name, $event, $updateOf, $perStatement, $action, $target->name, $match));
    }

    /**
     * A column of the target compared with, or for the audit table set to, a column of the same
     * kind of the changed row; null when no such pair exists.
     *
     * @return array{target: string, source: string}|null
     */
    private function match(Table $table, Table $target, TriggerAction $action): ?array
    {
        $targetColumns = $action === TriggerAction::InsertAudit ? ['ref'] : $target->columnNames();
        $pairs = [];

        foreach ($targetColumns as $targetColumn) {
            foreach ($table->columns as $source) {
                if ($source->kind === $target->column($targetColumn)->kind) {
                    $pairs[] = ['target' => $targetColumn, 'source' => $source->name];
                }
            }
        }

        return $pairs === [] ? null : $this->dice->pick($pairs);
    }

    /**
     * @return list<Table>
     */
    private function dataTables(Schema $schema): array
    {
        return array_values(array_filter($schema->tables, static fn (Table $table): bool => $table->name !== self::AUDIT && $table->inherits === null));
    }

    /**
     * @param  list<string>  $taken
     */
    private function nextName(string $prefix, array $taken): string
    {
        $number = 1;

        while (in_array("{$prefix}{$number}", $taken, true)) {
            $number++;
        }

        return "{$prefix}{$number}";
    }
}

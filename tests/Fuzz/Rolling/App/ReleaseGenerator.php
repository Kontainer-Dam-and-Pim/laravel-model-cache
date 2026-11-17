<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\App;

use Kontainer\ModelCache\ExpirationPolicy;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Dice;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\ForeignKey;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\Schema;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\SchemaGenerator;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\Table;

/**
 * Generates the releases of the application across deploys. A release is written against the
 * schema its deploy migrated to, minus what it stopped using, which the contract migration after
 * the deploy drops. Its models may call another column their key than the last release did, such
 * as a public identifier added next to a generated key, and its cache settings may differ.
 */
final readonly class ReleaseGenerator
{
    public function __construct(private Dice $dice) {}

    public function initial(Schema $schema): Release
    {
        return new Release(0, $schema, $this->models($schema, null), [
            'consider_rows' => ! $this->dice->chance(10),
            'scope' => $this->dice->chance(15) ? 'models' : 'all',
            'exclude_tables' => $this->dice->chance(15) ? [$this->dice->pick(array_keys($schema->tables))] : [],
            'expiration_time' => $this->expirationTime(),
        ]);
    }

    /**
     * The release deployed after the database was migrated to the given schema.
     */
    public function next(Release $previous, Schema $database): Release
    {
        $schema = $this->withoutUnused($database);
        $config = $previous->config;

        if ($this->dice->chance(20)) {
            $config['consider_rows'] = ! $config['consider_rows'];
        }

        if ($this->dice->chance(20)) {
            $config['scope'] = $config['scope'] === 'all' ? 'models' : 'all';
        }

        if ($this->dice->chance(20)) {
            $config['exclude_tables'] = $this->dice->some(array_keys($schema->tables), 1);
        }

        if ($this->dice->chance(20)) {
            $config['expiration_time'] = $this->expirationTime();
        }

        return new Release($previous->number + 1, $schema, $this->models($schema, $previous), $config);
    }

    /**
     * Mostly the default, sometimes no expiration at all.
     */
    private function expirationTime(): int
    {
        return $this->dice->chance(15) ? 0 : ExpirationPolicy::DEFAULT_SECONDS;
    }

    /**
     * The schema minus some of what the release stops using: a column of its data no key involves,
     * a view, a trigger or a foreign key.
     */
    private function withoutUnused(Schema $schema): Schema
    {
        $columns = [];
        foreach ($schema->tables as $table) {
            if ($table->name === SchemaGenerator::AUDIT || $table->inherits !== null) {
                continue;
            }

            foreach ($this->droppableColumns($schema, $table) as $column) {
                $columns[] = [$table->name, $column];
            }
        }

        foreach ($this->dice->some($columns, 1) as [$table, $column]) {
            $schema = $schema->withoutColumn($table, $column);
        }

        foreach ($this->dice->some(array_keys($schema->views), 1) as $view) {
            $schema = $schema->withoutView($view);
        }

        foreach ($this->dice->some(array_keys($schema->triggers), 1) as $trigger) {
            $schema = $schema->withoutTrigger($trigger);
        }

        foreach ($this->dice->some(array_keys($schema->foreignKeys), 1) as $foreignKey) {
            $schema = $schema->withoutForeignKey($foreignKey);
        }

        return $schema;
    }

    /**
     * @return list<string>
     */
    private function droppableColumns(Schema $schema, Table $table): array
    {
        $keyed = [...$table->primaryKey, ...$table->unique, 'label', SchemaGenerator::DELETED_AT];

        foreach ($schema->foreignKeys as $foreignKey) {
            if ($foreignKey->child === $table->name) {
                array_push($keyed, ...$foreignKey->childColumns);
            }

            if ($foreignKey->parent === $table->name) {
                array_push($keyed, ...$foreignKey->parentColumns);
            }
        }

        return array_values(array_diff($table->columnNames(), $keyed));
    }

    /**
     * @return array<string, ModelDefinition>
     */
    private function models(Schema $schema, ?Release $previous): array
    {
        $relations = [];
        foreach ($schema->foreignKeys as $foreignKey) {
            if (! $foreignKey->isSingleColumn()) {
                continue;
            }

            $relations[$foreignKey->child][] = self::belongsTo($foreignKey);
            $relations[$foreignKey->parent][] = self::hasMany($foreignKey);
        }

        $models = [];
        foreach ($schema->tables as $table) {
            $keyName = $this->keyName($table, $previous?->models[$table->name] ?? null);
            $models[$table->name] = new ModelDefinition(
                $table->name,
                $keyName,
                $table->column($keyName)->kind,
                $table->autoIncrement && $table->primaryKey === [$keyName],
                $relations[$table->name] ?? [],
                $table->hasColumn(SchemaGenerator::DELETED_AT) && (($previous?->models[$table->name]->softDeletes ?? false) || $this->dice->chance(70)),
            );
        }

        return $models;
    }

    /**
     * Mostly the column the previous release called the key. A release switches to a unique
     * column, as one introducing public identifiers next to generated keys does, most often right
     * after the deploy adding it, and back now and then. A composite key's model calls its first
     * column the key, as applications without composite key support do.
     */
    private function keyName(Table $table, ?ModelDefinition $previous): string
    {
        $primary = $table->primaryKey[0];
        $unique = array_values(array_diff($table->unique, [$primary]));

        if ($unique === []) {
            return $primary;
        }

        if ($previous !== null && $previous->keyName === $primary) {
            return $this->dice->chance(60) ? $this->dice->pick($unique) : $primary;
        }

        if ($previous !== null && in_array($previous->keyName, $unique, true)) {
            return $this->dice->chance(25) ? $primary : $previous->keyName;
        }

        return $this->dice->chance(40) ? $this->dice->pick($unique) : $primary;
    }

    private static function belongsTo(ForeignKey $foreignKey): Relation
    {
        return new Relation("parentOf{$foreignKey->name}", RelationKind::BelongsTo, $foreignKey->parent, $foreignKey->childColumns[0], $foreignKey->parentColumns[0]);
    }

    private static function hasMany(ForeignKey $foreignKey): Relation
    {
        return new Relation("childrenOf{$foreignKey->name}", RelationKind::HasMany, $foreignKey->child, $foreignKey->childColumns[0], $foreignKey->parentColumns[0]);
    }
}

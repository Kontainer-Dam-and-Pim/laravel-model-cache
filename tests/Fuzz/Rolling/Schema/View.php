<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema;

/**
 * A view over a table, a foreign key or another view, with the columns it exposes.
 */
final readonly class View
{
    /**
     * @param  string  $source  the table of a projection, the foreign key of a join or count, the view of a view
     * @param  non-empty-list<string>  $columns
     */
    public function __construct(
        public string $name,
        public ViewKind $kind,
        public string $source,
        public array $columns,
    ) {}

    /**
     * Every table column, view and table the definition reads, as table => columns (empty when
     * the whole relation is read).
     *
     * @return array<string, list<string>>
     */
    public function references(Schema $schema): array
    {
        return match ($this->kind) {
            ViewKind::Projection => [$this->source => [...$schema->table($this->source)->primaryKey, 'label']],
            ViewKind::ParentJoin => self::merge(
                [$schema->foreignKey($this->source)->child => [...$schema->table($schema->foreignKey($this->source)->child)->primaryKey, ...$schema->foreignKey($this->source)->childColumns]],
                [$schema->foreignKey($this->source)->parent => [...$schema->foreignKey($this->source)->parentColumns, 'label']],
            ),
            ViewKind::ChildCount => [$schema->foreignKey($this->source)->child => $schema->foreignKey($this->source)->childColumns],
            ViewKind::OverView => [$this->source => []],
        };
    }

    /**
     * @param  array<string, list<string>>  $first
     * @param  array<string, list<string>>  $second
     * @return array<string, list<string>>
     */
    private static function merge(array $first, array $second): array
    {
        foreach ($second as $table => $columns) {
            $first[$table] = array_values(array_unique([...$first[$table] ?? [], ...$columns]));
        }

        return $first;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'kind' => $this->kind->value, 'source' => $this->source, 'columns' => $this->columns];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['name'], ViewKind::from($data['kind']), $data['source'], $data['columns']);
    }
}

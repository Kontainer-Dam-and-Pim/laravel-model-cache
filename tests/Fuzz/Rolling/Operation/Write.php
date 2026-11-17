<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation;

/**
 * A write to a table: the rows an insert or upsert adds, or the values an update assigns to the
 * rows its conditions select.
 */
final readonly class Write
{
    /**
     * @param  list<Condition>  $conditions
     * @param  list<array<string, int|string|null>>  $rows  the rows of an insert or upsert, or the one row of values an update assigns
     * @param  string|null  $foreignKey  the foreign key to the parents a write joined to them filters by
     * @param  string|null  $label  the label of those parents
     */
    public function __construct(
        public Api $api,
        public WriteKind $kind,
        public string $table,
        public array $conditions,
        public array $rows,
        public bool $or = false,
        public ?string $foreignKey = null,
        public ?string $label = null,
    ) {}

    /**
     * The write, filtering its rows by the label of the parent the foreign key references.
     */
    public function joinedTo(string $foreignKey, string $label): self
    {
        return new self($this->api, $this->kind, $this->table, $this->conditions, $this->rows, $this->or, $foreignKey, $label);
    }

    /**
     * @return array<string, int|string|null>
     */
    public function values(): array
    {
        return $this->rows[0] ?? [];
    }

    public function describe(): string
    {
        $where = $this->conditions === [] ? '' : ' where '.implode($this->or ? ' or ' : ' and ', array_map(static fn (Condition $condition): string => $condition->describe(), $this->conditions));

        $parent = $this->foreignKey === null ? '' : " whose parent of {$this->foreignKey} is labelled {$this->label}";

        return "{$this->api->value} {$this->kind->value} {$this->table} ".json_encode($this->rows).$where.$parent;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'api' => $this->api->value,
            'kind' => $this->kind->value,
            'table' => $this->table,
            'conditions' => array_map(static fn (Condition $condition): array => $condition->toArray(), $this->conditions),
            'rows' => $this->rows,
            'or' => $this->or,
            'foreignKey' => $this->foreignKey,
            'label' => $this->label,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Api::from($data['api']),
            WriteKind::from($data['kind']),
            $data['table'],
            array_map(Condition::fromArray(...), $data['conditions']),
            $data['rows'],
            $data['or'],
            $data['foreignKey'],
            $data['label'],
        );
    }
}

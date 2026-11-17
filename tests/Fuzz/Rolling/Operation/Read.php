<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation;

/**
 * A read of a table or view: its conditions, joined by AND or OR, optionally joined to the parent
 * a foreign key references, eager loading a relation, or counted.
 */
final readonly class Read
{
    /**
     * @param  list<Condition>  $conditions
     * @param  string|null  $join  a foreign key of the table, whose parent's label the read adds
     * @param  string|null  $with  a relation of the release's model to eager load
     */
    public function __construct(
        public Api $api,
        public string $relation,
        public array $conditions,
        public bool $or = false,
        public ?string $join = null,
        public ?string $with = null,
        public bool $count = false,
    ) {}

    public function describe(): string
    {
        $where = $this->conditions === [] ? '' : ' where '.implode($this->or ? ' or ' : ' and ', array_map(static fn (Condition $condition): string => $condition->describe(), $this->conditions));

        return sprintf(
            '%s %s %s%s%s%s',
            $this->api->value,
            $this->count ? 'count' : 'read',
            $this->relation,
            $this->join === null ? '' : " join parent of {$this->join}",
            $this->with === null ? '' : " with {$this->with}",
            $where,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'api' => $this->api->value,
            'relation' => $this->relation,
            'conditions' => array_map(static fn (Condition $condition): array => $condition->toArray(), $this->conditions),
            'or' => $this->or,
            'join' => $this->join,
            'with' => $this->with,
            'count' => $this->count,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Api::from($data['api']), $data['relation'], array_map(Condition::fromArray(...), $data['conditions']), $data['or'], $data['join'], $data['with'], $data['count']);
    }
}

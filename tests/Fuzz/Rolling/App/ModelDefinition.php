<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\App;

use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\ValueKind;

/**
 * An Eloquent model of one release: the table it maps, the column it calls its key, which need
 * not be the table's primary key, and its relations.
 */
final readonly class ModelDefinition
{
    /**
     * @param  list<Relation>  $relations
     */
    public function __construct(
        public string $table,
        public string $keyName,
        public ValueKind $keyKind,
        public bool $incrementing,
        public array $relations,
        public bool $softDeletes = false,
    ) {}

    public function className(int $release): string
    {
        return 'Kontainer\\ModelCache\\Tests\\Fuzz\\Rolling\\Generated\\Release'.$release.'\\'.self::classBaseName($this->table);
    }

    public static function classBaseName(string $table): string
    {
        return 'Model'.ucfirst($table);
    }

    public function relation(string $name): Relation
    {
        foreach ($this->relations as $relation) {
            if ($relation->name === $name) {
                return $relation;
            }
        }

        throw new \LogicException("Model of {$this->table} has no relation {$name}");
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'table' => $this->table,
            'keyName' => $this->keyName,
            'keyKind' => $this->keyKind->value,
            'incrementing' => $this->incrementing,
            'relations' => array_map(static fn (Relation $relation): array => $relation->toArray(), $this->relations),
            'softDeletes' => $this->softDeletes,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['table'], $data['keyName'], ValueKind::from($data['keyKind']), $data['incrementing'], array_map(Relation::fromArray(...), $data['relations']), $data['softDeletes']);
    }
}

<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\App;

/**
 * An Eloquent relation a model declares over a single-column foreign key.
 */
final readonly class Relation
{
    public function __construct(
        public string $name,
        public RelationKind $kind,
        public string $related,
        public string $foreignKey,
        public string $ownerKey,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'kind' => $this->kind->value, 'related' => $this->related, 'foreignKey' => $this->foreignKey, 'ownerKey' => $this->ownerKey];
    }

    /**
     * @param  array<string, string>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['name'], RelationKind::from($data['kind']), $data['related'], $data['foreignKey'], $data['ownerKey']);
    }
}

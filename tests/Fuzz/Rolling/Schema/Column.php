<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema;

final readonly class Column
{
    public function __construct(
        public string $name,
        public ValueKind $kind,
        public bool $nullable,
        public int|string|null $default = null,
    ) {}

    /**
     * @return array{name: string, kind: string, nullable: bool, default: int|string|null}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'kind' => $this->kind->value, 'nullable' => $this->nullable, 'default' => $this->default];
    }

    /**
     * @param  array{name: string, kind: string, nullable: bool, default: int|string|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['name'], ValueKind::from($data['kind']), $data['nullable'], $data['default']);
    }
}

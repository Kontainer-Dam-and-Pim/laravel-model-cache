<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema;

final readonly class ForeignKey
{
    /**
     * @param  non-empty-list<string>  $childColumns
     * @param  non-empty-list<string>  $parentColumns
     */
    public function __construct(
        public string $name,
        public string $child,
        public array $childColumns,
        public string $parent,
        public array $parentColumns,
        public Rule $onDelete,
        public Rule $onUpdate,
    ) {}

    public function isSingleColumn(): bool
    {
        return count($this->childColumns) === 1;
    }

    public function withRules(Rule $onDelete, Rule $onUpdate): self
    {
        return new self($this->name, $this->child, $this->childColumns, $this->parent, $this->parentColumns, $onDelete, $onUpdate);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'child' => $this->child,
            'childColumns' => $this->childColumns,
            'parent' => $this->parent,
            'parentColumns' => $this->parentColumns,
            'onDelete' => $this->onDelete->value,
            'onUpdate' => $this->onUpdate->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['name'], $data['child'], $data['childColumns'], $data['parent'], $data['parentColumns'], Rule::from($data['onDelete']), Rule::from($data['onUpdate']));
    }
}

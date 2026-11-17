<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation;

final readonly class Condition
{
    /**
     * @param  list<int|string>  $values  one value for `=` and `>`, any number for `in`, none for `null`
     */
    public function __construct(
        public string $column,
        public Operator $operator,
        public array $values,
    ) {}

    public function describe(): string
    {
        return match ($this->operator) {
            Operator::IsNull => "{$this->column} is null",
            Operator::In => "{$this->column} in (".implode(', ', array_map('json_encode', $this->values)).')',
            default => "{$this->column} {$this->operator->value} ".json_encode($this->values[0]),
        };
    }

    /**
     * @return array{column: string, operator: string, values: list<int|string>}
     */
    public function toArray(): array
    {
        return ['column' => $this->column, 'operator' => $this->operator->value, 'values' => $this->values];
    }

    /**
     * @param  array{column: string, operator: string, values: list<int|string>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['column'], Operator::from($data['operator']), $data['values']);
    }
}

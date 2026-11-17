<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation;

/**
 * A read of a table or view: its conditions, joined by AND or OR, and its shape, with the foreign
 * key, relation or other table the shape needs and the label it filters parents by.
 */
final readonly class Read
{
    /**
     * @param  list<Condition>  $conditions
     * @param  string|null  $subject  the foreign key, model relation or other table of the shape
     * @param  string|null  $label  the label a shape filtering by one compares with
     */
    public function __construct(
        public Api $api,
        public string $relation,
        public array $conditions,
        public bool $or = false,
        public ReadShape $shape = ReadShape::Rows,
        public ?string $subject = null,
        public ?string $label = null,
    ) {}

    public function describe(): string
    {
        $where = $this->conditions === [] ? '' : ' where '.implode($this->or ? ' or ' : ' and ', array_map(static fn (Condition $condition): string => $condition->describe(), $this->conditions));
        $subject = $this->subject === null ? '' : " of {$this->subject}";
        $label = $this->label === null ? '' : " labelled {$this->label}";

        return "{$this->api->value} {$this->shape->value}{$subject}{$label} {$this->relation}{$where}";
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
            'shape' => $this->shape->value,
            'subject' => $this->subject,
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
            $data['relation'],
            array_map(Condition::fromArray(...), $data['conditions']),
            $data['or'],
            ReadShape::from($data['shape']),
            $data['subject'],
            $data['label'],
        );
    }
}

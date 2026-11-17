<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema;

/**
 * An `AFTER` trigger whose body makes one write to a table. A row-level trigger may match the
 * rows it writes to the changed row, through `NEW` on inserts and updates and `OLD` on deletes.
 */
final readonly class Trigger
{
    /**
     * @param  list<string>  $updateOf  the columns an `UPDATE OF` trigger is limited to, empty for any
     * @param  array{target: string, source: string}|null  $match  a target column compared with a column of the changed row
     */
    public function __construct(
        public string $name,
        public string $table,
        public TriggerEvent $event,
        public array $updateOf,
        public bool $perStatement,
        public TriggerAction $action,
        public string $target,
        public ?array $match,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public function references(): array
    {
        $references = [$this->table => [...$this->updateOf, ...($this->match === null ? [] : [$this->match['source']])]];
        $targetColumns = [...($this->action === TriggerAction::InsertAudit ? ['note', 'ref'] : []), ...($this->action === TriggerAction::UpdateTarget ? ['label'] : []), ...($this->match === null ? [] : [$this->match['target']])];
        $references[$this->target] = array_values(array_unique([...$references[$this->target] ?? [], ...$targetColumns]));

        return $references;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'table' => $this->table,
            'event' => $this->event->value,
            'updateOf' => $this->updateOf,
            'perStatement' => $this->perStatement,
            'action' => $this->action->value,
            'target' => $this->target,
            'match' => $this->match,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['name'], $data['table'], TriggerEvent::from($data['event']), $data['updateOf'], $data['perStatement'], TriggerAction::from($data['action']), $data['target'], $data['match']);
    }
}

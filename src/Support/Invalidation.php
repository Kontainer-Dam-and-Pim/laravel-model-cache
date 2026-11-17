<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Support;

use LogicException;

/**
 * Everything one or more writes require to be invalidated: their tags, the tables whose
 * dependents the database may change along with them, whether the whole cache must be
 * retired, and how long reads from lagging replicas must not be stored afterwards.
 *
 * Merging many writes, such as a large import inside one transaction, would collect a tag or a
 * row key per row; past MAX_TAGS they are replaced by the table-wide tags covering them, or by
 * retiring the cache when some tag has no known coverage, so one invalidation stays a bounded
 * script.
 */
final readonly class Invalidation
{
    public const int MAX_TAGS = 1000;

    /**
     * Every class a serialized invalidation holds, for unserializing it with no other class
     * allowed.
     */
    public const array SERIALIZED_CLASSES = [self::class, ColumnKeys::class, RowWrite::class];

    /**
     * @param  array<string, true>  $tags
     * @param  array<string, true>|null  $coverage  table-wide tags invalidating everything $tags does; null when unknown
     * @param  array<string, array<string, true>>  $cascadeSources  database => `Write::dependentsFields()` of each written table
     * @param  array<string, string>  $connections  database => name of a connection to it
     * @param  array<string, array<string, array{fallback: string, keys: ColumnKeys}>>  $keyedSources  database => `Write::keyedDependentsFields()` by field
     * @param  array<string, RowWrite>  $rowWrites  the rows each written table changed, by database and table
     */
    private function __construct(
        private array $tags,
        private ?array $coverage,
        private array $cascadeSources,
        private array $connections,
        public ?RetireReason $retires,
        public int $replicaLagSeconds,
        private bool $collapsed = false,
        private array $keyedSources = [],
        private array $rowWrites = [],
    ) {}

    public static function none(): self
    {
        return new self([], [], [], [], null, 0);
    }

    /**
     * Retires the whole cache, for the reason the metrics count it under.
     */
    public static function everything(RetireReason $reason = RetireReason::Fallback): self
    {
        return new self([], [], [], [], $reason, 0);
    }

    /**
     * @param  array<string>  $tags
     */
    public static function of(array $tags, int $replicaLagSeconds = 0): self
    {
        return new self(array_fill_keys($tags, true), $tags === [] ? [] : null, [], [], null, $replicaLagSeconds);
    }

    /**
     * @param  array<string>  $tags
     * @param  array<string>  $tableWideTags  tags of the written tables that invalidate everything $tags and $rowWrites do
     * @param  list<RowWrite>  $rowWrites
     */
    public static function ofWrite(array $tags, array $tableWideTags, int $replicaLagSeconds, array $rowWrites = []): self
    {
        $byTable = [];
        foreach ($rowWrites as $rowWrite) {
            $id = self::rowWriteId($rowWrite);
            $byTable[$id] = isset($byTable[$id]) ? $byTable[$id]->merge($rowWrite) : $rowWrite;
        }

        return (new self(array_fill_keys($tags, true), array_fill_keys($tableWideTags, true), [], [], null, $replicaLagSeconds, false, [], $byTable))->bounded();
    }

    private static function rowWriteId(RowWrite $rowWrite): string
    {
        return $rowWrite->database."\0".$rowWrite->table;
    }

    /**
     * Also invalidates the tables the database changes along with the written tables.
     *
     * @param  array<string>  $tables
     */
    public function withChangesOf(string $database, string $connection, Write $write, array $tables): self
    {
        if ($tables === []) {
            return $this;
        }

        $fields = [];
        $keyed = [];
        foreach ($tables as $table) {
            $fields += array_fill_keys($write->dependentsFields($table), true);

            foreach ($write->keyedDependentsFields($table) as $source) {
                $keyed[$source['field']] = ['fallback' => $source['fallback'], 'keys' => $source['keys']];
            }
        }

        return $this->merge(new self([], [], [$database => $fields], [$database => $connection], null, 0, false, $keyed === [] ? [] : [$database => $keyed]));
    }

    public function merge(self $other): self
    {
        $cascadeSources = $this->cascadeSources;
        foreach ($other->cascadeSources as $database => $fields) {
            $cascadeSources[$database] = ($cascadeSources[$database] ?? []) + $fields;
        }

        $keyedSources = $this->keyedSources;
        foreach ($other->keyedSources as $database => $sources) {
            foreach ($sources as $field => $source) {
                $known = $keyedSources[$database][$field]['keys'] ?? null;
                $keys = $known === null ? $source['keys'] : $known->merge($source['keys']);

                if ($keys->isEmpty()) {
                    unset($keyedSources[$database][$field]);
                    $cascadeSources[$database][$source['fallback']] = true;

                    continue;
                }

                $keyedSources[$database][$field] = ['fallback' => $source['fallback'], 'keys' => $keys];
            }
        }

        $rowWrites = $this->rowWrites;
        foreach ($other->rowWrites as $id => $rowWrite) {
            $rowWrites[$id] = isset($rowWrites[$id]) ? $rowWrites[$id]->merge($rowWrite) : $rowWrite;
        }

        return (new self(
            $this->tags + $other->tags,
            $this->coverage === null || $other->coverage === null ? null : $this->coverage + $other->coverage,
            $cascadeSources,
            $this->connections + $other->connections,
            $this->retires ?? $other->retires,
            max($this->replicaLagSeconds, $other->replicaLagSeconds),
            $this->collapsed || $other->collapsed,
            array_filter($keyedSources),
            $rowWrites,
        ))->bounded();
    }

    public function isEmpty(): bool
    {
        return $this->retires === null && $this->tags === [] && $this->cascadeSources === [] && $this->keyedSources === [] && $this->rowWrites === [];
    }

    public function size(): int
    {
        return count($this->tags) + array_sum(array_map('count', $this->cascadeSources)) + array_sum(array_map('count', $this->keyedSources)) + $this->rowKeyCount();
    }

    private function rowKeyCount(): int
    {
        return array_sum(array_map(static fn (RowWrite $rowWrite): int => 1 + $rowWrite->size(), $this->rowWrites));
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        $tags = array_map('strval', array_keys($this->tags));
        sort($tags, SORT_STRING);

        return $tags;
    }

    /**
     * @return array<string, list<string>>
     */
    public function cascadeSources(): array
    {
        $sources = [];
        foreach ($this->cascadeSources as $database => $fields) {
            $names = array_map('strval', array_keys($fields));
            sort($names, SORT_STRING);
            $sources[(string) $database] = $names;
        }
        ksort($sources, SORT_STRING);

        return $sources;
    }

    /**
     * @return array<string, list<array{field: string, fallback: string, keys: ColumnKeys}>> database => keyed fields, sorted
     */
    public function keyedSources(): array
    {
        $sources = [];
        foreach ($this->keyedSources as $database => $fields) {
            ksort($fields, SORT_STRING);
            foreach ($fields as $field => $source) {
                $sources[(string) $database][] = ['field' => (string) $field, 'fallback' => $source['fallback'], 'keys' => $source['keys']];
            }
        }
        ksort($sources, SORT_STRING);

        return $sources;
    }

    /**
     * @return array<string, list<RowWrite>> database => the rows each written table changed, sorted by table
     */
    public function rowWrites(): array
    {
        $byDatabase = [];
        $ids = array_keys($this->rowWrites);
        sort($ids, SORT_STRING);

        foreach ($ids as $id) {
            $rowWrite = $this->rowWrites[$id];
            $byDatabase[$rowWrite->database][] = $rowWrite;
        }

        ksort($byDatabase, SORT_STRING);

        return $byDatabase;
    }

    /**
     * The follow-up of an invalidation that reached databases whose dependents were not known
     * yet: only their cascades. Its row writes were already applied, to whole tables.
     *
     * @param  list<string>  $databases
     */
    public function onlyCascadesOf(array $databases): self
    {
        $selected = array_flip($databases);

        return new self(
            [],
            [],
            array_intersect_key($this->cascadeSources, $selected),
            array_intersect_key($this->connections, $selected),
            null,
            $this->replicaLagSeconds,
            false,
            array_intersect_key($this->keyedSources, $selected),
        );
    }

    public function connectionOf(string $database): string
    {
        return $this->connections[$database] ?? throw new LogicException('No connection is known for database '.$database);
    }

    private function bounded(): self
    {
        if (! $this->collapsed && count($this->tags) + $this->rowKeyCount() <= self::MAX_TAGS) {
            return $this;
        }

        if ($this->coverage === null || count($this->coverage) > self::MAX_TAGS) {
            return self::everything();
        }

        return new self($this->coverage, $this->coverage, $this->cascadeSources, $this->connections, $this->retires, $this->replicaLagSeconds, true, $this->keyedSources);
    }
}

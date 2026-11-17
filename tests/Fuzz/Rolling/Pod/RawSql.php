<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Pod;

use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Condition;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Operator;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\RawStyle;

/**
 * Spells hand-written SQL in a style: identifiers quoted as it says with the table prefix
 * applied by hand, keywords in its case, and its comments and line breaks around the statement.
 */
final readonly class RawSql
{
    private const array KEYWORDS = [
        'insert', 'into', 'values', 'update', 'set', 'delete', 'from', 'where', 'and', 'or', 'in', 'is', 'null', 'on',
        'conflict', 'do', 'nothing', 'duplicate', 'key', 'replace', 'with', 'as', 'select', 'using', 'join', 'merge',
        'when', 'matched', 'not', 'then', 'returning', 'exists', 'cast',
    ];

    private const array CLAUSES = ['where', 'set', 'from', 'values', 'on', 'using', 'when', 'returning', 'join'];

    public function __construct(
        private RawStyle $style,
        private string $prefix,
    ) {}

    public function table(string $name): string
    {
        return $this->style->quoting->quote($this->prefix.$name);
    }

    public function alias(string $alias): string
    {
        return $this->style->quoting->quote($alias);
    }

    /**
     * @param  string|null  $qualifier  a table or alias as `table()` or `alias()` spells it
     */
    public function column(string $column, ?string $qualifier = null): string
    {
        return ($qualifier === null ? '' : "{$qualifier}.").$this->style->quoting->quote($column);
    }

    /**
     * @param  list<string>  $columns
     */
    public function columns(array $columns, ?string $qualifier = null): string
    {
        return implode(', ', array_map(fn (string $column): string => $this->column($column, $qualifier), $columns));
    }

    /**
     * @param  list<Condition>  $conditions
     * @return array{string, list<int|string>}
     */
    public function conditions(array $conditions, bool $or, ?string $qualifier): array
    {
        $parts = [];
        $bindings = [];

        foreach ($conditions as $condition) {
            $column = $this->column($condition->column, $qualifier);
            $parts[] = match ($condition->operator) {
                Operator::Equals => "{$column} = ?",
                Operator::GreaterThan => "{$column} > ?",
                Operator::In => "{$column} in (".implode(', ', array_fill(0, count($condition->values), '?')).')',
                Operator::IsNull => "{$column} is null",
            };
            array_push($bindings, ...$condition->values);
        }

        return [$parts === [] ? '' : '('.implode($or ? ' or ' : ' and ', $parts).')', $bindings];
    }

    public function returning(string $columns): string
    {
        return $this->style->returning ? " returning {$columns}" : '';
    }

    /**
     * The statement with keywords in the style's case, its line breaks, comments and trailer.
     */
    public function finish(string $sql): string
    {
        $sql = (string) preg_replace_callback('/\b('.implode('|', self::KEYWORDS).')\b/', fn (array $keyword): string => $this->style->keywordCase->apply($keyword[1]), $sql);

        if ($this->style->lineBreaks) {
            $sql = (string) preg_replace('/ (?=(?i:'.implode('|', self::CLAUSES).')\b)/', "\n  ", $sql);
        }

        return $this->style->leadingComment.$sql.$this->style->trailing;
    }
}

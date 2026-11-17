<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation;

/**
 * How a hand-written statement is spelled: quoting, keyword case, the comments and whitespace
 * around it, and whether it returns the rows it wrote.
 */
final readonly class RawStyle
{
    public function __construct(
        public Quoting $quoting,
        public KeywordCase $keywordCase,
        public string $leadingComment,
        public string $trailing,
        public bool $lineBreaks,
        public bool $returning,
    ) {}

    /**
     * Lower-case keywords, identifiers as they are, nothing around the statement.
     */
    public static function plain(): self
    {
        return new self(Quoting::None, KeywordCase::Lower, '', '', false, false);
    }

    public function describe(): string
    {
        return sprintf(
            '%s, %s keywords%s%s%s%s',
            $this->quoting->value,
            $this->keywordCase->value,
            $this->leadingComment === '' ? '' : ', '.json_encode($this->leadingComment).' first',
            $this->trailing === '' ? '' : ', '.json_encode($this->trailing).' last',
            $this->lineBreaks ? ', line breaks' : '',
            $this->returning ? ', returning' : '',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'quoting' => $this->quoting->value,
            'keywordCase' => $this->keywordCase->value,
            'leadingComment' => $this->leadingComment,
            'trailing' => $this->trailing,
            'lineBreaks' => $this->lineBreaks,
            'returning' => $this->returning,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Quoting::from($data['quoting']), KeywordCase::from($data['keywordCase']), $data['leadingComment'], $data['trailing'], $data['lineBreaks'], $data['returning']);
    }
}

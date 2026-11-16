<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Support\Sql;

/**
 * Ported from sqlparser-rs src/dialect/mod.rs (Apache-2.0), commit 8a176d9: the hooks of the `Dialect` trait that
 * the tokenizer calls, and those the table factor parsing of `Parser` calls. Every character argument is one UTF-8 encoded character.
 */
interface Dialect
{
    public function isDelimitedIdentifierStart(string $ch): bool;

    public function isIdentifierStart(string $ch): bool;

    public function isIdentifierPart(string $ch): bool;

    public function isCustomOperatorPart(string $ch): bool;

    public function supportsStringLiteralBackslashEscape(): bool;

    public function supportsNationalStringLiteral(): bool;

    public function supportsLiteralPrefixInQualifiedName(): bool;

    public function ignoresWildcardEscapes(): bool;

    public function supportsUnicodeStringLiteral(): bool;

    public function supportsNumericPrefix(): bool;

    public function supportsNumericLiteralUnderscores(): bool;

    public function supportsNonDecimalIntegerLiterals(): bool;

    public function supportsTripleQuotedString(): bool;

    public function supportsPipeOperator(): bool;

    public function supportsDollarPlaceholder(): bool;

    public function supportsDollarAsMoneyPrefix(): bool;

    public function supportsNestedComments(): bool;

    public function supportsHashComment(): bool;

    public function supportsCopyFromStdinData(): bool;

    public function requiresHashCommentSpace(): bool;

    public function supportsMultilineCommentHints(): bool;

    public function supportsUnterminatedBlockComments(): bool;

    public function supportsStringEscapeConstant(): bool;

    public function requiresSingleLineCommentWhitespace(): bool;

    public function supportsGeometricTypes(): bool;

    public function supportsTildeLikeMatchOperator(): bool;

    public function supportsQuoteDelimitedString(): bool;

    public function isTableFactor(Token $word): bool;

    public function isTableAlias(Token $word, ?Token $next): bool;

    public function isTableFactorAlias(bool $explicit, Token $word, ?Token $next): bool;

    public function supportsTableHints(): bool;

    public function supportsIndexedBy(): bool;

    public function supportsTablePartitions(): bool;

    public function supportsTableInheritanceMarkers(): bool;

    public function supportsParensAroundTableFactor(): bool;

    public function supportsLeftAssociativeJoinsWithoutParens(): bool;

    public function supportsCrossJoinConstraint(): bool;

    public function supportsCommaJoinConstraint(): bool;

    public function supportsTableVersioning(): bool;

    public function supportsObjectNameDoubleDotNotation(): bool;

    public function supportsStringLiteralTableAlias(bool $explicit): bool;

    public function supportsInTableExpression(): bool;

    public function supportsValuesAsTableFactor(): bool;

    public function supportsFromFirstSelect(): bool;
}

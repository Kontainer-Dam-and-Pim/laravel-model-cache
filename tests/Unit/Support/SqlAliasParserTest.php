<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Unit\Support;

use Kontainer\ModelCache\Support\SqlAliasParser;
use PHPUnit\Framework\TestCase;

class SqlAliasParserTest extends TestCase
{
    public function testStripAliasRemovesTrailingAsAlias(): void
    {
        $this->assertSame('engines', SqlAliasParser::stripAlias('engines as e'));
        $this->assertSame('cars', SqlAliasParser::stripAlias('cars'));
    }

    public function testExtractAliasReturnsAliasOrNull(): void
    {
        $this->assertSame('e', SqlAliasParser::extractAlias('engines as e'));
        $this->assertNull(SqlAliasParser::extractAlias('drivers'));
    }

    public function testAliasesAreSplitAsLaravelSplitsThem(): void
    {
        $this->assertSame('engines', SqlAliasParser::stripAlias('engines AS e'));
        $this->assertSame('e', SqlAliasParser::extractAlias('engines   as   e'));
        $this->assertSame('crossroads', SqlAliasParser::stripAlias('crossroads'));
    }
}

<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Unit\Support;

use InvalidArgumentException;
use Kontainer\ModelCache\Redis;
use Kontainer\ModelCache\Support\ConfigValue;
use Kontainer\ModelCache\Tests\TestCase;

final class ConfigValueTest extends TestCase
{
    public function testAnEmptyStringUsesTheDefaultLikeAMissingOne(): void
    {
        config(['model-cache.prefix' => '']);

        $this->assertSame(Redis::DEFAULT_PREFIX.'key', (new Redis)->prefix('key'));
    }

    public function testATableListThatIsNotAListFailsLoudly(): void
    {
        config(['model-cache.exclude_tables' => 'users']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid model-cache.exclude_tables "users", expected a list of strings');

        ConfigValue::strings('model-cache.exclude_tables');
    }
}

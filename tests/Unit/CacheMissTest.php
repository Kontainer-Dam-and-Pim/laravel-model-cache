<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Unit;

use InvalidArgumentException;
use Kontainer\ModelCache\CacheMiss;
use Kontainer\ModelCache\Generation;
use Kontainer\ModelCache\Tests\TestCase;

final class CacheMissTest extends TestCase
{
    public function testKeepsAWellFormedFingerprint(): void
    {
        $fingerprint = str_repeat('0123456789', 4);

        $miss = new CacheMiss(new Generation(str_repeat('ab', 16)), 'key', $fingerprint, 'cars');

        $this->assertSame($fingerprint, $miss->fingerprint);
    }

    public function testRejectsAMalformedFingerprint(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Model Cache fingerprint "ABC", expected 40 lowercase hex characters');

        new CacheMiss(new Generation(str_repeat('ab', 16)), 'key', 'ABC');
    }
}

<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Unit\Support;

use Kontainer\ModelCache\Support\RedisReply;
use Kontainer\ModelCache\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use UnexpectedValueException;

final class RedisReplyTest extends TestCase
{
    public function testAListReplyIsReturnedAsGiven(): void
    {
        $this->assertSame(['generation', 'fingerprint', 'payload'], RedisReply::list(['generation', 'fingerprint', 'payload'], 2, 'lookup reply'));
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function malformedLists(): array
    {
        return [
            'nil' => [null, 'got null'],
            'error' => [false, 'got bool'],
            'too short' => [['generation'], 'got array of 1'],
            'not a list' => [['generation' => 'g', 'fingerprint' => 'f'], 'got array of 2'],
        ];
    }

    #[DataProvider('malformedLists')]
    public function testAMalformedListReplyFailsLoudly(mixed $reply, string $described): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Malformed Model Cache lookup reply: expected a list of at least 2 items, '.$described);

        RedisReply::list($reply, 2, 'lookup reply');
    }

    public function testAStringReplyIsReturnedAsGiven(): void
    {
        $this->assertSame('123', RedisReply::string('123', 'generation'));
    }

    public function testANonStringReplyWhereAStringIsExpectedFailsLoudly(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Malformed Model Cache generation: expected a string, got int');

        RedisReply::string(123, 'generation');
    }

    public function testIntegerRepliesAndIntegerStringsAreIntegers(): void
    {
        $this->assertSame(7, RedisReply::integer(7, 'counter'));
        $this->assertSame(-7, RedisReply::integer('-7', 'counter'));
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function malformedIntegers(): array
    {
        return [
            'nil' => [null, 'got null'],
            'text' => ['seven', 'got string'],
            'fraction' => ['1.5', 'got string'],
            'list' => [[7], 'got array of 1'],
        ];
    }

    #[DataProvider('malformedIntegers')]
    public function testAMalformedIntegerReplyFailsLoudly(mixed $reply, string $described): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Malformed Model Cache counter: expected an integer, '.$described);

        RedisReply::integer($reply, 'counter');
    }
}

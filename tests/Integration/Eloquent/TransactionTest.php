<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Integration\Eloquent;

use Illuminate\Support\Facades\DB;
use Kontainer\ModelCache\Tests\Concerns\MakesTestModels;
use Kontainer\ModelCache\Tests\Database\Models\Car;
use Kontainer\ModelCache\Tests\TestCase;
use PHPUnit\Framework\Assert;

class TransactionTest extends TestCase
{
    use MakesTestModels;

    public function testTransactionCommit(): void
    {
        DB::transaction(function (): void {
            Car::create(['name' => 'tx-commit']);
        });

        Assert::assertSame(1, Car::where('name', 'tx-commit')->count());
    }

    public function testTransactionRollbackOnException(): void
    {
        try {
            DB::transaction(function (): void {
                Car::create(['name' => 'tx-rollback']);
                throw new \RuntimeException('fail');
            });
            Assert::fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            Assert::assertSame('fail', $e->getMessage());
        }

        Assert::assertSame(0, Car::where('name', 'tx-rollback')->count());
    }
}

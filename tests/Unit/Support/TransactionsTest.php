<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Unit\Support;

use Kontainer\ModelCache\Database\MySqlConnection;
use Kontainer\ModelCache\Database\SqlServerConnection;
use Kontainer\ModelCache\Support\Transactions;
use Kontainer\ModelCache\Tests\TestCase;
use PDO;

final class TransactionsTest extends TestCase
{
    public function testTheServerStateDecidesWhereTheDriverReportsIt(): void
    {
        $connection = new MySqlConnection($this->pdo('mysql', inTransaction: false), 'test', '', ['name' => 'mysql', 'driver' => 'mysql']);
        $connection->beginTransaction();

        $this->assertSame(1, $connection->transactionLevel());
        $this->assertFalse(Transactions::isOpen($connection));
    }

    public function testLaravelsLevelCountsWhereTheDriverReportsOnlyWhatPdoBegan(): void
    {
        $connection = new SqlServerConnection($this->pdo('sqlsrv', inTransaction: false), 'test', '', ['name' => 'sqlsrv', 'driver' => 'sqlsrv']);

        $this->assertFalse(Transactions::isOpen($connection));

        $connection->beginTransaction();

        $this->assertTrue(Transactions::isOpen($connection));
    }

    public function testATransactionPdoBeganCountsWhereTheDriverReportsOnlyThat(): void
    {
        $connection = new SqlServerConnection($this->pdo('sqlsrv', inTransaction: true), 'test', '', ['name' => 'sqlsrv', 'driver' => 'sqlsrv']);

        $this->assertTrue(Transactions::isOpen($connection));
    }

    public function testARawBeginCountsWhereTheDriverReportsOnlyWhatPdoBegan(): void
    {
        $connection = new SqlServerConnection($this->pdo('sqlsrv', inTransaction: false), 'test', '', ['name' => 'sqlsrv', 'driver' => 'sqlsrv']);

        $connection->unprepared('BEGIN TRANSACTION');

        $this->assertTrue(Transactions::isOpen($connection));
    }

    private function pdo(string $driver, bool $inTransaction): PDO
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('getAttribute')->willReturnCallback(static fn (int $attribute): mixed => $attribute === PDO::ATTR_DRIVER_NAME ? $driver : null);
        $pdo->method('inTransaction')->willReturn($inTransaction);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('exec')->willReturn(0);

        return $pdo;
    }
}

<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Integration\Database;

use Illuminate\Support\Facades\DB;
use Kontainer\ModelCache\Database\SqlServerConnection;
use Kontainer\ModelCache\Tests\TestCase;
use PDO;

final class SqlServerConnectionTest extends TestCase
{
    private PDO $server;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.connections.sqlsrv_testing', [
            'driver' => 'sqlsrv',
            'host' => 'sqlsrv.invalid',
            'database' => 'test',
            'username' => 'sa',
            'password' => 'secret',
            'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->server->exec('create table cars (id integer primary key, name varchar(255) not null)');
        $this->server->exec("insert into cars (id, name) values (1, 'cached')");

        DB::connection('sqlsrv_testing')->setPdo($this->server)->setReadPdo($this->server);
    }

    public function testSqlServerDriverResolvesToTheCachingConnection(): void
    {
        $this->assertInstanceOf(SqlServerConnection::class, DB::connection('sqlsrv_testing'));
    }

    public function testReadsAreServedFromTheCache(): void
    {
        $this->assertSame(['cached'], $this->carNames());

        $this->server->exec("update cars set name = 'changed behind the cache' where id = 1");

        $this->assertSame(['cached'], $this->carNames());
    }

    public function testReadsGoToTheDatabaseWhileTheCacheIsInactive(): void
    {
        $this->assertSame(['cached'], $this->carNames());
        $this->server->exec("update cars set name = 'changed behind the cache' where id = 1");

        config(['model-cache.active' => false]);

        $this->assertSame(['changed behind the cache'], $this->carNames());
    }

    /**
     * @return list<mixed>
     */
    private function carNames(): array
    {
        return DB::connection('sqlsrv_testing')->table('cars')->where('id', 1)->pluck('name')->all();
    }
}

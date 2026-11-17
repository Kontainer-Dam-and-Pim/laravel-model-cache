<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Integration\Cache;

use Closure;
use Illuminate\Support\Facades\DB;
use Kontainer\ModelCache\Services;
use Kontainer\ModelCache\Tests\Concerns\RecordsRedisCommands;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\App\ModelDefinition;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\App\Relation;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\App\RelationKind;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\App\Release;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Dice;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Api;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Condition;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Operator;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Read;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\ReadShape;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\OperationGenerator;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Pod\Interpreter;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Pod\ModelSource;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\Column;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\Driver;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\ForeignKey;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\Rule;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\Schema;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\SchemaGenerator;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\SchemaWriter;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\Table;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\ValueKind;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\View;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\ViewKind;
use Kontainer\ModelCache\Tests\TestCase;

/**
 * The cost contract for every read shape the fuzzer generates, through each API that has it:
 * repeating a read never reaches the database and costs exactly one Redis round trip per query.
 */
final class RoundTripContractTest extends TestCase
{
    use RecordsRedisCommands;

    private const string KEY = 'fk1';

    public function testRepeatedReadsCostOneRoundTripPerQueryAndNoDatabaseAccess(): void
    {
        $release = self::release();
        $writer = new SchemaWriter(DB::connection());
        $writer->dropEverything();
        $writer->migrate(new Schema([]), $release->schema);

        if (! class_exists((new ModelDefinition('parents', 'id', ValueKind::Integer, true, []))->className($release->number))) {
            eval(ModelSource::of($release));
        }

        $interpreter = new Interpreter($release);
        foreach ((new OperationGenerator(new Dice(1), Driver::of(DB::connection()->getDriverName())))->seed($release) as $write) {
            $interpreter->write($write);
        }

        foreach (self::reads() as $read) {
            Services::cache()->flush();
            $this->loadDependencyMap();
            $run = static fn (): mixed => $interpreter->cachedRead($read);
            $firstRunQueries = $this->databaseQueriesDuring($run);
            $secondRunQueries = 0;

            $commands = $this->recordRedisCommands(function () use ($run, &$secondRunQueries): void {
                $secondRunQueries = $this->databaseQueriesDuring($run);
            });

            $this->assertSame(0, $secondRunQueries, "Repeated read \"{$read->describe()}\" reached the database");
            $this->assertSame(array_fill(0, $firstRunQueries, 'evalsha'), $commands, "Repeated read \"{$read->describe()}\" did not cost one round trip per query");
        }
    }

    /**
     * A read of every shape, through every API that has it.
     *
     * @return list<Read>
     */
    private static function reads(): array
    {
        $byKey = [new Condition('id', Operator::Equals, [2])];
        $reads = [
            new Read(Api::Builder, 'labels', []),
            new Read(Api::Builder, 'children', [], shape: ReadShape::Rows),
            new Read(Api::Raw, 'children', $byKey),
            new Read(Api::Model, 'children', $byKey),
            new Read(Api::Model, 'parents', [], shape: ReadShape::EagerLoad, subject: 'childrenOf'.self::KEY),
        ];

        foreach (ReadShape::cases() as $shape) {
            $reads[] = match ($shape) {
                ReadShape::Rows, ReadShape::Count, ReadShape::Sum, ReadShape::Max => new Read(Api::Builder, 'children', $byKey, shape: $shape),
                ReadShape::JoinParent, ReadShape::ChildrenOfLabelledParents, ReadShape::GroupedChildCounts => new Read(Api::Builder, 'children', [], shape: $shape, subject: self::KEY, label: 'a'),
                ReadShape::ParentsWithChildren, ReadShape::ChildCounts, ReadShape::JoinedChildCounts, ReadShape::MostChildren => new Read(Api::Builder, 'parents', [], shape: $shape, subject: self::KEY),
                ReadShape::RawParentsWithChildren, ReadShape::RawChildCounts => new Read(Api::Raw, 'parents', [], shape: $shape, subject: self::KEY),
                ReadShape::Union => new Read(Api::Builder, 'parents', [], shape: $shape, subject: 'children'),
                ReadShape::EagerLoad => new Read(Api::Model, 'children', [], shape: $shape, subject: 'parentOf'.self::KEY),
                ReadShape::WithCount => new Read(Api::Model, 'parents', [], shape: $shape, subject: 'childrenOf'.self::KEY),
                ReadShape::WhereHas => new Read(Api::Model, 'children', [], shape: $shape, subject: 'parentOf'.self::KEY, label: 'a'),
                ReadShape::WithTrashed, ReadShape::OnlyTrashed => new Read(Api::Model, 'children', [], shape: $shape),
            };
        }

        return $reads;
    }

    private static function release(): Release
    {
        $table = static fn (string $name, Column ...$columns): Table => new Table(
            $name,
            [new Column('id', ValueKind::Integer, false), new Column('label', ValueKind::Text, false, 'a'), new Column('amount', ValueKind::Integer, true), ...$columns],
            ['id'],
            true,
            $name === 'parents' ? ['ref'] : [],
        );
        $schema = new Schema(
            [
                SchemaGenerator::AUDIT => new Table(SchemaGenerator::AUDIT, [new Column('id', ValueKind::Integer, false), new Column('note', ValueKind::Text, false, 'a'), new Column('ref', ValueKind::Integer, true)], ['id'], true),
                'parents' => $table('parents', new Column('ref', ValueKind::Text, true)),
                'children' => $table('children', new Column(self::KEY.'_id', ValueKind::Integer, true), new Column(SchemaGenerator::DELETED_AT, ValueKind::Timestamp, true)),
            ],
            [self::KEY => new ForeignKey(self::KEY, 'children', [self::KEY.'_id'], 'parents', ['id'], Rule::Cascade, Rule::SetNull)],
            ['labels' => new View('labels', ViewKind::Projection, 'parents', ['id', 'label'])],
        );
        $model = static fn (string $table, Relation ...$relations): ModelDefinition => new ModelDefinition($table, 'id', ValueKind::Integer, true, $relations);

        return new Release(0, $schema, [
            SchemaGenerator::AUDIT => $model(SchemaGenerator::AUDIT),
            'parents' => $model('parents', new Relation('childrenOf'.self::KEY, RelationKind::HasMany, 'children', self::KEY.'_id', 'id')),
            'children' => new ModelDefinition('children', 'id', ValueKind::Integer, true, [new Relation('parentOf'.self::KEY, RelationKind::BelongsTo, 'parents', self::KEY.'_id', 'id')], true),
        ], []);
    }

    private function databaseQueriesDuring(Closure $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}

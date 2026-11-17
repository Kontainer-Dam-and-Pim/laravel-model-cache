<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Pod;

use Kontainer\ModelCache\Tests\Fuzz\Rolling\App\ModelDefinition;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\App\RelationKind;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\App\Release;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\ValueKind;

/**
 * The PHP source of a release's Eloquent models, which a pod of the release evaluates: its
 * classes exist in the pod's process only, as a deployed release's code does.
 */
final class ModelSource
{
    public static function of(Release $release): string
    {
        $namespace = 'Kontainer\\ModelCache\\Tests\\Fuzz\\Rolling\\Generated\\Release'.$release->number;
        $classes = array_map(static fn (ModelDefinition $model): string => self::class($model), array_values($release->models));

        return "namespace {$namespace};\n\n".implode("\n", $classes);
    }

    private static function class(ModelDefinition $model): string
    {
        $relations = '';
        foreach ($model->relations as $relation) {
            $method = $relation->kind === RelationKind::BelongsTo ? 'belongsTo' : 'hasMany';
            $type = $relation->kind === RelationKind::BelongsTo ? 'BelongsTo' : 'HasMany';
            $related = ModelDefinition::classBaseName($relation->related);
            $relations .= sprintf(
                "    public function %s(): \\Illuminate\\Database\\Eloquent\\Relations\\%s\n    {\n        return \$this->%s(%s::class, %s, %s);\n    }\n\n",
                $relation->name,
                $type,
                $method,
                $related,
                var_export($relation->foreignKey, true),
                var_export($relation->ownerKey, true),
            );
        }

        return sprintf(
            "final class %s extends \\Illuminate\\Database\\Eloquent\\Model\n{\n    use \\Kontainer\\ModelCache\\Database\\ModelCacheTrait;\n%s\n"
            ."    public \$timestamps = false;\n\n    public \$incrementing = %s;\n\n    protected \$guarded = [];\n\n"
            ."    protected \$table = %s;\n\n    protected \$primaryKey = %s;\n\n    protected \$keyType = %s;\n\n%s}\n",
            ModelDefinition::classBaseName($model->table),
            $model->softDeletes ? "    use \\Illuminate\\Database\\Eloquent\\SoftDeletes;\n" : '',
            $model->incrementing ? 'true' : 'false',
            var_export($model->table, true),
            var_export($model->keyName, true),
            var_export($model->keyKind === ValueKind::Integer ? 'int' : 'string', true),
            $relations,
        );
    }
}

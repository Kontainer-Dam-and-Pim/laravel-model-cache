<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\App;

use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\Schema;

/**
 * One release of the application using the cache: the schema its code is written against, which
 * lags behind the database after a migration its successor brought, its models and its
 * `model-cache` settings.
 */
final readonly class Release
{
    /**
     * @param  array<string, ModelDefinition>  $models  table => model
     * @param  array<string, mixed>  $config  `model-cache.*` setting => value
     */
    public function __construct(
        public int $number,
        public Schema $schema,
        public array $models,
        public array $config,
    ) {}

    public function model(string $table): ModelDefinition
    {
        return $this->models[$table] ?? throw new \LogicException("Release {$this->number} has no model of {$table}");
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'schema' => $this->schema->toArray(),
            'models' => array_values(array_map(static fn (ModelDefinition $model): array => $model->toArray(), $this->models)),
            'config' => $this->config,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $models = [];
        foreach ($data['models'] as $model) {
            $definition = ModelDefinition::fromArray($model);
            $models[$definition->table] = $definition;
        }

        return new self($data['number'], Schema::fromArray($data['schema']), $models, $data['config']);
    }
}

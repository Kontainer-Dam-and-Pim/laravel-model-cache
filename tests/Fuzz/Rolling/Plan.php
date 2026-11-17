<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling;

use Kontainer\ModelCache\Tests\Fuzz\Rolling\App\Release;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Step;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Steps;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\Schema;

/**
 * Everything one run replays: the schemas the database is migrated through, the releases pods
 * run, and the steps of the deploys, so a failing run can be written to a file, shrunk and
 * replayed.
 */
final readonly class Plan
{
    /**
     * @param  list<Schema>  $schemas  the schemas the database is migrated to, the first created at the start
     * @param  list<Release>  $releases  by number
     * @param  list<Step>  $steps
     */
    public function __construct(
        public int $seed,
        public array $schemas,
        public array $releases,
        public array $steps,
    ) {}

    /**
     * @param  list<Step>  $steps
     */
    public function withSteps(array $steps): self
    {
        return new self($this->seed, $this->schemas, $this->releases, $steps);
    }

    public function release(int $number): Release
    {
        return $this->releases[$number] ?? throw new \LogicException("No release {$number}");
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'seed' => $this->seed,
            'schemas' => array_map(static fn (Schema $schema): array => $schema->toArray(), $this->schemas),
            'releases' => array_map(static fn (Release $release): array => $release->toArray(), $this->releases),
            'steps' => array_map(static fn (Step $step): array => $step->toArray(), $this->steps),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['seed'],
            array_map(Schema::fromArray(...), $data['schemas']),
            array_map(Release::fromArray(...), $data['releases']),
            array_map(Steps::fromArray(...), $data['steps']),
        );
    }
}

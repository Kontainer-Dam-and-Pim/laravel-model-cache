<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation;

use UnexpectedValueException;

final class Steps
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): Step
    {
        return match ($data['type'] ?? null) {
            'pod' => new PodStep($data['pod'], match (true) {
                $data['read'] !== null => Read::fromArray($data['read']),
                $data['write'] !== null => Write::fromArray($data['write']),
                default => TransactionAction::from($data['transaction']),
            }),
            'lifecycle' => new LifecycleStep($data['pod'], Lifecycle::from($data['lifecycle']), $data['release']),
            'migrate' => new MigrateStep($data['schema']),
            'disturb' => new DisturbStep(Disturbance::from($data['disturbance']), $data['seed']),
            default => throw new UnexpectedValueException('Unknown step '.json_encode($data)),
        };
    }
}

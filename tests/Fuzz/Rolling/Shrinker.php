<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling;

use Closure;

/**
 * Removes steps from a failing plan for as long as the same read still reads a stale result, by
 * delta debugging: it tries removing ever smaller chunks, keeping each removal that still fails
 * that way. Steps a removal leaves the deploy unable to run are skipped when the plan runs.
 */
final readonly class Shrinker
{
    /**
     * @param  Closure(Plan): mixed  $run  runs a plan, throwing `StaleRead` when a read is stale
     */
    public function __construct(
        private Closure $run,
        private int $maxRuns,
    ) {}

    /**
     * @return array{Plan, StaleRead} the smallest plan found and how it fails
     */
    public function shrink(Plan $plan, StaleRead $failure): array
    {
        $steps = $plan->steps;
        $chunks = 2;
        $runs = 0;

        while (count($steps) >= 2 && $runs < $this->maxRuns) {
            $size = (int) ceil(count($steps) / $chunks);
            $reduced = false;

            for ($start = 0; $start < count($steps) && $runs < $this->maxRuns; $start += $size) {
                $candidate = [...array_slice($steps, 0, $start), ...array_slice($steps, $start + $size)];
                $runs++;
                $candidateFailure = $this->failureOf($plan->withSteps($candidate), $failure->read);

                if ($candidateFailure !== null) {
                    [$steps, $failure, $reduced] = [$candidate, $candidateFailure, true];
                    $chunks = max($chunks - 1, 2);

                    break;
                }
            }

            if (! $reduced) {
                if ($size === 1) {
                    break;
                }

                $chunks = min(count($steps), 2 * $chunks);
            }
        }

        return [$plan->withSteps($steps), $failure];
    }

    private function failureOf(Plan $plan, string $read): ?StaleRead
    {
        try {
            ($this->run)($plan);
        } catch (StaleRead $failure) {
            return $failure->read === $read ? $failure : null;
        }

        return null;
    }
}

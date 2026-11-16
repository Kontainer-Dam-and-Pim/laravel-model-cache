<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Debug;

use DebugBar\DataCollector\TimeDataCollector;

/**
 * Model Cache DebugBar collector.
 *
 * Collects timing measures for Model Cache operations and exposes them to
 * the DebugBar timeline and badge widgets. This class extends DebugBar's
 * TimeDataCollector to integrate seamlessly with the existing DebugBar UI.
 */
final class CacheCollector extends TimeDataCollector
{
    private ?float $startTime = null;

    public function startMeasuring(): void
    {
        $this->startTime = microtime(true);
    }

    /**
     * @param  list<string>  $hash
     * @param  list<string>  $tags
     * @param  list<mixed>  $parameters
     */
    public function endMeasuring(
        string $type,
        array $hash,
        array $tags,
        string $sql,
        array $parameters = []
    ): void {
        $endTime = microtime(true);

        $this->addMeasure(
            '['.ucfirst($type).'] '.$sql,
            $this->startTime ?? $endTime,
            $endTime,
            [
                'hash' => $hash,
                'tags' => $tags,
                'parameters' => $parameters,
            ]
        );
    }

    /** {@inheritDoc} */
    #[\Override]
    public function collect(): array
    {
        $data = parent::collect();
        $data['model-cache-measures'] = is_array($data['measures'] ?? null) ? count($data['measures']) : 0;

        return $data;
    }

    /** {@inheritDoc} */
    #[\Override]
    public function getName(): string
    {
        return 'model-cache';
    }

    /** {@inheritDoc} */
    #[\Override]
    public function getWidgets(): array
    {
        return [
            'Model Cache' => [
                'icon' => 'database',
                'widget' => 'PhpDebugBar.Widgets.TimelineWidget',
                'map' => 'model-cache',
                'default' => '{}',
            ],
            'Model Cache:badge' => [
                'map' => 'model-cache.model-cache-measures',
                'default' => 0,
            ],
        ];
    }
}

<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Console;

use Kontainer\ModelCache\Cache;
use Kontainer\ModelCache\Encoder;
use Kontainer\ModelCache\Redis;
use Throwable;

final class EnableCommand extends Command
{
    protected $signature = 'model-cache:enable';

    protected $description = 'Enable the Model Cache database cache.';

    public function handle(): int
    {
        if (! $this->writeConfig('active', true)) {
            $this->error('Failed to enable Model Cache.');

            return self::FAILURE;
        }

        $this->info('Model Cache enabled.');
        $this->line('Note: if you have MODEL_CACHE_ACTIVE=false in your .env, this setting will be ignored.');

        try {
            (new Cache(new Redis, new Encoder))->retireCurrentGeneration();
        } catch (Throwable $exception) {
            $this->warn('Could not retire results cached before Model Cache was disabled, flush them with model-cache:flush: '.$exception->getMessage());
        }

        return self::SUCCESS;
    }
}

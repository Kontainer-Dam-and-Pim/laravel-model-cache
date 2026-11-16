<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Console;

/**
 * Disable the Model Cache database cache.
 *
 * This command updates the package configuration to set `active` to false
 * and reports the outcome via the console.
 */
final class DisableCommand extends Command
{
    protected $signature = 'model-cache:disable';

    protected $description = 'Disable the Model Cache database cache.';

    public function handle(): int
    {
        if ($this->writeConfig('active', false)) {
            $this->info('Model Cache disabled.');

            return self::SUCCESS;
        }

        $this->error('Failed to disable Model Cache.');

        return self::FAILURE;
    }
}

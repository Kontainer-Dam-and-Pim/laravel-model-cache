<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Pod;

use Orchestra\Testbench\Foundation\Application;

/**
 * The application of a pod, configured where a test case's environment is: after the providers
 * are registered and before they boot.
 */
final class PodApplication extends Application
{
    /** @var array<string, mixed> */
    private array $settings = [];

    /**
     * @param  array<string, mixed>  $settings  configuration key => value
     */
    public function withSettings(array $settings): self
    {
        $this->settings = $settings;

        return $this;
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        foreach ($this->settings as $key => $value) {
            $app['config']->set($key, $value);
        }
    }
}

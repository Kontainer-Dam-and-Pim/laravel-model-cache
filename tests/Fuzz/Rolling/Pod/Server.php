<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Pod;

use Illuminate\Support\Facades\DB;
use Kontainer\ModelCache\ModelCacheServiceProvider;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\App\Release;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Read;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\TransactionAction;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Write;
use UnexpectedValueException;

/**
 * A pod: a process running one release with the cache, booted from the configuration the
 * coordinator sends, then running the steps it sends one JSON line at a time until its input ends.
 */
final class Server
{
    public const int LOCK_WAIT_SECONDS = 5;

    /**
     * @param  resource  $input
     * @param  resource  $output
     */
    public static function serve($input, $output): int
    {
        $boot = self::receive($input) ?? throw new UnexpectedValueException('A pod needs its configuration and release first');
        $release = Release::fromArray($boot['release']);
        $app = (new PodApplication)
            ->withSettings($boot['config'])
            ->configure(['extra' => ['providers' => [ModelCacheServiceProvider::class]]])
            ->createApplication();

        eval(ModelSource::of($release));
        self::limitLockWaits();
        $interpreter = new Interpreter($release);
        self::send($output, ['ready' => getmypid()]);

        while (($message = self::receive($input)) !== null) {
            self::send($output, match (true) {
                isset($message['read']) => $interpreter->read(Read::fromArray($message['read'])),
                isset($message['write']) => $interpreter->write(Write::fromArray($message['write'])),
                isset($message['transaction']) => $interpreter->transaction(TransactionAction::from($message['transaction'])),
                default => throw new UnexpectedValueException('Unknown pod message '.json_encode($message)),
            });
        }

        $app->terminate();

        return 0;
    }

    /**
     * A write waiting for row locks another pod holds fails instead of stalling the deploy.
     */
    private static function limitLockWaits(): void
    {
        $seconds = self::LOCK_WAIT_SECONDS;

        match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => DB::statement("set session innodb_lock_wait_timeout = {$seconds}, lock_wait_timeout = {$seconds}"),
            'pgsql' => DB::statement("set lock_timeout = '{$seconds}s'"),
            default => null,
        };
    }

    /**
     * @param  resource  $input
     * @return array<string, mixed>|null
     */
    private static function receive($input): ?array
    {
        $line = fgets($input);

        return $line === false ? null : json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  resource  $output
     * @param  array<string, mixed>  $message
     */
    private static function send($output, array $message): void
    {
        fwrite($output, json_encode($message, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)."\n");
        fflush($output);
    }
}

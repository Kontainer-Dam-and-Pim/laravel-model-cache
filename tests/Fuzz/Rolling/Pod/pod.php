<?php

declare(strict_types=1);
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Pod\Server;

require dirname(__DIR__, 4).'/vendor/autoload.php';

try {
    exit(Server::serve(STDIN, STDOUT));
} catch (Throwable $exception) {
    fwrite(STDERR, (string) $exception);

    exit(1);
}

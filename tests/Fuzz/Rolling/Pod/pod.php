<?php

declare(strict_types=1);
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Pod\Server;

require dirname(__DIR__, 4).'/vendor/autoload.php';

exit(Server::serve(STDIN, STDOUT));

<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Support;

use PHPUnit\Framework\AssertionFailedError;

final class StaleReadException extends AssertionFailedError {}

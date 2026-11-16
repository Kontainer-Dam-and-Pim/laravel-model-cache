<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Database\Models\Application;

use Illuminate\Database\Eloquent\Model;
use Kontainer\ModelCache\Database\ModelCacheTrait;

final class AutomationTriggerEvent extends Model
{
    use ModelCacheTrait;

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}

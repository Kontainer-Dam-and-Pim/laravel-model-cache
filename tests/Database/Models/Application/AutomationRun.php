<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Database\Models\Application;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Kontainer\ModelCache\Database\ModelCacheTrait;

final class AutomationRun extends Model
{
    use ModelCacheTrait;

    public $timestamps = false;

    protected $guarded = [];

    public function workspace(): HasOneThrough
    {
        return $this->hasOneThrough(Workspace::class, Automation::class, 'id', 'id', 'automation_id', 'workspace_id');
    }
}

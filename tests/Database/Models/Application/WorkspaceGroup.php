<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Database\Models\Application;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kontainer\ModelCache\Database\ModelCacheTrait;

final class WorkspaceGroup extends Model
{
    use ModelCacheTrait;

    public $timestamps = false;

    protected $guarded = [];

    public function newEloquentBuilder($query): OwnColumnsBuilder
    {
        return (new OwnColumnsBuilder($query))->select('workspace_groups.*');
    }

    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class, 'group_id');
    }
}

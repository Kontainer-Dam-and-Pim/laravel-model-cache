<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Database\Models\Application;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kontainer\ModelCache\Database\ModelCacheTrait;

final class Template extends Model
{
    use ModelCacheTrait;

    public const CREATED_AT = null;

    protected $guarded = [];

    public function newEloquentBuilder($query): OwnColumnsBuilder
    {
        return (new OwnColumnsBuilder($query))->select('templates.*');
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(TemplatePermission::class);
    }
}

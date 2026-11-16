<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Database\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Kontainer\ModelCache\Database\ModelCacheTrait;

class Car extends Model
{
    use HasFactory;
    use ModelCacheTrait;
    use SoftDeletes;

    protected $fillable = ['name', 'engine_id', 'driver_id'];

    public function engine(): HasOne
    {
        return $this->hasOne(Engine::class);
    }

    public function latestEngine(): HasOne
    {
        return $this->hasOne(Engine::class)->latestOfMany();
    }

    public function oldestEngine(): HasOne
    {
        return $this->hasOne(Engine::class)->oldestOfMany();
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function materials(): BelongsToMany
    {
        return $this->belongsToMany(Material::class);
    }
}

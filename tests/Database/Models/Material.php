<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Database\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Kontainer\ModelCache\Database\ModelCacheTrait;

class Material extends Model
{
    use HasFactory, ModelCacheTrait;

    protected $fillable = ['name'];

    public function cars(): BelongsToMany
    {
        return $this->belongsToMany(Car::class);
    }
}

<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Database\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kontainer\ModelCache\Database\ModelCacheTrait;

class Engine extends Model
{
    use HasFactory, ModelCacheTrait;

    protected $fillable = ['name', 'car_id'];

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }
}

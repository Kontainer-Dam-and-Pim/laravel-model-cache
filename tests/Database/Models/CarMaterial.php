<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Database\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Kontainer\ModelCache\Database\ModelCacheTrait;

class CarMaterial extends Model
{
    use HasFactory, ModelCacheTrait;

    protected $table = 'car_material';

    protected $fillable = ['car_id', 'material_id'];
}

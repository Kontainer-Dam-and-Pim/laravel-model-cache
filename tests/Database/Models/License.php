<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Kontainer\ModelCache\Database\ModelCacheTrait;

class License extends Model
{
    use ModelCacheTrait;

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    protected $fillable = ['code', 'holder'];
}

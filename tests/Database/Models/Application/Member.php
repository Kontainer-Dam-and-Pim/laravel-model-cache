<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Database\Models\Application;

use Illuminate\Database\Eloquent\Model;

final class Member extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}

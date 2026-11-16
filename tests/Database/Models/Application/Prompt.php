<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Database\Models\Application;

use Illuminate\Database\Eloquent\Model;
use Kontainer\ModelCache\Database\ModelCacheTrait;

class Prompt extends Model
{
    use ModelCacheTrait;

    public $timestamps = false;

    protected $table = 'prompts';

    protected $guarded = [];
}

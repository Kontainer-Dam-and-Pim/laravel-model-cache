<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Database\Models\Application;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Kontainer\ModelCache\Database\ModelCacheTrait;

final class TextTranslation extends Model
{
    use ModelCacheTrait;

    public $timestamps = false;

    protected $guarded = [];

    public function translatable(): MorphTo
    {
        return $this->morphTo();
    }
}

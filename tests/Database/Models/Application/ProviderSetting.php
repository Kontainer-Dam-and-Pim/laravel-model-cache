<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Database\Models\Application;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Kontainer\ModelCache\Database\ModelCacheTrait;

final class ProviderSetting extends Model
{
    use HasUuids;
    use ModelCacheTrait;

    public $timestamps = false;

    protected $primaryKey = 'uuid';

    protected $guarded = [];
}

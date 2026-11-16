<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Database\Models\Application;

use Illuminate\Database\Eloquent\Builder;

final class AssistantPrompt extends Prompt
{
    public function newEloquentBuilder($query): Builder
    {
        return (new OwnColumnsBuilder($query))->where('prompts.type', '=', 'assistant');
    }
}

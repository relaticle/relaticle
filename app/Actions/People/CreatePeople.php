<?php

declare(strict_types=1);

namespace App\Actions\People;

use App\Enums\CreationSource;
use App\Models\People;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final readonly class CreatePeople
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, array $data, CreationSource $source = CreationSource::WEB): People
    {
        abort_unless($user->can('create', People::class), 403);

        $attributes = Arr::only($data, ['name', 'company_id', 'custom_fields']);
        $attributes['creation_source'] = $source;

        $person = DB::transaction(fn (): People => People::query()->create($attributes));

        return $person->load('customFieldValues.customField.options');
    }
}

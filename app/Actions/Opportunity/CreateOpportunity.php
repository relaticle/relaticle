<?php

declare(strict_types=1);

namespace App\Actions\Opportunity;

use App\Enums\CreationSource;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final readonly class CreateOpportunity
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, array $data, CreationSource $source = CreationSource::WEB): Opportunity
    {
        abort_unless($user->can('create', Opportunity::class), 403);

        $attributes = Arr::only($data, ['name', 'company_id', 'contact_id', 'custom_fields']);
        $attributes['creation_source'] = $source;

        $opportunity = DB::transaction(fn (): Opportunity => Opportunity::query()->create($attributes));

        return $opportunity->load('customFieldValues.customField.options');
    }
}

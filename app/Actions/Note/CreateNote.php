<?php

declare(strict_types=1);

namespace App\Actions\Note;

use App\Enums\CreationSource;
use App\Models\Note;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final readonly class CreateNote
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, array $data, CreationSource $source = CreationSource::WEB): Note
    {
        abort_unless($user->can('create', Note::class), 403);

        $companyIds = Arr::pull($data, 'company_ids');
        $peopleIds = Arr::pull($data, 'people_ids');
        $opportunityIds = Arr::pull($data, 'opportunity_ids');

        $attributes = Arr::only($data, ['title', 'custom_fields']);
        $attributes['creation_source'] = $source;

        return DB::transaction(function () use ($attributes, $companyIds, $peopleIds, $opportunityIds): Note {
            $note = Note::query()->create($attributes);

            if ($companyIds !== null) {
                $note->companies()->sync($companyIds);
            }
            if ($peopleIds !== null) {
                $note->people()->sync($peopleIds);
            }
            if ($opportunityIds !== null) {
                $note->opportunities()->sync($opportunityIds);
            }

            return $note;
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Note;

use App\Models\Note;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final readonly class UpdateNote
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, Note $note, array $data): Note
    {
        abort_unless($user->can('update', $note), 403);

        $attributes = Arr::only($data, ['title', 'custom_fields']);

        return DB::transaction(function () use ($note, $attributes, $data): Note {
            $note->update($attributes);

            if (array_key_exists('company_ids', $data)) {
                $note->companies()->sync($data['company_ids']);
            }
            if (array_key_exists('people_ids', $data)) {
                $note->people()->sync($data['people_ids']);
            }
            if (array_key_exists('opportunity_ids', $data)) {
                $note->opportunities()->sync($data['opportunity_ids']);
            }

            return $note->refresh();
        });
    }
}

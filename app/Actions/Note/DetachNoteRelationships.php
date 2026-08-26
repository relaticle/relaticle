<?php

declare(strict_types=1);

namespace App\Actions\Note;

use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use App\Support\TenantFkValidator;
use Illuminate\Support\Facades\DB;

final readonly class DetachNoteRelationships
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, Note $note, array $data): Note
    {
        abort_unless($user->can('update', $note), 403);

        TenantFkValidator::assertOwnedMany($user, $data, [
            'company_ids' => Company::class,
            'people_ids' => People::class,
            'opportunity_ids' => Opportunity::class,
        ]);

        DB::transaction(function () use ($note, $data): void {
            if (array_key_exists('company_ids', $data)) {
                $note->companies()->detach($data['company_ids']);
            }
            if (array_key_exists('people_ids', $data)) {
                $note->people()->detach($data['people_ids']);
            }
            if (array_key_exists('opportunity_ids', $data)) {
                $note->opportunities()->detach($data['opportunity_ids']);
            }
        });

        return $note->refresh();
    }
}

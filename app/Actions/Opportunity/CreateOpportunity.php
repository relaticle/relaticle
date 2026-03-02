<?php

declare(strict_types=1);

namespace App\Actions\Opportunity;

use App\Enums\CreationSource;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class CreateOpportunity
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, array $data, CreationSource $source = CreationSource::WEB): Opportunity
    {
        abort_unless($user->can('create', Opportunity::class), 403);

        $data['creation_source'] = $source;
        $data['creator_id'] = $user->getKey();
        $data['team_id'] = $user->currentTeam->getKey();

        return DB::transaction(fn (): Opportunity => Opportunity::query()->create($data));
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Company;

use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class CreateCompany
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, array $data, CreationSource $source = CreationSource::WEB): Company
    {
        abort_unless($user->can('create', Company::class), 403);

        $data['creation_source'] = $source;
        $data['creator_id'] = $user->getKey();
        $data['team_id'] = $user->currentTeam->getKey();

        return DB::transaction(fn (): Company => Company::query()->create($data));
    }
}

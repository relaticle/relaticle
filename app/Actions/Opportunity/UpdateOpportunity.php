<?php

declare(strict_types=1);

namespace App\Actions\Opportunity;

use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class UpdateOpportunity
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, Opportunity $opportunity, array $data): Opportunity
    {
        abort_unless($user->can('update', $opportunity), 403);

        return DB::transaction(function () use ($opportunity, $data): Opportunity {
            $opportunity->update($data);

            return $opportunity->refresh();
        });
    }
}

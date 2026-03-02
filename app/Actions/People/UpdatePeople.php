<?php

declare(strict_types=1);

namespace App\Actions\People;

use App\Models\People;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class UpdatePeople
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, People $people, array $data): People
    {
        abort_unless($user->can('update', $people), 403);

        return DB::transaction(function () use ($people, $data): People {
            $people->update($data);

            return $people->refresh();
        });
    }
}

<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class TeamMemberDirectory
{
    /** @var array<string, array<string, array{name: string, avatar: string|null}>> */
    private array $membersByTeam = [];

    /**
     * @return array{name: string, avatar: string|null}|null
     */
    public function find(string $teamId, string $email): ?array
    {
        $this->load($teamId);

        return $this->membersByTeam[$teamId][Str::lower($email)] ?? null;
    }

    private function load(string $teamId): void
    {
        if (array_key_exists($teamId, $this->membersByTeam)) {
            return;
        }

        $members = [];

        User::query()
            ->whereHas(
                'teams',
                fn (Builder $query): Builder => $query->where('teams.id', $teamId),
            )
            ->get()
            ->each(function (User $user) use (&$members): void {
                $email = Str::lower(trim($user->email));

                if ($email === '' || trim($user->name) === '') {
                    return;
                }

                $members[$email] = [
                    'name' => trim($user->name),
                    'avatar' => $user->profile_photo_url,
                ];
            });

        $this->membersByTeam[$teamId] = $members;
    }
}

<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

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

        ConnectedAccount::query()
            ->where('team_id', $teamId)
            ->with('user')
            ->get()
            ->each(function (ConnectedAccount $account) use (&$members): void {
                $email = Str::lower(trim($account->email_address));

                if ($email === '' || isset($members[$email])) {
                    return;
                }

                $user = $account->user;
                $name = $user instanceof User && trim($user->name) !== ''
                    ? trim($user->name)
                    : trim((string) ($account->display_name ?? ''));

                if ($name === '' || Str::lower($name) === $email) {
                    return;
                }

                $members[$email] = [
                    'name' => $name,
                    'avatar' => $user instanceof User ? $user->profile_photo_url : null,
                ];
            });

        $this->membersByTeam[$teamId] = $members;
    }
}

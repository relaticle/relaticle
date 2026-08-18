<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read-only projection of a team's people: current members and pending
 * invitations in one list. Never written through — every mutation goes to the
 * actions in App\Actions\Jetstream.
 *
 * A team's owner has no row in `team_user` (Jetstream tracks ownership via
 * `teams.user_id`, not the membership pivot — verified empirically: creating
 * a team via the standard flow leaves zero `team_user` rows for its owner).
 * A union of `team_user` and `team_invitations` alone would silently drop the
 * owner from this projection, so a third leg synthesizes their row from
 * `teams` joined to `users`, keyed `member:owner` (a literal that can never
 * collide with `'member:' || <bigint>`) with `role` set to the literal
 * `'owner'`, matching Jetstream's `OwnerRole` key.
 *
 * @property string $id
 * @property ?string $user_id
 * @property ?string $name
 * @property string $email
 * @property string $role
 * @property string $status
 * @property ?string $profile_photo_path
 * @property string $source_id
 * @property Carbon $happened_at
 * @property ?Carbon $expires_at
 */
#[WithoutIncrementing]
#[WithoutTimestamps]
final class TeamPerson extends Model
{
    protected $keyType = 'string';

    protected $table = 'team_people';

    /**
     * @return Builder<self>
     */
    public static function forTeam(Team $team): Builder
    {
        $members = DB::table('team_user')
            ->join('users', 'users.id', '=', 'team_user.user_id')
            ->where('team_user.team_id', $team->id)
            ->where('team_user.user_id', '!=', $team->user_id)
            ->select([
                DB::raw("'member:' || team_user.id::text as id"),
                DB::raw('team_user.id::text as source_id'),
                'users.id as user_id',
                'users.name as name',
                'users.email as email',
                'users.profile_photo_path as profile_photo_path',
                'team_user.role as role',
                DB::raw("'member' as status"),
                'team_user.created_at as happened_at',
                DB::raw('null::timestamp as expires_at'),
            ]);

        $owner = DB::table('teams')
            ->join('users', 'users.id', '=', 'teams.user_id')
            ->where('teams.id', $team->id)
            ->select([
                DB::raw("'member:owner' as id"),
                DB::raw('teams.user_id::text as source_id'),
                'users.id as user_id',
                'users.name as name',
                'users.email as email',
                'users.profile_photo_path as profile_photo_path',
                DB::raw("'owner' as role"),
                DB::raw("'member' as status"),
                'teams.created_at as happened_at',
                DB::raw('null::timestamp as expires_at'),
            ]);

        $invitations = DB::table('team_invitations')
            ->where('team_id', $team->id)
            ->select([
                DB::raw("'invite:' || id::text as id"),
                DB::raw('id::text as source_id'),
                DB::raw('null::char(26) as user_id'),
                DB::raw('null::varchar as name'),
                'email',
                DB::raw('null::varchar as profile_photo_path'),
                'role',
                DB::raw("'invited' as status"),
                'created_at as happened_at',
                'expires_at',
            ]);

        return self::query()->fromSub(
            $members->unionAll($owner)->unionAll($invitations),
            'team_people'
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'happened_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}

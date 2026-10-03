<?php

declare(strict_types=1);

namespace Tests\Helpers;

use App\Enums\CreationSource;
use App\Enums\Plan;
use App\Models\Company;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

final class OverviewData
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function owner(?CarbonImmutable $signedUpAt = null, array $attributes = []): User
    {
        $signedUpAt ??= now();

        $user = User::factory()->withPersonalWorkspace()->create([
            'created_at' => $signedUpAt,
            'email_verified_at' => $signedUpAt,
            ...$attributes,
        ]);

        self::workspaceOf($user)->forceFill(['created_at' => $signedUpAt])->save();

        return $user->refresh();
    }

    public static function workspaceOf(User $user): Workspace
    {
        return $user->ownedWorkspaces()->firstOrFail();
    }

    public static function ownRecord(Workspace $workspace, User $creator, CarbonImmutable $at, CreationSource $source = CreationSource::WEB): Company
    {
        return Company::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'creator_id' => $creator->getKey(),
            'account_owner_id' => $creator->getKey(),
            'creation_source' => $source,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    public static function sampleRecord(Workspace $workspace, CarbonImmutable $at): Company
    {
        return Company::withoutEvents(fn (): Company => Company::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'creator_id' => null,
            'account_owner_id' => null,
            'creation_source' => CreationSource::SYSTEM,
            'created_at' => $at,
            'updated_at' => $at,
        ]));
    }

    public static function typedMessage(Workspace $workspace, User $user, CarbonImmutable $at): void
    {
        $conversationId = (string) Str::uuid7();

        DB::table('agent_conversations')->insert([
            'id' => $conversationId,
            'participant_type' => $user->getMorphClass(),
            'participant_id' => (string) $user->getKey(),
            'workspace_id' => $workspace->getKey(),
            'title' => 'Test conversation',
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        DB::table('agent_conversation_messages')->insert([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversationId,
            'participant_type' => $user->getMorphClass(),
            'participant_id' => (string) $user->getKey(),
            'agent' => 'test',
            'role' => 'user',
            'origin' => 'typed',
            'content' => 'Hello',
            'attachments' => '[]',
            'steps' => '[]',
            'usage' => '[]',
            'meta' => '[]',
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    public static function internalOwner(): User
    {
        $administrator = SystemAdministrator::factory()->create([
            'email' => 'Founder.'.Str::lower(Str::random(8)).'@Example.COM',
        ]);

        return self::owner(attributes: ['email' => $administrator->email]);
    }

    public static function trial(Workspace $workspace): Workspace
    {
        $workspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addDays(14), 'pro_trial_used_at' => now()])->save();

        return $workspace->refresh();
    }
}

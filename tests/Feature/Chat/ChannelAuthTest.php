<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

const CONV_MINE = '019dded5-aaaa-7bbb-8ccc-444400000001';
const CONV_OTHER = '019dded5-aaaa-7bbb-8ccc-444400000002';
const CONV_OTHER_WORKSPACE = '019dded5-aaaa-7bbb-8ccc-444400000003';
const CONV_FRESH = '019dded5-aaaa-7bbb-8ccc-444400000004';

it('grants access to own conversation channel', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    DB::table('agent_conversations')->insert([
        'id' => CONV_MINE,
        'participant_type' => 'user',
        'participant_id' => $user->getKey(),
        'workspace_id' => $user->current_workspace_id,
        'title' => 'Mine',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(chatChannelAuth($user, CONV_MINE))->toBeTrue();
});

it('denies access to another user conversation channel', function (): void {
    $mine = User::factory()->withPersonalWorkspace()->create();
    $other = User::factory()->withPersonalWorkspace()->create();

    DB::table('agent_conversations')->insert([
        'id' => CONV_OTHER,
        'participant_type' => 'user',
        'participant_id' => $other->getKey(),
        'workspace_id' => $other->current_workspace_id,
        'title' => 'Other',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(chatChannelAuth($mine, CONV_OTHER))->toBeFalse();
});

it('rejects channel ids that are not UUIDs', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    expect(chatChannelAuth($user, 'not-a-uuid'))->toBeFalse();
});

it('denies access for a fresh UUID when the conversation row does not exist yet', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    expect(chatChannelAuth($user, CONV_FRESH))->toBeFalse();
    expect(DB::table('agent_conversations')->where('id', CONV_FRESH)->exists())->toBeFalse();
});

it('refuses optimistic claim when another user already holds the conversation id', function (): void {
    $owner = User::factory()->withPersonalWorkspace()->create();
    $eve = User::factory()->withPersonalWorkspace()->create();

    // Owner claims first (e.g. via their own subscribe attempt or POST).
    DB::table('agent_conversations')->insert([
        'id' => CONV_FRESH,
        'participant_type' => 'user',
        'participant_id' => $owner->getKey(),
        'workspace_id' => $owner->current_workspace_id,
        'title' => 'Owner',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Eve later tries to subscribe to the same id, which must be denied.
    expect(chatChannelAuth($eve, CONV_FRESH))->toBeFalse();
});

it('denies access when the conversation belongs to user other workspace', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $otherWorkspace = Workspace::factory()->create(['user_id' => $user->getKey()]);
    $user->workspaces()->attach($otherWorkspace, ['role' => 'admin']);

    DB::table('agent_conversations')->insert([
        'id' => CONV_OTHER_WORKSPACE,
        'participant_type' => 'user',
        'participant_id' => $user->getKey(),
        'workspace_id' => $otherWorkspace->getKey(),
        'title' => 'Other',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(chatChannelAuth($user, CONV_OTHER_WORKSPACE))->toBeFalse();
});

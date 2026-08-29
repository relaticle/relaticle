<?php

declare(strict_types=1);

use App\Livewire\App\Teams\TeamMembers;
use App\Models\Membership;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

mutates(TeamMembers::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);
});

test('the members table renders every member of the team', function (): void {
    $members = User::factory()->count(2)->create();

    $this->team->users()->attach($members->mapWithKeys(
        fn (User $member): array => [$member->id => ['role' => 'editor']]
    )->all());

    livewire(TeamMembers::class, ['team' => $this->team])
        ->assertCanSeeTableRecords(Membership::query()->where('team_id', $this->team->id)->get())
        ->assertSee($members->first()->email)
        ->assertSee($members->last()->email);
});

test('the members table skips a membership row whose user no longer exists', function (): void {
    Schema::table('team_user', function (Blueprint $table): void {
        $table->dropForeign(['user_id']);
    });

    $member = User::factory()->create();
    $deletedUser = User::factory()->create();

    $this->team->users()->attach([
        $member->id => ['role' => 'editor'],
        $deletedUser->id => ['role' => 'editor'],
    ]);

    $membership = Membership::query()->where('user_id', $member->id)->sole();
    $orphanedMembership = Membership::query()->where('user_id', $deletedUser->id)->sole();

    $deletedUser->delete();

    livewire(TeamMembers::class, ['team' => $this->team])
        ->assertCanSeeTableRecords([$membership])
        ->assertCanNotSeeTableRecords([$orphanedMembership])
        ->assertSee($member->email);
});

test('the bound workspace is locked, so a payload cannot repoint the roster at another team', function (): void {
    $stranger = User::factory()->withTeam()->create();
    $strangerMember = User::factory()->create();
    $stranger->currentTeam->users()->attach($strangerMember->id, ['role' => 'editor']);

    $component = livewire(TeamMembers::class, ['team' => $this->team]);

    expect(fn () => $component->set('team', $stranger->currentTeam->getKey()))
        ->toThrow(Exception::class);

    $component->assertDontSee($strangerMember->email);
});

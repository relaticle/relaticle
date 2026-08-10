<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Relaticle\EmailIntegration\Filament\Resources\MeetingResource;
use Relaticle\EmailIntegration\Filament\Resources\MeetingResource\Pages\ListMeetings;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;

mutates(MeetingResource::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);

    $this->account = ConnectedAccount::withoutEvents(
        fn () => ConnectedAccount::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
        ])
    );
});

it('renders the meeting list page', function (): void {
    livewire(ListMeetings::class)->assertOk();
});

it('lists meetings for the current team', function (): void {
    $mine = Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
    ]);

    livewire(ListMeetings::class)
        ->assertCanSeeTableRecords([$mine]);
});

it('filters upcoming meetings', function (): void {
    $future = Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
        'starts_at' => Carbon::now()->addDays(2),
        'ends_at' => Carbon::now()->addDays(2)->addHour(),
    ]);
    $past = Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
        'starts_at' => Carbon::now()->subDays(2),
        'ends_at' => Carbon::now()->subDays(2)->addHour(),
    ]);

    livewire(ListMeetings::class)
        ->filterTable('upcoming')
        ->assertCanSeeTableRecords([$future])
        ->assertCanNotSeeTableRecords([$past]);
});

it('filters past meetings', function (): void {
    $future = Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
        'starts_at' => Carbon::now()->addDays(2),
        'ends_at' => Carbon::now()->addDays(2)->addHour(),
    ]);
    $past = Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
        'starts_at' => Carbon::now()->subDays(2),
        'ends_at' => Carbon::now()->subDays(2)->addHour(),
    ]);

    livewire(ListMeetings::class)
        ->filterTable('past')
        ->assertCanSeeTableRecords([$past])
        ->assertCanNotSeeTableRecords([$future]);
});

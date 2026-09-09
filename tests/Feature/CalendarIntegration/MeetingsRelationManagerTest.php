<?php

declare(strict_types=1);

use App\Enums\CustomFields\PeopleField;
use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Filament\Resources\OpportunityResource\Pages\ViewOpportunity;
use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Filament\Resources\PeopleResource\RelationManagers\MeetingsRelationManager;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Filament\Infolists\MeetingDetailInfolist;
use Relaticle\EmailIntegration\Filament\RelationManagers\BaseMeetingsRelationManager;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\EmailBlocklist;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Models\MeetingAttendee;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;

mutates(MeetingsRelationManager::class, BaseMeetingsRelationManager::class, EmailVisibilityService::class, MeetingDetailInfolist::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);
    Filament::setCurrentPanel(Filament::getPanel('app'));

    $this->account = ConnectedAccount::withoutEvents(
        fn () => ConnectedAccount::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
        ])
    );
});

it('shows meetings linked to this person only', function (): void {
    $person = People::factory()->for($this->team)->create();

    $linked = Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
    ]);
    $linked->people()->attach($person, ['link_source' => 'manual']);

    $other = Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
    ]);

    livewire(MeetingsRelationManager::class, [
        'ownerRecord' => $person,
        'pageClass' => ViewPeople::class,
    ])
        ->assertCanSeeTableRecords([$linked])
        ->assertCanNotSeeTableRecords([$other]);
});

it('can render the meetings relation manager', function (): void {
    $person = People::factory()->for($this->team)->create();

    livewire(MeetingsRelationManager::class, [
        'ownerRecord' => $person,
        'pageClass' => ViewPeople::class,
    ])
        ->assertOk();
});

it('shows one copy per occurrence and prefers the viewers calendar', function (string $type): void {
    [$person, $manager, $page] = match ($type) {
        'company' => [Company::factory()->for($this->team)->create(), App\Filament\Resources\CompanyResource\RelationManagers\MeetingsRelationManager::class, ViewCompany::class],
        'opportunity' => [Opportunity::factory()->for($this->team)->create(), App\Filament\Resources\OpportunityResource\RelationManagers\MeetingsRelationManager::class, ViewOpportunity::class],
        default => [People::factory()->for($this->team)->create(), MeetingsRelationManager::class, ViewPeople::class],
    };
    $teammate = User::factory()->create();
    $otherAccount = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $teammate->id,
    ]));
    $starts = now()->startOfHour();
    $otherCopy = Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $otherAccount->id,
        'ical_uid' => 'weekly@example.test',
        'starts_at' => $starts,
    ]);
    $ownCopy = Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
        'ical_uid' => 'weekly@example.test',
        'starts_at' => $starts,
    ]);
    $nextWeek = Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
        'ical_uid' => 'weekly@example.test',
        'starts_at' => $starts->addWeek(),
    ]);
    foreach ([$otherCopy, $ownCopy, $nextWeek] as $meeting) {
        $person->meetings()->attach($meeting, ['link_source' => 'manual']);
    }

    livewire($manager, ['ownerRecord' => $person, 'pageClass' => $page])
        ->assertCanSeeTableRecords([$ownCopy, $nextWeek])
        ->assertCanNotSeeTableRecords([$otherCopy])
        ->assertCountTableRecords(2);
})->with(['person', 'company', 'opportunity']);

it('does not merge meetings without a shared calendar identity', function (): void {
    $person = People::factory()->for($this->team)->create();
    $meetings = Meeting::factory()->count(2)->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
        'title' => 'Same title and time',
        'starts_at' => now()->startOfHour(),
        'ical_uid' => null,
    ]);
    $person->meetings()->attach($meetings->modelKeys(), ['link_source' => 'manual']);

    livewire(MeetingsRelationManager::class, ['ownerRecord' => $person, 'pageClass' => ViewPeople::class])
        ->assertCanSeeTableRecords($meetings)
        ->assertCountTableRecords(2);
});

it('does not let a hidden or unrelated copy suppress a linked visible meeting', function (): void {
    $person = People::factory()->for($this->team)->create();
    $teammate = User::factory()->create();
    $otherAccount = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $teammate->id,
    ]));
    $attributes = ['team_id' => $this->team->id, 'ical_uid' => 'same@example.test', 'starts_at' => now()->startOfHour()];
    $hidden = Meeting::factory()->create([...$attributes, 'connected_account_id' => $this->account->id, 'organizer_email' => 'hidden@example.test']);
    $unrelated = Meeting::factory()->create([...$attributes, 'connected_account_id' => $this->account->id]);
    $visible = Meeting::factory()->create([...$attributes, 'connected_account_id' => $otherAccount->id]);
    $person->meetings()->attach([$hidden->id, $visible->id], ['link_source' => 'manual']);
    EmailBlocklist::factory()->email('hidden@example.test')->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
    ]);

    livewire(MeetingsRelationManager::class, ['ownerRecord' => $person, 'pageClass' => ViewPeople::class])
        ->assertCanSeeTableRecords([$visible])
        ->assertCanNotSeeTableRecords([$hidden, $unrelated])
        ->assertCountTableRecords(1);
});

it('shows the viewers response on a teammates linked copy', function (): void {
    $person = People::factory()->for($this->team)->create();
    $teammate = User::factory()->create();
    $otherAccount = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $teammate->id,
    ]));
    $meeting = Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $otherAccount->id,
        'response_status' => AttendeeResponseStatus::ACCEPTED,
    ]);
    $meeting->people()->attach($person, ['link_source' => 'manual']);
    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->id,
        'email_address' => $this->account->email_address,
        'response_status' => AttendeeResponseStatus::DECLINED,
    ]);

    livewire(MeetingsRelationManager::class, ['ownerRecord' => $person, 'pageClass' => ViewPeople::class])
        ->assertTableColumnStateSet('response_status', AttendeeResponseStatus::DECLINED, $meeting);
});

it('hides meetings on a protected person', function (): void {
    $person = People::factory()->for($this->team)->create();

    $emailsField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->id)
        ->where('entity_type', 'people')
        ->where('code', PeopleField::EMAILS->value)
        ->firstOrFail();

    $person->saveCustomFieldValue($emailsField, [$this->user->email], $this->team);

    $meeting = Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
        'title' => 'Hidden on the protected record',
    ]);
    $meeting->people()->attach($person, ['link_source' => 'manual']);

    livewire(MeetingsRelationManager::class, [
        'ownerRecord' => $person,
        'pageClass' => ViewPeople::class,
    ])
        ->assertSee(__('filament/pages/record-emails.protected.heading'))
        ->assertCanNotSeeTableRecords([$meeting]);
});

it('shows the shared meeting detail in the relation manager view modal', function (): void {
    $person = People::factory()->for($this->team)->create();
    $starts = now()->startOfHour();
    $meeting = Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
        'title' => 'Shared Detail Meeting',
        'starts_at' => $starts,
        'ends_at' => $starts->copy()->addHour(),
        'response_status' => AttendeeResponseStatus::ACCEPTED,
    ]);
    $meeting->people()->attach($person, ['link_source' => 'manual']);
    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->id,
        'name' => 'Host Person',
        'is_organizer' => true,
        'response_status' => AttendeeResponseStatus::ACCEPTED,
    ]);

    livewire(MeetingsRelationManager::class, [
        'ownerRecord' => $person,
        'pageClass' => ViewPeople::class,
    ])
        ->mountAction(TestAction::make('view')->table($meeting))
        ->assertMountedActionModalSee('Shared Detail Meeting')
        ->assertMountedActionModalSee('Host Person')
        ->assertMountedActionModalSee(__('filament/resources/meeting.attendees.host'))
        ->assertMountedActionModalSee($person->name);
});

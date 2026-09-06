<?php

declare(strict_types=1);

use App\Filament\Resources\PeopleResource\Pages\PeopleEmailsPage;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Enums\EmailCategory;
use Relaticle\EmailIntegration\Filament\Pages\BaseRecordEmailsPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailLabel;
use Relaticle\EmailIntegration\Models\EmailParticipant;

mutates(BaseRecordEmailsPage::class, Email::class, EmailCategory::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->team = $this->user->currentTeam;

    $this->account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));

    $this->person = People::factory()->create([
        'team_id' => $this->team->id,
        'creator_id' => $this->user->id,
    ]);

    $this->actingAs($this->user);
    Filament::setTenant($this->team);
});

it('shows the system category tag on a record mailbox row', function (): void {
    $email = Email::factory()->inbound()->full()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->getKey(),
        'subject' => 'Q3 campaign recap',
        'snippet' => 'Last month campaign numbers are in.',
    ]);

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'name' => 'Maya Outreach',
        'email_address' => 'maya@acme.test',
    ]);

    EmailLabel::factory()->category(EmailCategory::Marketing->value)->create([
        'email_id' => $email->getKey(),
    ]);

    $this->person->emails()->attach($email->getKey());

    livewire(PeopleEmailsPage::class, ['record' => $this->person->getKey()])
        ->assertSee('Q3 campaign recap')
        ->assertSee(EmailCategory::Marketing->value);
});

it('resolves the classifier label the store path actually writes', function (): void {
    $email = Email::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->getKey(),
    ]);

    EmailLabel::factory()->category(EmailCategory::Marketing->value)->create([
        'email_id' => $email->getKey(),
    ]);

    expect($email->fresh(['labels'])->categoryLabel()?->label)->toBe(EmailCategory::Marketing->value);
});

it('hides the Other fallback instead of showing a category tag', function (): void {
    $email = Email::factory()->inbound()->full()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->getKey(),
        'subject' => 'Catching up next week',
        'snippet' => 'Are you free Thursday afternoon?',
    ]);

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'name' => 'Jane External',
        'email_address' => 'jane@external.test',
    ]);

    EmailLabel::factory()->category(EmailCategory::Other->value)->create([
        'email_id' => $email->getKey(),
    ]);

    $this->person->emails()->attach($email->getKey());

    expect($email->fresh(['labels'])->categoryLabel())->toBeNull();

    livewire(PeopleEmailsPage::class, ['record' => $this->person->getKey()])
        ->assertSee('Catching up next week')
        ->assertDontSee(EmailCategory::Other->value);
});

<?php

declare(strict_types=1);

use App\Filament\Actions\MassSendBulkAction;
use App\Filament\Resources\PeopleResource\Pages\ListPeople;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailBatch;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\EmailTemplate;

mutates(MassSendBulkAction::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'email_address' => 'sender@example.com',
        'display_name' => 'Test Sender',
    ]));
});

/**
 * Helper to attach a known email address to a person via EmailParticipant.
 */
function attachEmailToPerson(People $person, string $emailAddress, string $teamId, string $userId, string $accountId): void
{
    $email = Email::create([
        'team_id' => $teamId,
        'user_id' => $userId,
        'connected_account_id' => $accountId,
        'subject' => 'Previous email',
        'sent_at' => now()->subDays(1),
        'direction' => EmailDirection::INBOUND,
        'status' => EmailStatus::SYNCED,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'creation_source' => EmailCreationSource::SYNC,
    ]);

    EmailParticipant::create([
        'email_id' => $email->id,
        'email_address' => $emailAddress,
        'contact_id' => $person->id,
        'role' => 'to',
    ]);
}

it('creates an EmailBatch and persists one Email row per recipient', function (): void {
    $people = collect(range(1, 3))->map(fn (int $i): People => People::create([
        'team_id' => $this->team->id,
        'name' => "Person {$i}",
        'creator_id' => $this->user->id,
    ]));

    $people->each(function (People $person, int $index): void {
        attachEmailToPerson(
            $person,
            "person{$index}@example.com",
            $this->team->id,
            $this->user->id,
            $this->account->id,
        );
    });

    livewire(ListPeople::class)
        ->callTableBulkAction(
            'massSend',
            records: $people->all(),
            data: [
                'connected_account_id' => $this->account->id,
                'subject' => 'Hello everyone',
                'body_html' => '<p>Mass email body</p>',
            ],
        )
        ->assertNotified();

    expect(EmailBatch::where('team_id', $this->team->id)->count())->toBe(1);

    $batch = EmailBatch::where('team_id', $this->team->id)->first();
    expect($batch->total_recipients)->toBe(3)
        ->and($batch->status->value)->toBe('queued');

    expect(Email::where('batch_id', $batch->id)->count())->toBe(3)
        ->and(Email::where('batch_id', $batch->id)->where('status', EmailStatus::QUEUED)->count())->toBe(3);
});

it('skips people with no known email address', function (): void {
    $withEmail = People::create([
        'team_id' => $this->team->id,
        'name' => 'Has Email',
        'creator_id' => $this->user->id,
    ]);

    attachEmailToPerson($withEmail, 'has@example.com', $this->team->id, $this->user->id, $this->account->id);

    $withoutEmail = People::create([
        'team_id' => $this->team->id,
        'name' => 'No Email',
        'creator_id' => $this->user->id,
    ]);

    livewire(ListPeople::class)
        ->callTableBulkAction(
            'massSend',
            records: [$withEmail, $withoutEmail],
            data: [
                'connected_account_id' => $this->account->id,
                'subject' => 'Hello',
                'body_html' => '<p>Hi</p>',
            ],
        );

    $batch = EmailBatch::where('team_id', $this->team->id)->first();
    expect($batch->total_recipients)->toBe(1)
        ->and(Email::where('batch_id', $batch->id)->count())->toBe(1);

    $email = Email::where('batch_id', $batch->id)->firstOrFail();

    $this->assertDatabaseHas('emailables', [
        'email_id' => $email->getKey(),
        'emailable_type' => People::class,
        'emailable_id' => $withEmail->id,
    ]);
});

it('shows warning notification when no valid recipients exist', function (): void {
    $person = People::create([
        'team_id' => $this->team->id,
        'name' => 'No Email',
        'creator_id' => $this->user->id,
    ]);

    livewire(ListPeople::class)
        ->callTableBulkAction(
            'massSend',
            records: [$person],
            data: [
                'connected_account_id' => $this->account->id,
                'subject' => 'Hello',
                'body_html' => '<p>Hi</p>',
            ],
        )
        ->assertNotified('No valid recipients');

    expect(EmailBatch::count())->toBe(0);
});

it('applies template variables per recipient', function (): void {
    $personA = People::create([
        'team_id' => $this->team->id,
        'name' => 'Alice',
        'creator_id' => $this->user->id,
    ]);

    $personB = People::create([
        'team_id' => $this->team->id,
        'name' => 'Bob',
        'creator_id' => $this->user->id,
    ]);

    attachEmailToPerson($personA, 'alice@example.com', $this->team->id, $this->user->id, $this->account->id);
    attachEmailToPerson($personB, 'bob@example.com', $this->team->id, $this->user->id, $this->account->id);

    $template = EmailTemplate::create([
        'team_id' => $this->team->id,
        'created_by' => $this->user->id,
        'name' => 'Personalised',
        'subject' => 'Hi {name}',
        'body_html' => '<p>Hello {name}!</p>',
    ]);

    livewire(ListPeople::class)
        ->callTableBulkAction(
            'massSend',
            records: [$personA, $personB],
            data: [
                'connected_account_id' => $this->account->id,
                'template_id' => $template->id,
                'subject' => 'Hi {name}',
                'body_html' => '<p>Hello {name}!</p>',
            ],
        );

    $batch = EmailBatch::where('team_id', $this->team->id)->firstOrFail();

    expect(Email::where('batch_id', $batch->id)->where('subject', 'Hi Alice')->exists())->toBeTrue()
        ->and(Email::where('batch_id', $batch->id)->where('subject', 'Hi Bob')->exists())->toBeTrue();
});

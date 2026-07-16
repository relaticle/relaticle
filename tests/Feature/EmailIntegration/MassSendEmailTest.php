<?php

declare(strict_types=1);

use App\Enums\CustomFields\PeopleField;
use App\Filament\Resources\PeopleResource\Pages\ListPeople;
use App\Models\CustomField;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Actions\SendEmailBatchAction;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Filament\Actions\MassSendBulkAction;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailBatch;
use Relaticle\EmailIntegration\Models\EmailTemplate;

mutates(MassSendBulkAction::class);
mutates(SendEmailBatchAction::class);

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
 * Set a person's canonical email on the EMAILS custom field — the same source
 * MassSendBulkAction resolves recipients from. A person can have this value with
 * NO prior EmailParticipant row (i.e. never emailed before).
 */
function setPersonEmail(People $person, string $emailAddress): void
{
    $emailsField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $person->team_id)
        ->where('entity_type', 'people')
        ->where('code', PeopleField::EMAILS->value)
        ->firstOrFail();

    $person->saveCustomFieldValue($emailsField, [$emailAddress], $person->team);
}

it('creates an EmailBatch and persists one Email row per recipient', function (): void {
    $people = collect(range(1, 3))->map(fn (int $i): People => People::create([
        'team_id' => $this->team->id,
        'name' => "Person {$i}",
        'creator_id' => $this->user->id,
    ]));

    $people->each(function (People $person, int $index): void {
        setPersonEmail($person, "person{$index}@example.com");
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

    setPersonEmail($withEmail, 'has@example.com');

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
        )
        // The undercount bug: when some are skipped the toast must say so,
        // not silently report only the queued count.
        ->assertNotified('Mass email queued');

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

it('queues a person whose email is only in the custom field with no prior correspondence', function (): void {
    // No EmailParticipant row exists for this person — the only place their
    // address lives is the EMAILS custom field. The old participant-based
    // resolution silently dropped them.
    $person = People::create([
        'team_id' => $this->team->id,
        'name' => 'Never Emailed',
        'creator_id' => $this->user->id,
    ]);

    setPersonEmail($person, 'never@example.com');

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
        ->assertNotified('Mass email queued');

    $batch = EmailBatch::where('team_id', $this->team->id)->firstOrFail();
    expect($batch->total_recipients)->toBe(1);

    $email = Email::where('batch_id', $batch->id)->firstOrFail();

    $this->assertDatabaseHas('email_participants', [
        'email_id' => $email->getKey(),
        'email_address' => 'never@example.com',
        'role' => 'to',
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

    setPersonEmail($personA, 'alice@example.com');
    setPersonEmail($personB, 'bob@example.com');

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

it('scopes the template dropdown to the current team', function (): void {
    $person = People::create([
        'team_id' => $this->team->id,
        'name' => 'Recipient',
        'creator_id' => $this->user->id,
    ]);

    $ownPrivate = EmailTemplate::create([
        'team_id' => $this->team->id,
        'created_by' => $this->user->id,
        'name' => 'My private template',
        'subject' => 'Mine',
        'body_html' => '<p>Mine</p>',
        'is_shared' => false,
    ]);

    $teammateShared = EmailTemplate::create([
        'team_id' => $this->team->id,
        'created_by' => User::factory()->create()->id,
        'name' => 'Teammate shared template',
        'subject' => 'Shared',
        'body_html' => '<p>Shared</p>',
        'is_shared' => true,
    ]);

    // A shared template owned by a different team must never appear here.
    $foreignUser = User::factory()->withTeam()->create();
    $foreignShared = EmailTemplate::create([
        'team_id' => $foreignUser->currentTeam->id,
        'created_by' => $foreignUser->id,
        'name' => 'Foreign shared template',
        'subject' => 'Leaked',
        'body_html' => '<p>Leaked</p>',
        'is_shared' => true,
    ]);

    $component = livewire(ListPeople::class)
        ->mountTableBulkAction('massSend', records: [$person]);

    $options = $component->instance()
        ->getMountedTableActionForm()
        ->getComponent('template_id')
        ->getOptions();

    expect(array_keys($options))
        ->toContain($ownPrivate->id)
        ->toContain($teammateShared->id)
        ->not->toContain($foreignShared->id);
});

it('rejects a cross-team template id submitted on send', function (): void {
    $person = People::create([
        'team_id' => $this->team->id,
        'name' => 'Recipient',
        'creator_id' => $this->user->id,
    ]);
    setPersonEmail($person, 'recipient@example.com');

    // A crafted submit could carry another team's template id even though the
    // dropdown never offered it. The template select must reject it (its options
    // are team-scoped) rather than render the foreign template into the send.
    $foreignUser = User::factory()->withTeam()->create();
    $foreignTemplate = EmailTemplate::create([
        'team_id' => $foreignUser->currentTeam->id,
        'created_by' => $foreignUser->id,
        'name' => 'Foreign template',
        'subject' => 'LEAKED SUBJECT',
        'body_html' => '<p>LEAKED BODY</p>',
        'is_shared' => true,
    ]);

    livewire(ListPeople::class)
        ->callTableBulkAction(
            'massSend',
            records: [$person],
            data: [
                'connected_account_id' => $this->account->id,
                'template_id' => $foreignTemplate->id,
                'subject' => 'Plain subject',
                'body_html' => '<p>Plain body</p>',
            ],
        )
        ->assertHasTableActionErrors(['template_id']);

    expect(EmailBatch::where('team_id', $this->team->id)->exists())->toBeFalse();
});

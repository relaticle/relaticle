<?php

declare(strict_types=1);

use App\Enums\CustomFields\PeopleField;
use App\Features\EmailIntegration;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Filament\Resources\PeopleResource\Pages\ListPeople;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Relaticle\EmailIntegration\Actions\SendEmailBatchAction;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Filament\Actions\MassSendBulkAction;
use Relaticle\EmailIntegration\Livewire\EmailComposer;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailBatch;
use Relaticle\EmailIntegration\Models\EmailSignature;
use Relaticle\EmailIntegration\Models\EmailTemplate;
use Relaticle\EmailIntegration\Services\EmailTemplateRenderService;
use Relaticle\EmailIntegration\Services\MassSendRecipientResolver;
use Relaticle\EmailIntegration\Services\OpenMassSendComposer;
use Relaticle\EmailIntegration\Support\PersonRecipientFormatter;

mutates(MassSendBulkAction::class);
mutates(SendEmailBatchAction::class);
mutates(MassSendRecipientResolver::class);
mutates(OpenMassSendComposer::class);
mutates(PersonRecipientFormatter::class);
mutates(EmailComposer::class);

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
 * @return list<array{personId: string, email: string, name: string}>
 */
function massRecipientPayload(People $person, string $email): array
{
    return [[
        'personId' => (string) $person->getKey(),
        'email' => $email,
        'name' => (string) $person->name,
    ]];
}

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

it('hides mass send when email integration is disabled', function (): void {
    Feature::deactivate(EmailIntegration::class);

    livewire(ListPeople::class)
        ->assertTableBulkActionHidden('massSend');
});

it('shows mass send when email integration is enabled and an account is connected', function (): void {
    livewire(ListPeople::class)
        ->assertTableBulkActionVisible('massSend');
});

it('hides mass send when no connected account can send', function (): void {
    $this->account->update([
        'capabilities' => [
            'email' => true,
            'send' => false,
            'calendar' => false,
        ],
    ]);

    livewire(ListPeople::class)
        ->assertTableBulkActionHidden('massSend');
});

it('opens the composer from people bulk send with resolved recipients', function (): void {
    $people = collect(range(1, 3))->map(fn (int $i): People => People::create([
        'team_id' => $this->team->id,
        'name' => "Person {$i}",
        'creator_id' => $this->user->id,
    ]));

    $people->each(function (People $person, int $index): void {
        setPersonEmail($person, "person{$index}@example.com");
    });

    livewire(ListPeople::class)
        ->callTableBulkAction('massSend', records: $people->all())
        ->assertDispatched('composer:open');
});

it('creates an EmailBatch and persists one Email row per recipient from the composer', function (): void {
    $people = collect(range(1, 3))->map(fn (int $i): People => People::create([
        'team_id' => $this->team->id,
        'name' => "Person {$i}",
        'creator_id' => $this->user->id,
    ]));

    $people->each(function (People $person, int $index): void {
        setPersonEmail($person, "person{$index}@example.com");
    });

    $recipients = $people->map(fn (People $person, int $index): array => [
        'personId' => (string) $person->getKey(),
        'email' => "person{$index}@example.com",
        'name' => $person->name,
    ])->values()->all();

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open', payload: [
            'massSend' => true,
            'recipients' => $recipients,
        ])
        ->assertSet('isMassSend', true)
        ->assertSet('massRecipients', $recipients)
        ->set('subject', 'Hello everyone')
        ->set('bodyHtml', '<p>Mass email body</p>')
        ->call('send')
        ->assertDispatched('outbox:changed');

    expect(EmailBatch::where('team_id', $this->team->id)->count())->toBe(1);

    $batch = EmailBatch::where('team_id', $this->team->id)->first();
    expect($batch->total_recipients)->toBe(3)
        ->and($batch->status->value)->toBe('queued');

    expect(Email::where('batch_id', $batch->id)->count())->toBe(3)
        ->and(Email::where('batch_id', $batch->id)->where('status', EmailStatus::QUEUED)->count())->toBe(3);
});

it('warns when some selected people have no email but still opens the composer', function (): void {
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
        ->callTableBulkAction('massSend', records: [$withEmail, $withoutEmail])
        ->assertNotified('Some recipients skipped')
        ->assertDispatched('composer:open');
});

it('queues a person whose email is only in the custom field with no prior correspondence', function (): void {
    $person = People::create([
        'team_id' => $this->team->id,
        'name' => 'Never Emailed',
        'creator_id' => $this->user->id,
    ]);

    setPersonEmail($person, 'never@example.com');

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open', payload: [
            'massSend' => true,
            'recipients' => massRecipientPayload($person, 'never@example.com'),
        ])
        ->set('subject', 'Hello')
        ->set('bodyHtml', '<p>Hi</p>')
        ->call('send');

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
        ->callTableBulkAction('massSend', records: [$person])
        ->assertNotified('No valid recipients');

    expect(EmailBatch::count())->toBe(0);
});

it('sends a personalized subject as plain text when no template is saved', function (): void {
    $person = People::create([
        'team_id' => $this->team->id,
        'name' => 'Smith & Sons',
        'creator_id' => $this->user->id,
    ]);

    setPersonEmail($person, 'smith@example.com');

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open', payload: [
            'massSend' => true,
            'recipients' => massRecipientPayload($person, 'smith@example.com'),
        ])
        ->set('subject', 'Hello {name}')
        ->set('bodyHtml', '<p>Hi {name}</p>')
        ->call('send');

    $batch = EmailBatch::where('team_id', $this->team->id)->firstOrFail();
    $email = Email::where('batch_id', $batch->id)->firstOrFail();

    expect($email->subject)->toBe('Hello Smith & Sons');
    expect($email->body->body_html)->toBe('<p>Hi Smith &amp; Sons</p>');
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

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open', payload: [
            'massSend' => true,
            'recipients' => [
                ...massRecipientPayload($personA, 'alice@example.com'),
                ...massRecipientPayload($personB, 'bob@example.com'),
            ],
        ])
        ->set('subject', 'Hi {name}')
        ->set('bodyHtml', '<p>Hello {name}!</p>')
        ->call('send');

    $batch = EmailBatch::where('team_id', $this->team->id)->firstOrFail();

    expect(Email::where('batch_id', $batch->id)->where('subject', 'Hi Alice')->exists())->toBeTrue()
        ->and(Email::where('batch_id', $batch->id)->where('subject', 'Hi Bob')->exists())->toBeTrue();
});

it('removes a mass recipient from the sidebar', function (): void {
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

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open', payload: [
            'massSend' => true,
            'recipients' => [
                ...massRecipientPayload($personA, 'alice@example.com'),
                ...massRecipientPayload($personB, 'bob@example.com'),
            ],
        ])
        ->call('removeMassRecipient', (string) $personB->getKey())
        ->assertCount('massRecipients', 1)
        ->set('subject', 'Hello')
        ->set('bodyHtml', '<p>Hi</p>')
        ->call('send');

    $batch = EmailBatch::where('team_id', $this->team->id)->firstOrFail();
    expect($batch->total_recipients)->toBe(1);
});

it('adds all company team members from the mass send sidebar search', function (): void {
    $company = Company::create([
        'team_id' => $this->team->id,
        'name' => 'Acme Corp',
        'creator_id' => $this->user->id,
    ]);

    $personA = People::create([
        'team_id' => $this->team->id,
        'name' => 'Alice',
        'company_id' => $company->id,
        'creator_id' => $this->user->id,
    ]);

    $personB = People::create([
        'team_id' => $this->team->id,
        'name' => 'Bob',
        'company_id' => $company->id,
        'creator_id' => $this->user->id,
    ]);

    setPersonEmail($personA, 'alice@example.com');
    setPersonEmail($personB, 'bob@example.com');

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open', payload: ['massSend' => true, 'recipients' => []])
        ->assertSet('isMassSend', true)
        ->call('addMassCompanyTeamRecipients', (string) $company->getKey())
        ->assertCount('massRecipients', 2)
        ->call('addMassCompanyTeamRecipients', (string) $company->getKey())
        ->assertCount('massRecipients', 2);
});

it('opens the composer for company bulk send with all linked people who have email', function (): void {
    $company = Company::create([
        'team_id' => $this->team->id,
        'name' => 'Acme Corp',
        'creator_id' => $this->user->id,
    ]);

    $memberA = People::create([
        'team_id' => $this->team->id,
        'name' => 'Alice',
        'company_id' => $company->id,
        'creator_id' => $this->user->id,
    ]);

    $memberB = People::create([
        'team_id' => $this->team->id,
        'name' => 'Bob',
        'company_id' => $company->id,
        'creator_id' => $this->user->id,
    ]);

    People::create([
        'team_id' => $this->team->id,
        'name' => 'No Email',
        'company_id' => $company->id,
        'creator_id' => $this->user->id,
    ]);

    setPersonEmail($memberA, 'alice@example.com');
    setPersonEmail($memberB, 'bob@example.com');

    livewire(ListCompanies::class)
        ->callTableBulkAction('massSend', records: [$company])
        ->assertNotified('Some recipients skipped')
        ->assertDispatched('composer:open');
});

it('queues one email per company member from the composer', function (): void {
    $company = Company::create([
        'team_id' => $this->team->id,
        'name' => 'Acme Corp',
        'creator_id' => $this->user->id,
    ]);

    $memberA = People::create([
        'team_id' => $this->team->id,
        'name' => 'Alice',
        'company_id' => $company->id,
        'creator_id' => $this->user->id,
    ]);

    $memberB = People::create([
        'team_id' => $this->team->id,
        'name' => 'Bob',
        'company_id' => $company->id,
        'creator_id' => $this->user->id,
    ]);

    setPersonEmail($memberA, 'alice@example.com');
    setPersonEmail($memberB, 'bob@example.com');

    $result = resolve(MassSendRecipientResolver::class)->resolveFromCompanies(
        Company::query()->whereKey($company->getKey())->get(),
    );

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open', payload: [
            'massSend' => true,
            'recipients' => array_map(
                fn (array $recipient): array => [
                    'personId' => (string) $recipient['person']->getKey(),
                    'email' => $recipient['email'],
                    'name' => (string) $recipient['person']->name,
                ],
                $result->recipients,
            ),
            'linkRecordType' => Company::class,
            'linkRecordId' => (string) $company->getKey(),
        ])
        ->set('subject', 'Hello team')
        ->set('bodyHtml', '<p>Hi</p>')
        ->call('send');

    $batch = EmailBatch::where('team_id', $this->team->id)->firstOrFail();
    expect($batch->total_recipients)->toBe(2);
});

it('applies an authorized template through the composer picker', function (): void {
    $person = People::create([
        'team_id' => $this->team->id,
        'name' => 'Recipient',
        'creator_id' => $this->user->id,
    ]);

    setPersonEmail($person, 'recipient@example.com');

    $template = EmailTemplate::create([
        'team_id' => $this->team->id,
        'created_by' => $this->user->id,
        'name' => 'Authorized template',
        'subject' => 'Authorized subject',
        'body_html' => '<p>Authorized body</p>',
        'is_shared' => false,
    ]);

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open', payload: [
            'massSend' => true,
            'recipients' => massRecipientPayload($person, 'recipient@example.com'),
        ])
        ->call('applyTemplate', (string) $template->getKey())
        ->assertSet('subject', 'Authorized subject')
        ->set('subject', 'Edited hello {name}')
        ->set('bodyHtml', '<p>Edited body for {name}</p>')
        ->call('send');

    $batch = EmailBatch::where('team_id', $this->team->id)->firstOrFail();
    $email = Email::where('batch_id', $batch->id)->firstOrFail();

    expect($email->subject)->toBe('Edited hello Recipient')
        ->and($email->body->body_html)->toBe('<p>Edited body for Recipient</p>');
});

it('sends one email when mass sending is turned off', function (): void {
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

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open', payload: [
            'massSend' => true,
            'recipients' => [
                ...massRecipientPayload($personA, 'alice@example.com'),
                ...massRecipientPayload($personB, 'bob@example.com'),
            ],
        ])
        ->set('isMassSend', false)
        ->assertSet('to', ['alice@example.com', 'bob@example.com'])
        ->set('subject', 'One email')
        ->set('bodyHtml', '<p>Hello both</p>')
        ->call('send');

    expect(EmailBatch::count())->toBe(0);
    expect(Email::where('subject', 'One email')->count())->toBe(1);
});

it('builds mass recipients when mass sending is enabled from normal compose', function (): void {
    $person = People::create([
        'team_id' => $this->team->id,
        'name' => 'Alice',
        'creator_id' => $this->user->id,
    ]);

    setPersonEmail($person, 'alice@example.com');

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('to', ['alice@example.com'])
        ->set('isMassSend', true)
        ->assertSet('to', [])
        ->assertSet('massRecipients', massRecipientPayload($person, 'alice@example.com'));
});

it('uses the email address when a person name looks like an empty json array', function (): void {
    $person = People::create([
        'team_id' => $this->team->id,
        'name' => '[""]',
        'creator_id' => $this->user->id,
    ]);

    setPersonEmail($person, 'broken-name@example.com');

    $result = resolve(MassSendRecipientResolver::class)->resolveFromPeople(new Collection([$person]));

    expect($result->recipients)->toHaveCount(1)
        ->and($result->recipients[0]['email'])->toBe('broken-name@example.com');

    livewire(ListPeople::class)
        ->callTableBulkAction('massSend', records: [$person])
        ->assertDispatched('composer:open');

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open', payload: [
            'massSend' => true,
            'recipients' => [[
                'personId' => (string) $person->getKey(),
                'email' => 'broken-name@example.com',
                'name' => 'broken-name@example.com',
            ]],
        ])
        ->assertSet('massRecipients.0.name', 'broken-name@example.com');
});

it('replaces an open compose session when bulk mass send opens the composer again', function (): void {
    $person = People::create([
        'team_id' => $this->team->id,
        'name' => 'Alice',
        'creator_id' => $this->user->id,
    ]);

    setPersonEmail($person, 'alice@example.com');

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('subject', 'In progress')
        ->set('to', ['alice@example.com'])
        ->call('minimize')
        ->assertSet('isMinimized', true)
        ->dispatch('composer:open', payload: [
            'massSend' => true,
            'recipients' => massRecipientPayload($person, 'alice@example.com'),
        ])
        ->assertSet('isMinimized', false)
        ->assertSet('isMassSend', true)
        ->assertSet('massRecipients', massRecipientPayload($person, 'alice@example.com'))
        ->assertSet('to', [])
        ->assertSet('subject', '');
});

it('expands signatures and resolves merge tags per recipient on mass send', function (): void {
    $signature = EmailSignature::withoutEvents(fn () => EmailSignature::factory()->create([
        'connected_account_id' => $this->account->id,
        'content_html' => '<p>Best regards</p>',
        'is_default' => true,
    ]));

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

    $bodyHtml = resolve(EmailTemplateRenderService::class)
        ->applySignatureBlock('<p>Hello {name}</p>', $signature);

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open', payload: [
            'massSend' => true,
            'recipients' => [
                ...massRecipientPayload($personA, 'alice@example.com'),
                ...massRecipientPayload($personB, 'bob@example.com'),
            ],
        ])
        ->set('subject', 'Hi {name}')
        ->set('bodyHtml', $bodyHtml)
        ->call('send');

    $batch = EmailBatch::where('team_id', $this->team->id)->firstOrFail();
    $emails = Email::query()->where('batch_id', $batch->id)->with('body')->get();

    $aliceEmail = $emails->first(fn (Email $email): bool => $email->subject === 'Hi Alice');
    $bobEmail = $emails->first(fn (Email $email): bool => $email->subject === 'Hi Bob');

    expect($emails)->toHaveCount(2)
        ->and($aliceEmail)->not->toBeNull()
        ->and($bobEmail)->not->toBeNull()
        ->and($aliceEmail->body->body_html)->toContain('Hello Alice')
        ->and($aliceEmail->body->body_html)->toContain('Best regards')
        ->and($bobEmail->body->body_html)->toContain('Hello Bob')
        ->and($bobEmail->body->body_html)->toContain('Best regards')
        ->and($aliceEmail->body->body_html)->not->toContain('data-id="signature"');
});

it('ignores a tampered mass recipient email and sends to the CRM address', function (): void {
    $person = People::create([
        'team_id' => $this->team->id,
        'name' => 'Trusted Person',
        'creator_id' => $this->user->id,
    ]);

    setPersonEmail($person, 'real@example.com');

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open', payload: [
            'massSend' => true,
            'recipients' => [[
                'personId' => (string) $person->getKey(),
                'email' => 'attacker@evil.com',
                'name' => (string) $person->name,
            ]],
        ])
        ->set('subject', 'Tampered address')
        ->set('bodyHtml', '<p>Hi</p>')
        ->call('send');

    $email = Email::query()->where('subject', 'Tampered address')->firstOrFail();

    $this->assertDatabaseHas('email_participants', [
        'email_id' => $email->getKey(),
        'email_address' => 'real@example.com',
        'role' => 'to',
    ]);

    $this->assertDatabaseMissing('email_participants', [
        'email_id' => $email->getKey(),
        'email_address' => 'attacker@evil.com',
    ]);
});

it('drops recipients removed from To when mass sending is toggled off and back on', function (): void {
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

    $personC = People::create([
        'team_id' => $this->team->id,
        'name' => 'Carol',
        'creator_id' => $this->user->id,
    ]);

    setPersonEmail($personA, 'alice@example.com');
    setPersonEmail($personB, 'bob@example.com');
    setPersonEmail($personC, 'carol@example.com');

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open', payload: [
            'massSend' => true,
            'recipients' => [
                ...massRecipientPayload($personA, 'alice@example.com'),
                ...massRecipientPayload($personB, 'bob@example.com'),
                ...massRecipientPayload($personC, 'carol@example.com'),
            ],
        ])
        ->set('isMassSend', false)
        ->set('to', ['alice@example.com'])
        ->set('isMassSend', true)
        ->assertCount('massRecipients', 1)
        ->assertSet('massRecipients.0.personId', (string) $personA->getKey())
        ->set('subject', 'Subset send')
        ->set('bodyHtml', '<p>Hi</p>')
        ->call('send');

    $batch = EmailBatch::where('team_id', $this->team->id)->firstOrFail();

    expect($batch->total_recipients)->toBe(1);
});

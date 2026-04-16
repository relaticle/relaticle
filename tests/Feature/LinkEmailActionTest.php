<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\CustomField;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Actions\LinkEmailAction;
use Relaticle\EmailIntegration\Enums\ContactCreationMode;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\PublicEmailDomain;

mutates(LinkEmailAction::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));
});

function makeLinkEmail(array $overrides = []): Email
{
    return Email::factory()->create(array_merge([
        'team_id' => test()->team->id,
        'user_id' => test()->user->id,
        'connected_account_id' => test()->account->getKey(),
    ], $overrides));
}

test('LinkEmailAction links email to an existing company matched by domain', function (): void {
    $domainsField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'company')
        ->where('code', 'domains')
        ->first();

    $company = Company::create([
        'team_id' => $this->team->id,
        'name' => 'Acme',
        'creator_id' => $this->user->id,
    ]);

    if ($domainsField) {
        $company->saveCustomFieldValue($domainsField, 'https://acme.com', $this->team);
    }

    $email = makeLinkEmail();

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'contact@acme.com',
    ]);

    app(LinkEmailAction::class)->execute($email);

    if ($domainsField) {
        expect($email->companies()->where('companies.id', $company->getKey())->exists())->toBeTrue();
    } else {
        $this->markTestSkipped('No domains custom field seeded for this team.');
    }
});

test('LinkEmailAction skips company matching for public email domains', function (): void {
    $email = makeLinkEmail();

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'person@gmail.com',
    ]);

    app(LinkEmailAction::class)->execute($email);

    expect($email->companies()->count())->toBe(0);
});

test('LinkEmailAction skips company matching for team-specific public domains', function (): void {
    PublicEmailDomain::factory()->create([
        'team_id' => $this->team->id,
        'domain' => 'internal-mailer.com',
    ]);

    $email = makeLinkEmail();

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'noreply@internal-mailer.com',
    ]);

    app(LinkEmailAction::class)->execute($email);

    expect($email->companies()->count())->toBe(0);
});

test('LinkEmailAction links email to an existing person matched by email custom field', function (): void {
    $emailField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'people')
        ->where('code', 'emails')
        ->first();

    if (! $emailField) {
        $this->markTestSkipped('No emails custom field seeded for this team.');
    }

    $person = People::create([
        'team_id' => $this->team->id,
        'name' => 'Jane Doe',
        'creator_id' => $this->user->id,
    ]);

    $person->saveCustomFieldValue($emailField, ['jane@external.com'], $this->team);

    $email = makeLinkEmail();

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'jane@external.com',
    ]);

    app(LinkEmailAction::class)->execute($email);

    expect($email->people()->where('people.id', $person->getKey())->exists())->toBeTrue();
});

test('LinkEmailAction updates participant contact_id when linked to a person', function (): void {
    $emailField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'people')
        ->where('code', 'emails')
        ->first();

    if (! $emailField) {
        $this->markTestSkipped('No emails custom field seeded for this team.');
    }

    $person = People::create([
        'team_id' => $this->team->id,
        'name' => 'Bob Smith',
        'creator_id' => $this->user->id,
    ]);

    $person->saveCustomFieldValue($emailField, ['bob@partner.com'], $this->team);

    $email = makeLinkEmail();

    $participant = EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'bob@partner.com',
    ]);

    app(LinkEmailAction::class)->execute($email);

    expect($participant->fresh()->contact_id)->toBe($person->getKey());
});

test('LinkEmailAction does not auto-create companies when auto_create_companies is false', function (): void {
    $email = makeLinkEmail();

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'unknown@unknowncorp.com',
    ]);

    $countBefore = Company::where('team_id', $this->team->id)->count();

    app(LinkEmailAction::class)->execute($email);

    expect(Company::where('team_id', $this->team->id)->count())->toBe($countBefore);
});

test('LinkEmailAction auto-creates a company when auto_create_companies is true', function (): void {
    $this->account->update(['auto_create_companies' => true]);

    $email = makeLinkEmail();

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'contact@brandnewcorp.com',
    ]);

    app(LinkEmailAction::class)->execute($email);

    expect(Company::where('team_id', $this->team->id)->where('name', 'Brandnewcorp')->exists())->toBeTrue();
});

test('LinkEmailAction does not auto-create a person when contact_creation_mode is None', function (): void {
    $countBefore = People::where('team_id', $this->team->id)->count();

    $email = makeLinkEmail();

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'newperson@external.com',
    ]);

    app(LinkEmailAction::class)->execute($email);

    expect(People::where('team_id', $this->team->id)->count())->toBe($countBefore);
});

test('LinkEmailAction auto-creates a person when contact_creation_mode is All', function (): void {
    $this->account->update(['contact_creation_mode' => ContactCreationMode::All]);

    $email = makeLinkEmail();

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'newcontact@partner.com',
        'name' => 'New Contact',
    ]);

    app(LinkEmailAction::class)->execute($email);

    expect(People::where('team_id', $this->team->id)->where('name', 'New Contact')->exists())->toBeTrue();
});

test('LinkEmailAction does not auto-create a person when Bidirectional and only one direction exists', function (): void {
    $this->account->update(['contact_creation_mode' => ContactCreationMode::Bidirectional]);

    // Only inbound email — no outbound yet
    $inboundEmail = makeLinkEmail(['direction' => EmailDirection::INBOUND]);

    EmailParticipant::factory()->from()->create([
        'email_id' => $inboundEmail->getKey(),
        'email_address' => 'bidirectional@partner.com',
    ]);

    $countBefore = People::where('team_id', $this->team->id)->count();

    // Store first (no existing bidirectional history), then link the new email
    $newEmail = makeLinkEmail(['direction' => EmailDirection::INBOUND]);

    EmailParticipant::factory()->from()->create([
        'email_id' => $newEmail->getKey(),
        'email_address' => 'bidirectional@partner.com',
    ]);

    app(LinkEmailAction::class)->execute($newEmail);

    expect(People::where('team_id', $this->team->id)->count())->toBe($countBefore);
});

test('LinkEmailAction auto-creates a person when Bidirectional and both directions exist', function (): void {
    $this->account->update(['contact_creation_mode' => ContactCreationMode::Bidirectional]);

    $address = 'bidirectional@bidirectional.com';

    // Seed one inbound and one outbound email already in the system for this address
    $inbound = makeLinkEmail(['direction' => EmailDirection::INBOUND]);
    EmailParticipant::factory()->from()->create(['email_id' => $inbound->getKey(), 'email_address' => $address]);

    $outbound = makeLinkEmail(['direction' => EmailDirection::OUTBOUND]);
    EmailParticipant::factory()->to()->create(['email_id' => $outbound->getKey(), 'email_address' => $address]);

    // Now link a third email — should trigger person creation because both directions exist
    $newEmail = makeLinkEmail(['direction' => EmailDirection::INBOUND]);
    EmailParticipant::factory()->from()->create(['email_id' => $newEmail->getKey(), 'email_address' => $address, 'name' => 'Bidirectional Contact']);

    $countBefore = People::where('team_id', $this->team->id)->count();

    app(LinkEmailAction::class)->execute($newEmail);

    expect(People::where('team_id', $this->team->id)->count())->toBe($countBefore + 1);
});

test('LinkEmailAction increments person email_count when linked', function (): void {
    $emailField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'people')
        ->where('code', 'emails')
        ->first();

    if (! $emailField) {
        $this->markTestSkipped('No emails custom field seeded for this team.');
    }

    $person = People::create([
        'team_id' => $this->team->id,
        'name' => 'Metric Person',
        'creator_id' => $this->user->id,
        'email_count' => 0,
    ]);

    $person->saveCustomFieldValue($emailField, ['metric@company.com'], $this->team);

    $email = makeLinkEmail(['direction' => EmailDirection::INBOUND]);

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'metric@company.com',
    ]);

    app(LinkEmailAction::class)->execute($email);

    expect($person->fresh()->email_count)->toBe(1)
        ->and($person->fresh()->inbound_email_count)->toBe(1)
        ->and($person->fresh()->outbound_email_count)->toBe(0);
});

test('LinkEmailAction also links email to opportunity via person relationship', function (): void {
    $emailField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'people')
        ->where('code', 'emails')
        ->first();

    if (! $emailField) {
        $this->markTestSkipped('No emails custom field seeded for this team.');
    }

    $person = People::create([
        'team_id' => $this->team->id,
        'name' => 'Opportunity Contact',
        'creator_id' => $this->user->id,
    ]);

    $person->saveCustomFieldValue($emailField, ['opp@partner.com'], $this->team);

    $opportunity = Opportunity::create([
        'team_id' => $this->team->id,
        'name' => 'Big Deal',
        'contact_id' => $person->getKey(),
        'creator_id' => $this->user->id,
    ]);

    $email = makeLinkEmail();

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'opp@partner.com',
    ]);

    app(LinkEmailAction::class)->execute($email);

    expect($email->opportunities()->where('opportunities.id', $opportunity->getKey())->exists())->toBeTrue();
});

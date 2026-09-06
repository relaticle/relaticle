<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Actions\AutoCreateCompanyAction;
use Relaticle\EmailIntegration\Actions\AutoCreatePersonAction;
use Relaticle\EmailIntegration\Actions\LinkEmailAction;
use Relaticle\EmailIntegration\Enums\ContactCreationMode;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailBlocklist;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\PublicEmailDomain;
use Relaticle\EmailIntegration\Models\TeamEmailBlocklist;

mutates(LinkEmailAction::class);
mutates(AutoCreatePersonAction::class);

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

it('links email to an existing company matched by domain', function (): void {
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

it('does not match a company on a substring domain collision', function (): void {
    $domainsField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'company')
        ->where('code', 'domains')
        ->first();

    if (! $domainsField) {
        $this->markTestSkipped('No domains custom field seeded for this team.');
    }

    $company = Company::create([
        'team_id' => $this->team->id,
        'name' => 'Acme AU',
        'creator_id' => $this->user->id,
    ]);

    $company->saveCustomFieldValue($domainsField, 'https://acme.com.au', $this->team);

    $email = makeLinkEmail();

    // Sender is acme.co — must NOT collide with the stored acme.com.au domain.
    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'contact@acme.co',
    ]);

    app(LinkEmailAction::class)->execute($email);

    expect($email->companies()->where('companies.id', $company->getKey())->exists())->toBeFalse();
});

it('does not treat LIKE wildcards in the sender domain as a match', function (): void {
    $domainsField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'company')
        ->where('code', 'domains')
        ->first();

    if (! $domainsField) {
        $this->markTestSkipped('No domains custom field seeded for this team.');
    }

    $company = Company::create([
        'team_id' => $this->team->id,
        'name' => 'Wildcard Co',
        'creator_id' => $this->user->id,
    ]);

    $company->saveCustomFieldValue($domainsField, 'https://wildcard.com', $this->team);

    $email = makeLinkEmail();

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'spoof@wild_ard.com',
    ]);

    app(LinkEmailAction::class)->execute($email);

    expect($email->companies()->where('companies.id', $company->getKey())->exists())->toBeFalse();
});

it('skips company matching for public email domains', function (): void {
    $email = makeLinkEmail();

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'person@gmail.com',
    ]);

    app(LinkEmailAction::class)->execute($email);

    expect($email->companies()->count())->toBe(0);
});

it('skips company matching for team-specific public domains', function (): void {
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

it('links email to an existing person matched by email custom field', function (): void {
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

it('updates participant contact_id when linked to a person', function (): void {
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

it('does not auto-create companies when auto_create_companies is false', function (): void {
    $this->team->update(['auto_create_companies' => false]);

    $email = makeLinkEmail();

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'unknown@unknowncorp.com',
    ]);

    $countBefore = Company::where('team_id', $this->team->id)->count();

    app(LinkEmailAction::class)->execute($email);

    expect(Company::where('team_id', $this->team->id)->count())->toBe($countBefore);
});

it('auto-creates a company when auto_create_companies is true', function (): void {
    $this->team->update(['auto_create_companies' => true]);

    $email = makeLinkEmail();

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'contact@brandnewcorp.com',
    ]);

    app(LinkEmailAction::class)->execute($email);

    expect(Company::where('team_id', $this->team->id)->where('name', 'Brandnewcorp')->exists())->toBeTrue();
});

it('derives the company name from the registrable domain, not a mail subdomain', function (): void {
    $this->team->update(['auto_create_companies' => true]);

    $email = makeLinkEmail();

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'john@email.anthropic.com',
    ]);

    app(LinkEmailAction::class)->execute($email);

    expect(Company::where('team_id', $this->team->id)->where('name', 'Anthropic')->exists())->toBeTrue()
        ->and(Company::where('team_id', $this->team->id)->where('name', 'Email')->exists())->toBeFalse();
});

it('derives the company name from the registrable label across TLD shapes', function (string $address, string $expected): void {
    $this->team->update(['auto_create_companies' => true]);

    $email = makeLinkEmail();

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => $address,
    ]);

    app(LinkEmailAction::class)->execute($email);

    expect(Company::where('team_id', $this->team->id)->where('name', $expected)->exists())->toBeTrue();
})->with([
    'plain TLD' => ['john@acme.com', 'Acme'],
    'mail subdomain' => ['john@mail.acme.io', 'Acme'],
    'two-label TLD (co.uk)' => ['john@acme.co.uk', 'Acme'],
    'subdomain + two-label TLD' => ['john@mail.acme.co.uk', 'Acme'],
    'two-label TLD (co.us)' => ['john@acme.co.us', 'Acme'],
    'three-label suffix (k12.ak.us)' => ['john@acme.k12.ak.us', 'Acme'],
    'unknown new TLD' => ['john@acme.xyz', 'Acme'],
]);

it('does not auto-create a company for a no-reply / automated sender', function (): void {
    $this->team->update(['auto_create_companies' => true]);

    $email = makeLinkEmail();

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'notice@email.anthropic.com',
    ]);

    $countBefore = Company::where('team_id', $this->team->id)->count();

    app(LinkEmailAction::class)->execute($email);

    expect(Company::where('team_id', $this->team->id)->count())->toBe($countBefore);
});

it('does not auto-create a person for a no-reply / automated sender', function (): void {
    $this->team->update(['contact_creation_mode' => ContactCreationMode::All]);

    $email = makeLinkEmail();

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'noreply@partner.com',
        'name' => 'Partner Notifications',
    ]);

    $countBefore = People::where('team_id', $this->team->id)->count();

    app(LinkEmailAction::class)->execute($email);

    expect(People::where('team_id', $this->team->id)->count())->toBe($countBefore);
});

it('seeds an auto-created company with a protocol-less domain and ICP set to false', function (): void {
    $this->team->update(['auto_create_companies' => true]);

    $email = makeLinkEmail();

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'contact@brandnewcorp.com',
    ]);

    app(LinkEmailAction::class)->execute($email);

    $company = Company::where('team_id', $this->team->id)
        ->where('name', 'Brandnewcorp')
        ->with('customFieldValues.customField')
        ->firstOrFail();

    $domainsField = CustomField::query()
        ->where('tenant_id', $this->team->id)
        ->where('entity_type', 'company')
        ->where('code', 'domains')
        ->first();

    $icpField = CustomField::query()
        ->where('tenant_id', $this->team->id)
        ->where('entity_type', 'company')
        ->where('code', 'icp')
        ->first();

    if ($domainsField) {
        expect($company->getCustomFieldValue($domainsField))
            ->toContain('www.brandnewcorp.com')
            ->not->toContain('https://');
    }

    if ($icpField) {
        expect($company->getCustomFieldValue($icpField))->toBeFalse();
    }
});

it('does not create a duplicate company when the domain is already owned', function (): void {
    $action = app(AutoCreateCompanyAction::class);

    $first = $action->execute('brandnewcorp.com', $this->team->id, $this->team);
    $second = $action->execute('brandnewcorp.com', $this->team->id, $this->team);

    expect($second->getKey())->toBe($first->getKey());
    expect(Company::where('team_id', $this->team->id)->where('name', 'Brandnewcorp')->count())->toBe(1);
});

it('reuses an existing company that already owns the domain instead of creating one', function (): void {
    $domainsField = CustomField::query()
        ->where('tenant_id', $this->team->id)
        ->where('entity_type', 'company')
        ->where('code', 'domains')
        ->first();

    if (! $domainsField) {
        $this->markTestSkipped('No domains custom field seeded for this team.');
    }

    $existing = Company::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Acme Corp',
    ]);
    $existing->saveCustomFieldValue($domainsField, 'https://acme.com', $this->team);

    $resolved = app(AutoCreateCompanyAction::class)->execute('acme.com', $this->team->id, $this->team);

    expect($resolved->getKey())->toBe($existing->getKey());
    expect(Company::where('team_id', $this->team->id)->where('name', 'Acme')->exists())->toBeFalse();
});

it('creates distinct companies for same-named domains with different TLDs and preserves the first domain', function (): void {
    $domainsField = CustomField::query()
        ->where('tenant_id', $this->team->id)
        ->where('entity_type', 'company')
        ->where('code', 'domains')
        ->first();

    if (! $domainsField) {
        $this->markTestSkipped('No domains custom field seeded for this team.');
    }

    $action = app(AutoCreateCompanyAction::class);

    $first = $action->execute('acme.com', $this->team->id, $this->team);
    $second = $action->execute('acme.org', $this->team->id, $this->team);

    // Two distinct companies — same first label, different TLD must not dedup.
    expect($second->getKey())->not->toBe($first->getKey());
    expect(Company::where('team_id', $this->team->id)
        ->where('creation_source', CreationSource::SYSTEM)
        ->count())->toBe(2);

    // acme.com's domain is intact, not clobbered by acme.org.
    $firstFresh = Company::with('customFieldValues.customField')->findOrFail($first->getKey());
    $secondFresh = Company::with('customFieldValues.customField')->findOrFail($second->getKey());
    expect($firstFresh->getCustomFieldValue($domainsField))->toContain('www.acme.com');
    expect($secondFresh->getCustomFieldValue($domainsField))->toContain('www.acme.org');
});

it('does not auto-create a person when contact_creation_mode is None', function (): void {
    $this->team->update(['contact_creation_mode' => ContactCreationMode::None]);

    $countBefore = People::where('team_id', $this->team->id)->count();

    $email = makeLinkEmail();

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'newperson@external.com',
    ]);

    app(LinkEmailAction::class)->execute($email);

    expect(People::where('team_id', $this->team->id)->count())->toBe($countBefore);
});

it('auto-creates a person when contact_creation_mode is All', function (): void {
    $this->team->update(['contact_creation_mode' => ContactCreationMode::All]);

    $email = makeLinkEmail();

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'newcontact@partner.com',
        'name' => 'New Contact',
    ]);

    app(LinkEmailAction::class)->execute($email);

    expect(People::where('team_id', $this->team->id)->where('name', 'New Contact')->exists())->toBeTrue();
});

it('does not auto-create a person for a workspace-blocked address', function (): void {
    $this->team->update(['contact_creation_mode' => ContactCreationMode::All]);

    TeamEmailBlocklist::factory()->blocked()->email('blocked@partner.com')->create([
        'team_id' => $this->team->id,
        'created_by' => $this->user->id,
    ]);

    $email = makeLinkEmail();

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'blocked@partner.com',
        'name' => 'Blocked Contact',
    ]);

    app(LinkEmailAction::class)->execute($email);

    expect(People::where('team_id', $this->team->id)->where('name', 'Blocked Contact')->exists())->toBeFalse();
});

it('still auto-creates a person for a protected address', function (): void {
    $this->team->update(['contact_creation_mode' => ContactCreationMode::All]);

    TeamEmailBlocklist::factory()->protected()->email('vip@partner.com')->create([
        'team_id' => $this->team->id,
        'created_by' => $this->user->id,
    ]);

    $email = makeLinkEmail();

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'vip@partner.com',
        'name' => 'Protected Contact',
    ]);

    app(LinkEmailAction::class)->execute($email);

    expect(People::where('team_id', $this->team->id)->where('name', 'Protected Contact')->exists())->toBeTrue();
});

it('does not auto-create a person for a mailbox-blocklisted address', function (): void {
    $this->team->update(['contact_creation_mode' => ContactCreationMode::All]);

    EmailBlocklist::factory()->email('spam@badactor.com')->create([
        'user_id' => $this->user->id,
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->getKey(),
    ]);

    $email = makeLinkEmail();

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'spam@badactor.com',
        'name' => 'Spam Sender',
    ]);

    app(LinkEmailAction::class)->execute($email);

    expect(People::where('team_id', $this->team->id)->where('name', 'Spam Sender')->exists())->toBeFalse();
});

it('auto-creates other participants when one address is blocked', function (): void {
    $this->team->update(['contact_creation_mode' => ContactCreationMode::All]);

    TeamEmailBlocklist::factory()->blocked()->email('blocked@partner.com')->create([
        'team_id' => $this->team->id,
        'created_by' => $this->user->id,
    ]);

    $email = makeLinkEmail();

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'blocked@partner.com',
        'name' => 'Blocked Contact',
    ]);
    EmailParticipant::factory()->to()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'customer@acme.com',
        'name' => 'Real Customer',
    ]);

    app(LinkEmailAction::class)->execute($email);

    expect(People::where('team_id', $this->team->id)->where('name', 'Blocked Contact')->exists())->toBeFalse()
        ->and(People::where('team_id', $this->team->id)->where('name', 'Real Customer')->exists())->toBeTrue();
});

it('does not auto-create a company for a workspace-blocked domain', function (): void {
    $this->team->update(['auto_create_companies' => true]);

    TeamEmailBlocklist::factory()->blocked()->domain('blockedcorp.com')->create([
        'team_id' => $this->team->id,
        'created_by' => $this->user->id,
    ]);

    $email = makeLinkEmail();

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'anyone@blockedcorp.com',
    ]);

    app(LinkEmailAction::class)->execute($email);

    expect(Company::where('team_id', $this->team->id)->where('name', 'Blockedcorp')->exists())->toBeFalse();
});

it('creates distinct people for participants sharing a display name but different emails', function (): void {
    $this->team->update(['contact_creation_mode' => ContactCreationMode::All]);

    $email = makeLinkEmail();

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'john@a-corp.com',
        'name' => 'John Smith',
    ]);
    EmailParticipant::factory()->to()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'john@b-corp.com',
        'name' => 'John Smith',
    ]);

    app(LinkEmailAction::class)->execute($email);

    expect(People::where('team_id', $this->team->id)->where('name', 'John Smith')->count())->toBe(2);
});

it('does not duplicate a person when the same address appears on multiple participants', function (): void {
    $this->team->update(['contact_creation_mode' => ContactCreationMode::All]);

    $email = makeLinkEmail();

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'dup@partner.com',
        'name' => 'Dup Person',
    ]);
    EmailParticipant::factory()->cc()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'dup@partner.com',
        'name' => 'Dup Person',
    ]);

    app(LinkEmailAction::class)->execute($email);

    expect(People::where('team_id', $this->team->id)->where('name', 'Dup Person')->count())->toBe(1);
});

it('does not auto-create a person when Selective and the address has only inbound mail', function (): void {
    $this->team->update(['contact_creation_mode' => ContactCreationMode::Selective]);

    $inboundEmail = makeLinkEmail(['direction' => EmailDirection::INBOUND]);

    EmailParticipant::factory()->from()->create([
        'email_id' => $inboundEmail->getKey(),
        'email_address' => 'inbound-only@partner.com',
    ]);

    $countBefore = People::where('team_id', $this->team->id)->count();

    $newEmail = makeLinkEmail(['direction' => EmailDirection::INBOUND]);

    EmailParticipant::factory()->from()->create([
        'email_id' => $newEmail->getKey(),
        'email_address' => 'inbound-only@partner.com',
    ]);

    app(LinkEmailAction::class)->execute($newEmail);

    expect(People::where('team_id', $this->team->id)->count())->toBe($countBefore);
});

it('auto-creates a person and company on the first outbound email in Selective mode', function (): void {
    $this->team->update([
        'contact_creation_mode' => ContactCreationMode::Selective,
        'auto_create_companies' => true,
    ]);

    $email = makeLinkEmail(['direction' => EmailDirection::OUTBOUND]);

    EmailParticipant::factory()->to()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'jane@acme.com',
        'name' => 'Jane Prospect',
    ]);

    app(LinkEmailAction::class)->execute($email);

    expect(People::where('team_id', $this->team->id)->where('name', 'Jane Prospect')->exists())->toBeTrue()
        ->and(Company::where('team_id', $this->team->id)->where('name', 'Acme')->exists())->toBeTrue();
});

it('creates a person in Selective mode when a teammate already sent to the address', function (): void {
    $this->team->update(['contact_creation_mode' => ContactCreationMode::Selective]);

    $teammate = User::factory()->create(['current_team_id' => $this->team->id]);
    $this->team->users()->attach($teammate, ['role' => 'editor']);

    $teammateAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $teammate->id,
    ]));

    $address = 'shared@partner.com';

    $teammateOutbound = Email::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $teammate->id,
        'connected_account_id' => $teammateAccount->getKey(),
        'direction' => EmailDirection::OUTBOUND,
    ]);
    EmailParticipant::factory()->to()->create([
        'email_id' => $teammateOutbound->getKey(),
        'email_address' => $address,
    ]);

    $inbound = makeLinkEmail(['direction' => EmailDirection::INBOUND]);
    EmailParticipant::factory()->from()->create([
        'email_id' => $inbound->getKey(),
        'email_address' => $address,
        'name' => 'Shared Contact',
    ]);

    app(LinkEmailAction::class)->execute($inbound);

    expect(People::where('team_id', $this->team->id)->where('name', 'Shared Contact')->exists())->toBeTrue();
});

it('increments person email_count when linked', function (): void {
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

it('does not increment person email_count when the same email is linked again', function (): void {
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

    $action = app(LinkEmailAction::class);
    $action->execute($email);
    $action->execute($email);

    expect($person->fresh()->email_count)->toBe(1)
        ->and($person->fresh()->inbound_email_count)->toBe(1);
});

it('does not increment person email_count when the same email is reapplied', function (): void {
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
        'name' => 'Reapply Metric Person',
        'creator_id' => $this->user->id,
        'email_count' => 0,
    ]);

    $person->saveCustomFieldValue($emailField, ['reapply-metric@company.com'], $this->team);

    $email = makeLinkEmail(['direction' => EmailDirection::INBOUND]);

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'reapply-metric@company.com',
    ]);

    $action = app(LinkEmailAction::class);
    $action->execute($email);
    $action->reapply($email);

    expect($person->fresh()->email_count)->toBe(1)
        ->and($person->fresh()->inbound_email_count)->toBe(1);
});

it('also links email to opportunity via person relationship', function (): void {
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

function makeAcmeCompanyWithDomain(): ?Company
{
    $domainsField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', test()->team->getKey())
        ->where('entity_type', 'company')
        ->where('code', 'domains')
        ->first();

    if (! $domainsField) {
        return null;
    }

    $company = Company::create([
        'team_id' => test()->team->id,
        'name' => 'Acme',
        'creator_id' => test()->user->id,
    ]);

    $company->saveCustomFieldValue($domainsField, 'https://acme.com', test()->team);

    return $company;
}

it('counts company email metrics once per email despite multiple participants at the same domain', function (): void {
    $company = makeAcmeCompanyWithDomain();

    if (! $company) {
        $this->markTestSkipped('No domains custom field seeded for this team.');
    }

    $email = makeLinkEmail(['direction' => EmailDirection::INBOUND, 'sent_at' => now()]);

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'alice@acme.com',
    ]);
    EmailParticipant::factory()->to()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'bob@acme.com',
    ]);

    app(LinkEmailAction::class)->execute($email);

    expect((int) $company->fresh()->email_count)->toBe(1)
        ->and((int) $company->fresh()->inbound_email_count)->toBe(1);
});

it('does not move last_email_at backwards when an older email is linked after a newer one', function (): void {
    $company = makeAcmeCompanyWithDomain();

    if (! $company) {
        $this->markTestSkipped('No domains custom field seeded for this team.');
    }

    $newerSentAt = now();
    $newer = makeLinkEmail(['direction' => EmailDirection::INBOUND, 'sent_at' => $newerSentAt]);
    EmailParticipant::factory()->from()->create([
        'email_id' => $newer->getKey(),
        'email_address' => 'alice@acme.com',
    ]);
    app(LinkEmailAction::class)->execute($newer);

    // An older message arrives out of order (parallel backfill).
    $older = makeLinkEmail(['direction' => EmailDirection::INBOUND, 'sent_at' => now()->subDays(5)]);
    EmailParticipant::factory()->from()->create([
        'email_id' => $older->getKey(),
        'email_address' => 'bob@acme.com',
    ]);
    app(LinkEmailAction::class)->execute($older);

    expect($company->fresh()->last_email_at->toDateTimeString())->toBe($newerSentAt->toDateTimeString());
});

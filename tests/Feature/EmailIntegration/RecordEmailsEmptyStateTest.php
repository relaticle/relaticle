<?php

declare(strict_types=1);

use App\Enums\CustomFields\CompanyField;
use App\Enums\CustomFields\PeopleField;
use App\Enums\TeamRole;
use App\Filament\Resources\CompanyResource\Pages\CompanyEmailsPage;
use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Filament\Resources\OpportunityResource\Pages\OpportunityEmailsPage;
use App\Filament\Resources\PeopleResource\Pages\PeopleEmailsPage;
use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Filament\Resources\PeopleResource\RelationManagers\EmailsRelationManager;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailReaderActions;
use Relaticle\EmailIntegration\Filament\Pages\BaseRecordEmailsPage;
use Relaticle\EmailIntegration\Filament\RelationManagers\BaseEmailsRelationManager;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\TeamEmailBlocklist;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;

mutates(BaseRecordEmailsPage::class, BaseEmailsRelationManager::class, EmailVisibilityService::class, HasEmailReaderActions::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->team = $this->user->currentTeam;

    $this->person = People::factory()->create([
        'team_id' => $this->team->id,
        'creator_id' => $this->user->id,
    ]);

    $this->actingAs($this->user);
    Filament::setTenant($this->team);
});

it('shows a compose empty state when the record has no emails', function (): void {
    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));

    livewire(PeopleEmailsPage::class, ['record' => $this->person->getKey()])
        ->assertSee(__('filament/pages/record-emails.empty.description'))
        ->assertSee(__('filament/pages/record-emails.empty.compose'))
        ->assertSeeHtml('composer:open')
        ->assertDontSee(__('filament/pages/email-accounts.not_connected.record.heading'))
        ->callAction('composeEmail')
        ->assertDispatched('composer:open');
});

it('hides compose from the empty state when a search has no matches', function (): void {
    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));

    livewire(PeopleEmailsPage::class, ['record' => $this->person->getKey()])
        ->set('search', 'no-such-thread')
        ->assertSee(__('filament/pages/email-inbox.list_empty.no_results', ['search' => 'no-such-thread']))
        ->assertDontSee(__('filament/pages/record-emails.empty.description'))
        ->assertDontSee(__('filament/pages/record-emails.empty.compose'));
});

it('keeps the connect prompt instead of compose when no mailbox is linked', function (): void {
    livewire(PeopleEmailsPage::class, ['record' => $this->person->getKey()])
        ->assertSee(__('filament/pages/email-accounts.not_connected.record.heading'))
        ->assertDontSee(__('filament/pages/record-emails.empty.description'))
        ->assertDontSee(__('filament/pages/record-emails.empty.compose'));
});

it('shows the compose empty state when the record only has hidden emails', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));

    TeamEmailBlocklist::factory()->blocked()->email('blocked@contact.com')->create([
        'team_id' => $this->team->id,
        'created_by' => $this->user->id,
    ]);

    $email = Email::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $account->getKey(),
        'subject' => 'Blocked thread',
        'is_internal' => false,
    ]);

    EmailParticipant::query()->create([
        'email_id' => $email->id,
        'email_address' => 'blocked@contact.com',
        'name' => null,
        'role' => EmailParticipantRole::FROM,
    ]);

    $this->person->emails()->attach($email->getKey());

    livewire(PeopleEmailsPage::class, ['record' => $this->person->getKey()])
        ->assertDontSee('Blocked thread')
        ->assertSee(__('filament/pages/record-emails.empty.description'))
        ->assertSee(__('filament/pages/record-emails.empty.compose'));
});

it('opens the composer from the emails table empty state', function (): void {
    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));

    livewire(EmailsRelationManager::class, [
        'ownerRecord' => $this->person,
        'pageClass' => ViewPeople::class,
    ])
        ->assertSee(__('filament/relation-managers/emails.empty_state.heading'))
        ->assertSee(__('filament/relation-managers/emails.empty_state.description'))
        ->assertSee(__('filament/relation-managers/emails.empty_state.compose'))
        ->assertTableEmptyStateActionsExistInOrder(['composeEmail', 'configureMailbox'])
        ->callAction(TestAction::make('composeEmail')->table())
        ->assertDispatched('composer:open');
});

function writePersonEmail(People $person, string $emailAddress): void
{
    $emailsField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $person->team_id)
        ->where('entity_type', 'people')
        ->where('code', PeopleField::EMAILS->value)
        ->firstOrFail();

    $person->saveCustomFieldValue($emailsField, [$emailAddress], $person->team);
}

function writeCompanyDomain(Company $company, string $domain): void
{
    $domainsField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $company->team_id)
        ->where('entity_type', 'company')
        ->where('code', CompanyField::DOMAINS->value)
        ->firstOrFail();

    $company->saveCustomFieldValue($domainsField, [$domain], $company->team);
}

it('hides the emails tab on a protected workspace-member person', function (): void {
    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));

    writePersonEmail($this->person, $this->user->email);

    $email = Email::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'subject' => 'Mixed thread with a customer',
        'is_internal' => false,
    ]);

    EmailParticipant::query()->create([
        'email_id' => $email->id,
        'email_address' => $this->user->email,
        'name' => null,
        'role' => EmailParticipantRole::FROM,
    ]);
    EmailParticipant::query()->create([
        'email_id' => $email->id,
        'email_address' => 'customer@acme.com',
        'name' => null,
        'role' => EmailParticipantRole::TO,
    ]);

    $this->person->emails()->attach($email->getKey());

    livewire(PeopleEmailsPage::class, ['record' => $this->person->getKey()])
        ->assertSee(__('filament/pages/record-emails.protected.heading'))
        ->assertSee(__('filament/pages/record-emails.protected.description'))
        ->assertDontSee('Mixed thread with a customer')
        ->assertDontSee(__('filament/pages/record-emails.empty.compose'))
        ->assertActionHidden('composeEmail');
});

it('still shows a mixed thread on an unprotected company', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));

    writePersonEmail($this->person, $this->user->email);

    $company = Company::factory()->create([
        'team_id' => $this->team->id,
        'creator_id' => $this->user->id,
    ]);
    writeCompanyDomain($company, 'https://acme.com');

    $email = Email::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $account->getKey(),
        'subject' => 'Visible on the customer record',
        'is_internal' => false,
    ]);

    EmailParticipant::query()->create([
        'email_id' => $email->id,
        'email_address' => $this->user->email,
        'name' => null,
        'role' => EmailParticipantRole::FROM,
    ]);
    EmailParticipant::query()->create([
        'email_id' => $email->id,
        'email_address' => 'customer@acme.com',
        'name' => null,
        'role' => EmailParticipantRole::TO,
    ]);

    $this->person->emails()->attach($email->getKey());
    $company->emails()->attach($email->getKey());

    livewire(PeopleEmailsPage::class, ['record' => $this->person->getKey()])
        ->assertDontSee('Visible on the customer record');

    livewire(CompanyEmailsPage::class, ['record' => $company->getKey()])
        ->assertSee('Visible on the customer record')
        ->assertDontSee(__('filament/pages/record-emails.protected.heading'));
});

it('hides the emails tab on a company whose domain is protected', function (): void {
    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));

    $company = Company::factory()->create([
        'team_id' => $this->team->id,
        'creator_id' => $this->user->id,
    ]);
    writeCompanyDomain($company, 'https://secret.example');

    TeamEmailBlocklist::factory()->protected()->domain('secret.example')->create([
        'team_id' => $this->team->id,
        'created_by' => $this->user->id,
    ]);

    $email = Email::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'subject' => 'Should not appear on the protected company',
        'is_internal' => false,
    ]);
    $company->emails()->attach($email->getKey());

    livewire(CompanyEmailsPage::class, ['record' => $company->getKey()])
        ->assertSee(__('filament/pages/record-emails.protected.heading'))
        ->assertDontSee('Should not appear on the protected company');
});

it('omits the emails header badge on a company whose domain is protected', function (): void {
    $company = Company::factory()->create([
        'team_id' => $this->team->id,
        'creator_id' => $this->user->id,
        'email_count' => 4,
    ]);
    writeCompanyDomain($company, 'https://secret.example');

    TeamEmailBlocklist::factory()->protected()->domain('secret.example')->create([
        'team_id' => $this->team->id,
        'created_by' => $this->user->id,
    ]);

    $email = Email::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'is_internal' => false,
    ]);
    $company->emails()->attach($email->getKey());

    expect(
        livewire(ViewCompany::class, ['record' => $company->getKey()])
            ->instance()
            ->getAction('viewEmails', isMounting: false)
            ?->getBadge()
    )->toBeNull();
});

it('hides the emails tab from a teammate when the company domain is protected', function (): void {
    $teammate = User::factory()->create();
    $teammate->teams()->attach($this->team, ['role' => TeamRole::Editor->value]);
    $teammate->forceFill(['current_team_id' => $this->team->id])->save();

    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));
    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $teammate->id,
    ]));

    $company = Company::factory()->create([
        'team_id' => $this->team->id,
        'creator_id' => $this->user->id,
    ]);
    writeCompanyDomain($company, 'https://secret.example');

    TeamEmailBlocklist::factory()->protected()->domain('secret.example')->create([
        'team_id' => $this->team->id,
        'created_by' => $this->user->id,
    ]);

    $email = Email::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'subject' => 'Hidden from the team on this record',
        'is_internal' => false,
    ]);
    EmailParticipant::query()->create([
        'email_id' => $email->id,
        'email_address' => 'owner@secret.example',
        'name' => null,
        'role' => EmailParticipantRole::FROM,
    ]);
    $company->emails()->attach($email->getKey());

    $this->actingAs($teammate);
    Filament::setTenant($this->team);

    livewire(CompanyEmailsPage::class, ['record' => $company->getKey()])
        ->assertSee(__('filament/pages/record-emails.protected.heading'))
        ->assertDontSee(__('filament/pages/record-emails.empty.compose'))
        ->assertDontSee('Hidden from the team on this record')
        ->assertActionHidden('composeEmail');
});

it('hides the emails table on a protected person', function (): void {
    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));

    writePersonEmail($this->person, $this->user->email);

    livewire(EmailsRelationManager::class, [
        'ownerRecord' => $this->person,
        'pageClass' => ViewPeople::class,
    ])
        ->assertSee(__('filament/pages/record-emails.protected.heading'))
        ->assertSee(__('filament/pages/record-emails.protected.description'))
        ->assertDontSee(__('filament/relation-managers/emails.empty_state.compose'));
});

it('hides the emails table from a teammate on a protected person', function (): void {
    $teammate = User::factory()->create();
    $teammate->teams()->attach($this->team, ['role' => TeamRole::Editor->value]);
    $teammate->forceFill(['current_team_id' => $this->team->id])->save();

    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $teammate->id,
    ]));

    writePersonEmail($this->person, $this->user->email);

    $this->actingAs($teammate);
    Filament::setTenant($this->team);

    livewire(EmailsRelationManager::class, [
        'ownerRecord' => $this->person,
        'pageClass' => ViewPeople::class,
    ])
        ->assertSee(__('filament/pages/record-emails.protected.heading'))
        ->assertDontSee(__('filament/relation-managers/emails.empty_state.compose'));
});

it('hides the emails tab on a custom protected person', function (): void {
    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));

    TeamEmailBlocklist::factory()->protected()->email('legal@acme.com')->create([
        'team_id' => $this->team->id,
        'created_by' => $this->user->id,
    ]);

    writePersonEmail($this->person, 'legal@acme.com');

    $email = Email::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'subject' => 'Counsel thread with a customer',
        'is_internal' => false,
    ]);

    EmailParticipant::query()->create([
        'email_id' => $email->id,
        'email_address' => 'legal@acme.com',
        'name' => null,
        'role' => EmailParticipantRole::FROM,
    ]);
    EmailParticipant::query()->create([
        'email_id' => $email->id,
        'email_address' => 'customer@acme.com',
        'name' => null,
        'role' => EmailParticipantRole::TO,
    ]);

    $this->person->emails()->attach($email->getKey());

    livewire(PeopleEmailsPage::class, ['record' => $this->person->getKey()])
        ->assertSee(__('filament/pages/record-emails.protected.heading'))
        ->assertDontSee('Counsel thread with a customer');
});

it('hides the emails tab on a blocked person', function (): void {
    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));

    TeamEmailBlocklist::factory()->blocked()->email('blocked@contact.com')->create([
        'team_id' => $this->team->id,
        'created_by' => $this->user->id,
    ]);

    writePersonEmail($this->person, 'blocked@contact.com');

    $email = Email::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'subject' => 'Blocked thread with a customer',
        'is_internal' => false,
    ]);

    EmailParticipant::query()->create([
        'email_id' => $email->id,
        'email_address' => 'blocked@contact.com',
        'name' => null,
        'role' => EmailParticipantRole::FROM,
    ]);
    EmailParticipant::query()->create([
        'email_id' => $email->id,
        'email_address' => 'customer@acme.com',
        'name' => null,
        'role' => EmailParticipantRole::TO,
    ]);

    $this->person->emails()->attach($email->getKey());

    livewire(PeopleEmailsPage::class, ['record' => $this->person->getKey()])
        ->assertSee(__('filament/pages/record-emails.blocked.heading'))
        ->assertSee(__('filament/pages/record-emails.blocked.description'))
        ->assertDontSee(__('filament/pages/record-emails.protected.description'))
        ->assertDontSee('Blocked thread with a customer');
});

it('hides share all on a protected person', function (): void {
    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));

    writePersonEmail($this->person, $this->user->email);

    $email = Email::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'subject' => 'Owner thread on protected person',
        'is_internal' => false,
    ]);

    $this->person->emails()->attach($email->getKey());

    livewire(EmailsRelationManager::class, [
        'ownerRecord' => $this->person,
        'pageClass' => ViewPeople::class,
    ])
        ->assertActionHidden(TestAction::make('shareAllOnRecord')->table());
});

it('keeps the emails tab visible on an opportunity', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));

    writePersonEmail($this->person, $this->user->email);

    $opportunity = Opportunity::factory()->create([
        'team_id' => $this->team->id,
        'creator_id' => $this->user->id,
    ]);

    $email = Email::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $account->getKey(),
        'subject' => 'Visible on the deal',
        'is_internal' => false,
    ]);

    EmailParticipant::query()->create([
        'email_id' => $email->id,
        'email_address' => $this->user->email,
        'name' => null,
        'role' => EmailParticipantRole::FROM,
    ]);
    EmailParticipant::query()->create([
        'email_id' => $email->id,
        'email_address' => 'customer@acme.com',
        'name' => null,
        'role' => EmailParticipantRole::TO,
    ]);

    $this->person->emails()->attach($email->getKey());
    $opportunity->emails()->attach($email->getKey());

    livewire(PeopleEmailsPage::class, ['record' => $this->person->getKey()])
        ->assertDontSee('Visible on the deal');

    livewire(OpportunityEmailsPage::class, ['record' => $opportunity->getKey()])
        ->assertSee('Visible on the deal')
        ->assertDontSee(__('filament/pages/record-emails.protected.heading'));
});

it('shows the imported mailbox name on record email list rows', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'display_name' => 'Sales Inbox',
    ]));

    $email = Email::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $account->getKey(),
        'subject' => 'Quarterly forecast',
        'is_internal' => false,
    ]);

    EmailParticipant::query()->create([
        'email_id' => $email->id,
        'email_address' => 'customer@acme.com',
        'name' => 'Customer',
        'role' => EmailParticipantRole::FROM,
    ]);

    $this->person->emails()->attach($email->getKey());

    livewire(PeopleEmailsPage::class, ['record' => $this->person->getKey()])
        ->assertSee(__('filament/pages/email-inbox.list_row.via', ['name' => 'Sales Inbox']))
        ->assertSee(__('filament/pages/email-inbox.list_row.opening'));
});

it('shows a request access pill on record mailbox rows without body access', function (): void {
    $owner = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;
    $viewer = User::factory()->create(['current_team_id' => $team->id]);
    $team->users()->attach($viewer, ['role' => 'editor']);

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $team->id,
        'user_id' => $owner->id,
    ]));

    $person = People::factory()->create([
        'team_id' => $team->id,
        'creator_id' => $owner->id,
    ]);

    $email = Email::factory()->create([
        'team_id' => $team->id,
        'user_id' => $owner->id,
        'connected_account_id' => $account->getKey(),
        'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        'subject' => 'Quarterly forecast',
        'snippet' => 'Secret preview text',
    ]);

    $person->emails()->attach($email->getKey());

    Notification::fake();

    $this->actingAs($viewer);
    Filament::setTenant($team);

    livewire(PeopleEmailsPage::class, ['record' => $person->getKey()])
        ->assertSee(__('filament/pages/email-inbox.list_row.request_access', ['name' => $owner->name]))
        ->assertDontSee('Secret preview text')
        ->assertDontSeeHtml("selectEmail('{$email->getKey()}')")
        ->assertDontSee(__('filament/pages/email-inbox.list_row.opening'))
        ->call('selectEmail', $email->getKey())
        ->assertSet('selectedEmailId', null)
        ->assertDontSee('fi-email-reader-panel')
        ->mountAction('requestAccess', arguments: ['emailId' => $email->getKey()])
        ->setActionData(['tier_requested' => EmailPrivacyTier::FULL->value])
        ->callMountedAction()
        ->assertNotified('Access request sent.');
});

it('shows a requested label on the list pill when an access request is pending', function (): void {
    $owner = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;
    $viewer = User::factory()->create(['current_team_id' => $team->id]);
    $team->users()->attach($viewer, ['role' => 'editor']);

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $team->id,
        'user_id' => $owner->id,
    ]));

    $person = People::factory()->create([
        'team_id' => $team->id,
        'creator_id' => $owner->id,
    ]);

    $email = Email::factory()->create([
        'team_id' => $team->id,
        'user_id' => $owner->id,
        'connected_account_id' => $account->getKey(),
        'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
    ]);

    $person->emails()->attach($email->getKey());

    EmailAccessRequest::factory()->pending()->forTier(EmailPrivacyTier::FULL)->create([
        'email_id' => $email->getKey(),
        'requester_id' => $viewer->id,
        'owner_id' => $owner->id,
    ]);

    $this->actingAs($viewer);
    Filament::setTenant($team);

    livewire(PeopleEmailsPage::class, ['record' => $person->getKey()])
        ->assertSee(__('filament/pages/email-inbox.list_row.requested'))
        ->assertDontSee(__('filament/pages/email-inbox.list_row.request_access', ['name' => $owner->name]))
        ->assertActionHidden('requestAccess', ['emailId' => $email->getKey()]);
});

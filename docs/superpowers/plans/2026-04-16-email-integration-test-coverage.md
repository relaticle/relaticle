# Email Integration — Test Coverage Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add five test files covering the untested reading/viewing side and all three settings pages of the EmailIntegration package.

**Architecture:** All tests are standard Pest feature tests using `livewire()` to exercise Filament page actions and relation manager table actions against a real PostgreSQL test database. No mocks of internal services. All tests follow the existing project pattern: `beforeEach` with `User::factory()->withTeam()->create()`, `actingAs()`, and `Filament::setTenant()`.

**Tech Stack:** Pest 4, Filament 5, Livewire 4, PostgreSQL, `pestphp/pest-plugin-livewire`

---

## File Map

| File | Role |
|---|---|
| `tests/Feature/EmailIntegration/EmailsRelationManagerReadingTest.php` | **Create** — `requestAccess`, `manageSharing`, `shareAllOnRecord`, subject column privacy |
| `tests/Feature/EmailIntegration/EmailAccountsPageTest.php` | **Create** — `editSettings`, `disconnect`, page mounting scope |
| `tests/Feature/EmailIntegration/EmailPrivacySettingsPageTest.php` | **Create** — `save` action, page mounting pre-fill |
| `tests/Feature/EmailIntegration/EmailSignaturesPageTest.php` | **Create** — `createSignature`, `editSignature`, `deleteSignature`, mounting scope |
| `tests/Feature/EmailIntegration/EmailAccessRequestsPageTest.php` | **Create** — tabs, `selectRequest`, `approveAccessRequest`, `denyAccessRequest`, badge |

---

## Task 1: EmailsRelationManagerReadingTest.php

**Files:**
- Create: `tests/Feature/EmailIntegration/EmailsRelationManagerReadingTest.php`

**Reference classes:**
- `App\Filament\RelationManagers\BaseEmailsRelationManager` — the class under test
- `App\Filament\Resources\PeopleResource\RelationManagers\EmailsRelationManager` — concrete subclass used in tests
- `App\Filament\Resources\PeopleResource\Pages\ViewPeople` — required `pageClass` arg
- `App\Policies\EmailPolicy` — drives `requestAccess` and `manageSharing` visibility
- `Relaticle\EmailIntegration\Services\EmailSharingService` — called by `manageSharing` and `shareAllOnRecord`

**Key conventions:**
- `requestAccess` and `manageSharing` are **table record actions** → call with `callTableAction('actionName', $email, data: [...])`
- `shareAllOnRecord` is a **table header action** → call with `callAction('actionName', data: [...])`
- Table record action visibility → `assertTableActionHidden('actionName', $email)`
- Table header action visibility → `assertActionHidden('actionName')`
- Emails must be linked to the person via `emailables` to appear in the relation manager table
- The authenticated user varies per test (owner vs. viewer) — set `actingAs` inside each test, not in `beforeEach`

- [ ] **Step 1: Create the test file**

```php
<?php

declare(strict_types=1);

use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Filament\Resources\PeopleResource\RelationManagers\EmailsRelationManager;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Models\EmailShare;
use Relaticle\EmailIntegration\Notifications\EmailAccessRequestedNotification;

mutates(EmailsRelationManager::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withTeam()->create();
    $this->viewer = User::factory()->create(['current_team_id' => $this->owner->currentTeam->id]);
    $this->team = $this->owner->currentTeam;

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->owner->id,
    ]));

    $this->person = People::create([
        'team_id' => $this->team->id,
        'name' => 'Jane Doe',
        'creator_id' => $this->owner->id,
    ]);
});

function linkEmailToPerson(Email $email, People $person): void
{
    DB::table('emailables')->insert([
        'email_id' => $email->getKey(),
        'emailable_type' => (new People)->getMorphClass(),
        'emailable_id' => $person->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('requestAccess table action', function (): void {
    it('creates an EmailAccessRequest and notifies the owner', function (): void {
        $this->actingAs($this->viewer);
        Filament::setTenant($this->team);

        Notification::fake();

        $email = Email::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        ]);

        linkEmailToPerson($email, $this->person);

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->callTableAction('requestAccess', $email, data: [
                'tier_requested' => EmailPrivacyTier::FULL->value,
            ])
            ->assertNotified('Access request sent.');

        expect(
            EmailAccessRequest::where('email_id', $email->getKey())
                ->where('requester_id', $this->viewer->id)
                ->where('owner_id', $this->owner->id)
                ->where('status', 'pending')
                ->exists()
        )->toBeTrue();

        Notification::assertSentTo($this->owner, EmailAccessRequestedNotification::class);
    });

    it('shows a warning notification when a pending request already exists', function (): void {
        $this->actingAs($this->viewer);
        Filament::setTenant($this->team);

        $email = Email::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        ]);

        linkEmailToPerson($email, $this->person);

        EmailAccessRequest::create([
            'email_id' => $email->getKey(),
            'requester_id' => $this->viewer->id,
            'owner_id' => $this->owner->id,
            'tier_requested' => EmailPrivacyTier::FULL->value,
            'status' => 'pending',
        ]);

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->callTableAction('requestAccess', $email, data: [
                'tier_requested' => EmailPrivacyTier::FULL->value,
            ])
            ->assertNotified('You already have a pending request for this email.');

        expect(
            EmailAccessRequest::where('email_id', $email->getKey())
                ->where('requester_id', $this->viewer->id)
                ->count()
        )->toBe(1);
    });

    it('is hidden when the authenticated user is the email owner', function (): void {
        $this->actingAs($this->owner);
        Filament::setTenant($this->team);

        $email = Email::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        ]);

        linkEmailToPerson($email, $this->person);

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->assertTableActionHidden('requestAccess', $email);
    });

    it('is hidden when the viewer already has full body access via a share', function (): void {
        $this->actingAs($this->viewer);
        Filament::setTenant($this->team);

        $email = Email::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        ]);

        linkEmailToPerson($email, $this->person);

        EmailShare::factory()->tier(EmailPrivacyTier::FULL)->create([
            'email_id' => $email->getKey(),
            'shared_by' => $this->owner->id,
            'shared_with' => $this->viewer->id,
        ]);

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->assertTableActionHidden('requestAccess', $email);
    });
});

describe('manageSharing table action', function (): void {
    it('updates the email privacy_tier', function (): void {
        $this->actingAs($this->owner);
        Filament::setTenant($this->team);

        $email = Email::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        ]);

        linkEmailToPerson($email, $this->person);

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->callTableAction('manageSharing', $email, data: [
                'privacy_tier' => EmailPrivacyTier::FULL->value,
                'shares' => [],
            ])
            ->assertNotified('Sharing settings saved.');

        expect($email->fresh()->privacy_tier)->toBe(EmailPrivacyTier::FULL);
    });

    it('creates EmailShare rows for each specified teammate', function (): void {
        $this->actingAs($this->owner);
        Filament::setTenant($this->team);

        $email = Email::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        ]);

        linkEmailToPerson($email, $this->person);

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->callTableAction('manageSharing', $email, data: [
                'privacy_tier' => EmailPrivacyTier::METADATA_ONLY->value,
                'shares' => [
                    ['shared_with' => $this->viewer->id, 'tier' => EmailPrivacyTier::SUBJECT->value],
                ],
            ]);

        $this->assertDatabaseHas('email_shares', [
            'email_id' => $email->getKey(),
            'shared_with' => $this->viewer->id,
            'tier' => EmailPrivacyTier::SUBJECT->value,
        ]);
    });

    it("replaces the owner's previous shares when saved again", function (): void {
        $this->actingAs($this->owner);
        Filament::setTenant($this->team);

        $email = Email::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        ]);

        linkEmailToPerson($email, $this->person);

        EmailShare::factory()->create([
            'email_id' => $email->getKey(),
            'shared_by' => $this->owner->id,
            'shared_with' => $this->viewer->id,
            'tier' => EmailPrivacyTier::SUBJECT->value,
        ]);

        $otherViewer = User::factory()->create(['current_team_id' => $this->team->id]);

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->callTableAction('manageSharing', $email, data: [
                'privacy_tier' => EmailPrivacyTier::METADATA_ONLY->value,
                'shares' => [
                    ['shared_with' => $otherViewer->id, 'tier' => EmailPrivacyTier::FULL->value],
                ],
            ]);

        $this->assertDatabaseMissing('email_shares', [
            'email_id' => $email->getKey(),
            'shared_with' => $this->viewer->id,
        ]);

        $this->assertDatabaseHas('email_shares', [
            'email_id' => $email->getKey(),
            'shared_with' => $otherViewer->id,
        ]);
    });

    it('is hidden for non-owners', function (): void {
        $this->actingAs($this->viewer);
        Filament::setTenant($this->team);

        $email = Email::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        ]);

        linkEmailToPerson($email, $this->person);

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->assertTableActionHidden('manageSharing', $email);
    });
});

describe('shareAllOnRecord header action', function (): void {
    it('updates privacy tier on all owner emails linked to the record', function (): void {
        $this->actingAs($this->owner);
        Filament::setTenant($this->team);

        $emailA = Email::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        ]);

        $emailB = Email::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        ]);

        linkEmailToPerson($emailA, $this->person);
        linkEmailToPerson($emailB, $this->person);

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->callAction('shareAllOnRecord', data: [
                'privacy_tier' => EmailPrivacyTier::FULL->value,
                'shares' => [],
            ])
            ->assertNotified('Sharing settings saved for all your emails on this record.');

        expect($emailA->fresh()->privacy_tier)->toBe(EmailPrivacyTier::FULL)
            ->and($emailB->fresh()->privacy_tier)->toBe(EmailPrivacyTier::FULL);
    });

    it('creates EmailShare rows for each email on the record per specified teammate', function (): void {
        $this->actingAs($this->owner);
        Filament::setTenant($this->team);

        $email = Email::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        ]);

        linkEmailToPerson($email, $this->person);

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->callAction('shareAllOnRecord', data: [
                'privacy_tier' => EmailPrivacyTier::METADATA_ONLY->value,
                'shares' => [
                    ['shared_with' => $this->viewer->id, 'tier' => EmailPrivacyTier::SUBJECT->value],
                ],
            ]);

        $this->assertDatabaseHas('email_shares', [
            'email_id' => $email->getKey(),
            'shared_with' => $this->viewer->id,
            'tier' => EmailPrivacyTier::SUBJECT->value,
        ]);
    });

    it('is hidden when the authenticated user has no emails linked to the record', function (): void {
        $this->actingAs($this->owner);
        Filament::setTenant($this->team);

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->assertActionHidden('shareAllOnRecord');
    });
});

describe('subject column privacy enforcement', function (): void {
    it('shows (subject hidden) when the viewer cannot view the subject', function (): void {
        $this->actingAs($this->viewer);
        Filament::setTenant($this->team);

        $email = Email::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'subject' => 'Secret Subject',
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        ]);

        linkEmailToPerson($email, $this->person);

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->assertTableColumnStateSet('subject', '(subject hidden)', $email);
    });

    it('shows the real subject when the viewer can view the subject', function (): void {
        $this->actingAs($this->owner);
        Filament::setTenant($this->team);

        $email = Email::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'subject' => 'Real Subject',
            'privacy_tier' => EmailPrivacyTier::FULL,
        ]);

        linkEmailToPerson($email, $this->person);

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->assertTableColumnStateSet('subject', 'Real Subject', $email);
    });
});
```

- [ ] **Step 2: Run the tests**

```bash
php artisan test --compact tests/Feature/EmailIntegration/EmailsRelationManagerReadingTest.php
```

Expected: all tests pass. If any fail, read the failure message — most likely causes are:
- `linkEmailToPerson` helper function conflicts with a global helper already defined (rename if needed)
- `assertTableActionHidden`/`assertTableColumnStateSet` API differences in your Filament version (check Filament 5 release notes)

- [ ] **Step 3: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/EmailIntegration/EmailsRelationManagerReadingTest.php
git commit -m "test(email): cover relation manager reading side — requestAccess, manageSharing, shareAllOnRecord, subject privacy"
```

---

## Task 2: EmailAccountsPageTest.php

**Files:**
- Create: `tests/Feature/EmailIntegration/EmailAccountsPageTest.php`

**Reference classes:**
- `Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage` — the class under test
- `editSettings` action takes `arguments: ['account_id' => ...]` and `data: [...]`
- `disconnect` action takes `arguments: ['account_id' => ...]` only
- `getAccounts()` scopes by `user_id = auth()->id()` and `team_id = currentTeam`

- [ ] **Step 1: Create the test file**

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Enums\ContactCreationMode;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

mutates(EmailAccountsPage::class);

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

it('saves all four settings fields on editSettings', function (): void {
    livewire(EmailAccountsPage::class)
        ->callAction('editSettings', data: [
            'sync_inbox' => false,
            'sync_sent' => true,
            'contact_creation_mode' => ContactCreationMode::All->value,
            'auto_create_companies' => true,
        ], arguments: ['account_id' => $this->account->id]);

    expect($this->account->fresh())
        ->sync_inbox->toBeFalse()
        ->sync_sent->toBeTrue()
        ->contact_creation_mode->toBe(ContactCreationMode::All)
        ->auto_create_companies->toBeTrue();
});

it('does not update another user\'s account via editSettings', function (): void {
    $otherUser = User::factory()->create(['current_team_id' => $this->team->id]);
    $otherAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $otherUser->id,
        'sync_inbox' => true,
    ]));

    livewire(EmailAccountsPage::class)
        ->callAction('editSettings', data: [
            'sync_inbox' => false,
            'sync_sent' => false,
            'contact_creation_mode' => ContactCreationMode::None->value,
            'auto_create_companies' => false,
        ], arguments: ['account_id' => $otherAccount->id]);

    expect($otherAccount->fresh()->sync_inbox)->toBeTrue();
});

it('deletes the authenticated user\'s account on disconnect', function (): void {
    livewire(EmailAccountsPage::class)
        ->callAction('disconnect', arguments: ['account_id' => $this->account->id]);

    $this->assertDatabaseMissing('connected_accounts', [
        'id' => $this->account->id,
    ]);
});

it('does not delete another user\'s account on disconnect', function (): void {
    $otherUser = User::factory()->create(['current_team_id' => $this->team->id]);
    $otherAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $otherUser->id,
    ]));

    livewire(EmailAccountsPage::class)
        ->callAction('disconnect', arguments: ['account_id' => $otherAccount->id]);

    $this->assertDatabaseHas('connected_accounts', [
        'id' => $otherAccount->id,
    ]);
});

it('only loads the authenticated user\'s accounts in the current team on mount', function (): void {
    $otherUser = User::factory()->create(['current_team_id' => $this->team->id]);
    $otherAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $otherUser->id,
    ]));

    $ids = livewire(EmailAccountsPage::class)
        ->get('connectedAccounts')
        ->pluck('id')
        ->all();

    expect($ids)->toContain($this->account->id)
        ->not->toContain($otherAccount->id);
});
```

- [ ] **Step 2: Run the tests**

```bash
php artisan test --compact tests/Feature/EmailIntegration/EmailAccountsPageTest.php
```

Expected: all tests pass.

- [ ] **Step 3: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/EmailIntegration/EmailAccountsPageTest.php
git commit -m "test(email): cover EmailAccountsPage — editSettings, disconnect, mount scope"
```

---

## Task 3: EmailPrivacySettingsPageTest.php

**Files:**
- Create: `tests/Feature/EmailIntegration/EmailPrivacySettingsPageTest.php`

**Reference classes:**
- `Relaticle\EmailIntegration\Filament\Pages\EmailPrivacySettingsPage` — the class under test
- `saveAction()` reads directly from public Livewire properties `$default_email_sharing_tier`, `$protected_emails`, `$protected_domains` — use `->set()` to set them in tests before calling the action
- `saveAction()` replaces all `ProtectedRecipient` rows for the team on each save (full replace, not append)
- `mount()` pre-fills all three properties from the team and existing DB rows

- [ ] **Step 1: Create the test file**

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Filament\Pages\EmailPrivacySettingsPage;
use Relaticle\EmailIntegration\Models\ProtectedRecipient;

mutates(EmailPrivacySettingsPage::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);
});

it('updates the team default sharing tier', function (): void {
    livewire(EmailPrivacySettingsPage::class)
        ->set('default_email_sharing_tier', EmailPrivacyTier::FULL->value)
        ->callAction('save');

    expect($this->team->fresh()->default_email_sharing_tier)->toBe(EmailPrivacyTier::FULL);
});

it('creates protected email ProtectedRecipient rows', function (): void {
    livewire(EmailPrivacySettingsPage::class)
        ->set('protected_emails', ['cfo@company.com', 'legal@company.com'])
        ->set('protected_domains', [])
        ->callAction('save');

    expect(
        ProtectedRecipient::where('team_id', $this->team->id)
            ->where('type', 'email')
            ->count()
    )->toBe(2);

    $this->assertDatabaseHas('protected_recipients', [
        'team_id' => $this->team->id,
        'type' => 'email',
        'value' => 'cfo@company.com',
    ]);
});

it('creates protected domain ProtectedRecipient rows', function (): void {
    livewire(EmailPrivacySettingsPage::class)
        ->set('protected_emails', [])
        ->set('protected_domains', ['sensitiveaccount.com'])
        ->callAction('save');

    $this->assertDatabaseHas('protected_recipients', [
        'team_id' => $this->team->id,
        'type' => 'domain',
        'value' => 'sensitiveaccount.com',
    ]);
});

it('replaces all existing protected recipients on each save', function (): void {
    ProtectedRecipient::factory()->email('old@company.com')->create([
        'team_id' => $this->team->id,
        'created_by' => $this->user->id,
    ]);

    livewire(EmailPrivacySettingsPage::class)
        ->set('protected_emails', [])
        ->set('protected_domains', [])
        ->callAction('save');

    expect(ProtectedRecipient::where('team_id', $this->team->id)->count())->toBe(0);
});

it('sends a success notification after saving', function (): void {
    livewire(EmailPrivacySettingsPage::class)
        ->callAction('save')
        ->assertNotified('Privacy settings saved.');
});

it('pre-fills default_email_sharing_tier from the team setting on mount', function (): void {
    $this->team->update(['default_email_sharing_tier' => EmailPrivacyTier::SUBJECT]);

    livewire(EmailPrivacySettingsPage::class)
        ->assertSet('default_email_sharing_tier', EmailPrivacyTier::SUBJECT->value);
});

it('pre-fills protected_emails and protected_domains from existing rows on mount', function (): void {
    ProtectedRecipient::factory()->email('cfo@acme.com')->create([
        'team_id' => $this->team->id,
        'created_by' => $this->user->id,
    ]);

    ProtectedRecipient::factory()->domain('acme.com')->create([
        'team_id' => $this->team->id,
        'created_by' => $this->user->id,
    ]);

    livewire(EmailPrivacySettingsPage::class)
        ->assertSet('protected_emails', ['cfo@acme.com'])
        ->assertSet('protected_domains', ['acme.com']);
});
```

- [ ] **Step 2: Run the tests**

```bash
php artisan test --compact tests/Feature/EmailIntegration/EmailPrivacySettingsPageTest.php
```

Expected: all tests pass.

- [ ] **Step 3: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/EmailIntegration/EmailPrivacySettingsPageTest.php
git commit -m "test(email): cover EmailPrivacySettingsPage — save action, mount pre-fill"
```

---

## Task 4: EmailSignaturesPageTest.php

**Files:**
- Create: `tests/Feature/EmailIntegration/EmailSignaturesPageTest.php`

**Reference classes:**
- `Relaticle\EmailIntegration\Filament\Pages\EmailSignaturesPage` — the class under test
- `createSignature` action: takes `data: [connected_account_id, name, content_html, is_default]`
- `editSignature` action: takes `data: [name, content_html, is_default]` + `arguments: ['signature_id' => ...]`
- `deleteSignature` action: takes only `arguments: ['signature_id' => ...]`; scoped to owner via `whereHas('connectedAccount', fn ($q) => $q->where('user_id', auth()->id()))`
- After each action, the page calls `$this->signatures = $this->loadSignatures()` — the `signatures` property is refreshed

- [ ] **Step 1: Create the test file**

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Filament\Pages\EmailSignaturesPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\EmailSignature;

mutates(EmailSignaturesPage::class);

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

it('creates a signature and sends a success notification', function (): void {
    livewire(EmailSignaturesPage::class)
        ->callAction('createSignature', data: [
            'connected_account_id' => $this->account->id,
            'name' => 'Work Signature',
            'content_html' => '<p>Best regards</p>',
            'is_default' => false,
        ])
        ->assertNotified('Signature created.');

    $this->assertDatabaseHas('email_signatures', [
        'connected_account_id' => $this->account->id,
        'user_id' => $this->user->id,
        'name' => 'Work Signature',
    ]);
});

it('requires connected_account_id when creating a signature', function (): void {
    livewire(EmailSignaturesPage::class)
        ->callAction('createSignature', data: [
            'connected_account_id' => null,
            'name' => 'Work Signature',
            'content_html' => '<p>Best regards</p>',
        ])
        ->assertHasActionErrors(['connected_account_id' => 'required']);
});

it('requires name when creating a signature', function (): void {
    livewire(EmailSignaturesPage::class)
        ->callAction('createSignature', data: [
            'connected_account_id' => $this->account->id,
            'name' => null,
            'content_html' => '<p>Best regards</p>',
        ])
        ->assertHasActionErrors(['name' => 'required']);
});

it('requires content_html when creating a signature', function (): void {
    livewire(EmailSignaturesPage::class)
        ->callAction('createSignature', data: [
            'connected_account_id' => $this->account->id,
            'name' => 'Work Signature',
            'content_html' => null,
        ])
        ->assertHasActionErrors(['content_html' => 'required']);
});

it('updates a signature and sends a success notification', function (): void {
    $signature = EmailSignature::factory()->create([
        'connected_account_id' => $this->account->id,
        'user_id' => $this->user->id,
        'name' => 'Old Name',
        'content_html' => '<p>Old content</p>',
    ]);

    livewire(EmailSignaturesPage::class)
        ->callAction('editSignature', data: [
            'name' => 'New Name',
            'content_html' => '<p>Updated content</p>',
            'is_default' => false,
        ], arguments: ['signature_id' => $signature->id])
        ->assertNotified('Signature updated.');

    expect($signature->fresh()->name)->toBe('New Name');
});

it('clears the previous default when a signature is set as default', function (): void {
    $sigA = EmailSignature::factory()->default()->create([
        'connected_account_id' => $this->account->id,
        'user_id' => $this->user->id,
        'name' => 'Sig A',
        'content_html' => '<p>A</p>',
    ]);

    $sigB = EmailSignature::factory()->create([
        'connected_account_id' => $this->account->id,
        'user_id' => $this->user->id,
        'name' => 'Sig B',
        'content_html' => '<p>B</p>',
    ]);

    livewire(EmailSignaturesPage::class)
        ->callAction('editSignature', data: [
            'name' => 'Sig B',
            'content_html' => '<p>B</p>',
            'is_default' => true,
        ], arguments: ['signature_id' => $sigB->id]);

    expect($sigA->fresh()->is_default)->toBeFalse()
        ->and($sigB->fresh()->is_default)->toBeTrue();
});

it('deletes a signature and sends a success notification', function (): void {
    $signature = EmailSignature::factory()->create([
        'connected_account_id' => $this->account->id,
        'user_id' => $this->user->id,
    ]);

    livewire(EmailSignaturesPage::class)
        ->callAction('deleteSignature', arguments: ['signature_id' => $signature->id])
        ->assertNotified('Signature deleted.');

    $this->assertDatabaseMissing('email_signatures', ['id' => $signature->id]);
});

it("does not delete another user's signature", function (): void {
    $otherUser = User::factory()->create(['current_team_id' => $this->team->id]);
    $otherAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $otherUser->id,
    ]));
    $otherSignature = EmailSignature::factory()->create([
        'connected_account_id' => $otherAccount->id,
        'user_id' => $otherUser->id,
    ]);

    livewire(EmailSignaturesPage::class)
        ->callAction('deleteSignature', arguments: ['signature_id' => $otherSignature->id]);

    $this->assertDatabaseHas('email_signatures', ['id' => $otherSignature->id]);
});

it('only loads the authenticated user\'s signatures on mount', function (): void {
    $ownSignature = EmailSignature::factory()->create([
        'connected_account_id' => $this->account->id,
        'user_id' => $this->user->id,
    ]);

    $otherUser = User::factory()->create(['current_team_id' => $this->team->id]);
    $otherAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $otherUser->id,
    ]));
    $otherSignature = EmailSignature::factory()->create([
        'connected_account_id' => $otherAccount->id,
        'user_id' => $otherUser->id,
    ]);

    $ids = livewire(EmailSignaturesPage::class)
        ->get('signatures')
        ->pluck('id')
        ->all();

    expect($ids)->toContain($ownSignature->id)
        ->not->toContain($otherSignature->id);
});
```

- [ ] **Step 2: Run the tests**

```bash
php artisan test --compact tests/Feature/EmailIntegration/EmailSignaturesPageTest.php
```

Expected: all tests pass.

- [ ] **Step 3: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/EmailIntegration/EmailSignaturesPageTest.php
git commit -m "test(email): cover EmailSignaturesPage — create, edit, delete signatures, mount scope"
```

---

## Task 5: EmailAccessRequestsPageTest.php

**Files:**
- Create: `tests/Feature/EmailIntegration/EmailAccessRequestsPageTest.php`

**Reference classes:**
- `App\Filament\Pages\EmailAccessRequestsPage` — the class under test (in `app/`, not in the package)
- `setTab(string $tab)` and `selectRequest(string $id)` are public Livewire methods — call with `->call('methodName', $arg)`
- `approveAccessRequest` and `denyAccessRequest` are actions — call with `->callAction('actionName', arguments: ['requestId' => ...])`
- Both approve/deny actions guard ownership: `->where('owner_id', $this->authUser()->getKey())` — passing another user's request ID does nothing silently
- `getNavigationBadge()` is a static method — call it directly in PHP, don't use Livewire for it
- The `requests()` computed property is `#[Computed]` — after calling `setTab`, the Pest `get('requests')` returns a fresh collection

- [ ] **Step 1: Create the test file**

```php
<?php

declare(strict_types=1);

use App\Filament\Pages\EmailAccessRequestsPage;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Models\EmailShare;

mutates(EmailAccessRequestsPage::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withTeam()->create();
    $this->requester = User::factory()->create(['current_team_id' => $this->owner->currentTeam->id]);
    $this->team = $this->owner->currentTeam;

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->owner->id,
    ]));

    $this->email = Email::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->owner->id,
        'connected_account_id' => $this->account->getKey(),
        'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
    ]);
});

describe('tab switching', function (): void {
    it('incoming tab (default) shows requests where the authenticated user is the owner', function (): void {
        $this->actingAs($this->owner);
        Filament::setTenant($this->team);

        $incomingRequest = EmailAccessRequest::factory()->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        $ids = livewire(EmailAccessRequestsPage::class)
            ->get('requests')
            ->pluck('id')
            ->all();

        expect($ids)->toContain($incomingRequest->id);
    });

    it('outgoing tab shows requests where the authenticated user is the requester', function (): void {
        $this->actingAs($this->requester);
        Filament::setTenant($this->team);

        $outgoingRequest = EmailAccessRequest::factory()->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        $component = livewire(EmailAccessRequestsPage::class);
        $component->call('setTab', 'outgoing');

        $ids = $component->get('requests')->pluck('id')->all();

        expect($ids)->toContain($outgoingRequest->id);
    });

    it('clears selectedRequestId when switching tabs', function (): void {
        $this->actingAs($this->owner);
        Filament::setTenant($this->team);

        $request = EmailAccessRequest::factory()->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        livewire(EmailAccessRequestsPage::class)
            ->call('selectRequest', $request->id)
            ->assertSet('selectedRequestId', $request->id)
            ->call('setTab', 'outgoing')
            ->assertSet('selectedRequestId', null);
    });
});

it('selectRequest sets selectedRequestId', function (): void {
    $this->actingAs($this->owner);
    Filament::setTenant($this->team);

    $request = EmailAccessRequest::factory()->create([
        'requester_id' => $this->requester->id,
        'owner_id' => $this->owner->id,
        'email_id' => $this->email->getKey(),
    ]);

    livewire(EmailAccessRequestsPage::class)
        ->call('selectRequest', $request->id)
        ->assertSet('selectedRequestId', $request->id);
});

describe('approveAccessRequest action', function (): void {
    it('approves the request, creates an EmailShare, clears selection, and notifies', function (): void {
        $this->actingAs($this->owner);
        Filament::setTenant($this->team);

        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        livewire(EmailAccessRequestsPage::class)
            ->call('selectRequest', $request->id)
            ->callAction('approveAccessRequest', arguments: ['requestId' => $request->id])
            ->assertNotified('Access request approved.')
            ->assertSet('selectedRequestId', null);

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::APPROVED);

        $this->assertDatabaseHas('email_shares', [
            'email_id' => $this->email->getKey(),
            'shared_with' => $this->requester->id,
        ]);
    });

    it('does nothing when the authenticated user does not own the request', function (): void {
        $this->actingAs($this->requester);
        Filament::setTenant($this->team);

        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        livewire(EmailAccessRequestsPage::class)
            ->callAction('approveAccessRequest', arguments: ['requestId' => $request->id]);

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::PENDING);
        expect(EmailShare::where('email_id', $this->email->getKey())->count())->toBe(0);
    });
});

describe('denyAccessRequest action', function (): void {
    it('denies the request, clears selection, and notifies', function (): void {
        $this->actingAs($this->owner);
        Filament::setTenant($this->team);

        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        livewire(EmailAccessRequestsPage::class)
            ->call('selectRequest', $request->id)
            ->callAction('denyAccessRequest', arguments: ['requestId' => $request->id])
            ->assertNotified('Access request denied.')
            ->assertSet('selectedRequestId', null);

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::DENIED);
    });

    it('does nothing when the authenticated user does not own the request', function (): void {
        $this->actingAs($this->requester);
        Filament::setTenant($this->team);

        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        livewire(EmailAccessRequestsPage::class)
            ->callAction('denyAccessRequest', arguments: ['requestId' => $request->id]);

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::PENDING);
    });
});

describe('navigation badge', function (): void {
    it('returns the count of pending incoming requests as a string', function (): void {
        $this->actingAs($this->owner);
        Filament::setTenant($this->team);

        EmailAccessRequest::factory()->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
            'status' => EmailAccessRequestStatus::PENDING,
        ]);

        expect(EmailAccessRequestsPage::getNavigationBadge())->toBe('1');
    });

    it('returns null when there are no pending requests', function (): void {
        $this->actingAs($this->owner);
        Filament::setTenant($this->team);

        expect(EmailAccessRequestsPage::getNavigationBadge())->toBeNull();
    });
});
```

- [ ] **Step 2: Run the tests**

```bash
php artisan test --compact tests/Feature/EmailIntegration/EmailAccessRequestsPageTest.php
```

Expected: all tests pass.

- [ ] **Step 3: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/EmailIntegration/EmailAccessRequestsPageTest.php
git commit -m "test(email): cover EmailAccessRequestsPage — tabs, select, approve, deny, badge"
```

---

## Final Step: Run Full Suite

- [ ] **Run all email integration tests together**

```bash
php artisan test --compact tests/Feature/EmailIntegration/
```

Expected: all tests in the directory pass, including the 12 pre-existing files.

- [ ] **Run PHPStan**

```bash
vendor/bin/phpstan analyse
```

Expected: no new errors.

- [ ] **Run type coverage**

```bash
composer test:type-coverage
```

Expected: stays at or above 99.9%.

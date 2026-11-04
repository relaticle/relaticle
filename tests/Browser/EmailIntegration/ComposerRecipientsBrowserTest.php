<?php

declare(strict_types=1);

use App\Enums\CustomFields\PeopleField;
use App\Models\CustomField;
use App\Models\People;
use App\Models\User;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Livewire\EmailComposer;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;

mutates(EmailComposer::class);

it('turns any typed email address into a recipient chip and keeps invalid text editable', function (string $theme): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;
    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'email_address' => 'olivia@acme.example',
        'sync_cursor' => 'history-done',
        'last_synced_at' => now(),
    ]));

    $page = loginViaBrowser($user, $theme)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/email")
        ->click(__('filament/concerns/email-compose.actions.compose.label'))
        ->waitForText(__('filament/emails/composer.title'))
        ->type('[role="combobox"]', 'new.lead@globex.example')
        ->keys('[role="combobox"]', ['Enter'])
        ->assertSee('new.lead@globex.example')
        ->type('[role="combobox"]', 'not-an-address')
        ->keys('[role="combobox"]', ['Enter'])
        ->assertValue('[role="combobox"]', 'not-an-address')
        ->assertNoJavaScriptErrors();

    $page->screenshot(filename: "composer-recipients-{$theme}");
})->with(['inLightMode', 'inDarkMode']);

it('lists a CRM person once when they are also a recent correspondent', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'email_address' => 'olivia@acme.example',
        'sync_cursor' => 'history-done',
        'last_synced_at' => now(),
    ]));

    $person = People::factory()->for($workspace)->create(['name' => 'Dana Globex', 'creator_id' => $user->id]);
    $emailsField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $workspace->id)
        ->where('entity_type', 'people')
        ->where('code', PeopleField::EMAILS->value)
        ->firstOrFail();
    $person->saveCustomFieldValue($emailsField, ['dana@globex.example'], $workspace);

    $email = Email::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'connected_account_id' => $account->id,
        'status' => EmailStatus::SYNCED,
        'sent_at' => now()->subHour(),
    ]);
    EmailParticipant::factory()->create([
        'email_id' => $email->id,
        'email_address' => 'dana@globex.example',
        'role' => EmailParticipantRole::FROM,
    ]);

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/email")
        ->click(__('filament/concerns/email-compose.actions.compose.label'))
        ->waitForText(__('filament/emails/composer.title'))
        ->type('[role="combobox"]', 'dana@globex')
        ->assertScript('Array.from(document.querySelectorAll("[role=option]")).filter((option) => option.offsetParent !== null && option.textContent.includes("dana@globex.example")).length', 1)
        ->assertNoJavaScriptErrors();
});

it('opens the composer from a record emails tab addressed to the person with the caret on the subject', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;
    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'sync_cursor' => 'history-done',
        'last_synced_at' => now(),
    ]));

    $person = People::factory()->for($workspace)->create(['name' => 'Jane Customer', 'creator_id' => $user->id]);
    $emailsField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $workspace->id)
        ->where('entity_type', 'people')
        ->where('code', PeopleField::EMAILS->value)
        ->firstOrFail();
    $person->saveCustomFieldValue($emailsField, ['jane@acme-customer.example'], $workspace);

    $floatingComposer = 'Livewire.all().map((component) => Livewire.find(component.id)).find((component) => component.get("dock") === "floating")';

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/people/{$person->getKey()}")
        ->click('[role="tab"]:has-text("Emails")')
        ->click('button[x-tooltip][wire\\:click*="composer:open"]')
        ->waitForText(__('filament/emails/composer.title'))
        ->assertScript("{$floatingComposer}.get('to')[0]", 'jane@acme-customer.example')
        ->assertScript('document.activeElement.id', 'email-composer-subject')
        ->assertScript("{$floatingComposer}.get('linkRecordId')", (string) $person->getKey())
        ->click('button[wire\\:click="close"]')
        ->assertMissing('#email-composer-subject')
        ->assertNoJavaScriptErrors();
});

it('opens a saved draft from a click on its drafts table row', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'sync_cursor' => 'history-done',
        'last_synced_at' => now(),
    ]));
    Email::query()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'connected_account_id' => $account->id,
        'subject' => 'Half-written pitch',
        'direction' => EmailDirection::OUTBOUND,
        'status' => EmailStatus::DRAFT,
        'privacy_tier' => EmailPrivacyTier::PRIVATE,
        'creation_source' => EmailCreationSource::COMPOSE,
    ]);

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/email")
        ->click('Half-written pitch')
        ->assertVisible('#email-composer-subject')
        ->assertValue('#email-composer-subject', 'Half-written pitch')
        ->assertNoJavaScriptErrors();
});

it('closes the composer with escape and keeps the draft', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;
    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'sync_cursor' => 'history-done',
        'last_synced_at' => now(),
    ]));

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/email")
        ->click(__('filament/concerns/email-compose.actions.compose.label'))
        ->waitForText(__('filament/emails/composer.title'))
        ->type('[role="combobox"]', 'half-typed')
        ->keys('[role="combobox"]', ['Escape'])
        ->assertValue('[role="combobox"]', '')
        ->assertVisible('#email-composer-subject')
        ->type('#email-composer-subject', 'Escape keeps this draft')
        ->keys('#email-composer-subject', ['Escape'])
        ->assertMissing('#email-composer-subject')
        ->waitForText('Escape keeps this draft')
        ->assertNoJavaScriptErrors();

    expect(Email::query()->where('status', EmailStatus::DRAFT)->where('subject', 'Escape keeps this draft')->exists())->toBeTrue();
});

it('puts the caret on the recipient in a new email and on the message in an addressed draft', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'sync_cursor' => 'history-done',
        'last_synced_at' => now(),
    ]));
    $draft = Email::query()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'connected_account_id' => $account->id,
        'subject' => 'Addressed and titled',
        'direction' => EmailDirection::OUTBOUND,
        'status' => EmailStatus::DRAFT,
        'privacy_tier' => EmailPrivacyTier::PRIVATE,
        'creation_source' => EmailCreationSource::COMPOSE,
    ]);
    EmailParticipant::factory()->create([
        'email_id' => $draft->id,
        'email_address' => 'jane@acme-customer.example',
        'role' => EmailParticipantRole::TO,
    ]);

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/email")
        ->click(__('filament/concerns/email-compose.actions.compose.label'))
        ->waitForText(__('filament/emails/composer.title'))
        ->assertScript('document.activeElement.closest("[data-composer-to]") !== null', true)
        ->keys('[role="combobox"]', ['Escape'])
        ->assertMissing('#email-composer-subject')
        ->click('Addressed and titled')
        ->assertValue('#email-composer-subject', 'Addressed and titled')
        ->assertScript('document.activeElement.closest(".email-composer-body-ctn") !== null', true)
        ->assertNoJavaScriptErrors();
});

function connectSendingMailbox(User $user): void
{
    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $user->currentWorkspace->id,
        'email_address' => 'olivia@acme.example',
        'sync_cursor' => 'history-done',
        'last_synced_at' => now(),
    ]));
}

it('keeps mass sending in reach on a phone by leaving the composer full screen', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;
    connectSendingMailbox($user);

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(393, 852)
        ->navigate("/app/{$workspace->slug}/email")
        ->click(__('filament/concerns/email-compose.actions.compose.label'))
        ->waitForText(__('filament/emails/composer.title'))
        ->assertVisible('#composer-mass-send-toggle')
        ->assertMissing('[aria-label="'.__('filament/emails/composer.actions.shrink').'"]')
        ->assertNoJavaScriptErrors();
});

it('fits a shrunk composer onto a phone screen with every field on it', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;
    connectSendingMailbox($user);

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/email")
        ->click(__('filament/concerns/email-compose.actions.compose.label'))
        ->waitForText(__('filament/emails/composer.title'))
        ->click('[aria-label="'.__('filament/emails/composer.actions.shrink').'"]')
        ->assertVisible('[aria-label="'.__('filament/emails/composer.actions.expand').'"]')
        ->resize(393, 852)
        ->wait(0.5)
        ->assertScript("(() => { const field = document.querySelector('[role=\"combobox\"]').getBoundingClientRect(); return field.left >= 0 && field.right <= innerWidth; })()", true)
        ->assertNoJavaScriptErrors();
});

<?php

declare(strict_types=1);

use App\Enums\CustomFields\PeopleField;
use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Filament\Resources\PeopleResource\RelationManagers\EmailsRelationManager;
use App\Models\CustomField;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Filament\Concerns\ProvidesComposerToAddress;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\ComposeRecordRecipientResolver;
use Relaticle\EmailIntegration\Support\ComposerPageTo;

mutates(EmailsRelationManager::class);
mutates(ComposeRecordRecipientResolver::class);
mutates(ProvidesComposerToAddress::class);
mutates(ComposerPageTo::class);
mutates(ViewPeople::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'email_address' => 'sender@example.com',
        'display_name' => 'Test Sender',
    ]));

    $this->person = People::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Jane Doe',
        'creator_id' => $this->user->id,
    ]);
});

it('opens the floating composer instead of a compose modal', function (): void {
    livewire(EmailsRelationManager::class, [
        'ownerRecord' => $this->person,
        'pageClass' => ViewPeople::class,
    ])
        ->callAction('composeEmail')
        ->assertDispatched('composer:open');
});

it('prefills the composer to field with the record primary email when compose is opened from a person', function (): void {
    $emailsField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->workspace->id)
        ->where('entity_type', 'people')
        ->where('code', PeopleField::EMAILS->value)
        ->firstOrFail();

    $this->person->saveCustomFieldValue($emailsField, ['jane@example.com', 'other@example.com'], $this->workspace);

    livewire(EmailsRelationManager::class, [
        'ownerRecord' => $this->person,
        'pageClass' => ViewPeople::class,
    ])
        ->callAction('composeEmail')
        ->assertDispatched('composer:open', function (string $event, array $params): bool {
            expect($params['payload']['to'])->toBe(['jane@example.com'])
                ->and($params['payload']['linkRecordType'])->toBe(People::class)
                ->and($params['payload']['linkRecordId'])->toBe((string) $this->person->getKey());

            return true;
        });
});

it('exposes the person primary email on the view page for the composer', function (): void {
    $emailsField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->workspace->id)
        ->where('entity_type', 'people')
        ->where('code', PeopleField::EMAILS->value)
        ->firstOrFail();

    $this->person->saveCustomFieldValue($emailsField, ['jane@example.com', 'other@example.com'], $this->workspace);

    expect(livewire(ViewPeople::class, ['record' => $this->person->getKey()])->instance()->getEmail())
        ->toBe('jane@example.com');
});

it('passes the person email into the floating composer on the view page', function (): void {
    $emailsField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->workspace->id)
        ->where('entity_type', 'people')
        ->where('code', PeopleField::EMAILS->value)
        ->firstOrFail();

    $this->person->saveCustomFieldValue($emailsField, ['jane@example.com', 'other@example.com'], $this->workspace);

    livewire(ViewPeople::class, ['record' => $this->person->getKey()]);

    expect(ComposerPageTo::email())->toBe('jane@example.com');
});

it('exposes no composer email on the view page when the person has none', function (): void {
    expect(livewire(ViewPeople::class, ['record' => $this->person->getKey()])->instance()->getEmail())
        ->toBeNull();
});

it('leaves the composer to field empty when the person has no email address', function (): void {
    livewire(EmailsRelationManager::class, [
        'ownerRecord' => $this->person,
        'pageClass' => ViewPeople::class,
    ])
        ->callAction('composeEmail')
        ->assertDispatched('composer:open', function (string $event, array $params): bool {
            expect($params['payload']['to'])->toBe([]);

            return true;
        });
});

it('opens the composer when the mailbox cannot send', function (): void {
    $this->account->update([
        'capabilities' => [
            'email' => true,
            'send' => false,
            'calendar' => false,
        ],
    ]);

    livewire(EmailsRelationManager::class, [
        'ownerRecord' => $this->person,
        'pageClass' => ViewPeople::class,
    ])
        ->assertActionVisible('composeEmail')
        ->callAction('composeEmail')
        ->assertDispatched('composer:open');
});

it('is hidden when user has no active connected account', function (): void {
    $this->account->update(['status' => EmailAccountStatus::DISCONNECTED]);

    livewire(EmailsRelationManager::class, [
        'ownerRecord' => $this->person,
        'pageClass' => ViewPeople::class,
    ])
        ->assertActionHidden('composeEmail');
});

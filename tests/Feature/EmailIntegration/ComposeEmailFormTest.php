<?php

declare(strict_types=1);

use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Filament\Resources\PeopleResource\RelationManagers\EmailsRelationManager;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailSignature;
use Relaticle\EmailIntegration\Models\EmailTemplate;

mutates(EmailsRelationManager::class);

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

    $this->person = People::create([
        'team_id' => $this->team->id,
        'name' => 'Jane Doe',
        'creator_id' => $this->user->id,
    ]);
});

it('persists a queued Email linked to the record and notifies', function (): void {
    livewire(EmailsRelationManager::class, [
        'ownerRecord' => $this->person,
        'pageClass' => ViewPeople::class,
    ])
        ->callAction('composeEmail', data: [
            'connected_account_id' => $this->account->id,
            'to' => ['recipient@example.com'],
            'cc' => [],
            'bcc' => [],
            'subject' => 'Hello from Relaticle',
            'body_html' => '<p>Hi there</p>',
        ])
        ->assertNotified('Email queued');

    $email = Email::query()
        ->where('direction', EmailDirection::OUTBOUND)
        ->where('subject', 'Hello from Relaticle')
        ->firstOrFail();

    expect($email->status)->toBe(EmailStatus::QUEUED);

    $this->assertDatabaseHas('emailables', [
        'email_id' => $email->getKey(),
        'emailable_type' => People::class,
        'emailable_id' => $this->person->id,
    ]);
});

it('requires a connected_account_id', function (): void {
    livewire(EmailsRelationManager::class, [
        'ownerRecord' => $this->person,
        'pageClass' => ViewPeople::class,
    ])
        ->callAction('composeEmail', data: [
            'connected_account_id' => null,
            'to' => ['recipient@example.com'],
            'subject' => 'Hello',
            'body_html' => '<p>Hi</p>',
        ])
        ->assertHasActionErrors(['connected_account_id' => 'required']);
});

it('requires at least one recipient in the to field', function (): void {
    livewire(EmailsRelationManager::class, [
        'ownerRecord' => $this->person,
        'pageClass' => ViewPeople::class,
    ])
        ->callAction('composeEmail', data: [
            'connected_account_id' => $this->account->id,
            'to' => [],
            'subject' => 'Hello',
            'body_html' => '<p>Hi</p>',
        ])
        ->assertHasActionErrors(['to' => 'required']);
});

it('keeps the default signature in the body when a template is selected', function (): void {
    $signature = EmailSignature::factory()->create([
        'connected_account_id' => $this->account->id,
        'content_html' => '<p>Best, Test Sender</p>',
        'is_default' => true,
    ]);

    $template = EmailTemplate::factory()->create([
        'team_id' => $this->team->id,
        'created_by' => $this->user->id,
        'subject' => 'Promo subject',
        'body_html' => '<p>Template body here</p>',
    ]);

    livewire(EmailsRelationManager::class, [
        'ownerRecord' => $this->person,
        'pageClass' => ViewPeople::class,
    ])
        ->mountAction('composeEmail')
        ->set('mountedActions.0.data.template_id', $template->id)
        ->assertSet('mountedActions.0.data.subject', 'Promo subject')
        ->assertSet('mountedActions.0.data.signature_id', $signature->id)
        ->assertSet('mountedActions.0.data.body_html', function (mixed $body): bool {
            // RichEditor holds state as a ProseMirror doc array; serialise to compare.
            $json = json_encode($body);

            return str_contains($json, 'Template body here')
                && str_contains($json, 'Best, Test Sender');
        });
});

it('is hidden when user has no active connected account', function (): void {
    $this->account->update(['status' => EmailAccountStatus::DISCONNECTED]);

    livewire(EmailsRelationManager::class, [
        'ownerRecord' => $this->person,
        'pageClass' => ViewPeople::class,
    ])
        ->assertActionHidden('composeEmail');
});

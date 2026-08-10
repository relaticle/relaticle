<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Livewire\EmailComposer;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailSignature;

use function Pest\Laravel\actingAs;

mutates(EmailComposer::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'user_id' => $this->user->id,
        'team_id' => $this->user->current_team_id,
        'status' => 'active',
    ]));
    actingAs($this->user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->user->currentTeam);
});

it('opens via the composer:open event with the default account preselected', function (): void {
    Livewire::test(EmailComposer::class)
        ->assertSet('isOpen', false)
        ->dispatch('composer:open')
        ->assertSet('isOpen', true)
        ->assertSet('accountId', $this->account->id);
});

it('queues an email through SendEmailAction on send with the persisted body and undo-send window', function (): void {
    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('to', ['lead@example.com'])
        ->set('subject', 'Quarterly sync')
        ->set('bodyHtml', '<p>Hello there</p>')
        ->call('send')
        ->assertSet('isOpen', false);

    $email = Email::query()->where('subject', 'Quarterly sync')->sole();
    expect($email->status)->toBe(EmailStatus::QUEUED)
        ->and($email->connected_account_id)->toBe($this->account->id)
        ->and($email->body->body_html)->toContain('Hello there')
        // Interactive sends must keep the priority queue's undo-send window
        // (EmailPriority::PRIORITY), not fall back to the bulk default.
        ->and($email->scheduled_for)->not->toBeNull();
});

it('includes the default signature content in the sent body_html', function (): void {
    EmailSignature::withoutEvents(fn () => EmailSignature::factory()->create([
        'connected_account_id' => $this->account->id,
        'content_html' => '<p>Best, Test Sender</p>',
        'is_default' => true,
    ]));

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('to', ['lead@example.com'])
        ->set('subject', 'Signature roundtrip')
        ->call('send');

    $email = Email::query()->where('subject', 'Signature roundtrip')->sole();

    expect($email->body->body_html)->toContain('Best, Test Sender');
});

it('shows validation errors instead of sending when recipients are missing', function (): void {
    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('subject', 'No recipients')
        ->set('bodyHtml', '<p>Body</p>')
        ->call('send')
        ->assertHasErrors(['to'])
        ->assertSet('isOpen', true);

    expect(Email::query()->where('subject', 'No recipients')->exists())->toBeFalse();
});

it('surfaces a validation error for a malformed recipient and sends nothing', function (): void {
    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('to', ['not-an-email'])
        ->set('subject', 'Malformed recipient')
        ->set('bodyHtml', '<p>Body</p>')
        ->call('send')
        ->assertHasErrors(['to.0'])
        ->assertSet('isOpen', true);

    expect(Email::query()->where('subject', 'Malformed recipient')->exists())->toBeFalse();
});

it('rejects an empty body with no signature block and sends nothing', function (): void {
    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('to', ['lead@example.com'])
        ->set('subject', 'Empty body')
        ->call('send')
        ->assertHasErrors(['bodyHtml'])
        ->assertSet('isOpen', true);

    expect(Email::query()->where('subject', 'Empty body')->exists())->toBeFalse();
});

it('passes uploaded attachments through to the send action', function (): void {
    Storage::fake('local');

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('to', ['a@example.com'])
        ->set('subject', 'With attachment')
        ->set('bodyHtml', '<p>see attached</p>')
        ->set('attachments', [UploadedFile::fake()->create('quote.pdf', 12)])
        ->call('send');

    $email = Email::query()->where('subject', 'With attachment')->sole();

    expect($email->has_attachments)->toBeTrue()
        ->and($email->attachments()->count())->toBe(1)
        ->and($email->attachments()->first()->filename)->toBe('quote.pdf');
});

it('does not render for users without an active connected account', function (): void {
    $this->account->update(['status' => EmailAccountStatus::DISCONNECTED]);

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->assertSet('isOpen', false);
});

it('restores an already-open composer instead of resetting it when composer:open fires again', function (): void {
    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('subject', 'In progress')
        ->call('minimize')
        ->assertSet('isMinimized', true)
        ->dispatch('composer:open')
        ->assertSet('isOpen', true)
        ->assertSet('isMinimized', false)
        ->assertSet('subject', 'In progress');
});

it('does not leak another team\'s signature content via a foreign signatureId', function (): void {
    $otherUser = User::factory()->withTeam()->create();
    $otherAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'user_id' => $otherUser->id,
        'team_id' => $otherUser->current_team_id,
        'status' => 'active',
    ]));
    $otherSignature = EmailSignature::withoutEvents(fn () => EmailSignature::factory()->create([
        'connected_account_id' => $otherAccount->id,
        'content_html' => '<p>Confidential other-team signature</p>',
    ]));

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('to', ['victim@example.com'])
        ->set('subject', 'IDOR probe')
        ->set('bodyHtml', '<p>hello</p>')
        ->set('signatureId', $otherSignature->id)
        ->call('send');

    $email = Email::query()->where('subject', 'IDOR probe')->sole();

    expect($email->body->body_html)->not->toContain('Confidential other-team signature');
});

it('rejects a client-posted accountId that does not belong to the user', function (): void {
    $otherUser = User::factory()->withTeam()->create();
    $otherAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'user_id' => $otherUser->id,
        'team_id' => $otherUser->current_team_id,
        'status' => 'active',
    ]));

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('accountId', $otherAccount->id)
        ->assertSet('accountId', null);
});

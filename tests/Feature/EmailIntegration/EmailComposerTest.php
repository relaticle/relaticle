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

it('queues an email through SendEmailAction on send', function (): void {
    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('to', ['lead@example.com'])
        ->set('subject', 'Quarterly sync')
        ->set('bodyHtml', '<p>Hello there</p>')
        ->call('send')
        ->assertSet('isOpen', false);

    $email = Email::query()->where('subject', 'Quarterly sync')->sole();
    expect($email->status)->toBe(EmailStatus::QUEUED)
        ->and($email->connected_account_id)->toBe($this->account->id);
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

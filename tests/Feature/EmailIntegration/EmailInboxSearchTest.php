<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Filament\Pages\EmailInboxPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Services\EmailSearchService;

mutates(EmailInboxPage::class, EmailSearchService::class);

it('does not match hidden subject or snippet text when searching metadata-only teammate emails', function (): void {
    $owner = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;
    $viewer = User::factory()->create(['current_team_id' => $team->id]);
    $team->users()->attach($viewer, ['role' => 'editor']);

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $team->id,
        'user_id' => $owner->id,
    ]));

    $email = Email::factory()->inbound()->create([
        'team_id' => $team->id,
        'user_id' => $owner->id,
        'connected_account_id' => $account->getKey(),
        'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        'subject' => 'Quarterly forecast',
        'snippet' => 'Secret preview text',
        'is_internal' => false,
        'sent_at' => now(),
    ]);

    EmailParticipant::query()->create([
        'email_id' => $email->id,
        'email_address' => 'customer@acme.com',
        'name' => 'Customer',
        'role' => EmailParticipantRole::FROM,
    ]);

    $this->actingAs($viewer);
    Filament::setTenant($team);

    $page = livewire(EmailInboxPage::class)->set('accountId', 'all');

    expect($page->instance()->emails()->pluck('id')->all())->toBe([$email->getKey()]);

    $page->set('search', 'Quarterly forecast');

    expect($page->instance()->emails()->pluck('id')->all())->toBe([]);

    $page->set('search', 'Secret preview text');

    expect($page->instance()->emails()->pluck('id')->all())->toBe([]);

    $page->set('search', 'Customer');

    expect($page->instance()->emails()->pluck('id')->all())->toBe([$email->getKey()]);
});

it('still matches subject text on emails the viewer owns', function (): void {
    $owner = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $team->id,
        'user_id' => $owner->id,
        'is_default' => true,
    ]));

    $email = Email::factory()->inbound()->create([
        'team_id' => $team->id,
        'user_id' => $owner->id,
        'connected_account_id' => $account->getKey(),
        'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        'subject' => 'Quarterly forecast',
        'snippet' => 'Secret preview text',
        'sent_at' => now(),
    ]);

    EmailParticipant::query()->create([
        'email_id' => $email->id,
        'email_address' => 'customer@acme.com',
        'name' => 'Customer',
        'role' => EmailParticipantRole::FROM,
    ]);

    $this->actingAs($owner);
    Filament::setTenant($team);

    $page = livewire(EmailInboxPage::class)->set('search', 'Quarterly forecast');

    expect($page->instance()->emails()->pluck('id')->all())->toBe([$email->getKey()]);
});

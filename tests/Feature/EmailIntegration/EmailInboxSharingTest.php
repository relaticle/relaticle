<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Filament\Pages\EmailInboxPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;

mutates(EmailInboxPage::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withTeam()->create();
    $this->team = $this->owner->currentTeam;
    $this->viewer = User::factory()->create(['current_team_id' => $this->team->id]);
    $this->secondViewer = User::factory()->create(['current_team_id' => $this->team->id]);

    $this->account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->owner->id,
    ]));

    $this->actingAs($this->owner);
    Filament::setTenant($this->team);
});

it('shares an email with multiple teammates from one access tier row', function (): void {
    $email = Email::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->owner->id,
        'connected_account_id' => $this->account->getKey(),
        'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
    ]);

    livewire(EmailInboxPage::class)
        ->mountAction('manageSharing', ['emailId' => $email->getKey()])
        ->fillForm([
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY->value,
            'shares' => [
                [
                    'shared_with' => [$this->viewer->id, $this->secondViewer->id],
                    'tier' => EmailPrivacyTier::SUBJECT->value,
                ],
            ],
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    foreach ([$this->viewer, $this->secondViewer] as $viewer) {
        $this->assertDatabaseHas('email_shares', [
            'email_id' => $email->getKey(),
            'shared_with' => $viewer->id,
            'tier' => EmailPrivacyTier::SUBJECT->value,
        ]);
    }
});

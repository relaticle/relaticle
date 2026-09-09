<?php

declare(strict_types=1);

use App\Filament\Pages\Billing;
use App\Mail\ProTrialEndingSoonMail;
use App\Models\Team;
use App\Models\User;

it('renders the trial end date in the owner timezone with the paused-access outcome', function (): void {
    $owner = User::factory()->create(['timezone' => 'Asia/Tokyo']);
    $team = Team::factory()->create([
        'name' => 'Acme',
        'user_id' => $owner->id,
        'trial_ends_at' => '2026-09-09 23:30:00',
        'hosted_free_grandfathered_at' => null,
    ]);

    $mail = new ProTrialEndingSoonMail($team);

    $mail->assertHasSubject(__('mail.trial_ending.subject'));
    $mail->assertSeeInHtml(__('mail.trial_ending.heading', ['team' => 'Acme']));
    $mail->assertSeeInHtml(__('mail.trial_ending.ends_on', ['date' => 'Sep 10, 2026']));
    $mail->assertSeeInHtml(__('mail.trial_ending.paused'));
    $mail->assertDontSeeInHtml(__('mail.trial_ending.grandfathered', ['team' => 'Acme']));
    $mail->assertSeeInHtml(__('mail.footer.reason.owner', ['team' => 'Acme']));
    $mail->assertSeeInText(__('mail.trial_ending.cta').': '.Billing::getUrl(panel: 'app', tenant: $team));
});

it('describes the grandfathered outcome for grandfathered workspaces', function (): void {
    $owner = User::factory()->create();
    $team = Team::factory()->create([
        'name' => 'Acme',
        'user_id' => $owner->id,
        'trial_ends_at' => now()->addDays(3),
        'hosted_free_grandfathered_at' => now()->subMonth(),
    ]);

    $mail = new ProTrialEndingSoonMail($team);

    $mail->assertSeeInHtml(__('mail.trial_ending.grandfathered', ['team' => 'Acme']));
    $mail->assertDontSeeInHtml(__('mail.trial_ending.paused'));
});

<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use Relaticle\Cloud\Billing\Filament\Billing;
use Relaticle\Cloud\Billing\Mail\ProTrialEndingSoonMail;

mutates(ProTrialEndingSoonMail::class);

it('renders the trial end date in the owner timezone with the paused-access outcome', function (): void {
    $owner = User::factory()->create(['timezone' => 'Asia/Tokyo']);
    $workspace = Workspace::factory()->create([
        'name' => 'Acme',
        'user_id' => $owner->id,
        'trial_ends_at' => '2026-09-09 23:30:00',
        'hosted_free_grandfathered_at' => null,
    ]);

    $mail = new ProTrialEndingSoonMail($workspace);

    $mail->assertHasSubject('Your Pro trial ends on Sep 10');
    $mail->assertSeeInHtml('Pro for Acme ends on Sep 10');
    $mail->assertSeeInHtml('Your Pro trial ends on Sep 10, 2026.');
    $mail->assertSeeInHtml(__('mail.trial_ending.paused'));
    $mail->assertDontSeeInHtml(__('mail.trial_ending.grandfathered', ['workspace' => 'Acme']));
    $mail->assertSeeInHtml(__('mail.footer.reason.owner', ['workspace' => 'Acme']));
    $mail->assertSeeInText(__('mail.trial_ending.cta').': '.Billing::getUrl(panel: 'app', tenant: $workspace));
});

it('describes the grandfathered outcome for grandfathered workspaces', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'name' => 'Acme',
        'user_id' => $owner->id,
        'trial_ends_at' => now()->addDays(3),
        'hosted_free_grandfathered_at' => now()->subMonth(),
    ]);

    $mail = new ProTrialEndingSoonMail($workspace);

    $mail->assertSeeInHtml(__('mail.trial_ending.grandfathered', ['workspace' => 'Acme']));
    $mail->assertDontSeeInHtml(__('mail.trial_ending.paused'));
});

it('names the end date and no fixed length on an extended trial', function (): void {
    $owner = User::factory()->create(['timezone' => 'UTC']);
    $workspace = Workspace::factory()->create([
        'name' => 'Acme',
        'user_id' => $owner->id,
        'pro_trial_used_at' => '2026-10-01 09:00:00',
        'trial_ends_at' => '2026-11-15 09:00:00',
    ]);

    $mail = new ProTrialEndingSoonMail($workspace);

    $mail->assertHasSubject('Your Pro trial ends on Nov 15');
    $mail->assertSeeInHtml('Your Pro trial ends on Nov 15, 2026.');
    $mail->assertDontSeeInHtml('14-day');
    $mail->assertDontSeeInHtml('3 days');
});

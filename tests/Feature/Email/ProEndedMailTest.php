<?php

declare(strict_types=1);

use App\Filament\Pages\Billing;
use App\Mail\ProEndedMail;
use App\Models\User;
use App\Models\Workspace;

mutates(ProEndedMail::class);

function proEndedWorkspace(array $attributes = []): Workspace
{
    return Workspace::factory()->create([
        'name' => 'Acme',
        'user_id' => User::factory()->create()->id,
        'hosted_free_grandfathered_at' => null,
        ...$attributes,
    ]);
}

it('tells the owner the trial ended, the workspace is paused, and how to reopen it', function (): void {
    $workspace = proEndedWorkspace();

    $mail = ProEndedMail::afterTrial($workspace);

    $mail->assertHasSubject(__('mail.pro_ended.trial_ended.subject'));
    $mail->assertSeeInHtml(__('mail.pro_ended.trial_ended.heading', ['workspace' => 'Acme']));
    $mail->assertSeeInHtml(__('mail.pro_ended.paused', ['workspace' => 'Acme']));
    $mail->assertSeeInHtml(__('mail.pro_ended.restore'));
    $mail->assertSeeInText(__('mail.pro_ended.cta').': '.Billing::getUrl(panel: 'app', tenant: $workspace));
    $mail->assertDontSeeInHtml('mail.pro_ended.');

    expect($mail->envelope()->subject)->not->toStartWith('mail.');
});

it('names the ended subscription rather than a trial', function (): void {
    $mail = ProEndedMail::afterSubscription(proEndedWorkspace());

    $mail->assertHasSubject(__('mail.pro_ended.subscription_ended.subject'));
    $mail->assertSeeInHtml(__('mail.pro_ended.subscription_ended.heading', ['workspace' => 'Acme']));
    $mail->assertSeeInHtml(__('mail.pro_ended.paused', ['workspace' => 'Acme']));
    $mail->assertDontSeeInHtml('mail.pro_ended.');

    expect($mail->envelope()->subject)->not->toStartWith('mail.');
});

it('tells a grandfathered workspace it is back on Cloud Free, not paused', function (): void {
    $mail = ProEndedMail::afterTrial(proEndedWorkspace(['hosted_free_grandfathered_at' => now()->subYear()]));

    $mail->assertSeeInHtml(__('mail.pro_ended.grandfathered', ['workspace' => 'Acme']));
    $mail->assertSeeInHtml(__('mail.pro_ended.cta_grandfathered'));
    $mail->assertDontSeeInHtml(__('mail.pro_ended.paused', ['workspace' => 'Acme']));
});

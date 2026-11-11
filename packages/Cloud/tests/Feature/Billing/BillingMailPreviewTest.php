<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Mail\MailPreview;
use Illuminate\Mail\Mailable;
use Relaticle\Cloud\Admin\Mail\ManagedPlanInvoiceMail;
use Relaticle\Cloud\Billing\Mail\InactiveWorkspaceDeletedMail;
use Relaticle\Cloud\Billing\Mail\InactiveWorkspaceDeletionReminderMail;
use Relaticle\Cloud\Billing\Mail\InactiveWorkspaceDeletionWarningMail;
use Relaticle\Cloud\Billing\Mail\InactivityRuleNoticeMail;
use Relaticle\Cloud\Billing\Mail\ProEndedMail;
use Relaticle\Cloud\Billing\Mail\ProTrialEndingSoonMail;
use Relaticle\Cloud\CloudServiceProvider;

mutates(CloudServiceProvider::class, MailPreview::class);

it('registers the billing mail previews and renders each one', function (): void {
    $preview = resolve(MailPreview::class);

    expect($preview->names())->toContain('trial-ending', 'trial-ended', 'extension-ended', 'subscription-ended');

    foreach (['trial-ending', 'trial-ended', 'extension-ended', 'subscription-ended', 'inactive-deletion-warning', 'inactive-deletion-reminder', 'inactive-deleted', 'inactivity-rule-notice'] as $name) {
        expect($preview->render($name))
            ->toContain('<html xmlns="http://www.w3.org/1999/xhtml" lang="en">')
            ->not->toMatch('/\bmail\.[a-z_]+\.[a-z_]+/');
    }

    expect($preview->render('trial-ended'))->toContain('Extend your trial by 7 days');
    expect($preview->render('extension-ended'))->toContain('Your extended trial for Acme Robotics has ended')->not->toContain('Extend your trial');
});

it('renders a workspace name people type as text, never as markdown', function (Closure $mail): void {
    $workspace = Workspace::factory()->make(['id' => 1, 'user_id' => 1, 'name' => "Acme\n\n[Verify your account](https://phish.example) *now*", 'trial_ends_at' => now()->addDays(3), 'scheduled_deletion_at' => null]);
    $workspace->setRelation('owner', User::factory()->make(['id' => 1, 'name' => 'Ada Lovelace', 'timezone' => 'UTC']));

    $html = $mail($workspace)->render();

    expect($html)->not->toContain('href="https://phish.example"')
        ->and($html)->not->toContain('<em');
})->with([
    'trial ending' => [fn (Workspace $workspace): Mailable => new ProTrialEndingSoonMail($workspace, now()->addDays(3))],
    'trial ended' => [fn (Workspace $workspace): Mailable => ProEndedMail::afterTrial($workspace->forceFill(['plan' => Plan::Free, 'trial_ends_at' => null, 'pro_trial_used_at' => now()->subDay()]))],
    'subscription ended' => [fn (Workspace $workspace): Mailable => ProEndedMail::afterSubscription($workspace)],
    'inactive deletion warning' => [fn (Workspace $workspace): Mailable => new InactiveWorkspaceDeletionWarningMail($workspace, now()->addDays(14), now()->subDays(46))],
    'inactive deletion reminder' => [fn (Workspace $workspace): Mailable => new InactiveWorkspaceDeletionReminderMail($workspace, now()->addDays(5))],
    'inactivity rule notice' => [fn (Workspace $workspace): Mailable => new InactivityRuleNoticeMail($workspace, now()->addDays(60))],
    'inactive deleted' => [fn (Workspace $workspace): Mailable => new InactiveWorkspaceDeletedMail($workspace->name)],
    'managed plan invoice' => [fn (Workspace $workspace): Mailable => new ManagedPlanInvoiceMail($workspace, Plan::Pro, 'https://invoice.stripe.com/i/test', 4900, now()->addDays(14))],
]);

it('keeps the trial extension offer a link after the paragraphs around it became html', function (): void {
    expect(resolve(MailPreview::class)->render('trial-ended'))->toMatch('/<a href="[^"]+"[^>]*>Extend your trial by 7 days<\/a>/');
});

<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Enums\Plan;
use App\Models\Company;
use App\Models\User;
use App\Models\Workspace;
use Relaticle\Cloud\Billing\Filament\Billing;
use Relaticle\Cloud\Billing\Mail\ProEndedMail;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

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
    $billingUrl = Billing::getUrl(panel: 'app', tenant: $workspace);

    $mail = ProEndedMail::afterTrial($workspace);

    $mail->assertHasSubject('Your Pro trial for Acme has ended');
    $mail->assertSeeInHtml('Your Pro trial for Acme has ended');
    $mail->assertSeeInHtml('Acme is paused. Your records are safe.');
    $mail->assertSeeInHtml(__('mail.trial_ending.keeps', ['credits' => number_format(Plan::Pro->credits())]));
    $mail->assertSeeInHtml('Choose a plan');
    $mail->assertSeeInHtml('Need more time?');
    $mail->assertSeeInHtml('Extend your trial by 7 days');
    $mail->assertSeeInHtml('from the billing page. You can do this once.');
    $mail->assertSeeInHtml($billingUrl);
    $mail->assertSeeInText('[Extend your trial by 7 days]('.$billingUrl.')');
    $mail->assertSeeInHtml('Questions? Reply to this email.');
    $mail->assertDontSeeInHtml(__('mail.pro_ended.restore'));
    $mail->assertDontSeeInHtml('mail.pro_ended.');

    expect($mail->envelope()->subject)->not->toStartWith('mail.');
});

it('drops the extension offer once the trial was extended', function (): void {
    $mail = ProEndedMail::afterTrial(proEndedWorkspace(['trial_extended_at' => now()->subWeek()]));

    $mail->assertHasSubject('Your extended trial for Acme has ended');
    $mail->assertSeeInHtml('Your extended trial for Acme has ended');
    $mail->assertSeeInHtml('Acme is paused. Your records are safe.');
    $mail->assertSeeInHtml('Choose a plan');
    $mail->assertSeeInHtml('Questions? Reply to this email.');
    $mail->assertDontSeeInHtml('Extend your trial');
    $mail->assertDontSeeInHtml('Need more time?');
    $mail->assertDontSeeInHtml('mail.pro_ended.');
});

it('tells the team what they did during the trial', function (): void {
    $workspace = proEndedWorkspace();
    Company::factory()->count(2)->create(['workspace_id' => $workspace->id, 'creation_source' => CreationSource::WEB]);

    ProEndedMail::afterTrial($workspace)->assertSeeInHtml('During the trial your team added 2 records.');
    ProEndedMail::afterTrial(proEndedWorkspace(['trial_extended_at' => now()->subWeek()]))->assertDontSeeInHtml('During the trial');
});

it('leaves the usage line out of the subscription mail', function (): void {
    $workspace = proEndedWorkspace();
    Company::factory()->create(['workspace_id' => $workspace->id, 'creation_source' => CreationSource::WEB]);

    $mail = ProEndedMail::afterSubscription($workspace);

    $mail->assertDontSeeInHtml('During the trial');
    $mail->assertDontSeeInHtml(__('mail.trial_ending.keeps', ['credits' => number_format(Plan::Pro->credits())]));
    $mail->assertDontSeeInHtml('Extend your trial');
});

it('names the ended subscription rather than a trial', function (): void {
    $mail = ProEndedMail::afterSubscription(proEndedWorkspace());

    $mail->assertHasSubject(__('mail.pro_ended.subscription_ended.subject'));
    $mail->assertSeeInHtml(__('mail.pro_ended.subscription_ended.heading', ['workspace' => 'Acme']));
    $mail->assertSeeInHtml(__('mail.pro_ended.paused', ['workspace' => 'Acme']));
    $mail->assertDontSeeInHtml('mail.pro_ended.');

    expect($mail->envelope()->subject)->not->toStartWith('mail.');
});

it('offers to restart the managed plan that ended instead of selling Pro', function (Plan $plan, string $label, array $attributes): void {
    $workspace = proEndedWorkspace($attributes);
    $mail = ProEndedMail::afterManagedPlan($workspace, $plan);
    $contactUrl = route('contact', ['plan' => $plan->value]);

    $mail->assertHasSubject("Your {$label} plan has ended");
    $mail->assertSeeInHtml("{$label} for Acme has ended");
    $mail->assertSeeInHtml(__('mail.pro_ended.managed_ended.restore', ['plan' => $label]));
    $mail->assertSeeInHtml(__('mail.pro_ended.managed_ended.cta'));
    $mail->assertSeeInHtml($contactUrl);
    $mail->assertSeeInText(__('mail.pro_ended.managed_ended.cta').': '.$contactUrl);
    $mail->assertDontSeeInHtml('mail.pro_ended.');

    foreach (['Subscribe to Pro', 'Cloud Pro', 'Upgrade to Pro', 'return to Pro'] as $proCopy) {
        $mail->assertDontSeeInHtml($proCopy);
        $mail->assertDontSeeInText($proCopy);
    }
})->with([
    'enterprise' => [Plan::Enterprise, 'Enterprise', []],
    'grandfathered enterprise' => [Plan::Enterprise, 'Enterprise', ['hosted_free_grandfathered_at' => now()->subYear()]],
]);

it('keeps the pause notice in the managed plan mail', function (): void {
    $mail = ProEndedMail::afterManagedPlan(proEndedWorkspace(), Plan::Enterprise);

    $mail->assertSeeInHtml(__('mail.pro_ended.paused', ['workspace' => 'Acme']));
});

it('tells a grandfathered workspace it is back on Cloud Free, not paused', function (): void {
    $mail = ProEndedMail::afterTrial(proEndedWorkspace(['hosted_free_grandfathered_at' => now()->subYear()]));

    $mail->assertSeeInHtml(__('mail.pro_ended.grandfathered', ['workspace' => 'Acme']));
    $mail->assertSeeInHtml(__('mail.pro_ended.cta_grandfathered'));
    $mail->assertDontSeeInHtml(__('mail.pro_ended.paused', ['workspace' => 'Acme']));
});

it('says email and calendar sync are paused only when the workspace has a mailbox', function (): void {
    $workspace = proEndedWorkspace();

    ProEndedMail::afterTrial($workspace)->assertDontSeeInHtml(__('mail.pro_ended.mailbox_sync_paused'));

    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create(['workspace_id' => $workspace->id]));

    ProEndedMail::afterTrial($workspace)->assertSeeInHtml(__('mail.pro_ended.mailbox_sync_paused'));
});

it('says mailbox sync resumes with the managed plan, not a subscription', function (): void {
    $workspace = proEndedWorkspace();
    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create(['workspace_id' => $workspace->id]));

    $mail = ProEndedMail::afterManagedPlan($workspace, Plan::Enterprise);

    $mail->assertSeeInHtml(__('mail.pro_ended.managed_ended.mailbox_sync_paused', ['plan' => 'Enterprise']));
    $mail->assertDontSeeInHtml(__('mail.pro_ended.mailbox_sync_paused'));
});

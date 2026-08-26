<?php

declare(strict_types=1);

use App\Actions\User\UpdateNotificationPreferences;
use App\Enums\ActivationStep;
use App\Enums\CreationSource;
use App\Enums\Notifications\NotificationChannel;
use App\Enums\Notifications\NotificationType;
use App\Filament\Pages\ChatConversation;
use App\Filament\Pages\Dashboard;
use App\Mail\SetupNudgeMail;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

it('renders the nudge naming the unfinished step', function (): void {
    $owner = User::factory()->withPersonalTeam()->create(['name' => 'Dana Reed']);
    $team = $owner->currentTeam;

    $mail = new SetupNudgeMail($owner, $team, ActivationStep::FirstRecord->value, 'https://example.test/chat');

    $rendered = $mail->render();

    $mail->assertHasSubject('Your workspace is waiting');

    expect($rendered)
        ->toContain('Dana')
        ->toContain('Add your first contact')
        ->toContain('Put one real person in the CRM and the rest follows')
        ->toContain('Continue in Rela')
        ->toContain('https://example.test/chat')
        ->not->toContain('filament/pages/dashboard.')
        ->not->toContain('notifications.onboarding.');
});

it('never lets an unresolved step label reach the rendered body', function (): void {
    $owner = User::factory()->withPersonalTeam()->create(['name' => 'Dana Reed']);
    $team = $owner->currentTeam;

    foreach (ActivationStep::cases() as $step) {
        $mail = new SetupNudgeMail($owner, $team, $step->value, 'https://example.test/chat');

        expect($mail->render())->not->toContain('filament/pages/dashboard.');
    }
});

it('sends once to an owner who has created nothing', function (): void {
    Mail::fake();

    $owner = User::factory()->withPersonalTeam()->create(['timezone' => 'UTC']);

    $this->travelTo(now()->setTime(9, 0));

    $owner->currentTeam->forceFill(['created_at' => now()->subDays(2)])->save();

    $this->artisan('notifications:send-setup-nudge')->assertSuccessful();
    $this->artisan('notifications:send-setup-nudge')->assertSuccessful();

    Mail::assertQueuedCount(1);
    expect($owner->currentTeam->fresh()->setup_nudge_sent_at)->not->toBeNull();
});

it('skips a workspace that already has its own record', function (): void {
    Mail::fake();

    $owner = User::factory()->withPersonalTeam()->create(['timezone' => 'UTC']);
    $team = $owner->currentTeam;

    $this->travelTo(now()->setTime(9, 0));

    $team->forceFill(['created_at' => now()->subDays(2)])->save();
    Company::factory()->for($team)->create(['creation_source' => CreationSource::WEB]);

    $this->artisan('notifications:send-setup-nudge')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('skips an owner who turned setup reminders off', function (): void {
    Mail::fake();

    $owner = User::factory()->withPersonalTeam()->create(['timezone' => 'UTC']);

    $this->travelTo(now()->setTime(9, 0));

    $owner->currentTeam->forceFill(['created_at' => now()->subDays(2)])->save();
    resolve(UpdateNotificationPreferences::class)->execute(
        $owner,
        NotificationType::SetupNudge,
        NotificationChannel::Email,
        false,
    );

    $this->artisan('notifications:send-setup-nudge')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('sends at 09:00 in the owner timezone, not the app timezone', function (): void {
    Mail::fake();

    $owner = User::factory()->withPersonalTeam()->create(['timezone' => 'Asia/Tokyo']);

    // 00:00 UTC is 09:00 in Tokyo: the band has to be read in the user's zone.
    $this->travelTo(now()->setTime(0, 0));

    $owner->currentTeam->forceFill(['created_at' => now()->subDays(2)])->save();

    $this->artisan('notifications:send-setup-nudge')->assertSuccessful();

    Mail::assertQueuedCount(1);
});

it('stays silent outside the owner local 09:00 hour', function (): void {
    Mail::fake();

    $owner = User::factory()->withPersonalTeam()->create(['timezone' => 'Asia/Tokyo']);

    // 09:00 UTC is 18:00 in Tokyo: right hour in the wrong zone must not fire.
    $this->travelTo(now()->setTime(9, 0));

    $owner->currentTeam->forceFill(['created_at' => now()->subDays(2)])->save();

    $this->artisan('notifications:send-setup-nudge')->assertSuccessful();

    Mail::assertNothingQueued();
    expect($owner->currentTeam->fresh()->setup_nudge_sent_at)->toBeNull();
});

it('never nudges an owner who has not verified their email', function (): void {
    Mail::fake();

    $owner = User::factory()->withPersonalTeam()->unverified()->create(['timezone' => 'UTC']);

    $this->travelTo(now()->setTime(9, 0));

    $owner->currentTeam->forceFill(['created_at' => now()->subDays(2)])->save();

    $this->artisan('notifications:send-setup-nudge')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('leaves a workspace younger than two days alone', function (): void {
    Mail::fake();

    $owner = User::factory()->withPersonalTeam()->create(['timezone' => 'UTC']);

    $this->travelTo(now()->setTime(9, 0));

    $owner->currentTeam->forceFill(['created_at' => now()->subDay()])->save();

    $this->artisan('notifications:send-setup-nudge')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('skips a workspace already scheduled for deletion', function (): void {
    Mail::fake();

    $owner = User::factory()->withPersonalTeam()->create(['timezone' => 'UTC']);

    $this->travelTo(now()->setTime(9, 0));

    $owner->currentTeam->forceFill([
        'created_at' => now()->subDays(2),
        'scheduled_deletion_at' => now()->addDays(7),
    ])->save();

    $this->artisan('notifications:send-setup-nudge')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('points the nudge at the dashboard, never at an id-less chat URL', function (): void {
    Mail::fake();

    $owner = User::factory()->withPersonalTeam()->create(['timezone' => 'UTC']);
    $team = $owner->currentTeam;

    $this->travelTo(now()->setTime(9, 0));
    $team->forceFill(['created_at' => now()->subDays(2)])->save();

    $this->artisan('notifications:send-setup-nudge')->assertSuccessful();

    // An id-less chat URL redirects straight back to the dashboard, so the CTA
    // must not send the reader through that bounce.
    Mail::assertQueued(SetupNudgeMail::class, function (SetupNudgeMail $mail) use ($team): bool {
        return $mail->conversationUrl === Dashboard::getUrl(['tenant' => $team], panel: 'app')
            && $mail->conversationUrl !== ChatConversation::getUrl(['tenant' => $team], panel: 'app');
    });
});

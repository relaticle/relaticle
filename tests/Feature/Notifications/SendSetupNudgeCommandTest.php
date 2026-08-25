<?php

declare(strict_types=1);

use App\Actions\User\UpdateNotificationPreferences;
use App\Enums\ActivationStep;
use App\Enums\CreationSource;
use App\Enums\Notifications\NotificationChannel;
use App\Enums\Notifications\NotificationType;
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
        ->toContain(__('filament/pages/dashboard.activation.steps.first_record.label'))
        ->toContain('https://example.test/chat');
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

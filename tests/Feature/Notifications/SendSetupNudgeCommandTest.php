<?php

declare(strict_types=1);

use App\Enums\ActivationStep;
use App\Mail\SetupNudgeMail;
use App\Models\User;

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

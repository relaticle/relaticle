<?php

declare(strict_types=1);

use App\Models\User;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;

mutates(EmailVisibilityService::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create([
        'email' => 'owner@thefireflytech.com',
    ]);
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;

    $this->service = app(EmailVisibilityService::class);
});

it('infers workspace domains from member emails and connected accounts', function (): void {
    ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'email_address' => 'sales@thefireflytech.com',
    ]));

    expect($this->service->workspaceDomains($this->team))->toBe(['thefireflytech.com']);
});

it('ignores consumer email domains when inferring workspace domains', function (): void {
    $this->user->update(['email' => 'owner@gmail.com']);

    expect($this->service->workspaceDomains($this->team))->toBe([]);
});

it('includes system default visibility rows for members and workspace domains', function (): void {
    $rows = $this->service->visibilityTableRows($this->team, collect());

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['address'])->toBe(__('filament/pages/email-privacy-settings.visibility.table.members_row'))
        ->and($rows[1]['address'])->toBe('thefireflytech.com');
});

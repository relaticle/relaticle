<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\Scopes\ActiveAccountScope;

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));

    $this->email = Email::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->getKey(),
    ]);
});

it('shows emails while the connected account is active', function (): void {
    expect(Email::query()->whereKey($this->email->id)->exists())->toBeTrue();
});

it('hides emails once the connected account is disconnected', function (): void {
    $this->account->delete();

    expect(Email::query()->whereKey($this->email->id)->exists())->toBeFalse();
});

it('still exposes the emails when the scope is removed for audit views', function (): void {
    $this->account->delete();

    expect(
        Email::query()
            ->withoutGlobalScope(ActiveAccountScope::class)
            ->whereKey($this->email->id)
            ->exists()
    )->toBeTrue();
});
